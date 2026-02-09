<?php
/**
 * AVAILABILITY & BOOKING FUNCTIONS
 * Query DB e logiche di prenotazione
 * 
 * IMPROVEMENTS v3.4.0:
 * - [CRITICAL] Race condition protection in hard_lock_booking with transactions
 * - [CRITICAL] Waitlist alerts triggered on all date releases
 * - Improved cleanup with waitlist notification
 * - Better token generation with uniqueness check
 */

if (!defined('ABSPATH')) exit;

// =========================================================
// CONSTANTS
// =========================================================

if (!defined('PAGURO_SOFT_LOCK_HOURS')) define('PAGURO_SOFT_LOCK_HOURS', 48);
if (!defined('PAGURO_CANCELLATION_DAYS')) define('PAGURO_CANCELLATION_DAYS', 15);

// =========================================================
// AVAILABILITY QUERIES
// =========================================================

function paguro_get_availability($apt_id = null, $date_start = null, $date_end = null, $status = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'paguro_availability';
    
    $query = "SELECT * FROM $table WHERE 1=1";
    $params = [];
    
    if ($apt_id) {
        $query .= " AND apartment_id = %d";
        $params[] = $apt_id;
    }
    
    if ($date_start && $date_end) {
        $query .= " AND date_start <= %s AND date_end >= %s";
        $params[] = $date_end;
        $params[] = $date_start;
    }
    
    if ($status !== null) {
        $query .= " AND status = %d";
        $params[] = $status;
    }
    
    if ($params) {
        return $wpdb->get_results($wpdb->prepare($query, ...$params));
    } else {
        return $wpdb->get_results($query);
    }
}

function paguro_get_booking($token = null, $id = null, $email = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'paguro_availability';
    
    if ($token) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE lock_token = %s", $token));
    } elseif ($id) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    } elseif ($email) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE guest_email = %s", $email));
    }
    return null;
}

function paguro_get_booking_with_apartment($token) {
    global $wpdb;
    $table_b = $wpdb->prefix . 'paguro_availability';
    $table_a = $wpdb->prefix . 'paguro_apartments';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name, a.base_price FROM $table_b b
         JOIN $table_a a ON b.apartment_id = a.id
         WHERE b.lock_token = %s",
        $token
    ));
}

// =========================================================
// GROUP BOOKINGS
// =========================================================

function paguro_get_group_bookings($group_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'paguro_availability';
    if (!$group_id) return [];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE group_id = %s ORDER BY date_start ASC",
        $group_id
    ));
}

function paguro_get_group_bookings_with_apartment($group_id) {
    global $wpdb;
    $table_b = $wpdb->prefix . 'paguro_availability';
    $table_a = $wpdb->prefix . 'paguro_apartments';
    if (!$group_id) return [];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT b.*, a.name as apt_name, a.base_price FROM $table_b b
         JOIN $table_a a ON b.apartment_id = a.id
         WHERE b.group_id = %s
         ORDER BY b.date_start ASC",
        $group_id
    ));
}

function paguro_parse_group_discount_map($raw) {
    if (is_array($raw)) {
        $map = [];
        foreach ($raw as $k => $v) {
            $key = intval($k);
            $val = floatval($v);
            if ($key > 0 && $val >= 0) $map[$key] = $val;
        }
        ksort($map);
        return $map;
    }
    $raw = trim((string) $raw);
    if ($raw === '') return [];
    $pairs = preg_split('/[,\n;]+/', $raw);
    $map = [];
    foreach ($pairs as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        if (!preg_match('/^(\d+)\s*=\s*([0-9]+(?:[.,][0-9]+)?)$/', $pair, $m)) continue;
        $key = intval($m[1]);
        $val = floatval(str_replace(',', '.', $m[2]));
        if ($key > 0 && $val >= 0) $map[$key] = $val;
    }
    ksort($map);
    return $map;
}

function paguro_format_group_discount_map($map) {
    $parsed = paguro_parse_group_discount_map($map);
    if (!$parsed) return '';
    $parts = [];
    foreach ($parsed as $k => $v) {
        $parts[] = $k . '=' . rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
    return implode(', ', $parts);
}

function paguro_get_group_discount_for_count($count) {
    $count = intval($count);
    if ($count < 2) return 0;
    $map = paguro_parse_group_discount_map(get_option('paguro_group_discount_map', ''));
    return isset($map[$count]) ? floatval($map[$count]) : 0;
}

function paguro_calculate_group_totals($group_id, $bookings = null) {
    if ($bookings === null) {
        $bookings = paguro_get_group_bookings_with_apartment($group_id);
    }
    if (!$bookings) {
        return [
            'weeks' => [],
            'weeks_count' => 0,
            'total_raw' => 0,
            'discount' => 0,
            'total_final' => 0,
            'deposit' => 0,
            'remaining' => 0
        ];
    }
    $total_raw = 0;
    $weeks = [];
    foreach ($bookings as $b) {
        if (isset($b->status) && intval($b->status) === 3) {
            continue;
        }
        $price = function_exists('paguro_calculate_quote')
            ? paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end)
            : 0;
        $weeks[] = [
            'date_start' => $b->date_start,
            'date_end' => $b->date_end,
            'price' => $price,
            'booking' => $b
        ];
        $total_raw += $price;
    }
    $weeks_count = count($weeks);
    $discount = paguro_get_group_discount_for_count($weeks_count);
    $total_final = max(0, $total_raw - $discount);
    $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
    $deposit = $total_final > 0 ? ceil($total_final * ($deposit_percent / 100)) : 0;
    $remaining = $total_final - $deposit;

    return [
        'weeks' => $weeks,
        'weeks_count' => $weeks_count,
        'total_raw' => $total_raw,
        'discount' => $discount,
        'total_final' => $total_final,
        'deposit' => $deposit,
        'remaining' => $remaining
    ];
}

if (!function_exists('paguro_maybe_update_group_quote_after_cancel')) {
    function paguro_maybe_update_group_quote_after_cancel($group_id) {
        $group_id = (string) $group_id;
        if ($group_id === '') return false;
        if (!function_exists('paguro_get_group_bookings')) return false;
        $bookings = paguro_get_group_bookings($group_id);
        if (!$bookings) return false;

        $active = [];
        foreach ($bookings as $b) {
            if (intval($b->status) !== 3) {
                $active[] = $b;
            }
        }
        if (count($active) !== 1) return false;
        $remaining = $active[0];
        if (intval($remaining->status) !== 2) return false;

        $sent_user = function_exists('paguro_send_group_quote_request_to_user')
            ? paguro_send_group_quote_request_to_user($group_id)
            : false;
        $sent_admin = function_exists('paguro_send_group_quote_request_to_admin')
            ? paguro_send_group_quote_request_to_admin($group_id)
            : false;

        if (function_exists('paguro_add_history')) {
            paguro_add_history($remaining->id, 'GROUP_QUOTE_UPDATE', 'Preventivo aggiornato dopo cancellazione di una settimana');
        }
        return ($sent_user || $sent_admin);
    }
}

function paguro_generate_unique_group_id() {
    global $wpdb;
    $max_attempts = 10;
    $attempts = 0;
    $table = $wpdb->prefix . 'paguro_availability';
    do {
        $token = bin2hex(random_bytes(32));
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE lock_token = %s OR group_id = %s",
            $token,
            $token
        ));
        $attempts++;
    } while ($exists > 0 && $attempts < $max_attempts);
    if ($exists > 0) {
        error_log('[Paguro] Failed to generate unique group id after ' . $max_attempts . ' attempts');
        return false;
    }
    return $token;
}

// =========================================================
// TOKEN GENERATION
// =========================================================

/**
 * Generate unique token with collision check
 * IMPROVEMENT: Ensures token uniqueness in database
 */
function paguro_generate_unique_token() {
    global $wpdb;
    $max_attempts = 10;
    $attempts = 0;
    
    do {
        $token = bin2hex(random_bytes(32));
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s",
            $token
        ));
        $attempts++;
    } while ($exists > 0 && $attempts < $max_attempts);
    
    if ($exists > 0) {
        error_log('[Paguro] Failed to generate unique token after ' . $max_attempts . ' attempts');
        return false;
    }
    
    return $token;
}

// =========================================================
// BOOKING CRUD
// =========================================================

function paguro_create_quote_lock($apt_id, $date_start, $date_end) {
    global $wpdb;
    
    $token = paguro_generate_unique_token();
    if (!$token) {
        return false;
    }
    
    $lock_expires = date('Y-m-d H:i:s', time() + (PAGURO_SOFT_LOCK_HOURS * 3600));
    
    $wpdb->insert($wpdb->prefix . 'paguro_availability', [
        'apartment_id' => $apt_id,
        'date_start' => $date_start,
        'date_end' => $date_end,
        'status' => 2, // PREVENTIVO SOFT
        'lock_token' => $token,
        'lock_expires' => $lock_expires,
        'created_at' => current_time('mysql')
    ]);
    
    return $wpdb->insert_id;
}

function paguro_create_waitlist_entry($apt_id, $date_start, $date_end) {
    global $wpdb;
    
    $token = paguro_generate_unique_token();
    if (!$token) {
        return false;
    }
    
    $wpdb->insert($wpdb->prefix . 'paguro_availability', [
        'apartment_id' => $apt_id,
        'date_start' => $date_start,
        'date_end' => $date_end,
        'status' => 4, // WAITLIST
        'lock_token' => $token,
        'created_at' => current_time('mysql')
    ]);
    
    return ['id' => $wpdb->insert_id, 'token' => $token];
}

function paguro_update_booking($token, $data) {
    global $wpdb;
    return $wpdb->update($wpdb->prefix . 'paguro_availability', $data, ['lock_token' => $token]);
}

function paguro_update_booking_by_id($booking_id, $data) {
    global $wpdb;
    return $wpdb->update($wpdb->prefix . 'paguro_availability', $data, ['id' => $booking_id]);
}

// =========================================================
// HARD LOCK (Receipt Upload)
// =========================================================

/**
 * Hard Lock Booking with Race Condition Protection
 * 
 * IMPROVEMENT v3.4.0: Added transaction with FOR UPDATE lock to prevent
 * double-booking when two users upload receipt simultaneously for overlapping dates
 * 
 * @param int $booking_id Booking ID
 * @param string $receipt_url Uploaded receipt URL
 * @return bool Success status
 */
function paguro_hard_lock_booking($booking_id, $receipt_url) {
    global $wpdb;
    
    // Start transaction for race condition protection
    $wpdb->query('START TRANSACTION');
    
    try {
        // Lock the booking row for update
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE id = %d FOR UPDATE",
            $booking_id
        ));
        
        if (!$booking) {
            $wpdb->query('ROLLBACK');
            error_log('[Paguro] Hard lock failed: booking not found (ID: ' . $booking_id . ')');
            return false;
        }
        
        // Check if booking already has receipt (idempotency)
        if (!empty($booking->receipt_url)) {
            $wpdb->query('COMMIT');
            error_log('[Paguro] Hard lock skipped: receipt already uploaded (ID: ' . $booking_id . ')');
            return true;
        }
        
        // CRITICAL: Check for hard conflicts (confirmed or receipt-uploaded bookings)
        $conflict = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
             WHERE apartment_id = %d 
             AND id != %d
             AND (status = 1 OR (status = 2 AND receipt_url IS NOT NULL))
             AND (date_start < %s AND date_end > %s)
             FOR UPDATE",
            $booking->apartment_id,
            $booking_id,
            $booking->date_end,
            $booking->date_start
        ));
        
        if ($conflict > 0) {
            $wpdb->query('ROLLBACK');
            error_log('[Paguro] RACE CONDITION DETECTED: Hard conflict found (Booking ID: ' . $booking_id . ', Apt: ' . $booking->apartment_id . ')');
            
            // Send alert to admin about race condition
            if (function_exists('paguro_send_race_alert_to_admin')) {
                paguro_send_race_alert_to_admin($booking_id);
            }
            
            return false;
        }
        
        // Check history for previous admin confirmation
        $history = paguro_get_history($booking_id);
        $is_confirmed = false;
        foreach ($history as $entry) {
            if (!empty($entry['action']) && $entry['action'] === 'ADMIN_CONFIRM') {
                $is_confirmed = true;
                break;
            }
        }
        
        // Status: 2 = In validation, 1 = Confirmed (only if previously admin-confirmed)
        $status = $is_confirmed ? 1 : 2;
        
        // Update booking with receipt
        $wpdb->update(
            $wpdb->prefix . 'paguro_availability',
            [
                'receipt_url' => $receipt_url,
                'receipt_uploaded_at' => current_time('mysql'),
                'status' => $status
            ],
            ['id' => $booking_id]
        );
        
        // Commit transaction
        $wpdb->query('COMMIT');
        
        // Log history
        paguro_add_history($booking_id, 'HARD_LOCK', 'Receipt uploaded - In validation');
        
        error_log('[Paguro] Hard lock successful (Booking ID: ' . $booking_id . ', Status: ' . $status . ')');
        
        return true;
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('[Paguro] Hard lock exception: ' . $e->getMessage());
        return false;
    }
}

function paguro_hard_lock_group($group_id, $receipt_url) {
    global $wpdb;
    $group_id = (string) $group_id;
    if ($group_id === '') return false;

    $wpdb->query('START TRANSACTION');
    try {
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE group_id = %s ORDER BY date_start ASC FOR UPDATE",
            $group_id
        ));
        if (!$bookings) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        foreach ($bookings as $booking) {
            if (!empty($booking->receipt_url)) {
                continue;
            }
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
                 WHERE apartment_id = %d 
                 AND id != %d
                 AND (status = 1 OR (status = 2 AND receipt_url IS NOT NULL))
                 AND (date_start < %s AND date_end > %s)
                 FOR UPDATE",
                $booking->apartment_id,
                $booking->id,
                $booking->date_end,
                $booking->date_start
            ));
            if ($conflict > 0) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        foreach ($bookings as $booking) {
            if (!empty($booking->receipt_url)) {
                continue;
            }
            $history = paguro_get_history($booking->id);
            $is_confirmed = false;
            foreach ($history as $entry) {
                if (!empty($entry['action']) && $entry['action'] === 'ADMIN_CONFIRM') {
                    $is_confirmed = true;
                    break;
                }
            }
            $status = $is_confirmed ? 1 : 2;
            $wpdb->update(
                $wpdb->prefix . 'paguro_availability',
                [
                    'receipt_url' => $receipt_url,
                    'receipt_uploaded_at' => current_time('mysql'),
                    'status' => $status
                ],
                ['id' => $booking->id]
            );
            paguro_add_history($booking->id, 'HARD_LOCK', 'Receipt uploaded - In validation');
        }

        $wpdb->query('COMMIT');
        return true;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('[Paguro] Hard lock group exception: ' . $e->getMessage());
        return false;
    }
}

// =========================================================
// CHECK AVAILABILITY
// =========================================================

function paguro_is_date_available($apt_id, $date_start, $date_end) {
    global $wpdb;
    
    $occupied = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
         WHERE apartment_id = %d 
         AND (status = 1 OR (status = 2 AND receipt_url IS NOT NULL))
         AND (date_start < %s AND date_end > %s)",
        $apt_id,
        $date_end,
        $date_start
    ));
    
    return $occupied == 0;
}

function paguro_count_competing_quotes($apt_id, $date_start, $date_end, $exclude_id = null) {
    global $wpdb;
    
    // IMPROVEMENT: Only count non-expired soft locks
    $query = "SELECT COUNT(DISTINCT guest_email) FROM {$wpdb->prefix}paguro_availability 
              WHERE apartment_id = %d 
              AND status = 2 
              AND receipt_url IS NULL
              AND (lock_expires IS NULL OR lock_expires > NOW())
              AND (date_start < %s AND date_end > %s)";
    
    $params = [$apt_id, $date_end, $date_start];
    
    if ($exclude_id) {
        $query .= " AND id != %d";
        $params[] = $exclude_id;
    }
    
    return $wpdb->get_var($wpdb->prepare($query, ...$params));
}

function paguro_get_overlapping_waitlist($apt_id, $date_start, $date_end) {
    global $wpdb;
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_availability 
         WHERE apartment_id = %d 
         AND status = 4
         AND (date_start < %s AND date_end > %s)",
        $apt_id,
        $date_end,
        $date_start
    ));
}

// =========================================================
// HISTORY LOGGING
// =========================================================

function paguro_add_history($booking_id, $action, $details = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'paguro_availability';
    
    $row = $wpdb->get_row($wpdb->prepare("SELECT history_log FROM $table WHERE id = %d", $booking_id));
    
    if ($row) {
        $log = $row->history_log ? json_decode($row->history_log, true) : [];
        if (!is_array($log)) $log = [];
        
        $log[] = [
            'time' => current_time('mysql'),
            'action' => $action,
            'details' => $details
        ];
        
        $wpdb->update($table, ['history_log' => json_encode($log)], ['id' => $booking_id]);
    }
}

function paguro_get_history($booking_id) {
    global $wpdb;
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT history_log FROM {$wpdb->prefix}paguro_availability WHERE id = %d",
        $booking_id
    ));
    
    if ($row && $row->history_log) {
        return json_decode($row->history_log, true);
    }
    
    return [];
}

// =========================================================
// APARTMENT QUERIES
// =========================================================

function paguro_get_apartment($apt_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_apartments WHERE id = %d",
        $apt_id
    ));
}

function paguro_get_apartment_by_name($name) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s",
        $name
    ));
}

function paguro_get_all_apartments() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments ORDER BY name ASC");
}

// =========================================================
// BOOKING STATS
// =========================================================

function paguro_get_booking_stats($apt_id = null) {
    global $wpdb;

    $where = "WHERE 1=1";
    $params = [];
    
    if ($apt_id) {
        $where .= " AND apartment_id = %d";
        $params[] = $apt_id;
    }

    $query_confirmed = "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability $where AND status = 1";
    $query_pending = "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability $where AND status = 2";
    $query_cancelled = "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability $where AND status = 3";
    $query_waitlist = "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability $where AND status = 4";

    return [
        'confirmed' => $params ? $wpdb->get_var($wpdb->prepare($query_confirmed, ...$params)) : $wpdb->get_var($query_confirmed),
        'pending_quote' => $params ? $wpdb->get_var($wpdb->prepare($query_pending, ...$params)) : $wpdb->get_var($query_pending),
        'cancelled' => $params ? $wpdb->get_var($wpdb->prepare($query_cancelled, ...$params)) : $wpdb->get_var($query_cancelled),
        'waitlist' => $params ? $wpdb->get_var($wpdb->prepare($query_waitlist, ...$params)) : $wpdb->get_var($query_waitlist)
    ];
}

// =========================================================
// CLEANUP HELPERS
// =========================================================

/**
 * Cleanup expired soft-lock quotes
 * 
 * IMPROVEMENT v3.4.0: Now triggers waitlist alerts when dates are freed
 * 
 * @return int Number of deleted bookings
 */
function paguro_cleanup_expired_quotes() {
    global $wpdb;
    
    // First, get bookings that will be deleted (for waitlist notification)
    $expiring = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}paguro_availability 
         WHERE status = 2 
         AND receipt_url IS NULL 
         AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) 
              OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL " . PAGURO_SOFT_LOCK_HOURS . " HOUR)))"
    );
    
    // Delete expired quotes
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->prefix}paguro_availability 
         WHERE status = 2 
         AND receipt_url IS NULL 
         AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) 
              OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL " . PAGURO_SOFT_LOCK_HOURS . " HOUR)))"
    );
    
    // IMPROVEMENT: Trigger waitlist alerts for freed dates
    if ($expiring && count($expiring) > 0) {
        foreach ($expiring as $booking) {
            paguro_trigger_waitlist_alerts(
                $booking->apartment_id,
                $booking->date_start,
                $booking->date_end
            );
        }
        error_log('[Paguro] Cleanup: ' . $deleted . ' expired quotes deleted, waitlist alerts sent');
    }
    
    return $deleted;
}

// =========================================================
// WAITLIST HELPERS
// =========================================================

/**
 * Trigger waitlist alerts for overlapping dates
 * 
 * IMPROVEMENT v3.4.0: Centralized function called from multiple release points
 * 
 * @param int $apt_id Apartment ID
 * @param string $date_start Date start (Y-m-d)
 * @param string $date_end Date end (Y-m-d)
 * @return int Number of alerts sent
 */
function paguro_trigger_waitlist_alerts($apt_id, $date_start, $date_end) {
    $waitlisters = paguro_get_overlapping_waitlist($apt_id, $date_start, $date_end);
    
    if (!$waitlisters) return 0;
    
    $count = 0;
    foreach ($waitlisters as $wl) {
        if (is_email($wl->guest_email)) {
            // Send availability alert email
            if (function_exists('paguro_send_waitlist_availability_alert')) {
                paguro_send_waitlist_availability_alert($wl->id);
            }
            
            // Log in history
            paguro_add_history($wl->id, 'WAITLIST_ALERT_SENT', 'Availability alert sent for freed dates');
            $count++;
        }
    }
    
    if ($count > 0) {
        error_log('[Paguro] Waitlist alerts sent: ' . $count . ' (Apt: ' . $apt_id . ', Dates: ' . $date_start . ' - ' . $date_end . ')');
    }
    
    return $count;
}

?>
