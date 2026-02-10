<?php
/**
 * CENTRALIZED EMAIL FUNCTIONS
 * Gestisce tutti gli invii email del sistema
 */

if (!defined('ABSPATH')) exit;

// =========================================================
// EMAIL TEMPLATE HELPERS
// =========================================================

function paguro_build_email_html($content, $subject = 'Villa Celi', $is_admin = false) {
    $privacy_txt = '';
    if (!$is_admin) {
        $privacy_txt = get_option('paguro_msg_ui_privacy_notice', '');
    }

    $has_html = (bool) preg_match('/<[^>]+>/', (string) $content);
    if (!$has_html && !empty($privacy_txt)) {
        $has_html = (bool) preg_match('/<[^>]+>/', (string) $privacy_txt);
    }

    if (!empty($privacy_txt)) {
        $content .= $has_html ? '<br><br>' . $privacy_txt : "\n\n" . $privacy_txt;
    }

    $content = $has_html ? $content : nl2br(esc_html($content));
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f6f6f6; padding: 20px; margin: 0; }
        .email-container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .email-header { background: #0073aa; padding: 25px; text-align: center; color: #fff; }
        .email-header h1 { margin: 0; font-size: 28px; }
        .email-body { padding: 30px; color: #333; line-height: 1.6; }
        .email-body img { max-height: 2em; height: auto; width: auto; max-width: 100%; vertical-align: middle; }
        .email-footer { background: #eee; padding: 15px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ddd; }
        .button { display: inline-block; background: #28a745; color: #fff; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 15px 0; }
        .button:hover { background: #218838; }
        .info-box { background: #f8f9fa; padding: 15px; border-left: 4px solid #0073aa; margin: 15px 0; }
        .warning-box { background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; color: #856404; }
        .success-box { background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0; color: #155724; }
        .footer-links { margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>' . esc_html($subject) . '</h1>
        </div>
        <div class="email-body">
            ' . $content . '
        </div>
        <div class="email-footer">
            &copy; ' . date('Y') . ' Villa Celi - Tutti i diritti riservati
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

// =========================================================
// EMAIL SEND WRAPPER
// =========================================================

function paguro_send_email($to, $subject, $body, $is_admin = false) {
    if (!is_email($to)) return false;
    
    $html = paguro_build_email_html($body, $subject, $is_admin);
    $from_email = get_option('paguro_mail_from', 'info@villaceli.it');
    if (!is_email($from_email)) {
        $from_email = 'info@villaceli.it';
    }
    $from_name = get_option('paguro_mail_from_name', 'Villa Celi');
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>'
    ];
    
    $sent = wp_mail($to, $subject, $html, $headers);
    if (!$sent) {
        error_log('[Paguro] wp_mail failed: to=' . $to . ' subject=' . $subject);
    }
    
    return $sent;
}

// =========================================================
// PLACEHOLDERS HELPERS
// =========================================================

if (!function_exists('paguro_mask_iban')) {
    function paguro_mask_iban($iban)
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));
        if ($iban === '') return '';
        $len = strlen($iban);
        if ($len <= 8) return str_repeat('*', $len);
        $start = substr($iban, 0, 4);
        $end = substr($iban, -4);
        return $start . str_repeat('*', $len - 8) . $end;
    }
}

function paguro_get_email_placeholders($b) {
    $apt_name_raw = isset($b->apt_name) ? $b->apt_name : '';
    $apt_name = $apt_name_raw ? ucfirst($apt_name_raw) : '';

    $page_slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
    $link_riepilogo = site_url("/{$page_slug}/?token={$b->lock_token}");
    $booking_url = site_url("/" . get_option('paguro_checkout_slug', 'prenotazione') . "/");

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $arrival_dt = new DateTime($b->date_start, $tz);
    $cancel_days = defined('PAGURO_CANCELLATION_DAYS') ? PAGURO_CANCELLATION_DAYS : 15;
    $cancel_deadline_dt = (clone $arrival_dt)->modify('-' . intval($cancel_days) . ' days');
    $cancel_deadline = $cancel_deadline_dt->format('d/m/Y');
    $cancel_deadline_raw = $cancel_deadline_dt->format('Y-m-d');

    $total_cost = function_exists('paguro_get_booking_total_cost')
        ? paguro_get_booking_total_cost($b)
        : (function_exists('paguro_calculate_quote')
            ? paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end)
            : 0);
    $weeks_count = 0;
    try {
        $current = new DateTime($b->date_start, $tz);
        $end_dt = new DateTime($b->date_end, $tz);
        while ($current < $end_dt) {
            $weeks_count++;
            $current->modify('+1 week');
        }
    } catch (Exception $e) {
        $weeks_count = 0;
    }
    $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
    $deposit = $total_cost > 0 ? ceil($total_cost * ($deposit_percent / 100)) : 0;
    $remaining = $total_cost - $deposit;

    $total_cost_fmt = number_format($total_cost, 0, ',', '.');
    $deposit_cost_fmt = number_format($deposit, 0, ',', '.');
    $remaining_cost_fmt = number_format($remaining, 0, ',', '.');

    $customer_iban = isset($b->customer_iban) ? $b->customer_iban : '';
    $customer_iban_norm = strtoupper(preg_replace('/\s+/', '', $customer_iban));
    $customer_iban_priv = paguro_mask_iban($customer_iban_norm);
    $created_at_fmt = '';
    if (!empty($b->created_at)) {
        $created_at_dt = new DateTime($b->created_at, $tz);
        $created_at_fmt = $created_at_dt->format('d/m/Y H:i');
    }

    return [
        'guest_name' => $b->guest_name,
        'guest_email' => $b->guest_email,
        'guest_phone' => $b->guest_phone,
        'guest_notes' => $b->guest_notes,
        'customer_iban' => $customer_iban_norm,
        'customer_iban_priv' => $customer_iban_priv,
        'apt_name' => $apt_name,
        'apt_name_raw' => $apt_name_raw,
        'date_start' => date('d/m/Y', strtotime($b->date_start)),
        'date_end' => date('d/m/Y', strtotime($b->date_end)),
        'date_start_raw' => $b->date_start,
        'date_end_raw' => $b->date_end,
        'cancel_deadline' => $cancel_deadline,
        'cancel_deadline_raw' => $cancel_deadline_raw,
        'weeks_count' => $weeks_count,
        'count' => $weeks_count,
        // Costi (compat e raw)
        'total_cost' => $total_cost_fmt,
        'deposit_cost' => $deposit_cost_fmt,
        'remaining_cost' => $remaining_cost_fmt,
        'total_cost_raw' => $total_cost,
        'deposit_cost_raw' => $deposit,
        'remaining_cost_raw' => $remaining,
        'total_cost_fmt' => $total_cost_fmt,
        'deposit_cost_fmt' => $deposit_cost_fmt,
        'remaining_cost_fmt' => $remaining_cost_fmt,
        'deposit' => $deposit_cost_fmt,
        'deposit_percent' => $deposit_percent,
        'iban' => get_option('paguro_bank_iban'),
        'intestatario' => get_option('paguro_bank_owner'),
        'receipt_url' => $b->receipt_url,
        'receipt_uploaded_at' => $b->receipt_uploaded_at,
        'receipt_uploaded_at_fmt' => $b->receipt_uploaded_at ? date('d/m/Y H:i', strtotime($b->receipt_uploaded_at)) : '',
        'booking_id' => $b->id,
        'apartment_id' => $b->apartment_id,
        'status' => $b->status,
        'token' => $b->lock_token,
        'created_at' => $b->created_at,
        'created_at_fmt' => $created_at_fmt,
        'lock_expires' => $b->lock_expires,
        'link_riepilogo' => $link_riepilogo,
        'booking_url' => $booking_url,
        'admin_email' => get_option('admin_email')
    ];
}

function paguro_escape_user_placeholders($placeholders) {
    $safe = $placeholders;
    foreach (['guest_name', 'guest_email', 'guest_phone', 'guest_notes', 'customer_iban', 'customer_iban_priv'] as $key) {
        if (isset($safe[$key])) {
            $safe[$key] = esc_html($safe[$key]);
        }
    }
    return $safe;
}

function paguro_build_group_weeks_html($weeks) {
    if (!$weeks) return '';
    $html = '<ul>';
    foreach ($weeks as $w) {
        $label = date('d/m/Y', strtotime($w['date_start'])) . ' - ' . date('d/m/Y', strtotime($w['date_end']));
        $price = isset($w['price']) ? number_format($w['price'], 0, ',', '.') : '';
        $html .= '<li>' . esc_html($label) . ($price !== '' ? ' (€' . esc_html($price) . ')' : '') . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function paguro_get_group_email_placeholders($group_id) {
    if (!function_exists('paguro_get_group_bookings_with_apartment')) return [];
    $bookings = paguro_get_group_bookings_with_apartment($group_id);
    if (!$bookings) return [];
    $active = [];
    foreach ($bookings as $b) {
        if (intval($b->status) !== 3) {
            $active[] = $b;
        }
    }
    if (!$active) return [];
    $first = $active[0];
    $totals = function_exists('paguro_calculate_group_totals') ? paguro_calculate_group_totals($group_id, $bookings) : [
        'weeks' => [],
        'weeks_count' => 0,
        'total_raw' => 0,
        'discount' => 0,
        'total_final' => 0,
        'deposit' => 0,
        'remaining' => 0
    ];
    $weeks_list = paguro_build_group_weeks_html($totals['weeks']);
    $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
    $page_slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
    $link_riepilogo = site_url("/{$page_slug}/?token={$group_id}");
    $booking_url = site_url("/" . get_option('paguro_checkout_slug', 'prenotazione') . "/");

    $date_start = $active[0]->date_start;
    $date_end = $active[count($active) - 1]->date_end;
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $cancel_deadline = '';
    $cancel_deadline_raw = '';
    try {
        $arrival_dt = new DateTime($date_start, $tz);
        $cancel_days = defined('PAGURO_CANCELLATION_DAYS') ? PAGURO_CANCELLATION_DAYS : 15;
        $cancel_deadline_dt = (clone $arrival_dt)->modify('-' . intval($cancel_days) . ' days');
        $cancel_deadline = $cancel_deadline_dt->format('d/m/Y');
        $cancel_deadline_raw = $cancel_deadline_dt->format('Y-m-d');
    } catch (Exception $e) {
        $cancel_deadline = '';
        $cancel_deadline_raw = '';
    }

    return [
        'guest_name' => $first->guest_name,
        'guest_email' => $first->guest_email,
        'guest_phone' => $first->guest_phone,
        'apt_name' => ucfirst($first->apt_name),
        'apt_name_raw' => $first->apt_name,
        'date_start' => date('d/m/Y', strtotime($date_start)),
        'date_end' => date('d/m/Y', strtotime($date_end)),
        'date_start_raw' => $date_start,
        'date_end_raw' => $date_end,
        'weeks_list' => $weeks_list,
        'weeks_count' => $totals['weeks_count'],
        'count' => $totals['weeks_count'],
        'total_raw' => $totals['total_raw'],
        'total_raw_fmt' => number_format($totals['total_raw'], 0, ',', '.'),
        'discount_amount' => $totals['discount'],
        'discount_amount_fmt' => number_format($totals['discount'], 0, ',', '.'),
        'total_cost_raw' => $totals['total_final'],
        'total_cost' => number_format($totals['total_final'], 0, ',', '.'),
        'total_cost_fmt' => number_format($totals['total_final'], 0, ',', '.'),
        'deposit_cost_raw' => $totals['deposit'],
        'deposit_cost' => number_format($totals['deposit'], 0, ',', '.'),
        'deposit_cost_fmt' => number_format($totals['deposit'], 0, ',', '.'),
        'remaining_cost_raw' => $totals['remaining'],
        'remaining_cost' => number_format($totals['remaining'], 0, ',', '.'),
        'remaining_cost_fmt' => number_format($totals['remaining'], 0, ',', '.'),
        'deposit_percent' => $deposit_percent,
        'iban' => get_option('paguro_bank_iban'),
        'intestatario' => get_option('paguro_bank_owner'),
        'link_riepilogo' => $link_riepilogo,
        'booking_url' => $booking_url,
        'cancel_deadline' => $cancel_deadline,
        'cancel_deadline_raw' => $cancel_deadline_raw,
        'admin_email' => get_option('admin_email')
    ];
}

function paguro_get_admin_receipt_link($booking_id, $receipt_url = '') {
    $booking_id = intval($booking_id);
    if ($booking_id <= 0 || $receipt_url === '') {
        return admin_url('admin.php?page=paguro-booking&tab=bookings');
    }
    $hash = wp_hash($booking_id . '|' . $receipt_url);
    return add_query_arg(
        [
            'action' => 'paguro_admin_receipt',
            'booking_id' => $booking_id,
            'h' => $hash
        ],
        admin_url('admin-post.php')
    );
}

function paguro_get_gdpr_notice_for_booking($b) {
    if (!$b) return '';
    $has_receipt = !empty($b->receipt_url);
    $is_confirmed = false;
    if (function_exists('paguro_get_history') && !empty($b->id)) {
        $history = paguro_get_history($b->id);
        foreach ($history as $entry) {
            $action = $entry['action'] ?? '';
            if ($action === 'ADMIN_CONFIRM' || $action === 'ADMIN_VALIDATE_RECEIPT') {
                $is_confirmed = true;
                break;
            }
        }
    }
    $days = defined('PAGURO_CANCELLATION_DAYS') ? PAGURO_CANCELLATION_DAYS : 15;

    if (!$has_receipt) {
        return '<div class="warning-box">Attenzione: la cancellazione dei dati equivale alla cancellazione del preventivo. Vuoi procedere? (S/N)</div>';
    }
    if ($is_confirmed) {
        return '<div class="info-box">Richiesta presa in carico. I dati verranno cancellati ' . intval($days) . ' giorni dopo il check-out e riceverai conferma.</div>';
    }
    return '<div class="warning-box">Attenzione: è presente una prenotazione. Per cancellare i dati, cancella prima la prenotazione.</div>';
}

function paguro_append_gdpr_notice($body, $b) {
    $notice = paguro_get_gdpr_notice_for_booking($b);
    if ($notice === '') return $body;
    return $body . '<br><br>' . $notice;
}

// =========================================================
// 1. QUOTE SUBMITTED - USER
// =========================================================

function paguro_send_quote_request_to_user($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    if (!empty($b->group_id) && function_exists('paguro_send_group_quote_request_to_user')) {
        return paguro_send_group_quote_request_to_user($b->group_id);
    }
    
    $placeholders = paguro_get_email_placeholders($b);
    
    $subject = paguro_parse_template(
        get_option('paguro_txt_email_request_subj', 'Conferma Richiesta Preventivo: {apt_name}'),
        $placeholders
    );
    
    // Escape user input prima di inserire nel template
    $safe_placeholders = paguro_escape_user_placeholders($placeholders);
    
	    $template = get_option('paguro_txt_email_request_body', 
	        'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua richiesta per {apt_name} dal {date_start} al {date_end}.<br>' .
	        'Per procedere con la conferma e visualizzare i dati per il bonifico, accedi alla tua area riservata:<br>' .
	        '<a href="{link_riepilogo}" class="button">VAI ALLA TUA PRENOTAZIONE</a>'
	    );
	
	    if (stripos($template, '{link_riepilogo}') === false) {
	        $template .= '<br><br><a href="{link_riepilogo}" class="button">Accedi alla tua prenotazione</a>';
	    }
	
	    $body = paguro_parse_template($template, $safe_placeholders);
    
    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 2. QUOTE SUBMITTED - ADMIN
// =========================================================

function paguro_send_quote_request_to_admin($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b) return false;
    if (!empty($b->group_id) && function_exists('paguro_send_group_quote_request_to_admin')) {
        return paguro_send_group_quote_request_to_admin($b->group_id);
    }
    
    $admin_email = get_option('admin_email');
    
    $placeholders = paguro_get_email_placeholders($b);
    $placeholders = paguro_escape_user_placeholders($placeholders);
    
    $subject = paguro_parse_template(
        get_option('paguro_msg_email_adm_new_req_subj', 'Nuovo Preventivo: {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_msg_email_adm_new_req_body', 
            'Nuovo preventivo richiesto da {guest_name} ({guest_email}) per {apt_name} dal {date_start} al {date_end}.'
        ),
        $placeholders
    );
    
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// 3. RECEIPT UPLOADED - USER
// =========================================================

function paguro_send_receipt_received_to_user($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    if (!empty($b->group_id) && function_exists('paguro_send_group_receipt_received_to_user')) {
        return paguro_send_group_receipt_received_to_user($b->group_id);
    }
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject = paguro_parse_template(
        get_option('paguro_txt_email_receipt_subj', 'Distinta Ricevuta - {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_txt_email_receipt_body', 
            'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua distinta del bonifico per {apt_name} ({date_start} - {date_end}).<br>' .
            'Stiamo validando il pagamento. Ti contatteremo entro 24 ore.'
        ),
        $placeholders
    );
    
    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 4. RECEIPT UPLOADED - ADMIN
// =========================================================

function paguro_send_receipt_received_to_admin($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b) return false;
    if (!empty($b->group_id) && function_exists('paguro_send_group_receipt_received_to_admin')) {
        return paguro_send_group_receipt_received_to_admin($b->group_id);
    }
    
    $admin_email = get_option('admin_email');
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    $placeholders['admin_link'] = admin_url('admin.php?page=paguro-booking&tab=bookings');
    $placeholders['booking_id'] = $b->id;
    if (!empty($b->receipt_url)) {
        $placeholders['receipt_url'] = paguro_get_admin_receipt_link($b->id, $b->receipt_url);
    }
    
    $subject = paguro_parse_template(
        get_option('paguro_msg_email_adm_receipt_subj', 'Distinta Caricata: {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_msg_email_adm_receipt_body', 
            'Distinta ricevuta da {guest_name} ({guest_email}) per {apt_name} ({date_start} - {date_end}).<br>' .
            'ID prenotazione: {booking_id}.<br>' .
            'Apri il pannello admin per validare: <a href="{admin_link}">Vai alle prenotazioni</a>.'
        ),
        $placeholders
    );
    
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// 5. BOOKING CONFIRMED - USER
// =========================================================

function paguro_send_booking_confirmed_to_user($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name, a.base_price FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    if (!empty($b->group_id) && function_exists('paguro_send_group_booking_confirmed_to_user')) {
        return paguro_send_group_booking_confirmed_to_user($b->group_id);
    }
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject = paguro_parse_template(
        get_option('paguro_txt_email_confirm_subj', 'Prenotazione Confermata: {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_txt_email_confirm_body', 
            'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata confermata!<br>' .
            'Importo totale: €{total_cost}<br>Acconto: €{deposit}'
        ),
        $placeholders
    );
    
    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 6. BOOKING CONFIRMED - ADMIN
// =========================================================

function paguro_send_booking_confirmed_to_admin($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b) return false;
    if (!empty($b->group_id) && function_exists('paguro_send_group_booking_confirmed_to_admin')) {
        return paguro_send_group_booking_confirmed_to_admin($b->group_id);
    }
    
    $admin_email = get_option('admin_email');
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject = paguro_parse_template(
        get_option('paguro_msg_email_adm_wait_subj', 'Prenotazione Confermata: {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_msg_email_adm_wait_body', 
            'Prenotazione confermata per {guest_name} - {apt_name} (€{total_cost})'
        ),
        $placeholders
    );
    
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// 7. REFUND NOTIFIED - USER (Race Lost)
// =========================================================

function paguro_send_refund_notification($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject = paguro_parse_template(
        get_option('paguro_txt_email_race_lost_subj', 'Periodo non disponibile - {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_txt_email_race_lost_body', 
            'Ciao {guest_name},<br><br>Purtroppo un altro utente ha confermato prima il periodo {date_start} - {date_end} per {apt_name}.<br>' .
            'L\'acconto sarà rimborsato entro 5 giorni lavorativi.<br>Visita il nostro sito per altre disponibilità!'
        ),
        $placeholders
    );
    
    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 8. CANCEL REQUEST - USER
// =========================================================

function paguro_send_cancel_request_to_user($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject = paguro_parse_template(
        get_option('paguro_txt_email_cancel_req_subj', 'Richiesta cancellazione ricevuta - {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_txt_email_cancel_req_body', 
            'Ciao {guest_name},<br><br>' .
            'Abbiamo ricevuto la tua richiesta di cancellazione.<br><br>' .
            '<strong>Appartamento:</strong> {apt_name}<br>' .
            '<strong>Date soggiorno:</strong> {date_start} - {date_end}<br><br>' .
            'La richiesta è in verifica dal nostro staff.<br><br>' .
            '<strong>IBAN per rimborso:</strong> {customer_iban_priv}<br>' .
            'Il rimborso, se confermato, verrà disposto su questo IBAN.<br>' .
            'Non possiamo essere responsabili di eventuali errori nell\'IBAN comunicato.<br><br>' .
            'Cancellazione applicabile fino al {cancel_deadline}.'
        ),
        $placeholders
    );
    
    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 9. CANCEL REQUEST - ADMIN
// =========================================================

function paguro_send_cancel_request_to_admin($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b) return false;
    
    $admin_email = get_option('admin_email');
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject = paguro_parse_template(
        get_option('paguro_txt_email_cancel_req_adm_subj', 'Richiesta cancellazione - {apt_name}'),
        $placeholders
    );
    
    $body = paguro_parse_template(
        get_option('paguro_txt_email_cancel_req_adm_body', 
            'Richiesta cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end}).'
        ),
        $placeholders
    );
    
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// GROUP: BOOKING CONFIRMED
// =========================================================

function paguro_send_group_booking_confirmed_to_user($group_id) {
    if (!function_exists('paguro_get_group_bookings')) return false;
    $bookings = paguro_get_group_bookings($group_id);
    if (!$bookings) return false;
    foreach ($bookings as $b) {
        if (intval($b->status) !== 1) {
            return false;
        }
    }
    $placeholders = paguro_get_group_email_placeholders($group_id);
    if (empty($placeholders) || !is_email($placeholders['guest_email'] ?? '')) return false;
    $placeholders = paguro_escape_user_placeholders($placeholders);

    $subject = paguro_parse_template(
        get_option('paguro_txt_email_confirm_subj', 'Prenotazione Confermata: {apt_name}'),
        $placeholders
    );
    $template = get_option('paguro_txt_email_confirm_body',
        'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} è confermata!<br>' .
        'Settimane: {weeks_list}<br>' .
        'Importo totale: €{total_cost}<br>Acconto: €{deposit_cost}'
    );
    if (stripos($template, '{weeks_list}') === false) {
        $template .= '<br><br><strong>Settimane:</strong><br>{weeks_list}';
    }
    $body = paguro_parse_template($template, $placeholders);
    return paguro_send_email($placeholders['guest_email'], $subject, $body, false);
}

function paguro_send_group_booking_confirmed_to_admin($group_id) {
    if (!function_exists('paguro_get_group_bookings')) return false;
    $bookings = paguro_get_group_bookings($group_id);
    if (!$bookings) return false;
    foreach ($bookings as $b) {
        if (intval($b->status) !== 1) {
            return false;
        }
    }
    $placeholders = paguro_get_group_email_placeholders($group_id);
    if (empty($placeholders)) return false;
    $placeholders = paguro_escape_user_placeholders($placeholders);
    $admin_email = get_option('admin_email');
    $subject = paguro_parse_template(
        get_option('paguro_msg_email_adm_wait_subj', 'Prenotazione Confermata: {apt_name}'),
        $placeholders
    );
    $template = get_option('paguro_msg_email_adm_wait_body',
        'Prenotazione confermata per {guest_name} - {apt_name} (€{total_cost})'
    );
    if (stripos($template, '{weeks_list}') === false) {
        $template .= '<br><br><strong>Settimane:</strong><br>{weeks_list}';
    }
    $body = paguro_parse_template($template, $placeholders);
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// GROUP: RECEIPT UPLOADED
// =========================================================

function paguro_send_group_receipt_received_to_user($group_id) {
    $placeholders = paguro_get_group_email_placeholders($group_id);
    if (empty($placeholders) || !is_email($placeholders['guest_email'] ?? '')) return false;
    $placeholders = paguro_escape_user_placeholders($placeholders);

    $subject = paguro_parse_template(
        get_option('paguro_txt_email_receipt_subj', 'Distinta Ricevuta - {apt_name}'),
        $placeholders
    );

    $template = get_option('paguro_txt_email_receipt_body',
        'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua distinta del bonifico per {apt_name}.<br>' .
        'Stiamo validando il pagamento. Ti contatteremo entro 24 ore.'
    );
    if (stripos($template, '{weeks_list}') === false) {
        $template .= '<br><br><strong>Settimane:</strong><br>{weeks_list}';
    }
    $body = paguro_parse_template($template, $placeholders);
    return paguro_send_email($placeholders['guest_email'], $subject, $body, false);
}

function paguro_send_group_receipt_received_to_admin($group_id) {
    $placeholders = paguro_get_group_email_placeholders($group_id);
    if (empty($placeholders)) return false;
    $placeholders = paguro_escape_user_placeholders($placeholders);

    $admin_email = get_option('admin_email');
    $placeholders['admin_link'] = admin_url('admin.php?page=paguro-booking&tab=bookings');
    $subject = paguro_parse_template(
        get_option('paguro_msg_email_adm_receipt_subj', 'Distinta Caricata: {apt_name}'),
        $placeholders
    );
    $template = get_option('paguro_msg_email_adm_receipt_body',
        'Distinta ricevuta da {guest_name} ({guest_email}) per {apt_name}.<br>' .
        'Apri il pannello admin per validare: <a href="{admin_link}">Vai alle prenotazioni</a>.'
    );
    if (stripos($template, '{weeks_list}') === false) {
        $template .= '<br><br><strong>Settimane:</strong><br>{weeks_list}';
    }
    $body = paguro_parse_template($template, $placeholders);
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// GROUP: QUOTE SUBMITTED
// =========================================================

function paguro_send_group_quote_request_to_user($group_id) {
    $placeholders = paguro_get_group_email_placeholders($group_id);
    if (empty($placeholders) || !is_email($placeholders['guest_email'] ?? '')) return false;
    $placeholders = paguro_escape_user_placeholders($placeholders);

    $subject = paguro_parse_template(
        get_option('paguro_txt_email_request_subj', 'Conferma Richiesta Preventivo: {apt_name}'),
        $placeholders
    );

    $template = get_option('paguro_txt_email_request_body',
        'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua richiesta per {apt_name}.<br>' .
        'Per procedere con la conferma e visualizzare i dati per il bonifico, accedi alla tua area riservata:<br>' .
        '<a href="{link_riepilogo}" class="button">VAI ALLA TUA PRENOTAZIONE</a>'
    );

    if (stripos($template, '{weeks_list}') === false) {
        $template .= '<br><br><strong>Settimane selezionate:</strong><br>{weeks_list}';
    }
    if (stripos($template, '{discount_amount') === false && ($placeholders['discount_amount'] ?? 0) > 0) {
        $template .= '<br>Sconto multi‑settimana: €{discount_amount_fmt}';
    }
    if (stripos($template, '{total_cost') === false) {
        $template .= '<br>Totale finale: €{total_cost_fmt}';
    }

    $body = paguro_parse_template($template, $placeholders);
    return paguro_send_email($placeholders['guest_email'], $subject, $body, false);
}

function paguro_send_group_quote_request_to_admin($group_id) {
    $placeholders = paguro_get_group_email_placeholders($group_id);
    if (empty($placeholders)) return false;
    $placeholders = paguro_escape_user_placeholders($placeholders);

    $admin_email = get_option('admin_email');
    $subject = paguro_parse_template(
        get_option('paguro_msg_email_adm_new_req_subj', 'Nuovo Preventivo: {apt_name}'),
        $placeholders
    );
    $template = get_option('paguro_msg_email_adm_new_req_body',
        'Nuovo preventivo richiesto da {guest_name} ({guest_email}) per {apt_name}.'
    );
    if (stripos($template, '{weeks_list}') === false) {
        $template .= '<br><br><strong>Settimane:</strong><br>{weeks_list}';
    }
    $body = paguro_parse_template($template, $placeholders);
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// 10. CANCEL CONFIRMED - USER
// =========================================================

function paguro_send_cancellation_to_user($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject_tpl = get_option('paguro_msg_email_cancel_subj', 'Cancellazione Confermata');
    if ($subject_tpl === '') {
        $subject_tpl = 'Cancellazione Confermata';
    }
    $subject = paguro_parse_template($subject_tpl, $placeholders);
    
    $body_tpl = get_option('paguro_msg_email_cancel_body',
        'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata cancellata.<br>' .
        'Se applicabile, l\'acconto sarà rimborsato secondo le nostre condizioni.'
    );
    if ($body_tpl === '') {
        $body_tpl = 'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata cancellata.<br>' .
            'Se applicabile, l\'acconto sarà rimborsato secondo le nostre condizioni.';
    }
    $body = paguro_parse_template($body_tpl, $placeholders);
    $body = paguro_append_gdpr_notice($body, $b);
    
    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 10b. REFUND SENT - USER
// =========================================================

function paguro_send_refund_sent_to_user($booking_id) {
    global $wpdb;

    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id
         WHERE b.id = %d",
        $booking_id
    ));

    if (!$b || !is_email($b->guest_email)) return false;

    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));

    $subject_tpl = get_option('paguro_msg_email_refund_subj', 'Rimborso disposto - {apt_name}');
    if ($subject_tpl === '') {
        $subject_tpl = 'Rimborso disposto - {apt_name}';
    }
    $subject = paguro_parse_template($subject_tpl, $placeholders);

    $body_tpl = get_option('paguro_msg_email_refund_body',
        'Gentile {guest_name},<br><br>' .
        'Il rimborso è stato disposto su questo IBAN:<br>' .
        '<strong>{customer_iban_priv}</strong><br><br>' .
        'I tempi di accredito dipendono dalla banca.<br><br>' .
        'Grazie per averci contattato.'
    );
    if ($body_tpl === '') {
        $body_tpl = 'Gentile {guest_name},<br><br>' .
            'Il rimborso è stato disposto su questo IBAN:<br>' .
            '<strong>{customer_iban_priv}</strong><br><br>' .
            'I tempi di accredito dipendono dalla banca.<br><br>' .
            'Grazie per averci contattato.';
    }
    $body = paguro_parse_template($body_tpl, $placeholders);
    $body = paguro_append_gdpr_notice($body, $b);

    return paguro_send_email($b->guest_email, $subject, $body, false);
}

// =========================================================
// 11. CANCEL CONFIRMED - ADMIN
// =========================================================

function paguro_send_cancellation_to_admin($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b) return false;
    $admin_email = get_option('admin_email');
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    
    $subject_tpl = get_option('paguro_msg_email_adm_cancel_subj', 'Cancellazione: {apt_name}');
    if ($subject_tpl === '') {
        $subject_tpl = 'Cancellazione: {apt_name}';
    }
    $subject = paguro_parse_template($subject_tpl, $placeholders);
    
    $body_tpl = get_option('paguro_msg_email_adm_cancel_body',
        'Cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end})'
    );
    if ($body_tpl === '') {
        $body_tpl = 'Cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end})';
    }
    $body = paguro_parse_template($body_tpl, $placeholders);
    
    return paguro_send_email($admin_email, $subject, $body, true);
}

// =========================================================
// 12. WAITLIST ALERT - USER
// =========================================================

function paguro_send_waitlist_availability_alert($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));

    $subject_tpl = get_option('paguro_txt_email_waitlist_alert_subj', 'Disponibilità {apt_name}');
    $body_tpl = get_option('paguro_txt_email_waitlist_alert_body',
        "Gentile {guest_name},<br><br>" .
        "Si è liberato {apt_name} per {date_start} - {date_end}.<br>" .
        "Se sei interessato puoi prenotare ora.<br><br>" .
        "<a href='{booking_url}' style='display:inline-block; background:#28a745; color:#fff; padding:12px 25px; text-decoration:none; border-radius:5px; font-weight:bold;'>PRENOTA ORA</a>"
    );

    return paguro_send_email(
        $b->guest_email,
        paguro_parse_template($subject_tpl, $placeholders),
        paguro_parse_template($body_tpl, $placeholders),
        false
    );
}

// =========================================================
// 11. WAITLIST SUBMITTED - USER
// =========================================================

function paguro_send_waitlist_confirmation_to_user($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b || !is_email($b->guest_email)) return false;
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    $page_slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
    $placeholders['link_riepilogo'] = site_url("/{$page_slug}/?token={$b->lock_token}&waitlist=1");

    $subject_tpl = get_option('paguro_txt_email_waitlist_subj', "Lista d'attesa {apt_name}");
    $body_tpl = get_option('paguro_txt_email_waitlist_body',
        "Gentile {guest_name},<br><br>" .
        "La tua richiesta per {apt_name} ({date_start} - {date_end}) è in lista d'attesa.<br>" .
        "Ti avviseremo appena si libera.<br><br>" .
        "<a href='{link_riepilogo}' style='display:inline-block; background:#0073aa; color:#fff; padding:12px 25px; text-decoration:none; border-radius:5px; font-weight:bold;'>APRI RIEPILOGO</a>"
    );

    return paguro_send_email(
        $b->guest_email,
        paguro_parse_template($subject_tpl, $placeholders),
        paguro_parse_template($body_tpl, $placeholders),
        false
    );
}

// =========================================================
// 12. WAITLIST SUBMITTED - ADMIN
// =========================================================

function paguro_send_waitlist_confirmation_to_admin($booking_id) {
    global $wpdb;
    
    $b = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b 
         JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id 
         WHERE b.id = %d",
        $booking_id
    ));
    
    if (!$b) return false;
    $admin_email = get_option('admin_email');
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));

    $subject_tpl = get_option('paguro_txt_email_waitlist_adm_subj', "Nuova lista d'attesa: {apt_name}");
    $body_tpl = get_option('paguro_txt_email_waitlist_adm_body', 'Nuova iscrizione: {guest_name} ({guest_email}) | {apt_name} {date_start} - {date_end}.');

    return paguro_send_email(
        $admin_email,
        paguro_parse_template($subject_tpl, $placeholders),
        paguro_parse_template($body_tpl, $placeholders),
        true
    );
}

?>
