<?php

/**
 * Paguro ChatBot - Admin Dashboard
 * Gestione prenotazioni, configurazione, email templates
 * 
 * @version 3.4.24
 */

if (!defined('ABSPATH')) exit;

global $wpdb;

// Verifica permessi
if (!current_user_can('manage_options')) {
    wp_die(__('Non autorizzato.', 'paguro'));
}

// Variabili globali
$base_url = admin_url('admin.php?page=paguro-booking');
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt = $wpdb->prefix . 'paguro_apartments';
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'bookings';

if (!function_exists('paguro_quote_sent_from_admin')) {
    function paguro_quote_sent_from_admin($booking)
    {
        if (!$booking || empty($booking->history_log)) return false;
        $history = json_decode($booking->history_log, true);
        if (!is_array($history)) return false;
        foreach ($history as $entry) {
            $action = isset($entry['action']) ? (string) $entry['action'] : '';
            $details = isset($entry['details']) ? strtolower((string) $entry['details']) : '';
            if ($action === 'QUOTE_REQ' || $action === 'GROUP_QUOTE_UPDATE') return true;
            if ($action === 'ADMIN_SEND_QUOTE') return true;
            if ($action === 'ADMIN_RESEND_EMAIL' && strpos($details, 'preventivo') !== false) return true;
        }
        return false;
    }
}

if (!function_exists('paguro_parse_money_input')) {
    function paguro_parse_money_input($value)
    {
        if ($value === null) return null;
        $raw = trim((string) $value);
        if ($raw === '') return null;
        $normalized = str_replace([' ', "\t", "\n", "\r"], '', $raw);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) return null;
        return max(0, round((float) $normalized, 2));
    }
}

if (!function_exists('paguro_finance_distribute_amount')) {
    function paguro_finance_distribute_amount($amount, $weights)
    {
        $amount = max(0, round((float) $amount, 2));
        if (!is_array($weights) || !$weights) return [];

        $ids = array_keys($weights);
        $count = count($ids);
        if ($count === 1) {
            return [(int) $ids[0] => $amount];
        }

        $sum_weights = 0.0;
        foreach ($ids as $id) {
            $w = max(0, (float) ($weights[$id] ?? 0));
            $sum_weights += $w;
        }
        if ($sum_weights <= 0) {
            $sum_weights = (float) $count;
            foreach ($ids as $id) {
                $weights[$id] = 1;
            }
        }

        $result = [];
        $remaining = $amount;
        for ($i = 0; $i < $count; $i++) {
            $id = (int) $ids[$i];
            if ($i === $count - 1) {
                $result[$id] = round(max(0, $remaining), 2);
                continue;
            }
            $share = $amount * (max(0, (float) $weights[$id]) / $sum_weights);
            $share = round(max(0, $share), 2);
            $result[$id] = $share;
            $remaining -= $share;
        }

        return $result;
    }
}

// paguro_get_booking_status_label_for_export() e paguro_get_booking_summary_url()
// sono definite in includes/functions-admin-export.php (sempre caricato).

if (!function_exists('paguro_get_admin_apartment_index')) {
    function paguro_get_admin_apartment_index($table_apt)
    {
        global $wpdb;

        $apt_rows = $wpdb->get_results("SELECT id, name, apt_url FROM {$table_apt} ORDER BY name ASC");
        $apt_map = [];
        foreach ($apt_rows as $apt_row) {
            $apt_map[intval($apt_row->id)] = [
                'name' => (string) $apt_row->name,
                'url' => (string) ($apt_row->apt_url ?? ''),
            ];
        }

        return $apt_map;
    }
}

if (!function_exists('paguro_get_group_summary_url')) {
    function paguro_get_group_summary_url($group_id)
    {
        $group_id = (string) $group_id;
        if ($group_id === '') return '';

        return site_url('/' . get_option('paguro_page_slug', 'riepilogo-prenotazione') . '/?token=' . rawurlencode($group_id));
    }
}

if (!function_exists('paguro_get_active_booking_print_report')) {
    function paguro_get_active_booking_print_report($table_avail, $apt_map)
    {
        global $wpdb;

        $bookings = $wpdb->get_results("SELECT * FROM {$table_avail} WHERE status <> 3 ORDER BY date_start ASC, id ASC");
        $summary = [
            'total' => count($bookings),
            'confirmed' => 0,
            'quotes' => 0,
            'waitlist' => 0,
            'cancel_requests' => 0,
            'pending_receipts' => 0,
        ];

        foreach ($bookings as $booking) {
            $status = intval($booking->status);
            if ($status === 1) $summary['confirmed']++;
            if ($status === 2) $summary['quotes']++;
            if ($status === 4) $summary['waitlist']++;
            if ($status === 5) $summary['cancel_requests']++;
            if ($status === 2 && !empty($booking->receipt_url)) $summary['pending_receipts']++;
        }

        $grouped = [];
        foreach ($bookings as $booking) {
            if (!empty($booking->group_id)) {
                if (!isset($grouped[$booking->group_id])) {
                    $grouped[$booking->group_id] = [];
                }
                $grouped[$booking->group_id][] = $booking;
            }
        }

        $rows = [];
        $rendered_groups = [];
        foreach ($bookings as $booking) {
            if (!empty($booking->group_id)) {
                if (isset($rendered_groups[$booking->group_id])) {
                    continue;
                }
                $rendered_groups[$booking->group_id] = true;
                $children = $grouped[$booking->group_id];
                $first = $children[0];
                $last = $children[count($children) - 1];
                $apt_info = $apt_map[intval($first->apartment_id)] ?? ['name' => '', 'url' => ''];
                $any_receipt = false;
                $all_confirmed = true;
                $any_confirmed = false;
                $any_pending_receipt = false;
                foreach ($children as $child) {
                    if (!empty($child->receipt_url)) $any_receipt = true;
                    if (intval($child->status) !== 1) $all_confirmed = false;
                    if (intval($child->status) === 1) $any_confirmed = true;
                    if (intval($child->status) === 2 && !empty($child->receipt_url)) $any_pending_receipt = true;
                }

                $status_label = 'Preventivo';
                if ($all_confirmed) {
                    $status_label = 'Confermata';
                } elseif ($any_pending_receipt) {
                    $status_label = 'Distinta (in validazione)';
                } elseif ($any_confirmed) {
                    $status_label = 'Parziale';
                }

                $rows[] = [
                    'id_label' => 'GRP-' . substr((string) $booking->group_id, 0, 8),
                    'guest' => (string) ($first->guest_name ?? ''),
                    'email' => (string) ($first->guest_email ?? ''),
                    'phone' => (string) ($first->guest_phone ?? ''),
                    'apartment' => (string) ($apt_info['name'] ?? ''),
                    'apartment_link' => paguro_get_apartment_public_url($first->apartment_id ?? 0, $apt_info['url'] ?? ''),
                    'date_start' => (string) ($first->date_start ?? ''),
                    'date_end' => (string) ($last->date_end ?? ''),
                    'status' => $status_label,
                    'receipt' => $any_receipt ? 'Si' : 'No',
                    'summary_url' => paguro_get_group_summary_url($booking->group_id),
                    'notes' => count($children) > 1 ? ('Gruppo di ' . count($children) . ' settimane') : 'Gruppo',
                ];
                continue;
            }

            $apt_info = $apt_map[intval($booking->apartment_id)] ?? ['name' => '', 'url' => ''];
            $rows[] = [
                'id_label' => (string) intval($booking->id),
                'guest' => (string) ($booking->guest_name ?? ''),
                'email' => (string) ($booking->guest_email ?? ''),
                'phone' => (string) ($booking->guest_phone ?? ''),
                'apartment' => (string) ($apt_info['name'] ?? ''),
                'apartment_link' => paguro_get_apartment_public_url($booking->apartment_id ?? 0, $apt_info['url'] ?? ''),
                'date_start' => (string) ($booking->date_start ?? ''),
                'date_end' => (string) ($booking->date_end ?? ''),
                'status' => paguro_get_booking_status_label_for_export($booking->status ?? 0, $booking->receipt_url ?? ''),
                'receipt' => !empty($booking->receipt_url) ? 'Si' : 'No',
                'summary_url' => paguro_get_booking_summary_url($booking),
                'notes' => '',
            ];
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('paguro_get_finance_print_report')) {
    function paguro_get_finance_print_report($table_avail, $apt_map)
    {
        global $wpdb;

        $season_start = get_option('paguro_season_start', '2026-06-01');
        $season_end = get_option('paguro_season_end', '2026-09-30');
        $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
        $finance_deposit_overrides = get_option('paguro_finance_deposit_overrides', []);
        if (!is_array($finance_deposit_overrides)) $finance_deposit_overrides = [];
        $finance_received_overrides = get_option('paguro_finance_received_overrides', []);
        if (!is_array($finance_received_overrides)) $finance_received_overrides = [];

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_avail}
             WHERE status = 1 AND date_end >= %s AND date_start <= %s
             ORDER BY date_start ASC, id ASC",
            $season_start,
            $season_end
        ));

        $compute_values = function ($booking, $total_cost) use ($deposit_percent, $finance_deposit_overrides, $finance_received_overrides) {
            $total_cost = max(0, round((float) $total_cost, 2));
            $booking_key = (string) intval($booking->id ?? 0);

            $deposit = $total_cost > 0 ? ceil($total_cost * ($deposit_percent / 100)) : 0;
            if (array_key_exists($booking_key, $finance_deposit_overrides)) {
                $deposit = max(0, round((float) $finance_deposit_overrides[$booking_key], 2));
            }
            if ($deposit > $total_cost) $deposit = $total_cost;

            $deposit_received = $deposit;
            if (array_key_exists($booking_key, $finance_received_overrides)) {
                $deposit_received = max(0, round((float) $finance_received_overrides[$booking_key], 2));
            }
            if ($deposit_received > $total_cost) $deposit_received = $total_cost;

            $remaining = max(0, $total_cost - $deposit_received);

            return [
                'total' => $total_cost,
                'deposit' => $deposit,
                'received' => $deposit_received,
                'remaining' => $remaining,
                'forecast' => $remaining,
                'refund' => 0,
            ];
        };

        $grouped = [];
        foreach ($bookings as $booking) {
            if (!empty($booking->group_id)) {
                if (!isset($grouped[$booking->group_id])) {
                    $grouped[$booking->group_id] = [];
                }
                $grouped[$booking->group_id][] = $booking;
            }
        }

        $summary = [
            'received' => 0,
            'remaining' => 0,
            'forecast' => 0,
            'refund' => 0,
        ];
        $rows = [];
        $rendered_groups = [];
        foreach ($bookings as $booking) {
            if (!empty($booking->group_id)) {
                if (isset($rendered_groups[$booking->group_id])) {
                    continue;
                }
                $rendered_groups[$booking->group_id] = true;
                $children = $grouped[$booking->group_id];
                $first = $children[0];
                $last = $children[count($children) - 1];
                $apt_info = $apt_map[intval($first->apartment_id)] ?? ['name' => '', 'url' => ''];
                $group_total = 0;
                $group_deposit = 0;
                $group_received = 0;
                $group_remaining = 0;
                $group_forecast = 0;
                $group_refund = 0;
                foreach ($children as $child) {
                    $total_cost = function_exists('paguro_get_booking_total_cost') ? paguro_get_booking_total_cost($child) : 0;
                    $values = $compute_values($child, $total_cost);
                    $group_total += $values['total'];
                    $group_deposit += $values['deposit'];
                    $group_received += $values['received'];
                    $group_remaining += $values['remaining'];
                    $group_forecast += $values['forecast'];
                    $group_refund += $values['refund'];
                }

                $summary['received'] += $group_received;
                $summary['remaining'] += $group_remaining;
                $summary['forecast'] += $group_forecast;
                $summary['refund'] += $group_refund;

                $rows[] = [
                    'id_label' => 'GRP-' . substr((string) $booking->group_id, 0, 8),
                    'guest' => (string) ($first->guest_name ?? ''),
                    'email' => (string) ($first->guest_email ?? ''),
                    'apartment' => (string) ($apt_info['name'] ?? ''),
                    'date_start' => (string) ($first->date_start ?? ''),
                    'date_end' => (string) ($last->date_end ?? ''),
                    'total' => $group_total,
                    'deposit' => $group_deposit,
                    'received' => $group_received,
                    'remaining' => $group_remaining,
                    'summary_url' => paguro_get_group_summary_url($booking->group_id),
                ];
                continue;
            }

            $apt_info = $apt_map[intval($booking->apartment_id)] ?? ['name' => '', 'url' => ''];
            $total_cost = function_exists('paguro_get_booking_total_cost') ? paguro_get_booking_total_cost($booking) : 0;
            $values = $compute_values($booking, $total_cost);
            $summary['received'] += $values['received'];
            $summary['remaining'] += $values['remaining'];
            $summary['forecast'] += $values['forecast'];
            $summary['refund'] += $values['refund'];

            $rows[] = [
                'id_label' => (string) intval($booking->id),
                'guest' => (string) ($booking->guest_name ?? ''),
                'email' => (string) ($booking->guest_email ?? ''),
                'apartment' => (string) ($apt_info['name'] ?? ''),
                'date_start' => (string) ($booking->date_start ?? ''),
                'date_end' => (string) ($booking->date_end ?? ''),
                'total' => $values['total'],
                'deposit' => $values['deposit'],
                'received' => $values['received'],
                'remaining' => $values['remaining'],
                'summary_url' => paguro_get_booking_summary_url($booking),
            ];
        }

        return [
            'season_start' => $season_start,
            'season_end' => $season_end,
            'summary' => $summary,
            'rows' => $rows,
        ];
    }
}

// ====== SALVATAGGIO OPZIONI ======
if (isset($_POST['paguro_save_opts']) && check_admin_referer('paguro_admin_opts')) {
    update_option('paguro_season_start', sanitize_text_field(wp_unslash($_POST['season_start'] ?? '')));
    update_option('paguro_season_end', sanitize_text_field(wp_unslash($_POST['season_end'] ?? '')));
    update_option('paguro_deposit_percent', intval(wp_unslash($_POST['deposit_percent'] ?? 0)));
    update_option('paguro_group_discount_map', sanitize_text_field(wp_unslash($_POST['group_discount_map'] ?? '')));
    update_option('paguro_bank_iban', sanitize_text_field(wp_unslash($_POST['bank_iban'] ?? '')));
    update_option('paguro_bank_owner', sanitize_text_field(wp_unslash($_POST['bank_owner'] ?? '')));
    update_option('paguro_api_url', esc_url_raw(wp_unslash($_POST['paguro_api_url'] ?? '')));
    update_option('paguro_recaptcha_site', sanitize_text_field(wp_unslash($_POST['recaptcha_site'] ?? '')));
    update_option('paguro_recaptcha_secret', sanitize_text_field(wp_unslash($_POST['recaptcha_secret'] ?? '')));
    update_option('paguro_ai_provider', sanitize_text_field(wp_unslash($_POST['ai_provider'] ?? 'api')));
    update_option('paguro_ollama_url', esc_url_raw(wp_unslash($_POST['ollama_url'] ?? '')));
    update_option('paguro_ollama_model', sanitize_text_field(wp_unslash($_POST['ollama_model'] ?? '')));
    update_option('paguro_friend_structure_url', esc_url_raw(wp_unslash($_POST['friend_structure_url'] ?? '')));

    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Configurazione Salvata.</p></div>';
}

// ====== GESTIONE EMAIL TEMPLATES ======
if (isset($_POST['paguro_save_emails']) && check_admin_referer('paguro_email_opts')) {
    update_option('paguro_txt_email_request_subj', sanitize_text_field(wp_unslash($_POST['email_req_subj'] ?? '')));
    update_option('paguro_txt_email_request_body', wp_kses_post(wp_unslash($_POST['email_req_body'] ?? '')));
    update_option('paguro_txt_email_receipt_subj', sanitize_text_field(wp_unslash($_POST['email_rec_subj'] ?? '')));
    update_option('paguro_txt_email_receipt_body', wp_kses_post(wp_unslash($_POST['email_rec_body'] ?? '')));
    update_option('paguro_txt_email_confirm_subj', sanitize_text_field(wp_unslash($_POST['email_conf_subj'] ?? '')));
    update_option('paguro_txt_email_confirm_body', wp_kses_post(wp_unslash($_POST['email_conf_body'] ?? '')));
    update_option('paguro_txt_email_cancel_req_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_req_subj'] ?? '')));
    update_option('paguro_txt_email_cancel_req_body', wp_kses_post(wp_unslash($_POST['email_cancel_req_body'] ?? '')));
    update_option('paguro_txt_email_cancel_req_adm_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_req_adm_subj'] ?? '')));
    update_option('paguro_txt_email_cancel_req_adm_body', wp_kses_post(wp_unslash($_POST['email_cancel_req_adm_body'] ?? '')));
    update_option('paguro_msg_email_cancel_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_subj'] ?? '')));
    update_option('paguro_msg_email_cancel_body', wp_kses_post(wp_unslash($_POST['email_cancel_body'] ?? '')));
    update_option('paguro_msg_email_adm_cancel_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_adm_subj'] ?? '')));
    update_option('paguro_msg_email_adm_cancel_body', wp_kses_post(wp_unslash($_POST['email_cancel_adm_body'] ?? '')));
    update_option('paguro_msg_email_refund_subj', sanitize_text_field(wp_unslash($_POST['email_refund_subj'] ?? '')));
    update_option('paguro_msg_email_refund_body', wp_kses_post(wp_unslash($_POST['email_refund_body'] ?? '')));
    update_option('paguro_txt_email_waitlist_subj', sanitize_text_field(wp_unslash($_POST['email_waitlist_subj'] ?? '')));
    update_option('paguro_txt_email_waitlist_body', wp_kses_post(wp_unslash($_POST['email_waitlist_body'] ?? '')));
    update_option('paguro_txt_email_waitlist_adm_subj', sanitize_text_field(wp_unslash($_POST['email_waitlist_adm_subj'] ?? '')));
    update_option('paguro_txt_email_waitlist_adm_body', wp_kses_post(wp_unslash($_POST['email_waitlist_adm_body'] ?? '')));
    update_option('paguro_txt_email_waitlist_alert_subj', sanitize_text_field(wp_unslash($_POST['email_waitlist_alert_subj'] ?? '')));
    update_option('paguro_txt_email_waitlist_alert_body', wp_kses_post(wp_unslash($_POST['email_waitlist_alert_body'] ?? '')));

    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Email Templates Salvati.</p></div>';
}

// ====== GESTIONE WEB TEMPLATES ======
if (isset($_POST['paguro_save_web_templates']) && check_admin_referer('paguro_web_templates')) {
    update_option('paguro_msg_ui_social_pressure', wp_kses_post(wp_unslash($_POST['ui_social_pressure'] ?? '')));
    update_option('paguro_msg_ui_privacy_notice', wp_kses_post(wp_unslash($_POST['ui_privacy_notice'] ?? '')));
    update_option('paguro_msg_ui_upload_instruction', sanitize_text_field(wp_unslash($_POST['ui_upload_instruction'] ?? '')));
    update_option('paguro_msg_ui_summary_page', wp_kses_post(wp_unslash($_POST['ui_summary_page'] ?? '')));
    update_option('paguro_msg_ui_summary_confirm_page', wp_kses_post(wp_unslash($_POST['ui_summary_confirm_page'] ?? '')));
    update_option('paguro_msg_ui_login_page', wp_kses_post(wp_unslash($_POST['ui_login_page'] ?? '')));
    update_option('paguro_js_btn_book', sanitize_text_field(wp_unslash($_POST['ui_btn_book'] ?? '')));
    update_option('paguro_msg_ui_summary_title', sanitize_text_field(wp_unslash($_POST['ui_summary_title'] ?? '')));
    update_option('paguro_msg_ui_waitlist_title', sanitize_text_field(wp_unslash($_POST['ui_waitlist_title'] ?? '')));
    update_option('paguro_msg_ui_quote_title', sanitize_text_field(wp_unslash($_POST['ui_quote_title'] ?? '')));
    update_option('paguro_msg_ui_quote_subtitle', sanitize_text_field(wp_unslash($_POST['ui_quote_subtitle'] ?? '')));
    update_option('paguro_msg_ui_quote_name_help', sanitize_text_field(wp_unslash($_POST['ui_quote_name_help'] ?? '')));
    update_option('paguro_msg_ui_missing_token', sanitize_text_field(wp_unslash($_POST['ui_missing_token'] ?? '')));
    update_option('paguro_msg_ui_auth_title', sanitize_text_field(wp_unslash($_POST['ui_auth_title'] ?? '')));
    update_option('paguro_msg_ui_auth_help_quote', sanitize_textarea_field(wp_unslash($_POST['ui_auth_help_quote'] ?? '')));
    update_option('paguro_msg_ui_auth_help_waitlist', sanitize_textarea_field(wp_unslash($_POST['ui_auth_help_waitlist'] ?? '')));
    update_option('paguro_msg_ui_auth_email_label', sanitize_text_field(wp_unslash($_POST['ui_auth_email_label'] ?? '')));
    update_option('paguro_msg_ui_auth_submit', sanitize_text_field(wp_unslash($_POST['ui_auth_submit'] ?? '')));
    update_option('paguro_msg_ui_auth_error', sanitize_text_field(wp_unslash($_POST['ui_auth_error'] ?? '')));
    update_option('paguro_msg_ui_booking_not_found', sanitize_text_field(wp_unslash($_POST['ui_booking_not_found'] ?? '')));
    update_option('paguro_msg_ui_waitlist_not_found', sanitize_text_field(wp_unslash($_POST['ui_waitlist_not_found'] ?? '')));
    update_option('paguro_msg_ui_cancel_requested', sanitize_text_field(wp_unslash($_POST['ui_cancel_requested'] ?? '')));
    update_option('paguro_msg_ui_cancel_pending', sanitize_text_field(wp_unslash($_POST['ui_cancel_pending'] ?? '')));
    update_option('paguro_msg_ui_cancel_iban_required', sanitize_text_field(wp_unslash($_POST['ui_cancel_iban_required'] ?? '')));
    update_option('paguro_msg_ui_cancel_iban_invalid', sanitize_text_field(wp_unslash($_POST['ui_cancel_iban_invalid'] ?? '')));
    update_option('paguro_msg_ui_cancel_denied', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_denied'] ?? '')));
    update_option('paguro_msg_ui_waitlist_cancelled', sanitize_text_field(wp_unslash($_POST['ui_waitlist_cancelled'] ?? '')));
    update_option('paguro_msg_ui_waitlist_title_text', sanitize_text_field(wp_unslash($_POST['ui_waitlist_title_text'] ?? '')));
    update_option('paguro_msg_ui_waitlist_inline_notice', sanitize_textarea_field(wp_unslash($_POST['ui_waitlist_inline_notice'] ?? '')));
    update_option('paguro_msg_ui_waitlist_page_notice', sanitize_textarea_field(wp_unslash($_POST['ui_waitlist_page_notice'] ?? '')));
    update_option('paguro_msg_ui_waitlist_inline_dates_label', sanitize_text_field(wp_unslash($_POST['ui_waitlist_inline_dates_label'] ?? '')));
    update_option('paguro_msg_ui_section_booking', sanitize_text_field(wp_unslash($_POST['ui_section_booking'] ?? '')));
    update_option('paguro_msg_ui_section_pricing', sanitize_text_field(wp_unslash($_POST['ui_section_pricing'] ?? '')));
    update_option('paguro_msg_ui_section_payment', sanitize_text_field(wp_unslash($_POST['ui_section_payment'] ?? '')));
    update_option('paguro_msg_ui_section_upload', sanitize_text_field(wp_unslash($_POST['ui_section_upload'] ?? '')));
    update_option('paguro_msg_ui_section_uploaded', sanitize_text_field(wp_unslash($_POST['ui_section_uploaded'] ?? '')));
    update_option('paguro_msg_ui_summary_email_notice', sanitize_textarea_field(wp_unslash($_POST['ui_summary_email_notice'] ?? '')));
    update_option('paguro_msg_ui_secure_area_cta', sanitize_text_field(wp_unslash($_POST['ui_secure_area_cta'] ?? '')));
    update_option('paguro_msg_ui_secure_area_intro', sanitize_textarea_field(wp_unslash($_POST['ui_secure_area_intro'] ?? '')));
    update_option('paguro_msg_ui_section_actions', sanitize_text_field(wp_unslash($_POST['ui_section_actions'] ?? '')));
    update_option('paguro_msg_ui_group_actions_ready', sanitize_text_field(wp_unslash($_POST['ui_group_actions_ready'] ?? '')));
    update_option('paguro_msg_ui_group_actions_locked', sanitize_textarea_field(wp_unslash($_POST['ui_group_actions_locked'] ?? '')));
    update_option('paguro_msg_ui_label_guest', sanitize_text_field(wp_unslash($_POST['ui_label_guest'] ?? '')));
    update_option('paguro_msg_ui_label_name', sanitize_text_field(wp_unslash($_POST['ui_label_name'] ?? '')));
    update_option('paguro_msg_ui_label_email', sanitize_text_field(wp_unslash($_POST['ui_label_email'] ?? '')));
    update_option('paguro_msg_ui_label_phone', sanitize_text_field(wp_unslash($_POST['ui_label_phone'] ?? '')));
    update_option('paguro_msg_ui_label_apartment', sanitize_text_field(wp_unslash($_POST['ui_label_apartment'] ?? '')));
    update_option('paguro_msg_ui_label_checkin', sanitize_text_field(wp_unslash($_POST['ui_label_checkin'] ?? '')));
    update_option('paguro_msg_ui_label_checkout', sanitize_text_field(wp_unslash($_POST['ui_label_checkout'] ?? '')));
    update_option('paguro_msg_ui_label_check_suffix', sanitize_text_field(wp_unslash($_POST['ui_label_check_suffix'] ?? '')));
    update_option('paguro_msg_ui_label_total_cost', sanitize_text_field(wp_unslash($_POST['ui_label_total_cost'] ?? '')));
    update_option('paguro_msg_ui_label_deposit', sanitize_text_field(wp_unslash($_POST['ui_label_deposit'] ?? '')));
    update_option('paguro_msg_ui_label_remaining', sanitize_text_field(wp_unslash($_POST['ui_label_remaining'] ?? '')));
    update_option('paguro_msg_ui_label_owner', sanitize_text_field(wp_unslash($_POST['ui_label_owner'] ?? '')));
    update_option('paguro_msg_ui_label_iban', sanitize_text_field(wp_unslash($_POST['ui_label_iban'] ?? '')));
    update_option('paguro_msg_ui_label_amount', sanitize_text_field(wp_unslash($_POST['ui_label_amount'] ?? '')));
    update_option('paguro_msg_ui_upload_drop', sanitize_text_field(wp_unslash($_POST['ui_upload_drop'] ?? '')));
    update_option('paguro_msg_ui_upload_success_title', sanitize_text_field(wp_unslash($_POST['ui_upload_success_title'] ?? '')));
    update_option('paguro_msg_ui_upload_success_text', sanitize_textarea_field(wp_unslash($_POST['ui_upload_success_text'] ?? '')));
    update_option('paguro_msg_ui_receipt_pending', sanitize_text_field(wp_unslash($_POST['ui_receipt_pending'] ?? '')));
    update_option('paguro_msg_ui_receipt_uploaded_text', sanitize_textarea_field(wp_unslash($_POST['ui_receipt_uploaded_text'] ?? '')));
    update_option('paguro_msg_ui_receipt_view_cta', sanitize_text_field(wp_unslash($_POST['ui_receipt_view_cta'] ?? '')));
    update_option('paguro_msg_ui_cancel_label', sanitize_text_field(wp_unslash($_POST['ui_cancel_label'] ?? '')));
    update_option('paguro_msg_ui_cancel_placeholder', sanitize_text_field(wp_unslash($_POST['ui_cancel_placeholder'] ?? '')));
    update_option('paguro_msg_ui_cancel_help', wp_kses_post(wp_unslash($_POST['ui_cancel_help'] ?? '')));
    update_option('paguro_msg_ui_cancel_confirm', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_confirm'] ?? '')));
    update_option('paguro_msg_ui_cancel_cta', sanitize_text_field(wp_unslash($_POST['ui_cancel_cta'] ?? '')));
    update_option('paguro_msg_ui_group_week_confirm', sanitize_textarea_field(wp_unslash($_POST['ui_group_week_confirm'] ?? '')));
    update_option('paguro_msg_ui_cancel_deadline_note', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_deadline_note'] ?? '')));
    update_option('paguro_msg_ui_cancel_unavailable', wp_kses_post(wp_unslash($_POST['ui_cancel_unavailable'] ?? '')));
    update_option('paguro_msg_ui_cancel_requested_notice', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_requested_notice'] ?? '')));
    update_option('paguro_msg_ui_waitlist_section_info', sanitize_text_field(wp_unslash($_POST['ui_waitlist_section_info'] ?? '')));
    update_option('paguro_msg_ui_waitlist_section_dates', sanitize_text_field(wp_unslash($_POST['ui_waitlist_section_dates'] ?? '')));
    update_option('paguro_msg_ui_waitlist_section_history', sanitize_text_field(wp_unslash($_POST['ui_waitlist_section_history'] ?? '')));
    update_option('paguro_msg_ui_waitlist_exit_cta', sanitize_text_field(wp_unslash($_POST['ui_waitlist_exit_cta'] ?? '')));
    update_option('paguro_msg_ui_waitlist_exit_confirm', sanitize_textarea_field(wp_unslash($_POST['ui_waitlist_exit_confirm'] ?? '')));

    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Web Templates Salvati.</p></div>';
}

// ====== GESTIONE IA RULES ======
if (isset($_POST['paguro_save_ai_rules']) && check_admin_referer('paguro_ai_rules')) {
    $keywords = isset($_POST['ai_rule_keywords']) && is_array($_POST['ai_rule_keywords']) ? $_POST['ai_rule_keywords'] : [];
    $responses = isset($_POST['ai_rule_response']) && is_array($_POST['ai_rule_response']) ? $_POST['ai_rule_response'] : [];
    $rules = [];
    $count = max(count($keywords), count($responses));
    for ($i = 0; $i < $count; $i++) {
        $kw = sanitize_text_field(wp_unslash($keywords[$i] ?? ''));
        $resp = wp_kses_post(wp_unslash($responses[$i] ?? ''));
        if ($kw === '' || $resp === '') continue;
        $rules[] = [
            'keywords' => $kw,
            'response' => $resp
        ];
    }
    update_option('paguro_ai_rules', $rules);
    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Regole IA Salvate.</p></div>';
}

// ====== GESTIONE APPARTAMENTI ======
if (isset($_POST['paguro_apt_action']) && check_admin_referer('paguro_apt_nonce')) {
    if ($_POST['paguro_apt_action'] === 'add_apt') {
        $name = sanitize_text_field(wp_unslash($_POST['apt_name'] ?? ''));
        $apt_url = esc_url_raw(wp_unslash($_POST['apt_url'] ?? ''));
        $price = intval(wp_unslash($_POST['apt_price'] ?? 0));
        if ($price < 0) $price = 0;
        if ($price > 99999) $price = 99999;
        if ($name && $price > 0) {
            $wpdb->insert($table_apt, [
                'name' => $name,
                'apt_url' => $apt_url,
                'base_price' => $price,
                'pricing_json' => json_encode([])
            ]);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Appartamento aggiunto.</p></div>';
        }
    }
    if ($_POST['paguro_apt_action'] === 'update_apt') {
        $id = intval($_POST['apt_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['apt_name'] ?? ''));
        $apt_url = esc_url_raw(wp_unslash($_POST['apt_url'] ?? ''));
        $price = intval(wp_unslash($_POST['apt_price'] ?? 0));
        if ($price < 0) $price = 0;
        if ($price > 99999) $price = 99999;
        $pricing_week = isset($_POST['pricing_week']) && is_array($_POST['pricing_week']) ? $_POST['pricing_week'] : [];
        $pricing_season = isset($_POST['pricing_season']) && is_array($_POST['pricing_season']) ? $_POST['pricing_season'] : [];
        $pricing_season_choice = isset($_POST['pricing_season_choice']) && is_array($_POST['pricing_season_choice']) ? $_POST['pricing_season_choice'] : [];
        $pricing = [];
        $week_keys = array_unique(array_merge(array_keys($pricing_week), array_keys($pricing_season)));
        foreach ($week_keys as $k) {
            $key = sanitize_text_field(wp_unslash($k));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) continue;
            $val_raw = $pricing_week[$k] ?? '';
            $val = intval(wp_unslash($val_raw));
            if ($val < 0) $val = 0;
            if ($val > 99999) $val = 99999;
            $season_raw = sanitize_text_field(wp_unslash($pricing_season[$k] ?? ''));
            if ($season_raw === '' && isset($pricing_season_choice[$k])) {
                $choices = $pricing_season_choice[$k];
                if (!is_array($choices)) $choices = [$choices];
                $priority = ['A', 'M', 'B'];
                foreach ($priority as $opt) {
                    if (in_array($opt, $choices, true)) {
                        $season_raw = $opt;
                        break;
                    }
                }
                if ($season_raw === '' && !empty($choices)) {
                    $season_raw = sanitize_text_field(wp_unslash($choices[0]));
                }
            }
            $season = function_exists('paguro_normalize_season_code') ? paguro_normalize_season_code($season_raw) : strtoupper($season_raw);
            $has_price = ($val > 0);
            $has_season = ($season !== '');
            if ($has_season) {
                $pricing[$key] = [
                    'price' => $has_price ? $val : null,
                    'season' => $season
                ];
            } elseif ($has_price) {
                $pricing[$key] = $val;
            }
        }
        if ($id > 0 && $name && $price > 0) {
            $wpdb->update($table_apt, [
                'name' => $name,
                'apt_url' => $apt_url,
                'base_price' => $price,
                'pricing_json' => json_encode($pricing)
            ], ['id' => $id]);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Appartamento aggiornato.</p></div>';
        }
    }
    if ($_POST['paguro_apt_action'] === 'delete_apt') {
        $id = intval($_POST['apt_id']);
        $wpdb->delete($table_apt, ['id' => $id]);
        echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Appartamento eliminato.</p></div>';
    }
}

// ====== AZIONI SULLE PRENOTAZIONI ======
if (isset($_POST['paguro_action']) && check_admin_referer('paguro_admin_action')) {
    $req_id = intval($_POST['booking_id']);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id=%d", $req_id));

    if (!$booking) {
        echo '<div class="notice notice-error"><p>Prenotazione non trovata.</p></div>';
    } else {
        $apt = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_apt WHERE id=%d", $booking->apartment_id));

        if ($_POST['paguro_action'] === 'update_booking') {
            $guest_name = sanitize_text_field(wp_unslash($_POST['guest_name'] ?? ''));
            $guest_email = sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));
            $guest_phone = sanitize_text_field(wp_unslash($_POST['guest_phone'] ?? ''));
            $guest_notes = sanitize_textarea_field(wp_unslash($_POST['guest_notes'] ?? ''));
            $manual_price_raw = wp_unslash($_POST['manual_price'] ?? '');
            $manual_price = null;
            if ($manual_price_raw !== '') {
                $manual_price = floatval($manual_price_raw);
                if ($manual_price < 0) $manual_price = 0;
            }
            $manual_discount_raw = wp_unslash($_POST['manual_discount'] ?? '');
            $manual_discount = null;
            if ($manual_discount_raw !== '') {
                $manual_discount = floatval($manual_discount_raw);
                if ($manual_discount < 0) $manual_discount = 0;
            }

            // Gestione date (opzionale)
            $date_start = sanitize_text_field(wp_unslash($_POST['date_start'] ?? ''));
            $date_end = sanitize_text_field(wp_unslash($_POST['date_end'] ?? ''));
            $update_data = [
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
                'guest_phone' => $guest_phone,
                'guest_notes' => $guest_notes,
                'manual_price' => ($manual_price !== null && $manual_price > 0) ? $manual_price : null,
                'manual_discount' => ($manual_discount !== null && $manual_discount > 0) ? $manual_discount : null
            ];

            // Aggiungi date al data da aggiornare se presenti
            if (!empty($date_start) && !empty($date_end)) {
                // Valida che le date siano valide
                $start_obj = DateTime::createFromFormat('Y-m-d', $date_start);
                $end_obj = DateTime::createFromFormat('Y-m-d', $date_end);

                if ($start_obj && $end_obj && $start_obj < $end_obj) {
                    $update_data['date_start'] = $date_start;
                    $update_data['date_end'] = $date_end;
                }
            }

            $updated = $wpdb->update($table_avail, $update_data, ['id' => $req_id]);

            if ($updated !== false) {
                paguro_add_history($req_id, 'ADMIN_EDIT', 'Dati prenotazione aggiornati da amministratore');
                echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione aggiornata.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Errore durante l\'aggiornamento della prenotazione.</p></div>';
            }
        }

        if ($_POST['paguro_action'] === 'update_finance_values') {
            $total_input = paguro_parse_money_input(wp_unslash($_POST['finance_total'] ?? ''));
            $deposit_input = paguro_parse_money_input(wp_unslash($_POST['finance_deposit'] ?? ''));
            $received_input = paguro_parse_money_input(wp_unslash($_POST['finance_received'] ?? ''));
            $remaining_input = paguro_parse_money_input(wp_unslash($_POST['finance_remaining'] ?? ''));
            $edited_field = sanitize_key(wp_unslash($_POST['edited_field'] ?? ''));
            $group_id_input = sanitize_text_field(wp_unslash($_POST['group_id'] ?? ''));
            $booking_ids_raw = sanitize_text_field(wp_unslash($_POST['booking_ids'] ?? ''));

            $booking_ids = [];
            if ($booking_ids_raw !== '') {
                foreach (explode(',', $booking_ids_raw) as $id_piece) {
                    $id = intval(trim($id_piece));
                    if ($id > 0) {
                        $booking_ids[$id] = $id;
                    }
                }
            }
            if (!$booking_ids && $req_id > 0) {
                $booking_ids[$req_id] = $req_id;
            }
            $booking_ids = array_values($booking_ids);

            if (
                !$booking_ids ||
                $total_input === null ||
                $deposit_input === null ||
                $received_input === null ||
                $remaining_input === null
            ) {
                echo '<div class="notice notice-error"><p>Dati economici non validi.</p></div>';
            } else {
                $placeholders = implode(',', array_fill(0, count($booking_ids), '%d'));
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM $table_avail WHERE id IN ($placeholders)", ...$booking_ids)
                );
                if (!$rows) {
                    echo '<div class="notice notice-error"><p>Prenotazioni non trovate.</p></div>';
                } else {
                    $rows_by_id = [];
                    foreach ($rows as $row_item) {
                        $rows_by_id[intval($row_item->id)] = $row_item;
                    }
                    $booking_ids = array_values(array_intersect($booking_ids, array_keys($rows_by_id)));

                    if (!$booking_ids) {
                        echo '<div class="notice notice-error"><p>Prenotazioni non trovate.</p></div>';
                    } elseif ($group_id_input !== '') {
                        $group_ok = true;
                        foreach ($booking_ids as $check_id) {
                            $row_group = (string) ($rows_by_id[$check_id]->group_id ?? '');
                            if ($row_group !== $group_id_input) {
                                $group_ok = false;
                                break;
                            }
                        }
                        if (!$group_ok) {
                            echo '<div class="notice notice-error"><p>Gruppo non coerente.</p></div>';
                            $booking_ids = [];
                        }
                    }

                    if ($booking_ids) {
                        $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
                        $total = max(0, round((float) $total_input, 2));
                        $deposit = max(0, round((float) $deposit_input, 2));
                        $received = max(0, round((float) $received_input, 2));
                        $remaining = max(0, round((float) $remaining_input, 2));

                        if ($total < $deposit) $deposit = $total;
                        if ($total < $received) $received = $total;
                        if ($total < $remaining) $remaining = $total;

                        if ($edited_field === 'total') {
                            $deposit = $total > 0 ? ceil($total * ($deposit_percent / 100)) : 0;
                            if ($received > $total) $received = $total;
                        } elseif ($edited_field === 'deposit') {
                            $deposit = min($deposit, $total);
                        } elseif ($edited_field === 'received') {
                            $received = min($received, $total);
                        } elseif ($edited_field === 'remaining') {
                            $remaining = min($remaining, $total);
                            $received = max(0, $total - $remaining);
                        }

                        $remaining = max(0, $total - $received);

                        $totals_by_id = [];
                        $deposits_by_id = [];
                        $received_by_id = [];

                        if (count($booking_ids) === 1) {
                            $single_id = (int) $booking_ids[0];
                            $totals_by_id[$single_id] = $total;
                            $deposits_by_id[$single_id] = $deposit;
                            $received_by_id[$single_id] = $received;
                        } else {
                            $weights_total = [];
                            foreach ($booking_ids as $bid) {
                                $row_item = $rows_by_id[$bid];
                                $current_total = function_exists('paguro_get_booking_total_cost')
                                    ? floatval(paguro_get_booking_total_cost($row_item))
                                    : floatval($row_item->manual_price);
                                $weights_total[$bid] = max(0, $current_total);
                            }
                            $totals_by_id = paguro_finance_distribute_amount($total, $weights_total);

                            $weights_deposit = [];
                            foreach ($booking_ids as $bid) {
                                $weights_deposit[$bid] = max(0, (float) ($totals_by_id[$bid] ?? 0));
                            }
                            $deposits_by_id = paguro_finance_distribute_amount($deposit, $weights_deposit);

                            $weights_received = [];
                            foreach ($booking_ids as $bid) {
                                $weights_received[$bid] = max(0, (float) ($deposits_by_id[$bid] ?? 0));
                            }
                            $received_by_id = paguro_finance_distribute_amount($received, $weights_received);
                        }

                        $deposit_overrides = get_option('paguro_finance_deposit_overrides', []);
                        if (!is_array($deposit_overrides)) $deposit_overrides = [];
                        $received_overrides = get_option('paguro_finance_received_overrides', []);
                        if (!is_array($received_overrides)) $received_overrides = [];

                        foreach ($booking_ids as $bid) {
                            $row_total = max(0, round((float) ($totals_by_id[$bid] ?? 0), 2));
                            $row_deposit = max(0, round((float) ($deposits_by_id[$bid] ?? 0), 2));
                            $row_received = max(0, round((float) ($received_by_id[$bid] ?? 0), 2));
                            if ($row_deposit > $row_total) $row_deposit = $row_total;
                            if ($row_received > $row_total) $row_received = $row_total;

                            $wpdb->update(
                                $table_avail,
                                ['manual_price' => $row_total > 0 ? $row_total : null],
                                ['id' => $bid]
                            );

                            $default_deposit = $row_total > 0 ? ceil($row_total * ($deposit_percent / 100)) : 0;
                            $dep_key = (string) $bid;
                            if (abs($row_deposit - $default_deposit) < 0.01) {
                                unset($deposit_overrides[$dep_key]);
                            } else {
                                $deposit_overrides[$dep_key] = $row_deposit;
                            }

                            $row_obj = $rows_by_id[$bid];
                            $default_received = (intval($row_obj->status) === 1 || !empty($row_obj->receipt_url)) ? $row_deposit : 0;
                            $rec_key = (string) $bid;
                            if (abs($row_received - $default_received) < 0.01) {
                                unset($received_overrides[$rec_key]);
                            } else {
                                $received_overrides[$rec_key] = $row_received;
                            }

                            paguro_add_history($bid, 'ADMIN_FINANCE_EDIT', 'Importi economici aggiornati da amministratore');
                        }

                        update_option('paguro_finance_deposit_overrides', $deposit_overrides);
                        update_option('paguro_finance_received_overrides', $received_overrides);
                        echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Importi economici aggiornati.</p></div>';
                    }
                }
            }
        }

        if ($_POST['paguro_action'] === 'send_quote') {
            if (intval($booking->status) !== 2 || !empty($booking->receipt_url)) {
                echo '<div class="notice notice-error"><p>Impossibile inviare il preventivo per questo stato.</p></div>';
            } else {
                $sent_user = function_exists('paguro_send_quote_request_to_user')
                    ? paguro_send_quote_request_to_user($req_id)
                    : false;
                $sent_admin = function_exists('paguro_send_quote_request_to_admin')
                    ? paguro_send_quote_request_to_admin($req_id)
                    : false;

                if ($sent_user) {
                    paguro_add_history($req_id, 'ADMIN_SEND_QUOTE', 'Preventivo inviato da amministratore');
                    if ($sent_admin) {
                        paguro_add_history($req_id, 'ADMIN_SEND_QUOTE_ADMIN', 'Notifica preventivo inviata ad admin');
                    }
                    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Preventivo inviato.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Invio preventivo fallito. Verifica email guest.</p></div>';
                }
            }
        }

        if ($_POST['paguro_action'] === 'extend_expiry') {
            $days_raw = sanitize_text_field(wp_unslash($_POST['extend_days'] ?? ''));
            $days = intval($days_raw);

            if (!preg_match('/^\d{1,2}$/', $days_raw) || $days < 1 || $days > 99) {
                echo '<div class="notice notice-error"><p>Numero giorni non valido. Inserisci un valore tra 1 e 99.</p></div>';
            } elseif (intval($booking->status) !== 2) {
                echo '<div class="notice notice-error"><p>Azione disponibile solo per prenotazioni in stato Preventivo.</p></div>';
            } else {
                $base_ts = current_time('timestamp');
                if (!empty($booking->lock_expires)) {
                    $lock_ts = strtotime((string) $booking->lock_expires);
                    if ($lock_ts !== false && $lock_ts > $base_ts) {
                        $base_ts = $lock_ts;
                    }
                }

                $new_lock_expires = date('Y-m-d H:i:s', $base_ts + ($days * DAY_IN_SECONDS));
                $updated = $wpdb->update($table_avail, ['lock_expires' => $new_lock_expires], ['id' => $req_id]);

                if ($updated !== false) {
                    paguro_add_history($req_id, 'ADMIN_EXTEND_EXPIRY', 'Scadenza estesa di ' . intval($days) . ' giorni (nuova: ' . $new_lock_expires . ')');
                    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Scadenza estesa di ' . intval($days) . ' giorni.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Errore durante l\'estensione della scadenza.</p></div>';
                }
            }
        }

        if ($_POST['paguro_action'] === 'confirm_booking') {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);

            // Elimina preventivi in conflitto (non confermati)
            $losers = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status=2 AND (date_start<%s AND date_end>%s)",
                $booking->apartment_id,
                $req_id,
                $booking->date_end,
                $booking->date_start
            ));
            foreach ($losers as $l) {
                $wpdb->delete($table_avail, ['id' => $l->id]);
            }

            paguro_send_booking_confirmed_to_user($req_id);

            paguro_add_history($req_id, 'ADMIN_CONFIRM', 'Confermata da amministratore');
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione Confermata.</p></div>';
        }

        if ($_POST['paguro_action'] === 'validate_receipt') {
            if (!empty($booking->receipt_url) && intval($booking->status) === 2) {
                $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);

                // Elimina preventivi in conflitto (non confermati)
                $losers = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status=2 AND (date_start<%s AND date_end>%s)",
                    $booking->apartment_id,
                    $req_id,
                    $booking->date_end,
                    $booking->date_start
                ));
                foreach ($losers as $l) {
                    $wpdb->delete($table_avail, ['id' => $l->id]);
                }

                paguro_send_booking_confirmed_to_user($req_id);
                paguro_add_history($req_id, 'ADMIN_VALIDATE_RECEIPT', 'Distinta validata da amministratore');
                echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Distinta validata. Prenotazione confermata.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Distinta non valida o stato non corretto.</p></div>';
            }
        }

        if ($_POST['paguro_action'] === 'confirm_cancel') {
            $wpdb->update($table_avail, ['status' => 3], ['id' => $req_id]);
            paguro_add_history($req_id, 'ADMIN_CANCEL_CONFIRM', 'Cancellazione confermata da amministratore');
            paguro_trigger_waitlist_alerts($booking->apartment_id, $booking->date_start, $booking->date_end);
            $refund_sent = paguro_send_refund_sent_to_user($req_id);
            if ($refund_sent) {
                paguro_add_history($req_id, 'ADMIN_REFUND_SENT', 'Email bonifico disposto inviata');
            } else {
                paguro_add_history($req_id, 'ADMIN_REFUND_SEND_FAIL', 'Invio email bonifico disposto fallito');
            }
            paguro_send_cancellation_to_admin($req_id);
            if (!empty($booking->group_id) && function_exists('paguro_maybe_update_group_quote_after_cancel')) {
                paguro_maybe_update_group_quote_after_cancel($booking->group_id);
            }
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Cancellazione confermata.</p></div>';
        }


        if ($_POST['paguro_action'] === 'hard_delete_booking') {
            if (intval($booking->status) !== 3) {
                echo '<div class="notice notice-error"><p>Solo i record già cancellati possono essere eliminati definitivamente.</p></div>';
            } else {
                $deleted = $wpdb->delete($table_avail, ['id' => $req_id]);
                if ($deleted === false) {
                    echo '<div class="notice notice-error"><p>Errore durante l\'eliminazione definitiva.</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Record cancellato eliminato definitivamente.</p></div>';
                }
            }
        }

        if ($_POST['paguro_action'] === 'hard_delete_cancelled_bulk') {
            $bulk_ids_raw = isset($_POST['bulk_booking_ids']) && is_array($_POST['bulk_booking_ids']) ? $_POST['bulk_booking_ids'] : [];
            $bulk_ids = [];
            foreach ($bulk_ids_raw as $raw_id) {
                $id = intval(wp_unslash($raw_id));
                if ($id > 0) $bulk_ids[$id] = $id;
            }
            $bulk_ids = array_values($bulk_ids);
            if (!$bulk_ids) {
                echo '<div class="notice notice-error"><p>Seleziona almeno un record cancellato da eliminare.</p></div>';
            } else {
                $placeholders = implode(',', array_fill(0, count($bulk_ids), '%d'));
                $cancelled_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM $table_avail WHERE status = 3 AND id IN ($placeholders)",
                    ...$bulk_ids
                ));
                $cancelled_ids = array_map('intval', (array) $cancelled_ids);
                if (!$cancelled_ids) {
                    echo '<div class="notice notice-error"><p>Nessun record cancellato valido selezionato.</p></div>';
                } else {
                    $delete_placeholders = implode(',', array_fill(0, count($cancelled_ids), '%d'));
                    $deleted = $wpdb->query($wpdb->prepare(
                        "DELETE FROM $table_avail WHERE status = 3 AND id IN ($delete_placeholders)",
                        ...$cancelled_ids
                    ));
                    if ($deleted === false) {
                        echo '<div class="notice notice-error"><p>Errore durante l\'eliminazione multipla.</p></div>';
                    } else {
                        echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Eliminati definitivamente ' . intval($deleted) . ' record cancellati.</p></div>';
                    }
                }
            }
        }

        if ($_POST['paguro_action'] === 'delete_booking') {
            if (intval($booking->status) === 3) {
                echo '<div class="notice notice-warning is-dismissible"><p>Record già presente nell\'archivio cancellati.</p></div>';
            } else {
                $updated = $wpdb->update($table_avail, ['status' => 3], ['id' => $req_id]);
                if ($updated !== false) {
                    paguro_add_history($req_id, 'ADMIN_SOFT_DELETE', 'Spostata in archivio cancellati da Prenotazioni attive');
                    if (!empty($booking->group_id) && function_exists('paguro_maybe_update_group_quote_after_cancel')) {
                        paguro_maybe_update_group_quote_after_cancel($booking->group_id);
                    }
                    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione spostata in Archivio Cancellati.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Errore durante lo spostamento in archivio cancellati.</p></div>';
                }
            }
        }

        if ($_POST['paguro_action'] === 'resend_email') {

            $sent = false;
            $label = 'email';
            $status = intval($booking->status);
            $has_receipt = !empty($booking->receipt_url);
            $failure_reason = '';
            $admin_resend_note = sanitize_textarea_field(wp_unslash($_POST['admin_resend_note'] ?? ''));
            $email_extra = [];
            if ($admin_resend_note !== '') {
                $email_extra['admin_note'] = $admin_resend_note;
            }

            $history = paguro_get_history($req_id);
            $is_confirmed = false;
            $is_manual_insert = false;
            foreach ($history as $entry) {
                if (!empty($entry['action']) && $entry['action'] === 'ADMIN_CONFIRM') {
                    $is_confirmed = true;
                }
                if (!empty($entry['action']) && $entry['action'] === 'ADMIN_MANUAL_INSERT') {
                    $is_manual_insert = true;
                }
            }

            if ($is_manual_insert) {
                $sent = paguro_send_quote_request_to_user($req_id, $email_extra);
                $label = 'preventivo';
            } else {
                if ($status === 3) {
                    $sent = paguro_send_refund_sent_to_user($req_id);
                    $label = 'bonifico disposto';
                    if ($sent) {
                        paguro_add_history($req_id, 'ADMIN_REFUND_SENT', 'Email bonifico disposto inviata (reinvia)');
                    } else {
                        paguro_add_history($req_id, 'ADMIN_REFUND_SEND_FAIL', 'Invio email bonifico disposto fallito (reinvia)');
                    }
                } elseif ($status === 5) {
                    $sent = paguro_send_cancel_request_to_user($req_id);
                    $label = 'richiesta cancellazione';
                } elseif ($status === 1) {
                    $sent = paguro_send_booking_confirmed_to_user($req_id);
                    $label = 'prenotazione confermata';
                } elseif ($status === 4) {
                    $sent = paguro_send_waitlist_confirmation_to_user($req_id);
                    $label = 'lista d\'attesa';
                } elseif ($status === 2) {
                    if ($has_receipt && !$is_confirmed) {
                        $sent = paguro_send_receipt_received_to_user($req_id);
                        $label = 'distinta ricevuta';
                    } else {
                        $sent = paguro_send_quote_request_to_user($req_id, $email_extra);
                        $label = 'preventivo';
                    }
                }
            }

            $note_suffix = '';
            if ($admin_resend_note !== '') {
                $note_preview = $admin_resend_note;
                if (function_exists('mb_substr')) {
                    $note_preview = mb_substr($note_preview, 0, 160);
                    if (mb_strlen($admin_resend_note) > 160) $note_preview .= '...';
                } else {
                    $note_preview = substr($note_preview, 0, 160);
                    if (strlen($admin_resend_note) > 160) $note_preview .= '...';
                }
                $note_suffix = ' | nota: ' . $note_preview;
            }

            if ($sent) {
                paguro_add_history($req_id, 'ADMIN_RESEND_EMAIL', 'Email reinviata da amministratore (' . $label . ')' . $note_suffix);
                echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Email Reinviata (' . esc_html($label) . ').</p></div>';
            } else {
                if (!empty($booking->group_id) && function_exists('paguro_get_group_bookings')) {
                    $group_bookings = paguro_get_group_bookings($booking->group_id);
                    if ($status === 1 && $group_bookings) {
                        $confirmed = 0;
                        foreach ($group_bookings as $gb) {
                            if (intval($gb->status) === 1) $confirmed++;
                        }
                        if ($confirmed < count($group_bookings)) {
                            $failure_reason = 'Prenotazione multi‑settimana non completamente confermata (' . $confirmed . '/' . count($group_bookings) . ').';
                        }
                    }
                    if ($failure_reason === '' && function_exists('paguro_get_group_email_placeholders')) {
                        $ph = paguro_get_group_email_placeholders($booking->group_id);
                        $group_email = $ph['guest_email'] ?? '';
                        if ($ph === [] || !is_email($group_email)) {
                            $failure_reason = 'Email del gruppo mancante o non valida.';
                        }
                    }
                }
                if ($failure_reason === '' && !is_email($booking->guest_email)) {
                    $failure_reason = 'Email del guest mancante o non valida.';
                }
                if ($failure_reason === '') {
                    $failure_reason = 'Invio fallito (wp_mail). Verifica configurazione mail o log server.';
                }
                paguro_add_history($req_id, 'ADMIN_RESEND_EMAIL_FAIL', 'Reinvio fallito: ' . $failure_reason . $note_suffix);
                echo '<div class="notice notice-error"><p>Impossibile reinviare la mail per questo stato. ' . esc_html($failure_reason) . '</p></div>';
            }
        }

        if ($_POST['paguro_action'] === 'validate_group_receipt') {
            $group_id = sanitize_text_field(wp_unslash($_POST['group_id'] ?? ''));
            if ($group_id === '' || !function_exists('paguro_get_group_bookings')) {
                echo '<div class="notice notice-error"><p>Gruppo non valido.</p></div>';
            } else {
                $group_bookings = paguro_get_group_bookings($group_id);
                if (!$group_bookings) {
                    echo '<div class="notice notice-error"><p>Gruppo non trovato.</p></div>';
                } else {
                    $updated = 0;
                    foreach ($group_bookings as $gb) {
                        if (intval($gb->status) === 2 && !empty($gb->receipt_url)) {
                            $wpdb->update($table_avail, ['status' => 1], ['id' => $gb->id]);

                            $losers = $wpdb->get_results($wpdb->prepare(
                                "SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status=2 AND (date_start<%s AND date_end>%s)",
                                $gb->apartment_id,
                                $gb->id,
                                $gb->date_end,
                                $gb->date_start
                            ));
                            foreach ($losers as $l) {
                                $wpdb->delete($table_avail, ['id' => $l->id]);
                            }

                            paguro_add_history($gb->id, 'ADMIN_VALIDATE_RECEIPT', 'Distinta validata da amministratore (gruppo)');
                            $updated++;
                        }
                    }

                    $group_bookings = paguro_get_group_bookings($group_id);
                    $all_confirmed = true;
                    foreach ($group_bookings as $gb) {
                        if (intval($gb->status) === 3) {
                            continue;
                        }
                        if (intval($gb->status) !== 1) {
                            $all_confirmed = false;
                            break;
                        }
                    }
                    if ($all_confirmed && function_exists('paguro_send_group_booking_confirmed_to_user')) {
                        paguro_send_group_booking_confirmed_to_user($group_id);
                    }

                    if ($updated > 0) {
                        echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Distinta validata per il gruppo.</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Nessuna distinta valida da confermare per il gruppo.</p></div>';
                    }
                }
            }
        }

        if ($_POST['paguro_action'] === 'delete_group_booking') {
            $group_id = sanitize_text_field(wp_unslash($_POST['group_id'] ?? ''));
            if ($group_id === '' || !function_exists('paguro_get_group_bookings')) {
                echo '<div class="notice notice-error"><p>Gruppo non valido.</p></div>';
            } else {
                $group_bookings = paguro_get_group_bookings($group_id);
                if (!$group_bookings) {
                    echo '<div class="notice notice-error"><p>Gruppo non trovato.</p></div>';
                } else {
                    $moved = 0;
                    $already_cancelled = 0;
                    foreach ($group_bookings as $gb) {
                        $gb_id = intval($gb->id ?? 0);
                        if ($gb_id <= 0) continue;
                        if (intval($gb->status) === 3) {
                            $already_cancelled++;
                            continue;
                        }
                        $updated = $wpdb->update($table_avail, ['status' => 3], ['id' => $gb_id]);
                        if ($updated !== false) {
                            paguro_add_history($gb_id, 'ADMIN_SOFT_DELETE', 'Spostata in archivio cancellati da azione gruppo');
                            $moved++;
                        }
                    }

                    if (function_exists('paguro_maybe_update_group_quote_after_cancel')) {
                        paguro_maybe_update_group_quote_after_cancel($group_id);
                    }

                    if ($moved > 0) {
                        $msg = '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Gruppo spostato in Archivio Cancellati (' . intval($moved) . ' record).</p></div>';
                        if ($already_cancelled > 0) {
                            $msg = '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Gruppo aggiornato: ' . intval($moved) . ' record spostati, ' . intval($already_cancelled) . ' già cancellati.</p></div>';
                        }
                        echo $msg;
                    } else {
                        echo '<div class="notice notice-warning is-dismissible"><p>Tutti i record del gruppo sono già nell\'archivio cancellati.</p></div>';
                    }
                }
            }
        }

        if ($_POST['paguro_action'] === 'anonymize') {
            // GDPR Compliance: anonymize personal data, keep booking record
            $anonymized_notes = 'ANONIMIZZATO';
            $wpdb->update(
                $table_avail,
                [
                    'guest_name' => 'Anonimizzato',
                    'guest_email' => '',
                    'guest_phone' => '',
                    'guest_notes' => '',
                    'customer_iban' => '',
                    'lock_token' => '',
                    'receipt_url' => '',
                    'receipt_uploaded_at' => null
                ],
                ['id' => $req_id]
            );
            paguro_add_history($req_id, 'ADMIN_ANONYMIZE', $anonymized_notes);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione anonimizzata (GDPR).</p></div>';
        }
    }
}

// ====== NOTIFICHE ADMIN ======
$pending_receipts = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE status = 2 AND receipt_url IS NOT NULL"
);
if ($pending_receipts > 0) {
    $link = admin_url('admin.php?page=paguro-booking&tab=bookings&filter=receipts');
    echo '<div class="notice notice-warning"><p><strong>📄 Distinte in attesa di validazione:</strong> ' .
        intval($pending_receipts) .
        ' — <a href="' . esc_url($link) . '">Apri prenotazioni</a></p></div>';
}

$pending_cancel_requests = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE status = 5"
);
if ($pending_cancel_requests > 0) {
    $link = admin_url('admin.php?page=paguro-booking&tab=bookings&filter=cancel_requests');
    echo '<div class="notice notice-warning"><p><strong>⚠️ Richieste di cancellazione in attesa:</strong> ' .
        intval($pending_cancel_requests) .
        ' — <a href="' . esc_url($link) . '">Apri prenotazioni</a></p></div>';
}

// ====== INSERIMENTO MANUALE PRENOTAZIONE ======
if (isset($_POST['paguro_manual_booking']) && check_admin_referer('paguro_manual_nonce', 'paguro_manual_nonce')) {
    $apt_id = intval(wp_unslash($_POST['apt_id'] ?? 0));
    $start = sanitize_text_field(wp_unslash($_POST['date_start'] ?? ''));
    $end = sanitize_text_field(wp_unslash($_POST['date_end'] ?? ''));
    $guest_name = sanitize_text_field(wp_unslash($_POST['guest_name'] ?? ''));
    $guest_email = sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));
    $guest_phone = sanitize_text_field(wp_unslash($_POST['guest_phone'] ?? ''));
    $guest_notes = sanitize_textarea_field(wp_unslash($_POST['guest_notes'] ?? ''));
    $manual_price = floatval(wp_unslash($_POST['manual_price'] ?? 0));
    if ($manual_price < 0) $manual_price = 0;
    $manual_discount = floatval(wp_unslash($_POST['manual_discount'] ?? 0));
    if ($manual_discount < 0) $manual_discount = 0;

    if ($start >= $end) {
        echo '<div class="notice notice-error"><p>Errore: La data di arrivo deve essere precedente alla partenza.</p></div>';
    } else {
        // Controlla sovrapposizioni con prenotazioni confermate
        $overlap = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_avail WHERE apartment_id=%d AND status=1 AND (date_start < %s AND date_end > %s)",
            $apt_id,
            $end,
            $start
        ));

        if ($overlap > 0) {
            echo '<div class="notice notice-error"><p>Errore: Le date si sovrappongono a una prenotazione già confermata.</p></div>';
        } else {
            $token = bin2hex(random_bytes(16));
            $details = 'Inserimento manuale da pannello';
            if ($manual_price > 0) {
                $details .= ' (Prezzo: €' . number_format($manual_price, 0, ',', '.') . ')';
            }
            if ($manual_discount > 0) {
                $details .= ' (Sconto: €' . number_format($manual_discount, 0, ',', '.') . ')';
            }
            $hist = json_encode([['time' => current_time('mysql'), 'action' => 'ADMIN_MANUAL_INSERT', 'details' => $details]]);

            $wpdb->insert($table_avail, [
                'apartment_id' => $apt_id,
                'date_start' => $start,
                'date_end' => $end,
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
                'guest_phone' => $guest_phone,
                'guest_notes' => $guest_notes,
                'manual_price' => $manual_price > 0 ? $manual_price : null,
                'manual_discount' => $manual_discount > 0 ? $manual_discount : null,
                'status' => 2, // Preventivo (manuale)
                'lock_token' => $token,
                'history_log' => $hist,
                'created_at' => current_time('mysql')
            ]);

            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione Manuale Inserita (Preventivo).</p></div>';
        }
    }
}

if (isset($_GET['paguro_print']) && sanitize_key(wp_unslash($_GET['paguro_print'])) === 'compact') {
    $nonce_ok = isset($_GET['paguro_nonce']) && wp_verify_nonce(wp_unslash($_GET['paguro_nonce']), 'paguro_print_compact');
    if (!$nonce_ok) {
        wp_die(__('Nonce non valido per vista di stampa.', 'paguro'));
    }

    $apt_map_print = paguro_get_admin_apartment_index($table_apt);
    $active_report = paguro_get_active_booking_print_report($table_avail, $apt_map_print);
    $finance_report = paguro_get_finance_print_report($table_avail, $apt_map_print);
    $generated_at = current_time('d/m/Y H:i');
    $back_url = admin_url('admin.php?page=paguro-booking&tab=bookings');
    $print_date = function ($value) {
        if (empty($value)) return '';
        $ts = strtotime((string) $value);
        return $ts ? date('d/m/Y', $ts) : (string) $value;
    };
    $print_money = function ($value) {
        return 'EUR ' . number_format((float) $value, 0, ',', '.');
    };
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Paguro - Stampa compatta</title>
        <style>
            html.wp-toolbar {
                padding-top: 0 !important;
            }

            #wpadminbar,
            #adminmenumain,
            #wpfooter,
            #screen-meta,
            #screen-meta-links,
            .notice,
            .update-nag,
            .fs-notice,
            .error,
            .updated {
                display: none !important;
            }

            #wpcontent,
            #wpfooter,
            #wpbody-content {
                margin-left: 0 !important;
            }

            #wpcontent,
            #wpbody-content {
                padding-left: 0 !important;
            }

            #wpbody-content {
                padding-bottom: 0 !important;
            }

            .wrap.paguro-admin {
                margin: 0 !important;
            }

            @page { size: A4 landscape; margin: 10mm; }
            :root { color-scheme: light; --ink:#17212b; --muted:#5b6670; --line:#cfd7de; --panel:#ffffff; --panel-soft:#f3f6f8; }
            * { box-sizing: border-box; }
            body { margin:0; font-family:"Segoe UI", Arial, sans-serif; color:var(--ink); background:#e7edf2; }
            .paguro-print-toolbar { position:sticky; top:0; z-index:10; display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 18px; background:rgba(18,28,38,0.92); color:#fff; }
            .paguro-print-toolbar-actions { display:flex; gap:8px; flex-wrap:wrap; }
            .paguro-print-toolbar button, .paguro-print-toolbar a { appearance:none; border:1px solid rgba(255,255,255,0.18); background:#fff; color:#111827; text-decoration:none; padding:8px 12px; border-radius:8px; font-size:13px; cursor:pointer; }
            .paguro-print-page { max-width:1360px; margin:18px auto; padding:18px; background:var(--panel); box-shadow:0 10px 30px rgba(15,23,42,0.10); }
            .paguro-print-header { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid var(--line); }
            .paguro-print-header h1 { margin:0 0 8px; font-size:28px; line-height:1.1; }
            .paguro-print-subtitle, .paguro-print-meta { color:var(--muted); font-size:13px; line-height:1.4; }
            .paguro-card-grid { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; margin:12px 0 18px; }
            .paguro-finance-grid { grid-template-columns:repeat(4, minmax(0, 1fr)); }
            .paguro-card { border:1px solid var(--line); background:var(--panel-soft); border-radius:10px; padding:12px; }
            .paguro-card-label { display:block; margin-bottom:6px; color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:0.03em; }
            .paguro-card-value { font-size:24px; font-weight:700; }
            .paguro-section { margin-top:18px; }
            .paguro-section h2 { margin:0 0 6px; font-size:18px; }
            .paguro-section-note { margin:0 0 10px; color:var(--muted); font-size:12px; }
            .paguro-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:11px; }
            .paguro-table th, .paguro-table td { border:1px solid var(--line); padding:6px 7px; vertical-align:top; }
            .paguro-table th { background:#dde8ee; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:0.04em; }
            .paguro-table tbody tr:nth-child(even) td { background:#f8fafb; }
            .paguro-money { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
            .paguro-id { white-space:nowrap; font-weight:700; }
            .paguro-guest { white-space: nowrap; }
            .paguro-guest small, .paguro-print-muted { color:var(--muted); }
            .paguro-print-divider { margin-top:20px; padding-top:20px; border-top:2px solid var(--line); }
            @media print {
                body { background:#fff; }
                .paguro-print-toolbar { display:none; }
                .paguro-print-page { max-width:none; margin:0; padding:0; box-shadow:none; }
                .paguro-print-pagebreak { break-before: page; page-break-before: always; }
                a { color:inherit; text-decoration:none; }
            }
        </style>
    </head>
    <body>
        <div class="paguro-print-toolbar">
            <div>
                <strong>Vista stampa compatta</strong><br>
                <span style="font-size:12px; opacity:0.85;">Usa la stampa del browser per carta o Salva come PDF.</span>
            </div>
            <div class="paguro-print-toolbar-actions">
                <button type="button" onclick="window.print()">Stampa / PDF</button>
                <a href="<?php echo esc_url($back_url); ?>">Torna alla dashboard</a>
            </div>
        </div>

        <main class="paguro-print-page">
            <header class="paguro-print-header">
                <div>
                    <h1>Paguro Booking</h1>
                    <div class="paguro-print-subtitle">Report operativo compatto per stampa</div>
                </div>
                <div class="paguro-print-meta">
                    Generato il <?php echo esc_html($generated_at); ?><br>
                    Stagione economica: <?php echo esc_html($print_date($finance_report['season_start'])); ?> - <?php echo esc_html($print_date($finance_report['season_end'])); ?>
                </div>
            </header>

            <section class="paguro-section">
                <h2>Prenotazioni attive</h2>
                <p class="paguro-section-note">Vista compatta per gruppi e singole prenotazioni non cancellate.</p>
                <div class="paguro-card-grid">
                    <div class="paguro-card"><span class="paguro-card-label">Totale attive</span><div class="paguro-card-value"><?php echo esc_html($active_report['summary']['total']); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Confermate</span><div class="paguro-card-value"><?php echo esc_html($active_report['summary']['confirmed']); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Preventivi</span><div class="paguro-card-value"><?php echo esc_html($active_report['summary']['quotes']); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Distinte da validare</span><div class="paguro-card-value"><?php echo esc_html($active_report['summary']['pending_receipts']); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Waitlist / cancellazioni</span><div class="paguro-card-value"><?php echo esc_html($active_report['summary']['waitlist'] + $active_report['summary']['cancel_requests']); ?></div></div>
                </div>

                <table class="paguro-table">
                    <thead>
                        <tr>
                            <th style="width:10%;">ID</th>
                            <th style="width:24%;">Ospite</th>
                            <th style="width:16%;">Appartamento</th>
                            <th style="width:9%;">Arrivo</th>
                            <th style="width:9%;">Partenza</th>
                            <th style="width:14%;">Stato</th>
                            <th style="width:7%;">Distinta</th>
                            <th style="width:11%;">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($active_report['rows'])) { ?>
                            <?php foreach ($active_report['rows'] as $row) { ?>
                                <tr>
                                    <td class="paguro-id"><?php echo esc_html($row['id_label']); ?></td>
                                    <td class="paguro-guest">
                                        <?php echo esc_html($row['guest']); ?>
                                        <?php if ($row['email'] !== '') { ?> <small><?php echo esc_html($row['email']); ?></small><?php } ?>
                                        <?php if ($row['phone'] !== '') { ?> <small><?php echo esc_html($row['phone']); ?></small><?php } ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($row['apartment']); ?><br>
                                        <?php if ($row['apartment_link'] !== '') { ?><span class="paguro-print-muted"><?php echo esc_html($row['apartment_link']); ?></span><?php } ?>
                                    </td>
                                    <td><?php echo esc_html($print_date($row['date_start'])); ?></td>
                                    <td><?php echo esc_html($print_date($row['date_end'])); ?></td>
                                    <td><?php echo esc_html($row['status']); ?></td>
                                    <td><?php echo esc_html($row['receipt']); ?></td>
                                    <td><?php echo esc_html($row['notes']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="8">Nessuna prenotazione attiva.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </section>

            <section class="paguro-section paguro-print-divider paguro-print-pagebreak">
                <h2>Situazione economica</h2>
                <p class="paguro-section-note">Solo prenotazioni confermate nel range stagione configurato.</p>
                <div class="paguro-card-grid paguro-finance-grid">
                    <div class="paguro-card"><span class="paguro-card-label">Acconti ricevuti</span><div class="paguro-card-value"><?php echo esc_html($print_money($finance_report['summary']['received'])); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Saldi previsti</span><div class="paguro-card-value"><?php echo esc_html($print_money($finance_report['summary']['remaining'])); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Incasso previsto</span><div class="paguro-card-value"><?php echo esc_html($print_money($finance_report['summary']['forecast'])); ?></div></div>
                    <div class="paguro-card"><span class="paguro-card-label">Rimborsi</span><div class="paguro-card-value"><?php echo esc_html($print_money($finance_report['summary']['refund'])); ?></div></div>
                </div>

                <table class="paguro-table">
                    <thead>
                        <tr>
                            <th style="width:10%;">ID</th>
                            <th style="width:24%;">Ospite</th>
                            <th style="width:16%;">Appartamento</th>
                            <th style="width:9%;">Arrivo</th>
                            <th style="width:9%;">Partenza</th>
                            <th style="width:8%;">Totale</th>
                            <th style="width:8%;">Acconto</th>
                            <th style="width:8%;">Ricevuto</th>
                            <th style="width:8%;">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($finance_report['rows'])) { ?>
                            <?php foreach ($finance_report['rows'] as $row) { ?>
                                <tr>
                                    <td class="paguro-id"><?php echo esc_html($row['id_label']); ?></td>
                                    <td class="paguro-guest">
                                        <?php echo esc_html($row['guest']); ?>
                                        <?php if ($row['email'] !== '') { ?> <small><?php echo esc_html($row['email']); ?></small><?php } ?>
                                    </td>
                                    <td><?php echo esc_html($row['apartment']); ?></td>
                                    <td><?php echo esc_html($print_date($row['date_start'])); ?></td>
                                    <td><?php echo esc_html($print_date($row['date_end'])); ?></td>
                                    <td class="paguro-money"><?php echo esc_html($print_money($row['total'])); ?></td>
                                    <td class="paguro-money"><?php echo esc_html($print_money($row['deposit'])); ?></td>
                                    <td class="paguro-money"><?php echo esc_html($print_money($row['received'])); ?></td>
                                    <td class="paguro-money"><?php echo esc_html($print_money($row['remaining'])); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr><td colspan="9">Nessun dato economico disponibile per la stagione.</td></tr>
                        <?php } ?>
                    </tbody>
                </table>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

?>

<div class="wrap paguro-admin">
    <h1>📅 Paguro Booking - Amministrazione</h1>

    <script>
        (function() {
            function ensureOverlay() {
                var overlay = document.getElementById('paguro-admin-overlay');
                if (overlay) return overlay;
                overlay = document.createElement('div');
                overlay.id = 'paguro-admin-overlay';
                overlay.className = 'paguro-admin-overlay';
                overlay.innerHTML = '' +
                    '<div class=\"paguro-admin-overlay-backdrop\" aria-hidden=\"true\"></div>' +
                    '<div class=\"paguro-admin-overlay-card\" role=\"dialog\" aria-modal=\"true\">' +
                    '  <button type=\"button\" class=\"paguro-admin-overlay-close\" aria-label=\"Chiudi\">×</button>' +
                    '  <div class=\"paguro-admin-overlay-content\"></div>' +
                    '  <div class=\"paguro-admin-overlay-actions\"></div>' +
                    '</div>';
                document.body.appendChild(overlay);

                overlay.addEventListener('click', function(evt) {
                    if (evt.target.classList.contains('paguro-admin-overlay-backdrop') ||
                        evt.target.classList.contains('paguro-admin-overlay-close')) {
                        closeOverlay();
                    }
                });
                document.addEventListener('keydown', function(evt) {
                    if (evt.key === 'Escape' && overlay.classList.contains('is-open')) {
                        closeOverlay();
                    }
                });
                return overlay;
            }

            function openOverlay(contentHtml, actionsHtml) {
                var overlay = ensureOverlay();
                overlay.querySelector('.paguro-admin-overlay-content').innerHTML = contentHtml || '';
                overlay.querySelector('.paguro-admin-overlay-actions').innerHTML = actionsHtml || '';
                overlay.classList.add('is-open');
            }

            function closeOverlay() {
                var overlay = document.getElementById('paguro-admin-overlay');
                if (!overlay) return;
                overlay.classList.remove('is-open');
                overlay.querySelector('.paguro-admin-overlay-content').innerHTML = '';
                overlay.querySelector('.paguro-admin-overlay-actions').innerHTML = '';
            }

            function openConfirm(message, onConfirm) {
                var safe = (message || '').replace(/[&<>]/g, function(c) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;'
                    } [c];
                }).replace(/\\n/g, '<br>');
                var content = '<div style=\"line-height:1.5;\">' + safe + '</div>';
                var actions = '' +
                    '<button type=\"button\" class=\"button paguro-admin-confirm-cancel\">Annulla</button>' +
                    '<button type=\"button\" class=\"button button-primary paguro-admin-confirm-ok\">Conferma</button>';
                openOverlay(content, actions);
                var overlay = document.getElementById('paguro-admin-overlay');
                var okBtn = overlay.querySelector('.paguro-admin-confirm-ok');
                var cancelBtn = overlay.querySelector('.paguro-admin-confirm-cancel');
                okBtn.onclick = function() {
                    closeOverlay();
                    if (typeof onConfirm === 'function') onConfirm();
                };
                cancelBtn.onclick = function() {
                    closeOverlay();
                };
            }

            function showHistoryLog(btn) {
                if (!btn) return false;
                var raw = btn.getAttribute('data-history') || '';
                var logText = raw;
                try {
                    raw = atob(raw);
                } catch (err) {}
                if (typeof raw === 'string' && raw.indexOf('\\n') !== -1 && raw.indexOf('\n') === -1) {
                    raw = raw.replace(/\\n/g, '\n');
                }
                try {
                    var parsed = JSON.parse(raw);
                    if (Array.isArray(parsed)) {
                        logText = parsed.map(function(entry) {
                            var time = entry.time || '';
                            var action = entry.action || '';
                            var details = entry.details || '';
                            return (time + ' - ' + action + (details ? ' - ' + details : '')).trim() + '\n';
                        }).join('');
                    }
                } catch (err) {}
                var safe = (logText || 'Nessun log disponibile').replace(/[&<>]/g, function(c) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;'
                    } [c];
                });
                openOverlay('<pre style=\"white-space:pre-wrap; margin:0;\">' + safe + '</pre>');
                return false;
            }

            function showReceipt(btn) {
                if (!btn) return false;
                var url = btn.getAttribute('data-receipt-url') || btn.getAttribute('href') || '';
                if (!url) return false;
                var safeUrl = url.replace(/\"/g, '&quot;');
                openOverlay('<iframe src=\"' + safeUrl + '\" style=\"width:90vw; max-width:900px; height:75vh; border:0;\"></iframe>');
                return false;
            }

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function(c) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;'
                    } [c];
                });
            }

            function openEditBooking(btn) {
                if (!btn) return false;
                var data = {
                    id: btn.getAttribute('data-booking-id') || '',
                    name: btn.getAttribute('data-guest-name') || '',
                    email: btn.getAttribute('data-guest-email') || '',
                    phone: btn.getAttribute('data-guest-phone') || '',
                    notes: btn.getAttribute('data-guest-notes') || '',
                    price: btn.getAttribute('data-manual-price') || '',
                    discount: btn.getAttribute('data-manual-discount') || '',
                    dateStart: btn.getAttribute('data-date-start') || '',
                    dateEnd: btn.getAttribute('data-date-end') || '',
                    apartmentId: btn.getAttribute('data-apartment-id') || ''
                };

                var content = '' +
                    '<form id=\"paguro-edit-form\" class=\"paguro-admin-edit-form\" method=\"POST\">' +
                    '  <input type=\"hidden\" name=\"paguro_action\" value=\"update_booking\">' +
                    '  <input type=\"hidden\" name=\"booking_id\" value=\"' + escapeHtml(data.id) + '\">' +
                    '  <input type=\"hidden\" name=\"_wpnonce\" value=\"' + escapeHtml(window.paguroAdminNonce || '') + '\">' +
                    '  <label>Nome</label>' +
                    '  <input type=\"text\" name=\"guest_name\" required value=\"' + escapeHtml(data.name) + '\">' +
                    '  <label>Email</label>' +
                    '  <input type=\"email\" name=\"guest_email\" required value=\"' + escapeHtml(data.email) + '\">' +
                    '  <label>Telefono</label>' +
                    '  <input type=\"text\" name=\"guest_phone\" value=\"' + escapeHtml(data.phone) + '\">' +
                    '  <div style=\"display:grid; grid-template-columns: 1fr 1fr; gap: 10px;\">' +
                    '    <div>' +
                    '      <label>Data Arrivo</label>' +
                    '      <input type=\"date\" id=\"paguro-date-start\" name=\"date_start\" required value=\"' + escapeHtml(data.dateStart) + '\">' +
                    '    </div>' +
                    '    <div>' +
                    '      <label>Data Partenza</label>' +
                    '      <input type=\"date\" id=\"paguro-date-end\" name=\"date_end\" required value=\"' + escapeHtml(data.dateEnd) + '\">' +
                    '    </div>' +
                    '  </div>' +
                    '  <div id=\"paguro-overlap-alert\" style=\"display:none; padding:10px; margin:10px 0; background:#fff3cd; border:1px solid #ffc107; border-radius:3px; color:#856404;\"></div>' +
                    '  <label>Prezzo Totale (opzionale)</label>' +
                    '  <input type=\"number\" name=\"manual_price\" step=\"0.01\" min=\"0\" value=\"' + escapeHtml(data.price) + '\">' +
                    '  <label>Sconto € (opzionale)</label>' +
                    '  <input type="number" name="manual_discount" step="0.01" min="0" value="' + escapeHtml(data.discount) + '">' +
                    '  <label>Note</label>' +
                    '  <textarea name=\"guest_notes\" rows=\"4\">' + escapeHtml(data.notes) + '</textarea>' +
                    '</form>';
                var actions = '' +
                    '<button type=\"button\" class=\"button paguro-admin-overlay-cancel\">Annulla</button>' +
                    '<button type=\"submit\" class=\"button button-primary\" form=\"paguro-edit-form\" id=\"paguro-edit-submit\">Salva</button>';
                openOverlay(content, actions);

                // Aggiungi validazione date in tempo reale
                var dateStartInput = document.getElementById('paguro-date-start');
                var dateEndInput = document.getElementById('paguro-date-end');
                var overlapAlert = document.getElementById('paguro-overlap-alert');
                var submitBtn = document.getElementById('paguro-edit-submit');

                function checkOverlaps() {
                    if (!dateStartInput.value || !dateEndInput.value) return;

                    var formData = new FormData();
                    formData.append('action', 'paguro_check_date_overlap');
                    formData.append('booking_id', data.id);
                    formData.append('apartment_id', data.apartmentId);
                    formData.append('date_start', dateStartInput.value);
                    formData.append('date_end', dateEndInput.value);
                    formData.append('_wpnonce', window.paguroAdminNonce);

                    fetch(ajaxurl, {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(result) {
                            if (result.success && result.data.overlaps) {
                                overlapAlert.innerHTML = '<strong>⚠️ Attenzione:</strong> Conflitto con prenotazione: ' + escapeHtml(result.data.conflicting_name) + ' (' + escapeHtml(result.data.conflicting_dates) + ')';
                                overlapAlert.style.display = 'block';
                                submitBtn.disabled = true;
                            } else {
                                overlapAlert.style.display = 'none';
                                submitBtn.disabled = false;
                            }
                        })
                        .catch(function() {
                            overlapAlert.style.display = 'none';
                        });
                }

                if (dateStartInput) dateStartInput.addEventListener('change', checkOverlaps);
                if (dateEndInput) dateEndInput.addEventListener('change', checkOverlaps);

                return false;
            }

            function openExtendExpiry(btn) {
                if (!btn) return false;
                var bookingId = btn.getAttribute('data-booking-id') || '';

                var content = '' +
                    '<form id=\"paguro-extend-expiry-form\" class=\"paguro-admin-edit-form\" method=\"POST\">' +
                    '  <input type=\"hidden\" name=\"paguro_action\" value=\"extend_expiry\">' +
                    '  <input type=\"hidden\" name=\"booking_id\" value=\"' + escapeHtml(bookingId) + '\">' +
                    '  <input type=\"hidden\" name=\"_wpnonce\" value=\"' + escapeHtml(window.paguroAdminNonce || '') + '\">' +
                    '  <div class=\"paguro-admin-inline-field\">Estendi per <input type=\"number\" id=\"paguro-extend-days\" name=\"extend_days\" min=\"1\" max=\"99\" step=\"1\" inputmode=\"numeric\" required> giorni</div>' +
                    '</form>';
                var actions = '' +
                    '<button type=\"button\" class=\"button paguro-admin-overlay-cancel\">Annulla</button>' +
                    '<button type=\"submit\" class=\"button button-primary\" form=\"paguro-extend-expiry-form\">Conferma</button>';
                openOverlay(content, actions);

                var form = document.getElementById('paguro-extend-expiry-form');
                var input = document.getElementById('paguro-extend-days');
                if (input) {
                    input.focus();
                    input.addEventListener('input', function() {
                        var digits = String(input.value || '').replace(/\D/g, '').slice(0, 2);
                        input.value = digits;
                    });
                }
                if (form && input) {
                    form.addEventListener('submit', function(evt) {
                        var value = parseInt(input.value, 10);
                        if (!Number.isFinite(value) || value < 1 || value > 99) {
                            evt.preventDefault();
                            input.focus();
                        }
                    });
                }
                return false;
            }

            function openResendEmailNote(btn) {
                if (!btn) return false;
                var form = btn.closest('form');
                if (!form) return false;

                var content = '' +
                    '<div style=\"line-height:1.5; margin-bottom:10px;\">' +
                    '  <strong>Reinvio email cliente</strong><br>' +
                    '  Puoi aggiungere una nota contestuale da includere nel messaggio. Se lasci vuoto, la mail viene inviata senza nota.' +
                    '</div>' +
                    '<label for=\"paguro-resend-note\" style=\"display:block; margin-bottom:6px; font-weight:600;\">Nota per il cliente (opzionale)</label>' +
                    '<textarea id=\"paguro-resend-note\" rows=\"5\" style=\"width:100%; min-width:320px; max-width:720px;\" placeholder=\"Es. Ti inoltriamo il preventivo aggiornato con lo sconto corretto.\"></textarea>';
                var actions = '' +
                    '<button type=\"button\" class=\"button paguro-admin-overlay-cancel\">Annulla</button>' +
                    '<button type=\"button\" class=\"button\" id=\"paguro-resend-submit-empty\">Invia senza nota</button>' +
                    '<button type=\"button\" class=\"button button-primary\" id=\"paguro-resend-submit-note\">Reinvia con nota</button>';
                openOverlay(content, actions);

                var noteInput = document.getElementById('paguro-resend-note');
                if (noteInput) noteInput.focus();

                var submitResend = function(noteText) {
                    var noteField = form.querySelector('input[name=\"admin_resend_note\"]');
                    if (!noteField) {
                        noteField = document.createElement('input');
                        noteField.type = 'hidden';
                        noteField.name = 'admin_resend_note';
                        form.appendChild(noteField);
                    }
                    noteField.value = noteText || '';

                    var actionName = btn.getAttribute('name');
                    var actionValue = btn.getAttribute('value');
                    if (actionName) {
                        var actionField = form.querySelector('input[name=\"' + actionName + '\"]');
                        if (!actionField || actionField.type !== 'hidden') {
                            actionField = document.createElement('input');
                            actionField.type = 'hidden';
                            actionField.name = actionName;
                            form.appendChild(actionField);
                        }
                        actionField.value = actionValue || '';
                    }

                    closeOverlay();
                    form.submit();
                };

                var sendEmptyBtn = document.getElementById('paguro-resend-submit-empty');
                var sendNoteBtn = document.getElementById('paguro-resend-submit-note');
                if (sendEmptyBtn) {
                    sendEmptyBtn.onclick = function() {
                        submitResend('');
                    };
                }
                if (sendNoteBtn) {
                    sendNoteBtn.onclick = function() {
                        submitResend(noteInput ? noteInput.value : '');
                    };
                }
                return false;
            }

            window.paguroAdminShowLog = showHistoryLog;
            window.paguroAdminShowReceipt = showReceipt;
            window.paguroAdminEditBooking = openEditBooking;
            window.paguroAdminNonce = '<?php echo esc_js(wp_create_nonce('paguro_admin_action')); ?>';

            document.addEventListener('click', function(e) {
                var target = e.target;
                if (target && target.nodeType === 3) {
                    target = target.parentElement;
                }
                if (!target || typeof target.closest !== 'function') return;
                var btn = target.closest('.paguro-history-toggle');
                if (btn) {
                    e.preventDefault();
                    showHistoryLog(btn);
                    return;
                }

                var receipt = target.closest('.paguro-admin-receipt-link');
                if (receipt) {
                    e.preventDefault();
                    showReceipt(receipt);
                    return;
                }

                var editBtn = target.closest('.paguro-edit-booking');
                if (editBtn) {
                    e.preventDefault();
                    openEditBooking(editBtn);
                    return;
                }

                var extendBtn = target.closest('.paguro-extend-expiry');
                if (extendBtn) {
                    e.preventDefault();
                    openExtendExpiry(extendBtn);
                    return;
                }

                var resendBtn = target.closest('.paguro-resend-email');
                if (resendBtn) {
                    e.preventDefault();
                    openResendEmailNote(resendBtn);
                    return;
                }

                if (target.closest('.paguro-admin-overlay-cancel')) {
                    e.preventDefault();
                    closeOverlay();
                    return;
                }

                var confirmBtn = target.closest('.paguro-admin-confirm');
                if (confirmBtn) {
                    e.preventDefault();
                    var msg = confirmBtn.getAttribute('data-confirm') || 'Confermi l\'azione?';
                    openConfirm(msg, function() {
                        var form = confirmBtn.closest('form');
                        if (!form) return;
                        var name = confirmBtn.getAttribute('name');
                        var value = confirmBtn.getAttribute('value');
                        if (name) {
                            var hidden = form.querySelector('input[name=\"' + name + '\"]');
                            if (!hidden) {
                                hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = name;
                                form.appendChild(hidden);
                            }
                            hidden.value = value || '';
                        }
                        form.submit();
                    });
                }
            });

            document.addEventListener('DOMContentLoaded', function() {
                function wrapSections(containerId) {
                    var container = document.getElementById(containerId);
                    if (!container) return;
                    var headers = container.querySelectorAll('h3');
                    headers.forEach(function(h3) {
                        if (h3.closest('details')) return;
                        var next = h3.nextElementSibling;
                        if (!next) return;
                        var details = document.createElement('details');
                        details.className = 'paguro-details';
                        var summary = document.createElement('summary');
                        summary.textContent = h3.textContent;
                        details.appendChild(summary);
                        details.appendChild(next);
                        h3.replaceWith(details);
                    });
                }
                wrapSections('paguro-web-templates');
                wrapSections('paguro-email-templates');
            });
        })();
    </script>

    <style>
        .paguro-admin-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .paguro-admin-overlay.is-open {
            display: flex;
        }

        .paguro-admin-overlay-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
        }

        .paguro-admin-overlay-card {
            position: relative;
            z-index: 1;
            background: #fff;
            border-radius: 10px;
            padding: 18px;
            max-height: 85vh;
            overflow: auto;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
        }

        .paguro-admin-overlay-actions {
            margin-top: 16px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .paguro-admin-edit-form {
            display: grid;
            gap: 8px;
            min-width: 280px;
        }

        .paguro-admin-edit-form label {
            font-weight: 600;
            margin-top: 4px;
        }

        .paguro-admin-edit-form input,
        .paguro-admin-edit-form textarea {
            width: 100%;
        }

        .paguro-admin-inline-field {
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.4;
        }

        .paguro-admin-inline-field input {
            width: 84px;
            margin: 0;
        }

        .paguro-admin-overlay-close {
            position: absolute;
            right: 8px;
            top: 6px;
            border: none;
            background: transparent;
            font-size: 20px;
            cursor: pointer;
        }

        .paguro-details {
            margin: 10px 0 16px;
            padding: 6px 0;
        }

        .paguro-details summary {
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
        }

        .paguro-details table {
            margin-top: 10px;
        }

        .paguro-group-base {
            background-color: transparent;
        }

        .paguro-group-alt {
            background-color: transparent;
        }

        .paguro-group-base td:first-child,
        .paguro-group-alt td:first-child {
            border-left: 3px solid #6f8fd6;
        }

        .paguro-row-status-no-receipt {
            background-color: #fdecec;
        }

        .paguro-row-status-receipt {
            background-color: #e7f6ea;
        }

        .paguro-row-status-confirmed {
            background-color: #e3f2e8;
        }

        .paguro-row-status-cancelled {
            background-color: #f8d7da;
        }

        .paguro-row-status-waitlist {
            background-color: #e8f1ff;
        }

        .paguro-row-status-cancel-request {
            background-color: #fff3cd;
        }

        .paguro-group-base.paguro-row-status-no-receipt {
            background-color: #fbe2e2;
        }

        .paguro-group-alt.paguro-row-status-no-receipt {
            background-color: #f8d6d6;
        }

        .paguro-group-base.paguro-row-status-receipt {
            background-color: #def1e3;
        }

        .paguro-group-alt.paguro-row-status-receipt {
            background-color: #d4ead9;
        }

        .paguro-group-base.paguro-row-status-confirmed {
            background-color: #dff0e6;
        }

        .paguro-group-alt.paguro-row-status-confirmed {
            background-color: #d4eadf;
        }

        .paguro-group-base.paguro-row-status-cancelled {
            background-color: #f4cfd4;
        }

        .paguro-group-alt.paguro-row-status-cancelled {
            background-color: #f0c2c9;
        }

        .paguro-group-row td,
        .paguro-group-child td {
            transition: background-color 0.2s ease;
        }

        .paguro-status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
            vertical-align: middle;
        }

        .paguro-status-dot--confirmed {
            background-color: #28a745;
        }

        .paguro-status-dot--receipt {
            background-color: #28a745;
        }

        .paguro-status-dot--no-receipt {
            background-color: #dc3545;
        }

        .paguro-status-dot--cancelled {
            background-color: #dc3545;
        }

        .paguro-status-dot--waitlist {
            background-color: #007bff;
        }

        .paguro-status-dot--cancel-request {
            background-color: #ffc107;
        }

        .paguro-status-dot--partial {
            background-color: #fd7e14;
        }

        .paguro-status-dot--quote {
            background-color: #6c757d;
        }

        .paguro-calendar-overview-wrap {
            margin: 12px 0 14px;
            overflow-x: auto;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            background: #fff;
        }

        .paguro-calendar-overview {
            width: max-content;
            min-width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        .paguro-calendar-overview th,
        .paguro-calendar-overview td {
            padding: 3px 4px;
            border-right: 1px solid #e2e4e7;
            border-bottom: 1px solid #e2e4e7;
            text-align: center;
            font-size: 11px;
            line-height: 1.1;
            min-width: 26px;
            white-space: nowrap;
        }

        .paguro-calendar-overview .paguro-cal-apt-col {
            min-width: 140px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            background: #fff;
            position: sticky;
            left: 0;
            z-index: 1;
        }

        .paguro-calendar-overview thead .paguro-cal-apt-col {
            z-index: 2;
        }

        .paguro-calendar-overview .paguro-cal-month-head {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.2px;
        }

        .paguro-calendar-overview .paguro-cal-month-06 {
            background: #eaf4ff;
        }

        .paguro-calendar-overview .paguro-cal-month-07 {
            background: #ecf9ee;
        }

        .paguro-calendar-overview .paguro-cal-month-08 {
            background: #fff4e3;
        }

        .paguro-calendar-overview .paguro-cal-month-09 {
            background: #f2ecff;
        }

        .paguro-calendar-overview .paguro-cal-day-head {
            font-weight: 500;
        }

        .paguro-calendar-overview .paguro-cal-day-dow {
            display: block;
            font-weight: 600;
            line-height: 1.1;
        }

        .paguro-calendar-overview .paguro-cal-day-num {
            display: block;
            font-size: 10px;
            line-height: 1.1;
            margin-top: 1px;
        }

        .paguro-calendar-overview .paguro-cal-cell-free {
            background: #dff3df;
        }

        .paguro-calendar-overview .paguro-cal-cell-quote {
            background: #ffd8a8;
        }

        .paguro-calendar-overview .paguro-cal-cell-booked {
            background: #f6baba;
            color: #8f1313;
            font-weight: 700;
            font-size: 9px;
            word-break: break-word;
            padding: 2px 3px;
            line-height: 1.2;
            white-space: normal;
        }

        .paguro-calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 8px 0 10px;
            align-items: center;
            font-size: 12px;
        }

        .paguro-calendar-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .paguro-calendar-legend-swatch {
            width: 14px;
            height: 14px;
            border: 1px solid #c3c4c7;
            border-radius: 2px;
            display: inline-block;
        }

        .paguro-calendar-legend-swatch--booked {
            background: #f6baba;
        }

        .paguro-calendar-legend-swatch--quote {
            background: #ffd8a8;
        }

        .paguro-calendar-legend-swatch--free {
            background: #dff3df;
        }
    </style>

    <nav class="nav-tab-wrapper">
        <a href="<?php echo add_query_arg('tab', 'bookings'); ?>" class="nav-tab <?php echo $current_tab === 'bookings' ? 'nav-tab-active' : ''; ?>">📋 Prenotazioni</a>
        <a href="<?php echo add_query_arg('tab', 'apartments'); ?>" class="nav-tab <?php echo $current_tab === 'apartments' ? 'nav-tab-active' : ''; ?>">🏠 Appartamenti</a>
        <a href="<?php echo add_query_arg('tab', 'emails'); ?>" class="nav-tab <?php echo $current_tab === 'emails' ? 'nav-tab-active' : ''; ?>">📧 Email Templates</a>
        <a href="<?php echo add_query_arg('tab', 'web_templates'); ?>" class="nav-tab <?php echo $current_tab === 'web_templates' ? 'nav-tab-active' : ''; ?>">🖥️ Web Templates</a>
        <a href="<?php echo add_query_arg('tab', 'settings'); ?>" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Configurazione</a>
        <a href="<?php echo add_query_arg('tab', 'ai'); ?>" class="nav-tab <?php echo $current_tab === 'ai' ? 'nav-tab-active' : ''; ?>">🤖 IA</a>
    </nav>

    <div class="tab-content">

        <!-- TAB: PRENOTAZIONI -->
        <?php if ($current_tab === 'bookings') { ?>
            <h2>Gestione Prenotazioni</h2>

            <!-- INSERIMENTO MANUALE -->
            <div class="card paguro-manual-card">
                <div class="paguro-card-header">
                    <h3>➕ Inserisci Prenotazione Manuale</h3>
                    <button type="button" class="button paguro-toggle-manual" aria-expanded="false" aria-controls="paguro-manual-body">Apri</button>
                </div>
                <div id="paguro-manual-body" class="paguro-card-body is-collapsed">
                    <form method="POST" class="paguro-manual-form">
                        <?php wp_nonce_field('paguro_manual_nonce', 'paguro_manual_nonce'); ?>

                        <table class="form-table">
                            <tr>
                                <th>Appartamento</th>
                                <td>
                                    <select name="apt_id" required>
                                        <option value="">-- Seleziona --</option>
                                        <?php $apts = $wpdb->get_results("SELECT * FROM $table_apt ORDER BY name");
                                        foreach ($apts as $apt) {
                                            echo '<option value="' . $apt->id . '">' . esc_html($apt->name) . ' (€' . number_format($apt->base_price, 0, ',', '.') . '/settimana)</option>';
                                        } ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Arrivo</th>
                                <td><input type="date" name="date_start" required></td>
                            </tr>
                            <tr>
                                <th>Partenza</th>
                                <td><input type="date" name="date_end" required></td>
                            </tr>
                            <tr>
                                <th>Prezzo Totale (opzionale)</th>
                                <td>
                                    <input type="number" name="manual_price" step="0.01" min="0" placeholder="Prezzo €">
                                    <p class="description">Se vuoto, usa il calcolo automatico.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Sconto € (opzionale)</th>
                                <td>
                                    <input type="number" name="manual_discount" step="0.01" min="0" placeholder="Sconto €">
                                    <p class="description">Shortcode: <code>{manual_discount}</code>, <code>{manual_discount_fmt}</code>, <code>{manual_discount_raw}</code>.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Nome Guest</th>
                                <td><input type="text" name="guest_name" required></td>
                            </tr>
                            <tr>
                                <th>Email</th>
                                <td><input type="email" name="guest_email" required></td>
                            </tr>
                            <tr>
                                <th>Telefono</th>
                                <td><input type="text" name="guest_phone"></td>
                            </tr>
                            <tr class="paguro-col-span-2">
                                <th>Note</th>
                                <td><textarea name="guest_notes" rows="3"></textarea></td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" name="paguro_manual_booking" class="button button-primary">Inserisci Prenotazione</button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- TABELLA PRENOTAZIONI -->
            <?php
            $filter = sanitize_text_field(wp_unslash($_GET['filter'] ?? ''));
            $filter = in_array($filter, ['receipts', 'cancel_requests'], true) ? $filter : 'all';
            $bookings_url = add_query_arg(['tab' => 'bookings'], $base_url);
            $filters = [
                'all' => ['label' => 'Tutte attive', 'url' => $bookings_url],
                'receipts' => ['label' => 'Solo distinte', 'url' => add_query_arg('filter', 'receipts', $bookings_url)],
                'cancel_requests' => ['label' => 'Solo richieste cancellazione', 'url' => add_query_arg('filter', 'cancel_requests', $bookings_url)],
            ];
            ?>
            <h3>Prenotazioni Attive</h3>
            <div class="paguro-filter-links">
                <?php foreach ($filters as $key => $info) { ?>
                    <a href="<?php echo esc_url($info['url']); ?>" class="button <?php echo ($filter === $key) ? 'button-primary' : ''; ?>">
                        <?php echo esc_html($info['label']); ?>
                    </a>
                <?php } ?>
            </div>
            <?php
            $season_start_raw = get_option('paguro_season_start', '2026-06-01');
            $season_end_raw = get_option('paguro_season_end', '2026-09-30');
            try {
                $calendar_start = new DateTime($season_start_raw);
            } catch (Exception $e) {
                $calendar_start = new DateTime('2026-06-01');
            }
            try {
                $calendar_end_inclusive = new DateTime($season_end_raw);
            } catch (Exception $e) {
                $calendar_end_inclusive = new DateTime('2026-09-30');
            }
            if ($calendar_end_inclusive < $calendar_start) {
                $calendar_end_inclusive = clone $calendar_start;
            }
            $calendar_end_exclusive = (clone $calendar_end_inclusive)->modify('+1 day');

            $calendar_month_labels = [
                '01' => 'Gennaio',
                '02' => 'Febbraio',
                '03' => 'Marzo',
                '04' => 'Aprile',
                '05' => 'Maggio',
                '06' => 'Giugno',
                '07' => 'Luglio',
                '08' => 'Agosto',
                '09' => 'Settembre',
                '10' => 'Ottobre',
                '11' => 'Novembre',
                '12' => 'Dicembre',
            ];
            $calendar_month_palette = ['#eaf4ff', '#ecf9ee', '#fff4e3', '#f2ecff', '#ffeef3', '#eef7f8'];
            $calendar_day_names = [
                'Mon' => 'Lun',
                'Tue' => 'Mar',
                'Wed' => 'Mer',
                'Thu' => 'Gio',
                'Fri' => 'Ven',
                'Sat' => 'Sab',
                'Sun' => 'Dom',
            ];
            $calendar_days = [];
            $calendar_months = [];
            $calendar_month_colspan = [];
            $calendar_month_colors = [];
            $calendar_multiple_years = $calendar_start->format('Y') !== $calendar_end_inclusive->format('Y');
            $cursor = clone $calendar_start;
            while ($cursor < $calendar_end_exclusive) {
                $month_key = $cursor->format('Y-m');
                if (!isset($calendar_months[$month_key])) {
                    $month_num = $cursor->format('m');
                    $month_label = $calendar_month_labels[$month_num] ?? $cursor->format('m/Y');
                    if ($calendar_multiple_years) {
                        $month_label .= ' ' . $cursor->format('Y');
                    }
                    $calendar_months[$month_key] = $month_label;
                    $calendar_month_colspan[$month_key] = 0;
                    $palette_index = (count($calendar_months) - 1) % count($calendar_month_palette);
                    $calendar_month_colors[$month_key] = $calendar_month_palette[$palette_index];
                }
                $calendar_days[] = [
                    'key' => $cursor->format('Y-m-d'),
                    'month' => $month_key,
                    'dow' => ($calendar_day_names[$cursor->format('D')] ?? $cursor->format('D')),
                    'day_num' => $cursor->format('d'),
                ];
                $calendar_month_colspan[$month_key]++;
                $cursor->modify('+1 day');
            }

            $calendar_apts = $wpdb->get_results("SELECT id, name FROM $table_apt ORDER BY name ASC");
            $calendar_blocks = []; // Nuova struttura: blocchi di prenotazione con colspan

            if (!empty($calendar_apts)) {
                $apt_ids = array_map('intval', wp_list_pluck($calendar_apts, 'id'));
                if (!empty($apt_ids)) {
                    $id_placeholders = implode(',', array_fill(0, count($apt_ids), '%d'));
                    $calendar_rows = $wpdb->get_results($wpdb->prepare(
                        "SELECT apartment_id, date_start, date_end, status, guest_name
                         FROM {$wpdb->prefix}paguro_availability
                         WHERE apartment_id IN ($id_placeholders)
                         AND status IN (1,2,5)
                         AND date_start < %s
                         AND date_end > %s
                         ORDER BY apartment_id ASC, date_start ASC",
                        ...array_merge($apt_ids, [$calendar_end_exclusive->format('Y-m-d'), $calendar_start->format('Y-m-d')])
                    ));

                    // Crea array di indici giorno per calcolo colspan
                    $day_index = [];
                    foreach ($calendar_days as $idx => $day) {
                        $day_index[$day['key']] = $idx;
                    }

                    // Costruisce blocchi per ogni appartamento
                    foreach ($calendar_apts as $apt) {
                        $calendar_blocks[$apt->id] = [];
                    }

                    // Popola i blocchi dalle prenotazioni
                    foreach ($calendar_rows as $row) {
                        $apt_id = intval($row->apartment_id);
                        $start_key = ($row->date_start < $calendar_start->format('Y-m-d')) ? $calendar_start->format('Y-m-d') : $row->date_start;
                        $end_key = ($row->date_end > $calendar_end_exclusive->format('Y-m-d')) ? $calendar_end_exclusive->format('Y-m-d') : $row->date_end;

                        // Salta se il blocco è completamente fuori dal calendario
                        if (!isset($day_index[$start_key]) || !isset($day_index[$end_key])) {
                            // Calcola solo se il blocco interseca con il calendario visibile
                            $booking_start = new DateTime($start_key);
                            $booking_end = new DateTime($end_key);

                            $vis_start = max($booking_start, $calendar_start);
                            $vis_end = min($booking_end, $calendar_end_exclusive);

                            if ($vis_start >= $vis_end) {
                                continue;
                            }

                            $start_key = $vis_start->format('Y-m-d');
                            $end_key = $vis_end->format('Y-m-d');
                        }

                        if (!isset($day_index[$start_key])) continue;

                        $status_state = intval($row->status) === 2 ? 'quote' : 'booked';
                        $guest_name = ($status_state === 'booked') ? ($row->guest_name ?: 'Occupato') : '';

                        // Calcola l'indice di inizio
                        $start_idx = $day_index[$start_key];

                        // Calcola il colspan (contando i giorni del calendario)
                        $booking_start = new DateTime($start_key);
                        $booking_end = new DateTime($end_key);
                        $colspan = 0;
                        $current = clone $booking_start;
                        while ($current < $booking_end && isset($day_index[$current->format('Y-m-d')])) {
                            $colspan++;
                            $current->modify('+1 day');
                        }

                        if ($colspan > 0) {
                            $calendar_blocks[$apt_id][] = [
                                'start_idx' => $start_idx,
                                'colspan' => $colspan,
                                'state' => $status_state,
                                'name' => $guest_name
                            ];
                        }
                    }
                }
            }
            ?>
            <div class="paguro-calendar-legend" aria-label="Legenda calendario disponibilità">
                <span class="paguro-calendar-legend-item"><span class="paguro-calendar-legend-swatch paguro-calendar-legend-swatch--booked" aria-hidden="true"></span> Prenotata (Nome guest)</span>
                <span class="paguro-calendar-legend-item"><span class="paguro-calendar-legend-swatch paguro-calendar-legend-swatch--quote" aria-hidden="true"></span> Preventivo</span>
                <span class="paguro-calendar-legend-item"><span class="paguro-calendar-legend-swatch paguro-calendar-legend-swatch--free" aria-hidden="true"></span> Libera</span>
            </div>
            <div class="paguro-calendar-overview-wrap">
                <table class="paguro-calendar-overview">
                    <thead>
                        <tr>
                            <th class="paguro-cal-apt-col">Appartamento</th>
                            <?php foreach ($calendar_months as $month_key => $month_label) { ?>
                                <th class="paguro-cal-month-head" style="background:<?php echo esc_attr($calendar_month_colors[$month_key] ?? '#f6f7f7'); ?>;" colspan="<?php echo intval($calendar_month_colspan[$month_key] ?? 0); ?>"><?php echo esc_html($month_label); ?></th>
                            <?php } ?>
                        </tr>
                        <tr>
                            <th class="paguro-cal-apt-col"></th>
                            <?php foreach ($calendar_days as $day) { ?>
                                <th class="paguro-cal-day-head" style="background:<?php echo esc_attr($calendar_month_colors[$day['month']] ?? '#f6f7f7'); ?>;"><span class="paguro-cal-day-dow"><?php echo esc_html($day['dow']); ?></span><span class="paguro-cal-day-num"><?php echo esc_html($day['day_num']); ?></span></th>
                            <?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calendar_apts as $apt_row) { ?>
                            <tr>
                                <th class="paguro-cal-apt-col"><?php echo esc_html($apt_row->name); ?></th>
                                <?php
                                // Renderizza blocchi e celle libere per questo appartamento
                                $blocks = $calendar_blocks[$apt_row->id] ?? [];
                                usort($blocks, function ($a, $b) {
                                    return $a['start_idx'] - $b['start_idx'];
                                });

                                $rendered_idx = 0;
                                foreach ($blocks as $block) {
                                    // Celle libere prima di questo blocco
                                    while ($rendered_idx < $block['start_idx']) {
                                ?>
                                        <td class="paguro-cal-cell paguro-cal-cell-free"></td>
                                    <?php
                                        $rendered_idx++;
                                    }

                                    // Il blocco stesso
                                    $class = 'paguro-cal-cell-' . $block['state'];
                                    $display_text = $block['name'];
                                    ?>
                                    <td class="paguro-cal-cell <?php echo esc_attr($class); ?>" colspan="<?php echo intval($block['colspan']); ?>"><?php echo esc_html($display_text); ?></td>
                                <?php
                                    $rendered_idx += $block['colspan'];
                                }

                                // Celle libere rimanenti
                                while ($rendered_idx < count($calendar_days)) {
                                ?>
                                    <td class="paguro-cal-cell paguro-cal-cell-free"></td>
                                <?php
                                    $rendered_idx++;
                                }
                                ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php
            $export_active_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'paguro_export_csv',
                        'export' => 'active_bookings',
                    ],
                    admin_url('admin-post.php')
                ),
                'paguro_export_active_bookings',
                'paguro_nonce'
            );
            $export_finance_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action' => 'paguro_export_csv',
                        'export' => 'finance',
                    ],
                    admin_url('admin-post.php')
                ),
                'paguro_export_finance',
                'paguro_nonce'
            );
            $print_compact_url = wp_nonce_url(
                add_query_arg(['tab' => 'bookings', 'paguro_print' => 'compact'], $base_url),
                'paguro_print_compact',
                'paguro_nonce'
            );
            ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin:14px 0 12px;">
                <a href="<?php echo esc_url($print_compact_url); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">Vista stampa compatta</a>
                <a href="<?php echo esc_url($export_active_url); ?>" class="button button-secondary">Esporta CSV prenotazioni attive</a>
                <a href="<?php echo esc_url($export_finance_url); ?>" class="button button-secondary">Esporta CSV situazione economica</a>
            </div>
            <table class="widefat striped paguro-bookings-table">
                <thead>
                    <tr>
                        <th width="80">ID</th>
                        <th>Guest</th>
                        <th>Appartamento</th>
                        <th>Arrivo</th>
                        <th>Partenza</th>
                        <th width="120">Stato</th>
                        <th width="90">Distinta</th>
                        <th width="60">Log</th>
                        <th width="200">Azioni</th>
                    </tr>
                    <tr class="paguro-table-filters" data-table-filter="bookings">
                        <th><input type="text" data-col="0" placeholder="ID"></th>
                        <th><input type="text" data-col="1" placeholder="Guest"></th>
                        <th><input type="text" data-col="2" placeholder="Appartamento"></th>
                        <th><input type="text" data-col="3" placeholder="Arrivo"></th>
                        <th><input type="text" data-col="4" placeholder="Partenza"></th>
                        <th>
                            <select data-col="5">
                                <option value="">Tutti</option>
                                <option value="Confermata">Confermata</option>
                                <option value="Preventivo">Preventivo</option>
                                <option value="Waitlist">Waitlist</option>
                                <option value="Richiesta cancellazione">Richiesta cancellazione</option>
                                <option value="Distinta">Distinta</option>
                            </select>
                        </th>
                        <th>
                            <select data-col="6">
                                <option value="">Tutte</option>
                                <option value="Vedi">Con distinta</option>
                                <option value="—">Senza distinta</option>
                            </select>
                        </th>
                        <th>
                            <select data-col="7">
                                <option value="">Tutti</option>
                                <option value="🕒">Con log</option>
                                <option value="—">Senza log</option>
                            </select>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $limit = 50;
                    $where = '';
                    $exclude_incomplete_clause = " AND NOT (
                        status = 2
                        AND receipt_url IS NULL
                        AND TRIM(COALESCE(guest_name, '')) = ''
                        AND TRIM(COALESCE(guest_email, '')) = ''
                    )";
                    if ($filter === 'receipts') {
                        $where = 'WHERE status=2 AND receipt_url IS NOT NULL';
                    } elseif ($filter === 'cancel_requests') {
                        $where = 'WHERE status=5';
                    }
                    if ($where) {
                        $where .= $exclude_incomplete_clause;
                        $bookings = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}paguro_availability $where ORDER BY created_at DESC LIMIT %d",
                            $limit
                        ));
                    } else {
                        $bookings = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE status <> 3 {$exclude_incomplete_clause} ORDER BY created_at DESC LIMIT %d",
                            $limit
                        ));
                    }
                    if ($bookings) {
                        $grouped = [];
                        foreach ($bookings as $b) {
                            if (!empty($b->group_id)) {
                                if (!isset($grouped[$b->group_id])) $grouped[$b->group_id] = [];
                                $grouped[$b->group_id][] = $b;
                            }
                        }
                        $rendered_groups = [];
                        $group_index = 0;
                        foreach ($bookings as $b) {
                            if (!empty($b->group_id)) {
                                if (isset($rendered_groups[$b->group_id])) {
                                    continue;
                                }
                                $rendered_groups[$b->group_id] = true;
                                $group_index++;
                                $group_class = ($group_index % 2 === 0) ? 'paguro-group-alt' : 'paguro-group-base';
                                $children = $grouped[$b->group_id];
                                $first = $children[0];
                                $apt = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_apt WHERE id=%d", $first->apartment_id));
                                $last = $children[count($children) - 1];
                                $all_confirmed = true;
                                $all_cancelled = true;
                                $any_confirmed = false;
                                $any_receipt = false;
                                $any_pending_receipt = false;
                                $receipt_link = '';
                                foreach ($children as $cb) {
                                    if ($cb->status != 1) $all_confirmed = false;
                                    if ($cb->status == 1) $any_confirmed = true;
                                    if ($cb->status != 3) $all_cancelled = false;
                                    if (!empty($cb->receipt_url) && !$receipt_link) {
                                        $receipt_link = paguro_get_admin_receipt_link($cb->id, $cb->receipt_url);
                                        $any_receipt = true;
                                    }
                                    if (intval($cb->status) === 2 && !empty($cb->receipt_url)) {
                                        $any_pending_receipt = true;
                                    }
                                }
                                $group_status = $all_cancelled ? 'Cancellata' : ($all_confirmed ? 'Confermata' : ($any_confirmed ? 'Parziale' : 'Preventivo'));
                                $group_status_class = $all_confirmed ? 'paguro-badge--green' : ($all_cancelled ? 'paguro-badge--red' : ($any_confirmed ? 'paguro-badge--orange' : 'paguro-badge--gray'));
                                $group_row_status_class = $all_cancelled ? 'paguro-row-status-cancelled' : ($all_confirmed ? 'paguro-row-status-confirmed' : ($any_receipt ? 'paguro-row-status-receipt' : 'paguro-row-status-no-receipt'));
                                $group_dot_class = $all_cancelled ? 'paguro-status-dot--cancelled' : ($all_confirmed ? 'paguro-status-dot--confirmed' : ($any_confirmed ? 'paguro-status-dot--partial' : 'paguro-status-dot--quote'));
                                $group_id_short = substr($b->group_id, 0, 8);
                                $group_link = site_url("/" . get_option('paguro_page_slug', 'riepilogo-prenotazione') . "/?token={$b->group_id}");
                    ?>
                                <tr class="paguro-group-row <?php echo esc_attr($group_class . ' ' . $group_row_status_class); ?>" data-group="<?php echo esc_attr($b->group_id); ?>">
                                    <td><strong>GRP-<?php echo esc_html($group_id_short); ?></strong></td>
                                    <td>
                                        <?php echo esc_html($first->guest_name); ?><br>
                                        <small><?php echo esc_html($first->guest_email); ?></small>
                                        <?php if (!empty($first->guest_phone)) { ?><br><small><?php echo esc_html($first->guest_phone); ?></small><?php } ?>
                                    </td>
                                    <td><?php echo esc_html($apt->name ?? 'N/A'); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($children[0]->date_start)); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($last->date_end)); ?></td>
                                    <td><span class="paguro-badge <?php echo esc_attr($group_status_class); ?>"><span class="paguro-status-dot <?php echo esc_attr($group_dot_class); ?>" aria-hidden="true"></span><?php echo esc_html($group_status); ?></span></td>
                                    <td>
                                        <?php if ($any_receipt) { ?>
                                            <a href="#" data-receipt-url="<?php echo esc_url($receipt_link); ?>" class="button button-small paguro-admin-receipt-link" onclick="return paguroAdminShowReceipt(this);">Vedi</a>
                                        <?php } else { ?>
                                            <span style="color:#999;">—</span>
                                        <?php } ?>
                                    </td>
                                    <td><span style="color:#999;">—</span></td>
                                    <td>
                                        <a href="<?php echo esc_url($group_link); ?>" class="button button-small">Riepilogo</a>
                                        <?php if ($any_pending_receipt) { ?>
                                            <form method="POST" style="display:inline;">
                                                <?php wp_nonce_field('paguro_admin_action'); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo esc_attr($first->id); ?>">
                                                <input type="hidden" name="group_id" value="<?php echo esc_attr($b->group_id); ?>">
                                                <button type="submit" name="paguro_action" value="validate_group_receipt" class="button button-small paguro-admin-confirm" data-confirm="Validare tutte le distinte del gruppo?">Valida Distinta</button>
                                            </form>
                                        <?php } ?>
                                        <form method="POST" style="display:inline;">
                                            <?php wp_nonce_field('paguro_admin_action'); ?>
                                            <input type="hidden" name="booking_id" value="<?php echo esc_attr($first->id); ?>">
                                            <input type="hidden" name="group_id" value="<?php echo esc_attr($b->group_id); ?>">
                                            <button type="submit" name="paguro_action" value="delete_group_booking" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Spostare in archivio cancellati tutte le prenotazioni del gruppo?">Sposta tutto in cancellati</button>
                                        </form>
                                        <button type="button" class="button button-small paguro-group-toggle" data-group="<?php echo esc_attr($b->group_id); ?>">Dettagli</button>
                                    </td>
                                </tr>
                                <?php
                                foreach ($children as $cb) {
                                    $apt_child = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_apt WHERE id=%d", $cb->apartment_id));
                                    $status_labels = [
                                        1 => 'Confermata',
                                        2 => 'Preventivo',
                                        3 => 'Cancellata',
                                        4 => 'Waitlist',
                                        5 => 'Richiesta cancellazione',
                                    ];
                                    $status_classes = [
                                        1 => 'paguro-badge--green',
                                        2 => 'paguro-badge--gray',
                                        3 => 'paguro-badge--red',
                                        4 => 'paguro-badge--blue',
                                        5 => 'paguro-badge--orange',
                                    ];
                                    $status_label = $status_labels[$cb->status] ?? 'Sconosciuto';
                                    $status_class = $status_classes[$cb->status] ?? 'paguro-badge--gray';
                                    $row_status_class = 'paguro-row-status-no-receipt';
                                    $dot_class = 'paguro-status-dot--no-receipt';
                                    if (intval($cb->status) === 1) {
                                        $row_status_class = 'paguro-row-status-confirmed';
                                        $dot_class = 'paguro-status-dot--confirmed';
                                    } elseif (intval($cb->status) === 3) {
                                        $row_status_class = 'paguro-row-status-cancelled';
                                        $dot_class = 'paguro-status-dot--cancelled';
                                    } elseif (intval($cb->status) === 4) {
                                        $row_status_class = 'paguro-row-status-waitlist';
                                        $dot_class = 'paguro-status-dot--waitlist';
                                    } elseif (intval($cb->status) === 5) {
                                        $row_status_class = 'paguro-row-status-cancel-request';
                                        $dot_class = 'paguro-status-dot--cancel-request';
                                    } elseif (intval($cb->status) === 2) {
                                        $row_status_class = !empty($cb->receipt_url) ? 'paguro-row-status-receipt' : 'paguro-row-status-no-receipt';
                                        $dot_class = !empty($cb->receipt_url) ? 'paguro-status-dot--receipt' : 'paguro-status-dot--no-receipt';
                                    }
                                    $status_action = '';
                                    $status_confirm = '';
                                    if ($cb->status == 2 && !empty($cb->receipt_url)) {
                                        $status_label = 'Distinta (in validazione)';
                                        $status_class = 'paguro-badge--yellow';
                                        $status_action = 'validate_receipt';
                                        $status_confirm = 'Validare la distinta e confermare la prenotazione?';
                                    } elseif ($cb->status == 5) {
                                        $status_action = 'confirm_cancel';
                                        $status_confirm = 'Confermare la cancellazione?';
                                    }
                                    if ($status_action) {
                                        $status_label = '<button type="submit" name="paguro_action" value="' . esc_attr($status_action) . '" class="button button-small paguro-admin-confirm" data-confirm="' . esc_attr($status_confirm) . '"><span class="paguro-status-dot ' . esc_attr($dot_class) . '" aria-hidden="true"></span>' . esc_html($status_label) . '</button>';
                                    } else {
                                        $status_label = '<span class="paguro-badge ' . esc_attr($status_class) . '"><span class="paguro-status-dot ' . esc_attr($dot_class) . '" aria-hidden="true"></span>' . esc_html($status_label) . '</span>';
                                    }
                                ?>
                                    <tr class="paguro-group-child <?php echo esc_attr($group_class . ' ' . $row_status_class); ?>" data-group="<?php echo esc_attr($b->group_id); ?>" style="display:none;">
                                        <td>&nbsp;&nbsp;↳ <?php echo $cb->id; ?></td>
                                        <td>
                                            <?php echo esc_html($cb->guest_name); ?><br>
                                            <small><?php echo esc_html($cb->guest_email); ?></small>
                                            <?php if (!empty($cb->guest_phone)) { ?><br><small><?php echo esc_html($cb->guest_phone); ?></small><?php } ?>
                                        </td>
                                        <td><?php echo esc_html($apt_child->name ?? 'N/A'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cb->date_start)); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cb->date_end)); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <?php wp_nonce_field('paguro_admin_action'); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo $cb->id; ?>">
                                                <?php echo $status_label; ?>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if (!empty($cb->receipt_url)) { ?>
                                                <a href="#" data-receipt-url="<?php echo esc_url(paguro_get_admin_receipt_link($cb->id, $cb->receipt_url)); ?>" class="button button-small paguro-admin-receipt-link" onclick="return paguroAdminShowReceipt(this);">Vedi</a>
                                            <?php } else { ?>
                                                <span style="color:#999;">—</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($cb->history_log)) { ?>
                                                <a href="#" class="button button-small paguro-history-toggle" data-history="<?php echo esc_attr(base64_encode($cb->history_log)); ?>" title="Mostra log" onclick="return paguroAdminShowLog(this);">🕒</a>
                                            <?php } else { ?>
                                                <span style="color:#999;">—</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <?php
                                            $quote_sent = paguro_quote_sent_from_admin($cb);
                                            if ($cb->status == 2 && empty($cb->receipt_url)) {
                                                $email_action = $quote_sent ? 'resend_email' : 'send_quote';
                                                $email_label = $quote_sent ? 'Reinvia preventivo' : 'Invia preventivo';
                                            } else {
                                                $email_action = 'resend_email';
                                                $email_label = 'Reinvia Email';
                                            }
                                            ?>
                                            <form method="POST" style="display: inline;">
                                                <?php wp_nonce_field('paguro_admin_action'); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo $cb->id; ?>">
                                                <button type="button"
                                                    class="button button-small paguro-edit-booking"
                                                    data-booking-id="<?php echo esc_attr($cb->id); ?>"
                                                    data-guest-name="<?php echo esc_attr($cb->guest_name); ?>"
                                                    data-guest-email="<?php echo esc_attr($cb->guest_email); ?>"
                                                    data-guest-phone="<?php echo esc_attr($cb->guest_phone); ?>"
                                                    data-guest-notes="<?php echo esc_attr($cb->guest_notes); ?>"
                                                    data-manual-price="<?php echo esc_attr($cb->manual_price !== null ? $cb->manual_price : ''); ?>"
                                                    data-manual-discount="<?php echo esc_attr((isset($cb->manual_discount) && $cb->manual_discount !== null) ? $cb->manual_discount : ''); ?>"
                                                    data-date-start="<?php echo esc_attr($cb->date_start); ?>"
                                                    data-date-end="<?php echo esc_attr($cb->date_end); ?>"
                                                    data-apartment-id="<?php echo esc_attr($cb->apartment_id); ?>">Modifica</button>
                                                <?php if (intval($cb->status) === 2) { ?>
                                                    <button type="button" class="button button-small paguro-extend-expiry" data-booking-id="<?php echo esc_attr($cb->id); ?>">Estendi scadenza</button>
                                                    <?php if (empty($cb->receipt_url)) { ?>
                                                        <button type="submit" name="paguro_action" value="confirm_booking" class="button button-small paguro-admin-confirm" data-confirm="Confermare manualmente questa prenotazione?">Conferma manualmente</button>
                                                    <?php } ?>
                                                <?php } ?>
                                                <button type="submit"
                                                    name="paguro_action"
                                                    value="<?php echo esc_attr($email_action); ?>"
                                                    class="button button-small<?php echo ($email_action === 'resend_email') ? ' paguro-resend-email' : ''; ?>"
                                                    data-email-action="<?php echo esc_attr($email_action); ?>"><?php echo esc_html($email_label); ?></button>
                                                <button type="submit" name="paguro_action" value="anonymize" class="button button-small paguro-admin-confirm" data-confirm="Anonimizzare i dati?">GDPR</button>
                                                <button type="submit" name="paguro_action" value="delete_booking" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Spostare in archivio cancellati?">Sposta in cancellati</button>
                                            </form>
                                        </td>
                                    </tr>
                            <?php
                                }
                                continue;
                            }

                            $apt = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_apt WHERE id=%d", $b->apartment_id));
                            $status_labels = [
                                1 => 'Confermata',
                                2 => 'Preventivo',
                                3 => 'Cancellata',
                                4 => 'Waitlist',
                                5 => 'Richiesta cancellazione',
                            ];
                            $status_classes = [
                                1 => 'paguro-badge--green',
                                2 => 'paguro-badge--gray',
                                3 => 'paguro-badge--red',
                                4 => 'paguro-badge--blue',
                                5 => 'paguro-badge--orange',
                            ];
                            $status_label = $status_labels[$b->status] ?? 'Sconosciuto';
                            $status_class = $status_classes[$b->status] ?? 'paguro-badge--gray';
                            $row_status_class = 'paguro-row-status-no-receipt';
                            $dot_class = 'paguro-status-dot--no-receipt';
                            if (intval($b->status) === 1) {
                                $row_status_class = 'paguro-row-status-confirmed';
                                $dot_class = 'paguro-status-dot--confirmed';
                            } elseif (intval($b->status) === 3) {
                                $row_status_class = 'paguro-row-status-cancelled';
                                $dot_class = 'paguro-status-dot--cancelled';
                            } elseif (intval($b->status) === 4) {
                                $row_status_class = 'paguro-row-status-waitlist';
                                $dot_class = 'paguro-status-dot--waitlist';
                            } elseif (intval($b->status) === 5) {
                                $row_status_class = 'paguro-row-status-cancel-request';
                                $dot_class = 'paguro-status-dot--cancel-request';
                            } elseif (intval($b->status) === 2) {
                                $row_status_class = !empty($b->receipt_url) ? 'paguro-row-status-receipt' : 'paguro-row-status-no-receipt';
                                $dot_class = !empty($b->receipt_url) ? 'paguro-status-dot--receipt' : 'paguro-status-dot--no-receipt';
                            }
                            $status_action = '';
                            $status_confirm = '';
                            if ($b->status == 2 && !empty($b->receipt_url)) {
                                $status_label = 'Distinta (in validazione)';
                                $status_class = 'paguro-badge--yellow';
                                $status_action = 'validate_receipt';
                                $status_confirm = 'Validare la distinta e confermare la prenotazione?';
                            } elseif ($b->status == 5) {
                                $status_action = 'confirm_cancel';
                                $status_confirm = 'Confermare la cancellazione?';
                            }
                            if ($status_action) {
                                $status_label = '<button type="submit" name="paguro_action" value="' . esc_attr($status_action) . '" class="button button-small paguro-admin-confirm" data-confirm="' . esc_attr($status_confirm) . '"><span class="paguro-status-dot ' . esc_attr($dot_class) . '" aria-hidden="true"></span>' . esc_html($status_label) . '</button>';
                            } else {
                                $status_label = '<span class="paguro-badge ' . esc_attr($status_class) . '"><span class="paguro-status-dot ' . esc_attr($dot_class) . '" aria-hidden="true"></span>' . esc_html($status_label) . '</span>';
                            }
                            ?>
                            <tr class="<?php echo esc_attr($row_status_class); ?>">
                                <td><?php echo $b->id; ?></td>
                                <td>
                                    <?php echo esc_html($b->guest_name); ?><br>
                                    <small><?php echo esc_html($b->guest_email); ?></small>
                                    <?php if (!empty($b->guest_phone)) { ?><br><small><?php echo esc_html($b->guest_phone); ?></small><?php } ?>
                                </td>
                                <td><?php echo esc_html($apt->name ?? 'N/A'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($b->date_start)); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($b->date_end)); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <?php wp_nonce_field('paguro_admin_action'); ?>
                                        <input type="hidden" name="booking_id" value="<?php echo $b->id; ?>">
                                        <?php echo $status_label; ?>
                                    </form>
                                </td>
                                <td>
                                    <?php if (!empty($b->receipt_url)) { ?>
                                        <a href="#" data-receipt-url="<?php echo esc_url(paguro_get_admin_receipt_link($b->id, $b->receipt_url)); ?>" class="button button-small paguro-admin-receipt-link" onclick="return paguroAdminShowReceipt(this);">Vedi</a>
                                    <?php } else { ?>
                                        <span style="color:#999;">—</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if (!empty($b->history_log)) { ?>
                                        <a href="#" class="button button-small paguro-history-toggle" data-history="<?php echo esc_attr(base64_encode($b->history_log)); ?>" title="Mostra log" onclick="return paguroAdminShowLog(this);">🕒</a>
                                    <?php } else { ?>
                                        <span style="color:#999;">—</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php
                                    $quote_sent = paguro_quote_sent_from_admin($b);
                                    if ($b->status == 2 && empty($b->receipt_url)) {
                                        $email_action = $quote_sent ? 'resend_email' : 'send_quote';
                                        $email_label = $quote_sent ? 'Reinvia preventivo' : 'Invia preventivo';
                                    } else {
                                        $email_action = 'resend_email';
                                        $email_label = 'Reinvia Email';
                                    }
                                    ?>
                                    <form method="POST" style="display: inline;">
                                        <?php wp_nonce_field('paguro_admin_action'); ?>
                                        <input type="hidden" name="booking_id" value="<?php echo $b->id; ?>">
                                        <button type="button"
                                            class="button button-small paguro-edit-booking"
                                            data-booking-id="<?php echo esc_attr($b->id); ?>"
                                            data-guest-name="<?php echo esc_attr($b->guest_name); ?>"
                                            data-guest-email="<?php echo esc_attr($b->guest_email); ?>"
                                            data-guest-phone="<?php echo esc_attr($b->guest_phone); ?>"
                                            data-guest-notes="<?php echo esc_attr($b->guest_notes); ?>"
                                            data-manual-price="<?php echo esc_attr($b->manual_price !== null ? $b->manual_price : ''); ?>"
                                            data-manual-discount="<?php echo esc_attr((isset($b->manual_discount) && $b->manual_discount !== null) ? $b->manual_discount : ''); ?>"
                                            data-date-start="<?php echo esc_attr($b->date_start); ?>"
                                            data-date-end="<?php echo esc_attr($b->date_end); ?>"
                                            data-apartment-id="<?php echo esc_attr($b->apartment_id); ?>">Modifica</button>
                                        <?php if (intval($b->status) === 2) { ?>
                                            <button type="button" class="button button-small paguro-extend-expiry" data-booking-id="<?php echo esc_attr($b->id); ?>">Estendi scadenza</button>
                                            <?php if (empty($b->receipt_url)) { ?>
                                                <button type="submit" name="paguro_action" value="confirm_booking" class="button button-small paguro-admin-confirm" data-confirm="Confermare manualmente questa prenotazione?">Conferma manualmente</button>
                                            <?php } ?>
                                        <?php } ?>
                                        <button type="submit"
                                            name="paguro_action"
                                            value="<?php echo esc_attr($email_action); ?>"
                                            class="button button-small<?php echo ($email_action === 'resend_email') ? ' paguro-resend-email' : ''; ?>"
                                            data-email-action="<?php echo esc_attr($email_action); ?>"><?php echo esc_html($email_label); ?></button>
                                        <button type="submit" name="paguro_action" value="anonymize" class="button button-small paguro-admin-confirm" data-confirm="Anonimizzare i dati?">GDPR</button>
                                        <button type="submit" name="paguro_action" value="delete_booking" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Spostare in archivio cancellati?">Sposta in cancellati</button>
                                    </form>
                                </td>
                            </tr>
                    <?php
                        }
                    } else {
                        echo '<tr><td colspan="9" style="text-align: center; padding: 20px;">Nessuna prenotazione.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            <details class="paguro-details paguro-finance-details">
                <summary>Situazione Economica</summary>
                <p style="margin:12px 0;">
                    <a href="<?php echo esc_url($export_finance_url); ?>" class="button button-secondary">Scarica CSV situazione economica</a>
                </p>
                <?php
                $season_start_fin = get_option('paguro_season_start', '2026-06-01');
                $season_end_fin = get_option('paguro_season_end', '2026-09-30');
                $finance_bookings = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}paguro_availability
                 WHERE status = 1 AND date_end >= %s AND date_start <= %s
                 ORDER BY date_start ASC",
                    $season_start_fin,
                    $season_end_fin
                ));
                $finance_grouped = [];
                foreach ($finance_bookings as $fb) {
                    if (!empty($fb->group_id)) {
                        if (!isset($finance_grouped[$fb->group_id])) $finance_grouped[$fb->group_id] = [];
                        $finance_grouped[$fb->group_id][] = $fb;
                    }
                }
                $finance_rendered_groups = [];
                $finance_group_index = 0;
                $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
                $sum_deposit_received = 0;
                $sum_remaining = 0;
                $sum_forecast = 0;
                $sum_refunds = 0;
                $finance_deposit_overrides = get_option('paguro_finance_deposit_overrides', []);
                if (!is_array($finance_deposit_overrides)) $finance_deposit_overrides = [];
                $finance_received_overrides = get_option('paguro_finance_received_overrides', []);
                if (!is_array($finance_received_overrides)) $finance_received_overrides = [];

                $apt_rows = $wpdb->get_results("SELECT id, name FROM $table_apt ORDER BY name");
                $apt_map = [];
                foreach ($apt_rows as $apt_item) {
                    $apt_map[$apt_item->id] = $apt_item->name;
                }
                $compute_finance_values = function ($booking, $total_cost) use ($deposit_percent, $finance_deposit_overrides, $finance_received_overrides) {
                    $total_cost = max(0, round((float) $total_cost, 2));
                    $booking_key = (string) intval($booking->id ?? 0);
                    $default_deposit = $total_cost > 0 ? ceil($total_cost * ($deposit_percent / 100)) : 0;
                    $deposit = $default_deposit;
                    if (array_key_exists($booking_key, $finance_deposit_overrides)) {
                        $deposit = max(0, round((float) $finance_deposit_overrides[$booking_key], 2));
                    }
                    if ($deposit > $total_cost) $deposit = $total_cost;

                    $default_received = (intval($booking->status ?? 0) === 1 || !empty($booking->receipt_url)) ? $deposit : 0;
                    $deposit_received = $default_received;
                    if (array_key_exists($booking_key, $finance_received_overrides)) {
                        $deposit_received = max(0, round((float) $finance_received_overrides[$booking_key], 2));
                    }
                    if ($deposit_received > $total_cost) $deposit_received = $total_cost;

                    $remaining = max(0, $total_cost - $deposit_received);
                    $forecast = $remaining;
                    return [
                        'total' => $total_cost,
                        'deposit' => $deposit,
                        'deposit_received' => $deposit_received,
                        'remaining' => $remaining,
                        'forecast' => $forecast,
                        'refund' => 0
                    ];
                };
                ?>

                <div class="paguro-finance-summary">
                    <div class="paguro-finance-card">
                        <span class="label">Acconti ricevuti</span>
                        <strong class="value" id="paguro-fin-deposit">€ 0,00</strong>
                    </div>
                    <div class="paguro-finance-card">
                        <span class="label">Saldi previsti</span>
                        <strong class="value" id="paguro-fin-remaining">€ 0,00</strong>
                    </div>
                    <div class="paguro-finance-card">
                        <span class="label">Previsione incasso</span>
                        <strong class="value" id="paguro-fin-forecast">€ 0,00</strong>
                    </div>
                    <div class="paguro-finance-card">
                        <span class="label">Caparre restituite</span>
                        <strong class="value" id="paguro-fin-refunds">€ 0,00</strong>
                    </div>
                </div>

                <table class="widefat striped paguro-finance-table">
                    <thead>
                        <tr>
                            <th width="80">ID</th>
                            <th>Guest</th>
                            <th>Appartamento</th>
                            <th>Arrivo</th>
                            <th>Partenza</th>
                            <th>Stato</th>
                            <th>Totale</th>
                            <th>Acconto</th>
                            <th>Acconto ricevuto</th>
                            <th>Saldo previsto</th>
                            <th>Incasso previsto</th>
                            <th>Rimborso</th>
                        </tr>
                        <tr class="paguro-table-filters" data-table-filter="finance">
                            <th><input type="text" data-col="0" placeholder="ID"></th>
                            <th><input type="text" data-col="1" placeholder="Guest"></th>
                            <th><input type="text" data-col="2" placeholder="Appartamento"></th>
                            <th><input type="text" data-col="3" placeholder="Arrivo"></th>
                            <th><input type="text" data-col="4" placeholder="Partenza"></th>
                            <th><input type="text" data-col="5" placeholder="Stato"></th>
                            <th><input type="text" data-col="6" placeholder="Totale"></th>
                            <th><input type="text" data-col="7" placeholder="Acconto"></th>
                            <th><input type="text" data-col="8" placeholder="Ricevuto"></th>
                            <th><input type="text" data-col="9" placeholder="Saldo"></th>
                            <th><input type="text" data-col="10" placeholder="Incasso"></th>
                            <th><input type="text" data-col="11" placeholder="Rimborso"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $finance_money_attr = function ($value) {
                            return number_format((float) $value, 2, '.', '');
                        };
                        if ($finance_bookings) {
                            foreach ($finance_bookings as $b) {
                                if (!empty($b->group_id)) {
                                    if (isset($finance_rendered_groups[$b->group_id])) {
                                        continue;
                                    }
                                    $finance_rendered_groups[$b->group_id] = true;
                                    $finance_group_index++;
                                    $group_class = ($finance_group_index % 2 === 0) ? 'paguro-group-alt' : 'paguro-group-base';
                                    $children = $finance_grouped[$b->group_id];
                                    $first = $children[0];
                                    $last = $children[count($children) - 1];
                                    $apt_name = $apt_map[$first->apartment_id] ?? 'N/A';

                                    $group_status = 'Confermata';
                                    $group_status_class = 'paguro-badge--green';
                                    $group_row_status_class = 'paguro-row-status-confirmed';
                                    $group_dot_class = 'paguro-status-dot--confirmed';
                                    $group_total = 0;
                                    $group_deposit = 0;
                                    $group_received = 0;
                                    $group_remaining = 0;
                                    $group_forecast = 0;
                                    $group_refund = 0;
                                    $group_booking_ids = [];
                                    $children_finance = [];
                                    foreach ($children as $cb) {
                                        $total_child = function_exists('paguro_get_booking_total_cost') ? paguro_get_booking_total_cost($cb) : 0;
                                        $finance_child = $compute_finance_values($cb, $total_child);
                                        $group_total += $finance_child['total'];
                                        $group_deposit += $finance_child['deposit'];
                                        $group_received += $finance_child['deposit_received'];
                                        $group_remaining += $finance_child['remaining'];
                                        $group_forecast += $finance_child['forecast'];
                                        $group_refund += $finance_child['refund'];
                                        $group_booking_ids[] = intval($cb->id);
                                        $children_finance[intval($cb->id)] = $finance_child;
                                    }
                                    $sum_deposit_received += $group_received;
                                    $sum_remaining += $group_remaining;
                                    $sum_forecast += $group_forecast;
                                    $sum_refunds += $group_refund;

                                    $group_id_short = substr($b->group_id, 0, 8);
                        ?>
                                    <tr class="paguro-finance-group-row <?php echo esc_attr($group_class . ' ' . $group_row_status_class); ?>"
                                        data-finance-row="1"
                                        data-row-type="group"
                                        data-booking-id="<?php echo esc_attr(intval($first->id)); ?>"
                                        data-booking-ids="<?php echo esc_attr(implode(',', $group_booking_ids)); ?>"
                                        data-group="<?php echo esc_attr($b->group_id); ?>"
                                        data-group-id="<?php echo esc_attr($b->group_id); ?>"
                                        data-total="<?php echo esc_attr($finance_money_attr($group_total)); ?>"
                                        data-deposit="<?php echo esc_attr($finance_money_attr($group_deposit)); ?>"
                                        data-received="<?php echo esc_attr($finance_money_attr($group_received)); ?>"
                                        data-remaining="<?php echo esc_attr($finance_money_attr($group_remaining)); ?>"
                                        data-forecast="<?php echo esc_attr($finance_money_attr($group_forecast)); ?>"
                                        data-refund="<?php echo esc_attr($finance_money_attr($group_refund)); ?>">
                                        <td>
                                            <strong>GRP-<?php echo esc_html($group_id_short); ?></strong><br>
                                            <button type="button" class="button button-small paguro-finance-toggle" data-group="<?php echo esc_attr($b->group_id); ?>">Dettagli</button>
                                        </td>
                                        <td><?php echo esc_html($first->guest_name); ?><br><small><?php echo esc_html($first->guest_email); ?></small></td>
                                        <td><?php echo esc_html($apt_name); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($children[0]->date_start)); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($last->date_end)); ?></td>
                                        <td><span class="paguro-badge <?php echo esc_attr($group_status_class); ?>"><span class="paguro-status-dot <?php echo esc_attr($group_dot_class); ?>" aria-hidden="true"></span><?php echo esc_html($group_status); ?></span></td>
                                        <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="total">€ <?php echo number_format($group_total, 0, ',', '.'); ?></button></td>
                                        <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="deposit">€ <?php echo number_format($group_deposit, 0, ',', '.'); ?></button></td>
                                        <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="received">€ <?php echo number_format($group_received, 0, ',', '.'); ?></button></td>
                                        <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="remaining">€ <?php echo number_format($group_remaining, 0, ',', '.'); ?></button></td>
                                        <td><span class="paguro-finance-static" data-fin-field="forecast">€ <?php echo number_format($group_forecast, 0, ',', '.'); ?></span></td>
                                        <td><span class="paguro-finance-static" data-fin-field="refund"><?php echo $group_refund > 0 ? ('€ ' . number_format($group_refund, 0, ',', '.')) : '—'; ?></span></td>
                                    </tr>
                                    <?php
                                    foreach ($children as $cb) {
                                        $apt_child = $apt_map[$cb->apartment_id] ?? 'N/A';
                                        $status_label = 'Confermata';
                                        $status_class = 'paguro-badge--green';
                                        $row_status_class = 'paguro-row-status-confirmed';
                                        $dot_class = 'paguro-status-dot--confirmed';
                                        $child_fin = $children_finance[intval($cb->id)];
                                    ?>
                                        <tr class="paguro-finance-child <?php echo esc_attr($group_class . ' ' . $row_status_class); ?>"
                                            data-group="<?php echo esc_attr($b->group_id); ?>"
                                            data-finance-row="1"
                                            data-row-type="child"
                                            data-booking-id="<?php echo esc_attr(intval($cb->id)); ?>"
                                            data-booking-ids="<?php echo esc_attr(intval($cb->id)); ?>"
                                            data-group-id="<?php echo esc_attr($b->group_id); ?>"
                                            data-total="<?php echo esc_attr($finance_money_attr($child_fin['total'])); ?>"
                                            data-deposit="<?php echo esc_attr($finance_money_attr($child_fin['deposit'])); ?>"
                                            data-received="<?php echo esc_attr($finance_money_attr($child_fin['deposit_received'])); ?>"
                                            data-remaining="<?php echo esc_attr($finance_money_attr($child_fin['remaining'])); ?>"
                                            data-forecast="<?php echo esc_attr($finance_money_attr($child_fin['forecast'])); ?>"
                                            data-refund="<?php echo esc_attr($finance_money_attr($child_fin['refund'])); ?>"
                                            style="display:none;">
                                            <td>&nbsp;&nbsp;↳ <?php echo $cb->id; ?></td>
                                            <td><?php echo esc_html($cb->guest_name); ?><br><small><?php echo esc_html($cb->guest_email); ?></small></td>
                                            <td><?php echo esc_html($apt_child); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($cb->date_start)); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($cb->date_end)); ?></td>
                                            <td><span class="paguro-badge <?php echo esc_attr($status_class); ?>"><span class="paguro-status-dot <?php echo esc_attr($dot_class); ?>" aria-hidden="true"></span><?php echo esc_html($status_label); ?></span></td>
                                            <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="total">€ <?php echo number_format($child_fin['total'], 0, ',', '.'); ?></button></td>
                                            <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="deposit">€ <?php echo number_format($child_fin['deposit'], 0, ',', '.'); ?></button></td>
                                            <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="received">€ <?php echo number_format($child_fin['deposit_received'], 0, ',', '.'); ?></button></td>
                                            <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="remaining">€ <?php echo number_format($child_fin['remaining'], 0, ',', '.'); ?></button></td>
                                            <td><span class="paguro-finance-static" data-fin-field="forecast">€ <?php echo number_format($child_fin['forecast'], 0, ',', '.'); ?></span></td>
                                            <td><span class="paguro-finance-static" data-fin-field="refund"><?php echo $child_fin['refund'] > 0 ? ('€ ' . number_format($child_fin['refund'], 0, ',', '.')) : '—'; ?></span></td>
                                        </tr>
                                <?php
                                    }
                                    continue;
                                }

                                $apt_name = $apt_map[$b->apartment_id] ?? 'N/A';
                                $status_label = 'Confermata';
                                $status_class = 'paguro-badge--green';
                                $total_cost = function_exists('paguro_get_booking_total_cost') ? paguro_get_booking_total_cost($b) : 0;
                                $finance_single = $compute_finance_values($b, $total_cost);
                                $sum_deposit_received += $finance_single['deposit_received'];
                                $sum_remaining += $finance_single['remaining'];
                                $sum_forecast += $finance_single['forecast'];
                                $sum_refunds += $finance_single['refund'];
                                ?>
                                <tr data-finance-row="1"
                                    data-row-type="single"
                                    data-booking-id="<?php echo esc_attr(intval($b->id)); ?>"
                                    data-booking-ids="<?php echo esc_attr(intval($b->id)); ?>"
                                    data-group-id=""
                                    data-total="<?php echo esc_attr($finance_money_attr($finance_single['total'])); ?>"
                                    data-deposit="<?php echo esc_attr($finance_money_attr($finance_single['deposit'])); ?>"
                                    data-received="<?php echo esc_attr($finance_money_attr($finance_single['deposit_received'])); ?>"
                                    data-remaining="<?php echo esc_attr($finance_money_attr($finance_single['remaining'])); ?>"
                                    data-forecast="<?php echo esc_attr($finance_money_attr($finance_single['forecast'])); ?>"
                                    data-refund="<?php echo esc_attr($finance_money_attr($finance_single['refund'])); ?>">
                                    <td><?php echo $b->id; ?></td>
                                    <td><?php echo esc_html($b->guest_name); ?><br><small><?php echo esc_html($b->guest_email); ?></small></td>
                                    <td><?php echo esc_html($apt_name); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($b->date_start)); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($b->date_end)); ?></td>
                                    <td><span class="paguro-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                    <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="total">€ <?php echo number_format($finance_single['total'], 0, ',', '.'); ?></button></td>
                                    <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="deposit">€ <?php echo number_format($finance_single['deposit'], 0, ',', '.'); ?></button></td>
                                    <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="received">€ <?php echo number_format($finance_single['deposit_received'], 0, ',', '.'); ?></button></td>
                                    <td><button type="button" class="button-link paguro-finance-editable" data-fin-field="remaining">€ <?php echo number_format($finance_single['remaining'], 0, ',', '.'); ?></button></td>
                                    <td><span class="paguro-finance-static" data-fin-field="forecast">€ <?php echo number_format($finance_single['forecast'], 0, ',', '.'); ?></span></td>
                                    <td><span class="paguro-finance-static" data-fin-field="refund"><?php echo $finance_single['refund'] > 0 ? ('€ ' . number_format($finance_single['refund'], 0, ',', '.')) : '—'; ?></span></td>
                                </tr>
                        <?php
                            }
                        } else {
                            echo '<tr><td colspan="12" style="text-align:center; padding: 18px;">Nessun dato economico per la stagione.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <script>
                    (function() {
                        var depositPercent = <?php echo intval($deposit_percent); ?>;
                        var labels = {
                            total: 'Totale',
                            deposit: 'Acconto',
                            received: 'Acconto ricevuto',
                            remaining: 'Saldo previsto'
                        };
                        var toNumber = function(value) {
                            var num = parseFloat(value);
                            return Number.isFinite(num) ? num : 0;
                        };
                        var parseMoneyInput = function(raw) {
                            if (raw === null || typeof raw === 'undefined') return null;
                            var normalized = String(raw).trim();
                            if (!normalized) return null;
                            normalized = normalized.replace(/\s+/g, '').replace(/\./g, '').replace(',', '.');
                            var parsed = parseFloat(normalized);
                            return Number.isFinite(parsed) ? Math.max(0, parsed) : null;
                        };
                        var formatMoney = function(value) {
                            var amount = Math.round(toNumber(value));
                            return '€ ' + amount.toLocaleString('it-IT');
                        };
                        var setText = function(id, value) {
                            var el = document.getElementById(id);
                            if (el) el.textContent = formatMoney(value);
                        };
                        var getState = function(row) {
                            return {
                                total: toNumber(row.dataset.total),
                                deposit: toNumber(row.dataset.deposit),
                                received: toNumber(row.dataset.received),
                                remaining: toNumber(row.dataset.remaining),
                                forecast: toNumber(row.dataset.forecast),
                                refund: toNumber(row.dataset.refund)
                            };
                        };
                        var setState = function(row, state) {
                            row.dataset.total = (Math.round(state.total * 100) / 100).toFixed(2);
                            row.dataset.deposit = (Math.round(state.deposit * 100) / 100).toFixed(2);
                            row.dataset.received = (Math.round(state.received * 100) / 100).toFixed(2);
                            row.dataset.remaining = (Math.round(state.remaining * 100) / 100).toFixed(2);
                            row.dataset.forecast = (Math.round(state.forecast * 100) / 100).toFixed(2);
                            row.dataset.refund = (Math.round(state.refund * 100) / 100).toFixed(2);

                            row.querySelectorAll('[data-fin-field]').forEach(function(cell) {
                                var field = cell.getAttribute('data-fin-field');
                                if (!field) return;
                                if (field === 'refund' && state.refund <= 0) {
                                    cell.textContent = '—';
                                    return;
                                }
                                cell.textContent = formatMoney(state[field] || 0);
                            });
                        };
                        var recalcState = function(state, editedField) {
                            var total = Math.max(0, toNumber(state.total));
                            var deposit = Math.max(0, toNumber(state.deposit));
                            var received = Math.max(0, toNumber(state.received));
                            var remaining = Math.max(0, toNumber(state.remaining));
                            if (deposit > total) deposit = total;
                            if (received > total) received = total;
                            if (remaining > total) remaining = total;

                            if (editedField === 'total') {
                                deposit = total > 0 ? Math.ceil(total * (depositPercent / 100)) : 0;
                            } else if (editedField === 'deposit') {
                                deposit = Math.min(deposit, total);
                            } else if (editedField === 'received') {
                                received = Math.min(received, total);
                            } else if (editedField === 'remaining') {
                                remaining = Math.min(remaining, total);
                                received = Math.max(0, total - remaining);
                            }
                            remaining = Math.max(0, total - received);
                            return {
                                total: total,
                                deposit: deposit,
                                received: received,
                                remaining: remaining,
                                forecast: remaining,
                                refund: Math.max(0, toNumber(state.refund))
                            };
                        };
                        var distributeAmount = function(total, weights) {
                            var amount = Math.max(0, toNumber(total));
                            if (!weights.length) return [];
                            if (weights.length === 1) return [amount];
                            var sum = 0;
                            weights.forEach(function(w) {
                                sum += Math.max(0, toNumber(w));
                            });
                            if (sum <= 0) sum = weights.length;
                            var distributed = [];
                            var remaining = amount;
                            for (var i = 0; i < weights.length; i++) {
                                if (i === weights.length - 1) {
                                    distributed.push(Math.max(0, remaining));
                                    continue;
                                }
                                var w = Math.max(0, toNumber(weights[i]));
                                if (w <= 0 && sum === weights.length) w = 1;
                                var share = Math.round((amount * (w / sum)) * 100) / 100;
                                distributed.push(Math.max(0, share));
                                remaining -= share;
                            }
                            return distributed;
                        };
                        var refreshGroupFromChildren = function(groupId) {
                            if (!groupId) return;
                            var groupRow = document.querySelector('tr[data-row-type="group"][data-group="' + groupId + '"]');
                            if (!groupRow) return;
                            var children = Array.prototype.slice.call(document.querySelectorAll('tr[data-row-type="child"][data-group="' + groupId + '"]'));
                            if (!children.length) return;
                            var groupState = {
                                total: 0,
                                deposit: 0,
                                received: 0,
                                remaining: 0,
                                forecast: 0,
                                refund: 0
                            };
                            children.forEach(function(child) {
                                var s = getState(child);
                                groupState.total += s.total;
                                groupState.deposit += s.deposit;
                                groupState.received += s.received;
                                groupState.remaining += s.remaining;
                                groupState.forecast += s.forecast;
                                groupState.refund += s.refund;
                            });
                            setState(groupRow, groupState);
                        };
                        var applyGroupToChildren = function(groupRow) {
                            var groupId = groupRow.getAttribute('data-group');
                            if (!groupId) return;
                            var state = getState(groupRow);
                            var children = Array.prototype.slice.call(document.querySelectorAll('tr[data-row-type="child"][data-group="' + groupId + '"]'));
                            if (!children.length) return;
                            var totalWeights = children.map(function(child) {
                                return toNumber(child.dataset.total);
                            });
                            var totals = distributeAmount(state.total, totalWeights);
                            var deposits = distributeAmount(state.deposit, totals);
                            var receiveds = distributeAmount(state.received, deposits);
                            children.forEach(function(child, index) {
                                var childState = {
                                    total: totals[index] || 0,
                                    deposit: deposits[index] || 0,
                                    received: receiveds[index] || 0,
                                    remaining: 0,
                                    forecast: 0,
                                    refund: toNumber(child.dataset.refund)
                                };
                                childState = recalcState(childState, '');
                                setState(child, childState);
                            });
                        };
                        var updateSummary = function() {
                            var totals = {
                                received: 0,
                                remaining: 0,
                                forecast: 0,
                                refunds: 0
                            };
                            document.querySelectorAll('tr[data-finance-row="1"]').forEach(function(row) {
                                var type = row.getAttribute('data-row-type');
                                if (type !== 'group' && type !== 'single') return;
                                totals.received += toNumber(row.dataset.received);
                                totals.remaining += toNumber(row.dataset.remaining);
                                totals.forecast += toNumber(row.dataset.forecast);
                                totals.refunds += toNumber(row.dataset.refund);
                            });
                            setText('paguro-fin-deposit', totals.received);
                            setText('paguro-fin-remaining', totals.remaining);
                            setText('paguro-fin-forecast', totals.forecast);
                            setText('paguro-fin-refunds', totals.refunds);
                        };
                        var saveRow = function(row, editedField) {
                            if (!window.paguroAdminNonce) return;
                            var state = getState(row);
                            var bookingId = row.getAttribute('data-booking-id') || '';
                            if (!bookingId) return;
                            var payload = new FormData();
                            payload.append('paguro_action', 'update_finance_values');
                            payload.append('booking_id', bookingId);
                            payload.append('booking_ids', row.getAttribute('data-booking-ids') || bookingId);
                            payload.append('group_id', row.getAttribute('data-group-id') || '');
                            payload.append('finance_total', String(state.total));
                            payload.append('finance_deposit', String(state.deposit));
                            payload.append('finance_received', String(state.received));
                            payload.append('finance_remaining', String(state.remaining));
                            payload.append('edited_field', editedField || '');
                            payload.append('_wpnonce', window.paguroAdminNonce);
                            row.classList.add('is-saving');
                            fetch(window.location.href, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: payload
                            }).catch(function() {
                                row.classList.add('is-save-error');
                            }).finally(function() {
                                row.classList.remove('is-saving');
                            });
                        };
                        document.querySelectorAll('.paguro-finance-editable').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var field = btn.getAttribute('data-fin-field');
                                if (!field) return;
                                var row = btn.closest('tr[data-finance-row="1"]');
                                if (!row) return;
                                var state = getState(row);
                                var current = state[field] || 0;
                                var input = window.prompt('Nuovo valore per ' + (labels[field] || field) + ' (€)', String(Math.round(current)));
                                if (input === null) return;
                                var parsed = parseMoneyInput(input);
                                if (parsed === null) {
                                    window.alert('Valore non valido.');
                                    return;
                                }
                                state[field] = parsed;
                                state = recalcState(state, field);
                                setState(row, state);
                                if (row.getAttribute('data-row-type') === 'group') {
                                    applyGroupToChildren(row);
                                } else if (row.getAttribute('data-row-type') === 'child') {
                                    refreshGroupFromChildren(row.getAttribute('data-group'));
                                }
                                updateSummary();
                                saveRow(row, field);
                            });
                        });

                        document.querySelectorAll('.paguro-finance-toggle').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var group = btn.getAttribute('data-group');
                                if (!group) return;
                                document.querySelectorAll('.paguro-finance-child[data-group="' + group + '"]').forEach(function(row) {
                                    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
                                });
                            });
                        });
                        updateSummary();
                    })();
                </script>

            </details>

            <details class="paguro-details paguro-cancelled-details">
                <summary>Archivio Cancellati</summary>
                <?php
                $cancelled_limit = 200;
                $cancelled_bookings = $wpdb->get_results($wpdb->prepare(
                    "SELECT b.*, a.name AS apt_name
                     FROM {$wpdb->prefix}paguro_availability b
                     LEFT JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id
                     WHERE b.status = 3
                     ORDER BY b.created_at DESC
                     LIMIT %d",
                    $cancelled_limit
                ));
                $cancelled_bulk_seed_id = !empty($cancelled_bookings) ? intval($cancelled_bookings[0]->id) : 0;
                ?>
                <p class="description">I record cancellati restano archiviati qui. Da questa vista puoi eliminarli definitivamente (singolo o multiplo).</p>

                <form id="paguro-cancelled-bulk-form" method="POST" style="margin-bottom:10px;">
                    <?php wp_nonce_field('paguro_admin_action'); ?>
                    <input type="hidden" name="booking_id" value="<?php echo esc_attr($cancelled_bulk_seed_id); ?>">
                    <button type="submit" name="paguro_action" value="hard_delete_cancelled_bulk" class="button button-secondary paguro-admin-confirm" data-confirm="Eliminare definitivamente i record cancellati selezionati?">Elimina selezionati</button>
                </form>

                <table class="widefat striped paguro-cancelled-table">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" class="paguro-cancelled-check-all" aria-label="Seleziona tutti"></th>
                            <th width="80">ID</th>
                            <th>Guest</th>
                            <th>Appartamento</th>
                            <th>Arrivo</th>
                            <th>Partenza</th>
                            <th width="130">Creata</th>
                            <th width="60">Log</th>
                            <th width="210">Azioni</th>
                        </tr>
                        <tr class="paguro-table-filters" data-table-filter="cancelled">
                            <th></th>
                            <th><input type="text" data-col="1" placeholder="ID"></th>
                            <th><input type="text" data-col="2" placeholder="Guest"></th>
                            <th><input type="text" data-col="3" placeholder="Appartamento"></th>
                            <th><input type="text" data-col="4" placeholder="Arrivo"></th>
                            <th><input type="text" data-col="5" placeholder="Partenza"></th>
                            <th><input type="text" data-col="6" placeholder="Creata"></th>
                            <th>
                                <select data-col="7">
                                    <option value="">Tutti</option>
                                    <option value="🕒">Con log</option>
                                    <option value="—">Senza log</option>
                                </select>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($cancelled_bookings) {
                            foreach ($cancelled_bookings as $cb) {
                                $summary_link = !empty($cb->lock_token)
                                    ? site_url('/' . get_option('paguro_page_slug', 'riepilogo-prenotazione') . '/?token=' . rawurlencode($cb->lock_token))
                                    : '';
                        ?>
                                <tr class="paguro-row-status-cancelled">
                                    <td>
                                        <input type="checkbox" class="paguro-cancelled-check" form="paguro-cancelled-bulk-form" name="bulk_booking_ids[]" value="<?php echo esc_attr(intval($cb->id)); ?>">
                                    </td>
                                    <td><?php echo intval($cb->id); ?></td>
                                    <td>
                                        <?php echo esc_html($cb->guest_name); ?><br>
                                        <small><?php echo esc_html($cb->guest_email); ?></small>
                                        <?php if (!empty($cb->guest_phone)) { ?><br><small><?php echo esc_html($cb->guest_phone); ?></small><?php } ?>
                                    </td>
                                    <td><?php echo esc_html($cb->apt_name ?? 'N/A'); ?></td>
                                    <td><?php echo !empty($cb->date_start) ? esc_html(date('d/m/Y', strtotime($cb->date_start))) : '—'; ?></td>
                                    <td><?php echo !empty($cb->date_end) ? esc_html(date('d/m/Y', strtotime($cb->date_end))) : '—'; ?></td>
                                    <td><?php echo !empty($cb->created_at) ? esc_html(date('d/m/Y H:i', strtotime($cb->created_at))) : '—'; ?></td>
                                    <td>
                                        <?php if (!empty($cb->history_log)) { ?>
                                            <a href="#" class="button button-small paguro-history-toggle" data-history="<?php echo esc_attr(base64_encode($cb->history_log)); ?>" title="Mostra log" onclick="return paguroAdminShowLog(this);">🕒</a>
                                        <?php } else { ?>
                                            <span style="color:#999;">—</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <?php wp_nonce_field('paguro_admin_action'); ?>
                                            <input type="hidden" name="booking_id" value="<?php echo esc_attr(intval($cb->id)); ?>">
                                            <button type="submit" name="paguro_action" value="hard_delete_booking" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Eliminare definitivamente questo record cancellato?">Elimina definitivamente</button>
                                        </form>
                                        <?php if ($summary_link !== '') { ?>
                                            <a href="<?php echo esc_url($summary_link); ?>" class="button button-small">Riepilogo</a>
                                        <?php } ?>
                                    </td>
                                </tr>
                        <?php
                            }
                        } else {
                            echo '<tr><td colspan="9" style="text-align:center; padding: 18px;">Nessun record cancellato in archivio.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </details>
        <?php } ?>
        <!-- TAB: APPARTAMENTI -->

        <?php if ($current_tab === 'apartments') { ?>
            <h2>Gestione Appartamenti</h2>
            <?php
            $edit_apt_id = isset($_GET['edit_apt']) ? intval($_GET['edit_apt']) : 0;
            $edit_apt = $edit_apt_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_apt WHERE id=%d", $edit_apt_id)) : null;
            $apt_cancel_url = add_query_arg('tab', 'apartments', admin_url('admin.php?page=paguro-booking'));
            $season_start = get_option('paguro_season_start', '2026-06-01');
            $season_end = get_option('paguro_season_end', '2026-09-30');
            $season_start_dt = new DateTime($season_start);
            $season_end_dt = new DateTime($season_end);
            if ($season_start_dt->format('N') != 6) {
                $season_start_dt->modify('next saturday');
            }
            $edit_prices = [];
            if ($edit_apt && !empty($edit_apt->pricing_json)) {
                $decoded = json_decode($edit_apt->pricing_json, true);
                if (is_array($decoded)) {
                    $edit_prices = $decoded;
                }
            }
            $all_apts = $wpdb->get_results("SELECT * FROM $table_apt ORDER BY name");
            $apt_pricing_map = [];
            foreach ($all_apts as $apt_item) {
                $decoded = $apt_item->pricing_json ? json_decode($apt_item->pricing_json, true) : [];
                if (!is_array($decoded)) {
                    $decoded = [];
                }
                $apt_pricing_map[$apt_item->id] = [
                    'base_price' => floatval($apt_item->base_price),
                    'pricing' => $decoded
                ];
            }
            $month_names = [
                '01' => 'Gennaio',
                '02' => 'Febbraio',
                '03' => 'Marzo',
                '04' => 'Aprile',
                '05' => 'Maggio',
                '06' => 'Giugno',
                '07' => 'Luglio',
                '08' => 'Agosto',
                '09' => 'Settembre',
                '10' => 'Ottobre',
                '11' => 'Novembre',
                '12' => 'Dicembre'
            ];
            ?>

            <?php if ($edit_apt) { ?>
                <div class="card">
                    <h3>✏️ Modifica Appartamento</h3>
                    <form method="POST" style="max-width: 900px;">
                        <?php wp_nonce_field('paguro_apt_nonce'); ?>
                        <input type="hidden" name="apt_id" value="<?php echo intval($edit_apt->id); ?>">
                        <table class="form-table">
                            <tr>
                                <th>Nome</th>
                                <td><input type="text" name="apt_name" value="<?php echo esc_attr($edit_apt->name); ?>" required></td>
                            </tr>
                            <tr>
                                <th>Link pagina</th>
                                <td><input type="url" name="apt_url" value="<?php echo esc_attr($edit_apt->apt_url ?? ''); ?>" style="width:100%; max-width:500px;" placeholder="https://www.example.com/appartamento"></td>
                            </tr>
                            <tr>
                                <th>Prezzo Base (€/settimana)</th>
                                <td><input type="number" name="apt_price" step="1" min="0" max="99999" inputmode="numeric" value="<?php echo esc_attr($edit_apt->base_price); ?>" required></td>
                            </tr>
                        </table>
                        <div class="paguro-pricing-tools" style="margin: 10px 0 18px;">
                            <div style="margin-bottom:10px;">
                                <strong>Copia prezzo per mese</strong><br>
                                <label for="paguro-copy-month" class="screen-reader-text">Mese</label>
                                <select id="paguro-copy-month" style="margin-right:6px;">
                                    <?php foreach ($month_names as $m_key => $m_label) { ?>
                                        <option value="<?php echo esc_attr($m_key); ?>"><?php echo esc_html($m_label); ?></option>
                                    <?php } ?>
                                </select>
                                <label for="paguro-copy-month-price" class="screen-reader-text">Prezzo</label>
                                <input type="number" id="paguro-copy-month-price" step="1" min="0" max="99999" inputmode="numeric" placeholder="Prezzo €" style="max-width:140px; margin-right:6px;">
                                <button type="button" class="button" id="paguro-copy-month-btn">Copia mese</button>
                            </div>
                            <div>
                                <strong>Copia prezzi dall'altro appartamento</strong><br>
                                <label for="paguro-copy-apt" class="screen-reader-text">Appartamento</label>
                                <select id="paguro-copy-apt" style="margin-right:6px;">
                                    <option value="">Seleziona...</option>
                                    <?php foreach ($all_apts as $apt_item) {
                                        if ($apt_item->id == $edit_apt->id) continue;
                                    ?>
                                        <option value="<?php echo esc_attr($apt_item->id); ?>"><?php echo esc_html($apt_item->name); ?></option>
                                    <?php } ?>
                                </select>
                                <label style="margin-right:6px;">
                                    <input type="checkbox" id="paguro-copy-apt-base" checked> Copia anche prezzo base
                                </label>
                                <button type="button" class="button" id="paguro-copy-apt-btn">Copia appartamento</button>
                            </div>
                        </div>
                        <h4>Prezzi settimanali (sab-sab)</h4>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th class="paguro-week-cell">Settimana</th>
                                    <th>Prezzo (€)</th>
                                    <th class="paguro-season-cell">Stagione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $cur = clone $season_start_dt;
                                while ($cur < $season_end_dt) {
                                    $week_end = (clone $cur)->modify('+1 week');
                                    if ($week_end > $season_end_dt) break;
                                    $week_key = $cur->format('Y-m-d');
                                    $label = $cur->format('d/m/Y') . ' - ' . $week_end->format('d/m/Y');
                                    $price_value = '';
                                    $season_value = '';
                                    if (isset($edit_prices[$week_key])) {
                                        $entry = $edit_prices[$week_key];
                                        if (is_array($entry)) {
                                            $price_value = $entry['price'] ?? '';
                                            $season_value = $entry['season'] ?? '';
                                        } else {
                                            $price_value = $entry;
                                        }
                                    }
                                    if (function_exists('paguro_normalize_season_code')) {
                                        $season_value = paguro_normalize_season_code($season_value);
                                    } else {
                                        $season_value = strtoupper((string) $season_value);
                                    }
                                ?>
                                    <tr>
                                        <td class="paguro-week-cell"><?php echo esc_html($label); ?></td>
                                        <td>
                                            <input type="number" name="pricing_week[<?php echo esc_attr($week_key); ?>]" step="1" min="0" max="99999" inputmode="numeric" value="<?php echo esc_attr($price_value); ?>" style="max-width: 160px;">
                                        </td>
                                        <td class="paguro-season-cell">
                                            <input type="hidden" name="pricing_season[<?php echo esc_attr($week_key); ?>]" value="<?php echo esc_attr($season_value); ?>">
                                            <label style="margin-right:8px;">
                                                <input type="checkbox" class="paguro-season-toggle" data-season="B" name="pricing_season_choice[<?php echo esc_attr($week_key); ?>][]" value="B" <?php checked($season_value, 'B'); ?>> B
                                            </label>
                                            <label style="margin-right:8px;">
                                                <input type="checkbox" class="paguro-season-toggle" data-season="M" name="pricing_season_choice[<?php echo esc_attr($week_key); ?>][]" value="M" <?php checked($season_value, 'M'); ?>> M
                                            </label>
                                            <label>
                                                <input type="checkbox" class="paguro-season-toggle" data-season="A" name="pricing_season_choice[<?php echo esc_attr($week_key); ?>][]" value="A" <?php checked($season_value, 'A'); ?>> A
                                            </label>
                                        </td>
                                    </tr>
                                <?php
                                    $cur->modify('+1 week');
                                }
                                ?>
                            </tbody>
                        </table>
                        <p class="description">Lascia vuoto per usare il prezzo base settimanale.</p>
                        <p>
                            <button type="submit" name="paguro_apt_action" value="update_apt" class="button button-primary">Aggiorna</button>
                            <a href="<?php echo esc_url($apt_cancel_url); ?>" class="button">Annulla</a>
                        </p>
                    </form>
                </div>
            <?php } ?>

            <div class="card">
                <h3>➕ Aggiungi Appartamento</h3>
                <form method="POST" style="max-width: 500px;">
                    <?php wp_nonce_field('paguro_apt_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Nome</th>
                            <td><input type="text" name="apt_name" required></td>
                        </tr>
                        <tr>
                            <th>Link pagina</th>
                            <td><input type="url" name="apt_url" style="width:100%; max-width:500px;" placeholder="https://www.example.com/appartamento"></td>
                        </tr>
                        <tr>
                            <th>Prezzo Base (€/settimana)</th>
                            <td><input type="number" name="apt_price" step="1" min="0" max="99999" inputmode="numeric" required></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="paguro_apt_action" value="add_apt" class="button button-primary">Aggiungi</button>
                    </p>
                </form>
            </div>

            <h3>Appartamenti Registrati</h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Link pagina</th>
                        <th>Shortcode Chat</th>
                        <th>Prezzo Base (€/settimana)</th>
                        <th width="150">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($all_apts as $apt) {
                    ?>
                        <tr>
                            <td><?php echo $apt->id; ?></td>
                            <td><?php echo esc_html($apt->name); ?></td>
                            <td>
                                <?php if (!empty($apt->apt_url)) { ?>
                                    <a href="<?php echo esc_url($apt->apt_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($apt->apt_url); ?></a>
                                <?php } else { ?>
                                    <span style="color:#999;">—</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php $apt_slug = sanitize_title((string) ($apt->name ?? '')); ?>
                                <code>[paguro_chat apt_slug="<?php echo esc_attr($apt_slug); ?>"]</code><br>
                                <small>ID fallback: <code>[paguro_chat apt_id="<?php echo esc_attr(intval($apt->id)); ?>"]</code></small>
                            </td>
                            <td>€<?php echo number_format($apt->base_price, 0); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <?php wp_nonce_field('paguro_apt_nonce'); ?>
                                    <input type="hidden" name="apt_id" value="<?php echo $apt->id; ?>">
                                    <a href="<?php echo esc_url(add_query_arg(['tab' => 'apartments', 'edit_apt' => $apt->id])); ?>" class="button button-small">Modifica</a>
                                    <button type="submit" name="paguro_apt_action" value="delete_apt" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Eliminare?">Elimina</button>
                                </form>
                            </td>
                        </tr>
                    <?php
                    }
                    ?>
                </tbody>
            </table>

            <?php if ($edit_apt) { ?>
                <script>
                    window.paguroAptPricing = <?php echo wp_json_encode($apt_pricing_map); ?>;
                    document.addEventListener('DOMContentLoaded', function() {
                        var monthBtn = document.getElementById('paguro-copy-month-btn');
                        var monthSelect = document.getElementById('paguro-copy-month');
                        var monthPrice = document.getElementById('paguro-copy-month-price');
                        var aptBtn = document.getElementById('paguro-copy-apt-btn');
                        var aptSelect = document.getElementById('paguro-copy-apt');
                        var aptBase = document.getElementById('paguro-copy-apt-base');
                        var baseInput = document.querySelector('input[name="apt_price"]');

                        function getWeekInputs() {
                            return Array.prototype.slice.call(document.querySelectorAll('input[name^="pricing_week["]'));
                        }

                        function setSeasonForRow(row, season) {
                            if (!row) return;
                            var hidden = row.querySelector('input[name^="pricing_season["]');
                            if (!hidden) return;
                            var normalized = season ? String(season).toUpperCase() : '';
                            hidden.value = normalized;
                            row.querySelectorAll('.paguro-season-toggle').forEach(function(cb) {
                                cb.checked = (cb.getAttribute('data-season') === normalized);
                            });
                        }

                        function setSeasonForDate(date, season) {
                            var priceInput = document.querySelector('input[name="pricing_week[' + date + ']"]');
                            if (!priceInput) return;
                            setSeasonForRow(priceInput.closest('tr'), season);
                        }

                        document.querySelectorAll('.paguro-season-toggle').forEach(function(cb) {
                            cb.addEventListener('change', function() {
                                var row = cb.closest('tr');
                                if (!row) return;
                                var hidden = row.querySelector('input[name^="pricing_season["]');
                                if (!hidden) return;
                                if (cb.checked) {
                                    row.querySelectorAll('.paguro-season-toggle').forEach(function(other) {
                                        if (other !== cb) other.checked = false;
                                    });
                                    hidden.value = cb.getAttribute('data-season') || '';
                                } else {
                                    hidden.value = '';
                                }
                            });
                        });

                        if (monthBtn) {
                            monthBtn.addEventListener('click', function() {
                                var month = monthSelect ? monthSelect.value : '';
                                var price = monthPrice ? monthPrice.value : '';
                                if (!month || !price) {
                                    alert('Seleziona un mese e inserisci un prezzo.');
                                    return;
                                }
                                getWeekInputs().forEach(function(input) {
                                    var match = input.name.match(/pricing_week\[(\d{4}-\d{2}-\d{2})\]/);
                                    if (!match) return;
                                    var date = match[1];
                                    if (date.substring(5, 7) === month) {
                                        input.value = price;
                                    }
                                });
                            });
                        }

                        if (aptBtn) {
                            aptBtn.addEventListener('click', function() {
                                var aptId = aptSelect ? aptSelect.value : '';
                                if (aptId) aptId = String(aptId).trim();
                                if (!aptId) {
                                    alert('Seleziona un appartamento da cui copiare i prezzi.');
                                    return;
                                }
                                var data = window.paguroAptPricing ? window.paguroAptPricing[aptId] : null;
                                if (!data && window.paguroAptPricing) {
                                    var aptInt = parseInt(aptId, 10);
                                    if (!isNaN(aptInt)) {
                                        data = window.paguroAptPricing[aptInt];
                                    }
                                }
                                if (!data) {
                                    alert('Prezzi non disponibili per questo appartamento.');
                                    return;
                                }
                                var pricing = data.pricing || {};
                                getWeekInputs().forEach(function(input) {
                                    var match = input.name.match(/pricing_week\[(\d{4}-\d{2}-\d{2})\]/);
                                    if (!match) return;
                                    var date = match[1];
                                    var entry = pricing.hasOwnProperty(date) ? pricing[date] : '';
                                    if (entry && typeof entry === 'object') {
                                        input.value = entry.price !== undefined && entry.price !== null ? entry.price : '';
                                        setSeasonForRow(input.closest('tr'), entry.season || '');
                                    } else {
                                        input.value = entry || '';
                                        setSeasonForRow(input.closest('tr'), '');
                                    }
                                });
                                if (aptBase && aptBase.checked && baseInput) {
                                    baseInput.value = data.base_price || '';
                                }
                            });
                        }
                    });
                </script>
            <?php } ?>



        <?php } ?>

        <!-- TAB: EMAIL TEMPLATES -->
        <?php if ($current_tab === 'emails') { ?>
            <h2>Email Templates</h2>

            <div class="card paguro-full-width-card" id="paguro-email-templates">
                <?php
                $default_cancel_req_body_old = 'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua richiesta di cancellazione per {apt_name} ({date_start} - {date_end}).<br>La richiesta è in verifica dal nostro staff.<br><br>IBAN per rimborso: {customer_iban_priv}<br>Se applicabile, il termine di cancellazione era {cancel_deadline}.';
                $default_cancel_req_body = 'Ciao {guest_name},<br><br>' .
                    'Abbiamo ricevuto la tua richiesta di cancellazione.<br><br>' .
                    '<strong>Appartamento:</strong> {apt_name}<br>' .
                    '<strong>Date soggiorno:</strong> {date_start} - {date_end}<br><br>' .
                    'La richiesta è in verifica dal nostro staff.<br><br>' .
                    '<strong>IBAN per rimborso:</strong> {customer_iban_priv}<br>' .
                    'Il rimborso, se confermato, verrà disposto su questo IBAN.<br>' .
                    'Non possiamo essere responsabili di eventuali errori nell\'IBAN comunicato.<br><br>' .
                    'Cancellazione applicabile fino al {cancel_deadline}.';

                $default_refund_subj = 'Bonifico disposto - {apt_name}';
                $default_refund_body = 'Ciao {guest_name},<br><br>' .
                    'Ti confermiamo che il bonifico di rimborso è stato disposto sul seguente IBAN:<br>' .
                    '<strong>{customer_iban_priv}</strong><br><br>' .
                    'I tempi di accredito possono variare in base alla tua banca.<br><br>' .
                    'Grazie comunque per averci contattato e per la fiducia riposta.<br>' .
                    'Speriamo di poterti ospitare in futuro.';

                $default_cancel_subj = 'Cancellazione Confermata';
                $default_cancel_body = 'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata cancellata.<br>Se applicabile, l\'acconto sarà rimborsato secondo le nostre condizioni.';
                $default_cancel_adm_subj = 'Cancellazione: {apt_name}';
                $default_cancel_adm_body = 'Cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end})';

                $current_cancel_req_body = get_option('paguro_txt_email_cancel_req_body', '');
                if ($current_cancel_req_body === '' || $current_cancel_req_body === $default_cancel_req_body_old) {
                    update_option('paguro_txt_email_cancel_req_body', $default_cancel_req_body);
                }
                if (get_option('paguro_msg_email_refund_subj', '') === '') {
                    update_option('paguro_msg_email_refund_subj', $default_refund_subj);
                }
                if (get_option('paguro_msg_email_refund_body', '') === '') {
                    update_option('paguro_msg_email_refund_body', $default_refund_body);
                }
                if (get_option('paguro_msg_email_cancel_subj', '') === '') {
                    update_option('paguro_msg_email_cancel_subj', $default_cancel_subj);
                }
                if (get_option('paguro_msg_email_cancel_body', '') === '') {
                    update_option('paguro_msg_email_cancel_body', $default_cancel_body);
                }
                if (get_option('paguro_msg_email_adm_cancel_subj', '') === '') {
                    update_option('paguro_msg_email_adm_cancel_subj', $default_cancel_adm_subj);
                }
                if (get_option('paguro_msg_email_adm_cancel_body', '') === '') {
                    update_option('paguro_msg_email_adm_cancel_body', $default_cancel_adm_body);
                }
                ?>

                <p style="color: #666;">Personalizza i modelli email. Variabili disponibili: <code>{guest_name}</code>, <code>{guest_email}</code>, <code>{guest_phone}</code>, <code>{customer_iban}</code>, <code>{customer_iban_priv}</code>, <code>{apt_name}</code>, <code>{apt_link}</code>, <code>{apt_link_html}</code>, <code>{date_start}</code>, <code>{date_end}</code>, <code>{total_cost}</code>, <code>{deposit_cost}</code>, <code>{remaining_cost}</code>, <code>{deposit_percent}</code>, <code>{iban}</code>, <code>{intestatario}</code>, <code>{link_riepilogo}</code>, <code>{cancel_deadline}</code>, <code>{manual_discount}</code>, <code>{manual_discount_fmt}</code>, <code>{manual_discount_raw}</code>, <code>{admin_note}</code>, <code>{admin_note_block}</code></p>

                <form method="POST">
                    <?php wp_nonce_field('paguro_email_opts'); ?>

                    <h3>Email: Richiesta Quotazione (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-REQ</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_req_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_request_subj', 'La tua Quotazione - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_request_body', 'Caro/a {guest_name},<br><br>Abbiamo ricevuto la tua richiesta di quotazione per {apt_name}.<br>Date: {date_start} - {date_end}<br><br><a href="{link_riepilogo}" class="button">Accedi alla tua prenotazione</a>'),
                                    'email_req_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Ricevimento Distinta (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-REC</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_rec_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_receipt_subj', 'Ricevimento Distinta Bonifico - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_receipt_body', 'Caro/a {guest_name},\n\nAbbiamo ricevuto la distinta del bonifico per {apt_name}.\n\nImporto: €{deposit_cost}\n\nGrazie per la prenotazione!'),
                                    'email_rec_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Prenotazione Confermata (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-CONF</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_conf_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_confirm_subj', 'Prenotazione Confermata! - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_confirm_body', 'Caro/a {guest_name},\n\nLa tua prenotazione per {apt_name} è confermata!\n\nArrivo: {date_start}\nPartenza: {date_end}\nTotale: €{total_cost}\n\nTi aspettiamo!'),
                                    'email_conf_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Richiesta Cancellazione (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-REQ</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_req_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_cancel_req_subj', 'Richiesta cancellazione ricevuta - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_cancel_req_body', 'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua richiesta di cancellazione per {apt_name} ({date_start} - {date_end}).<br>La richiesta è in verifica dal nostro staff.<br><br>Se applicabile, il termine di cancellazione era {cancel_deadline}.'),
                                    'email_cancel_req_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Richiesta Cancellazione (Admin) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-REQ-ADM</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_req_adm_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_cancel_req_adm_subj', 'Richiesta cancellazione - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_cancel_req_adm_body', 'Richiesta cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end}).'),
                                    'email_cancel_req_adm_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Cancellazione Confermata (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-CONF</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_subj" value="<?php echo esc_attr(get_option('paguro_msg_email_cancel_subj', 'Cancellazione Confermata')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_email_cancel_body', 'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata cancellata.<br>Se applicabile, l\'acconto sarà rimborsato secondo le nostre condizioni.'),
                                    'email_cancel_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Cancellazione Confermata (Admin) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-CONF-ADM</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_adm_subj" value="<?php echo esc_attr(get_option('paguro_msg_email_adm_cancel_subj', 'Cancellazione: {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_email_adm_cancel_body', 'Cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end})'),
                                    'email_cancel_adm_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Bonifico Disposto (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-REFUND</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_refund_subj" value="<?php echo esc_attr(get_option('paguro_msg_email_refund_subj', 'Rimborso disposto - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_email_refund_body', 'Gentile {guest_name},<br><br>Il rimborso è stato disposto su questo IBAN:<br><strong>{customer_iban_priv}</strong><br><br>I tempi di accredito dipendono dalla banca.<br><br>Grazie per averci contattato.'),
                                    'email_refund_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Lista d'Attesa (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-WAITLIST</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_waitlist_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_waitlist_subj', 'Lista d\'attesa {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_waitlist_body', 'Gentile {guest_name},<br><br>La tua richiesta per {apt_name} ({date_start} - {date_end}) è in lista d\'attesa.<br>Ti avviseremo appena si libera.<br><br><a href="{link_riepilogo}" class="button">APRI RIEPILOGO</a>'),
                                    'email_waitlist_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Lista d'Attesa (Admin) <span class="paguro-badge paguro-badge--gray">EMAIL-WAITLIST-ADM</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_waitlist_adm_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_waitlist_adm_subj', 'Nuova lista d\'attesa: {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_waitlist_adm_body', 'Nuova iscrizione: {guest_name} ({guest_email}) | {apt_name} {date_start} - {date_end}.'),
                                    'email_waitlist_adm_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Avviso Disponibilità Waitlist (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-WAITLIST-ALERT</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_waitlist_alert_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_waitlist_alert_subj', 'Disponibilità {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_waitlist_alert_body', 'Gentile {guest_name},<br><br>Si è liberato {apt_name} per {date_start} - {date_end}.<br>Se sei interessato puoi prenotare ora.<br><br><a href="{booking_url}" class="button">PRENOTA ORA</a>'),
                                    'email_waitlist_alert_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="paguro_save_emails" class="button button-primary">Salva Email Templates</button>
                    </p>
                </form>
            </div>

        <?php } ?>

        <!-- TAB: WEB TEMPLATES -->
        <?php if ($current_tab === 'web_templates') { ?>
            <h2>Web Templates</h2>

            <div class="card paguro-full-width-card" id="paguro-web-templates">
                <p style="color: #666;">Messaggi e contenuti visibili all'utente nel browser.</p>

                <form method="POST">
                    <?php wp_nonce_field('paguro_web_templates'); ?>

                    <h3>Chat e Form</h3>
                    <table class="form-table">
                        <tr>
                            <th>Social Pressure</th>
                            <td>
                                <textarea name="ui_social_pressure" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_social_pressure', '')); ?></textarea>
                                <p class="description">Usa <code>{count}</code> per il numero di richieste attive.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Privacy Notice</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_privacy_notice', ''),
                                    'ui_privacy_notice',
                                    ['textarea_rows' => 4]
                                );
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Bottone Chat (singola settimana)</th>
                            <td>
                                <input type="text" name="ui_btn_book" value="<?php echo esc_attr(get_option('paguro_js_btn_book', '[Richiedi solo questa settimana]')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Istruzione Upload</th>
                            <td>
                                <input type="text" name="ui_upload_instruction" value="<?php echo esc_attr(get_option('paguro_msg_ui_upload_instruction', 'Carica la distinta per bloccare le date.')); ?>" style="width:100%; max-width: 600px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Riepilogo Prenotazione</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contenuto Riepilogo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_summary_page', '<div>...</div>'),
                                    'ui_summary_page',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Puoi usare i placeholder del riepilogo (vedi DEPLOYMENT_GUIDE.md).</p>
                                <details style="margin-top:10px;">
                                    <summary>Placeholder disponibili</summary>
                                    <div style="margin-top:10px;">
                                        <code>{guest_name}</code> <code>{guest_email}</code> <code>{guest_phone}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{apt_name}</code> <code>{apt_link}</code>
                                        <code>{date_start}</code> <code>{date_end}</code> <code>{date_start_raw}</code> <code>{date_end_raw}</code>
                                        <code>{total_cost}</code> <code>{deposit_cost}</code> <code>{remaining_cost}</code> <code>{manual_discount}</code> <code>{manual_discount_fmt}</code> <code>{manual_discount_raw}</code>
                                        <code>{total_cost_fmt}</code> <code>{deposit_cost_fmt}</code> <code>{remaining_cost_fmt}</code>
                                        <code>{total_cost_raw}</code> <code>{deposit_cost_raw}</code> <code>{remaining_cost_raw}</code>
                                        <code>{deposit_percent}</code> <code>{iban}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{intestatario}</code>
                                        <code>{receipt_url}</code> <code>{receipt_uploaded_at}</code> <code>{receipt_uploaded_at_fmt}</code>
                                        <code>{booking_id}</code> <code>{apartment_id}</code> <code>{status}</code> <code>{token}</code>
                                        <code>{created_at}</code> <code>{created_at_fmt}</code> <code>{lock_expires}</code>
                                        <code>{link_riepilogo}</code> <code>{booking_url}</code> <code>{apt_link}</code>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    </table>

                    <?php
                    $summary_confirm_default = '<p><strong>Prenotazione confermata.</strong></p>' .
                        '<p>{apt_name} | {date_start} - {date_end}</p>' .
                        '<p>Riceverai una email con il riepilogo del preventivo e le istruzioni per accedere all\'area personale.</p>';
                    $summary_confirm_value = get_option('paguro_msg_ui_summary_confirm_page', '');
                    if ($summary_confirm_value === '') {
                        $summary_confirm_value = $summary_confirm_default;
                        update_option('paguro_msg_ui_summary_confirm_page', $summary_confirm_value);
                    }
                    ?>

                    <h3>Riepilogo Prenotazione Confermata</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contenuto Riepilogo (Confermata)</th>
                            <td>
                                <?php
                                wp_editor(
                                    $summary_confirm_value,
                                    'ui_summary_confirm_page',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Usato solo per prenotazioni con stato Confermata. Se vuoto, usa il riepilogo standard.</p>
                                <details style="margin-top:10px;">
                                    <summary>Placeholder disponibili</summary>
                                    <div style="margin-top:10px;">
                                        <code>{guest_name}</code> <code>{guest_email}</code> <code>{guest_phone}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{apt_name}</code> <code>{apt_link}</code>
                                        <code>{date_start}</code> <code>{date_end}</code> <code>{date_start_raw}</code> <code>{date_end_raw}</code>
                                        <code>{total_cost}</code> <code>{deposit_cost}</code> <code>{remaining_cost}</code> <code>{manual_discount}</code> <code>{manual_discount_fmt}</code> <code>{manual_discount_raw}</code>
                                        <code>{total_cost_fmt}</code> <code>{deposit_cost_fmt}</code> <code>{remaining_cost_fmt}</code>
                                        <code>{total_cost_raw}</code> <code>{deposit_cost_raw}</code> <code>{remaining_cost_raw}</code>
                                        <code>{deposit_percent}</code> <code>{iban}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{intestatario}</code>
                                        <code>{receipt_url}</code> <code>{receipt_uploaded_at}</code> <code>{receipt_uploaded_at_fmt}</code>
                                        <code>{booking_id}</code> <code>{apartment_id}</code> <code>{status}</code> <code>{token}</code>
                                        <code>{created_at}</code> <code>{created_at_fmt}</code> <code>{lock_expires}</code>
                                        <code>{link_riepilogo}</code> <code>{booking_url}</code> <code>{apt_link}</code>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Header e Login</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Riepilogo Preventivo</th>
                            <td>
                                <input type="text" name="ui_summary_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_summary_title', 'Riepilogo preventivo')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Riepilogo Lista d'Attesa</th>
                            <td>
                                <input type="text" name="ui_waitlist_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_title', "Riepilogo lista d'attesa")); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Token Mancante</th>
                            <td>
                                <input type="text" name="ui_missing_token" value="<?php echo esc_attr(get_option('paguro_msg_ui_missing_token', 'Codice di accesso mancante.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Login</th>
                            <td>
                                <input type="text" name="ui_auth_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_title', 'Area riservata')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Login (Preventivo)</th>
                            <td>
                                <textarea name="ui_auth_help_quote" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_auth_help_quote', "Inserisci l'email usata per la richiesta. Ti abbiamo inviato una email con il riepilogo del preventivo.")); ?></textarea>
                            </td>
                        </tr>

                        <tr>
                            <th>Messaggio Riepilogo Web</th>
                            <td>
                                <textarea name="ui_summary_email_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_summary_email_notice', 'Riceverai una email con il riepilogo del preventivo. Da questa area puoi aprire la sezione operativa.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone Area Operativa</th>
                            <td>
                                <input type="text" name="ui_secure_area_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_secure_area_cta', 'Visualizza dati beneficiario e upload distinta')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Intro Area Operativa</th>
                            <td>
                                <textarea name="ui_secure_area_intro" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_secure_area_intro', 'Per completare la procedura apri la sezione operativa qui sotto.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Login (Lista d'Attesa)</th>
                            <td>
                                <textarea name="ui_auth_help_waitlist" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_auth_help_waitlist', "Inserisci l'email usata per la lista d'attesa.")); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Label Email Login</th>
                            <td>
                                <input type="text" name="ui_auth_email_label" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_email_label', 'Email *')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone Login</th>
                            <td>
                                <input type="text" name="ui_auth_submit" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_submit', 'Accedi')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Errore Login</th>
                            <td>
                                <input type="text" name="ui_auth_error" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_error', 'Email non trovata.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Richiesta Preventivo (Form)</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Form Preventivo</th>
                            <td>
                                <input type="text" name="ui_quote_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_quote_title', 'Richiesta preventivo {apt_name}')); ?>" style="width:100%; max-width: 800px;">
                                <p class="description">Usa <code>{apt_name}</code> per il nome appartamento.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Sottotitolo Form Preventivo</th>
                            <td>
                                <input type="text" name="ui_quote_subtitle" value="<?php echo esc_attr(get_option('paguro_msg_ui_quote_subtitle', 'Compila i dati per ricevere il preventivo.')); ?>" style="width:100%; max-width: 800px;">
                                <p class="description">Se vuoto, non viene mostrato.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Nota sotto "Nome Completo"</th>
                            <td>
                                <input type="text" name="ui_quote_name_help" value="<?php echo esc_attr(get_option('paguro_msg_ui_quote_name_help', 'Intestatario IBAN')); ?>" style="width:100%; max-width: 800px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Alert e Stati</h3>
                    <table class="form-table">
                        <tr>
                            <th>Prenotazione Non Trovata</th>
                            <td>
                                <input type="text" name="ui_booking_not_found" value="<?php echo esc_attr(get_option('paguro_msg_ui_booking_not_found', 'Prenotazione non trovata.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Lista d'Attesa Non Trovata</th>
                            <td>
                                <input type="text" name="ui_waitlist_not_found" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_not_found', "Iscrizione non trovata.")); ?>" style="width:100%; max-width: 800px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Lista d'Attesa Annullata</th>
                            <td>
                                <input type="text" name="ui_waitlist_cancelled" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_cancelled', "Iscrizione annullata.")); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Stato Lista d'Attesa</th>
                            <td>
                                <input type="text" name="ui_waitlist_title_text" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_title_text', "Sei in lista d'attesa")); ?>" style="width:100%; max-width: 500px;">
                                <p class="description">Nel riepilogo viene aggiunta automaticamente l'icona ✅.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Stato Lista d'Attesa (Inline)</th>
                            <td>
                                <textarea name="ui_waitlist_inline_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_waitlist_inline_notice', 'Ti avviseremo appena si libera.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Stato Lista d'Attesa (Pagina)</th>
                            <td>
                                <textarea name="ui_waitlist_page_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_waitlist_page_notice', 'Ti avviseremo appena si libera.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Label "Date Richieste" (Inline)</th>
                            <td>
                                <input type="text" name="ui_waitlist_inline_dates_label" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_inline_dates_label', 'Periodo:')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione Richiesta</th>
                            <td>
                                <input type="text" name="ui_cancel_requested" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_requested', 'Richiesta di cancellazione inviata.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione In Attesa</th>
                            <td>
                                <input type="text" name="ui_cancel_pending" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_pending', 'Cancellazione già richiesta.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>IBAN Richiesto</th>
                            <td>
                                <input type="text" name="ui_cancel_iban_required" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_iban_required', 'Inserisci un IBAN valido.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>IBAN Non Valido</th>
                            <td>
                                <input type="text" name="ui_cancel_iban_invalid" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_iban_invalid', 'IBAN non valido.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione Non Disponibile (Alert)</th>
                            <td>
                                <textarea name="ui_cancel_denied" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_denied', 'Cancellazione non disponibile oltre {cancel_deadline}.')); ?></textarea>
                                <p class="description">Usa <code>{cancel_deadline}</code> per la data limite.</p>
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Sezioni ed Etichette</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Sezione "Dati Prenotazione"</th>
                            <td>
                                <input type="text" name="ui_section_booking" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_booking', 'Dettagli soggiorno')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Calcolo Prezzo"</th>
                            <td>
                                <input type="text" name="ui_section_pricing" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_pricing', 'Prezzo')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Dati per il Bonifico"</th>
                            <td>
                                <input type="text" name="ui_section_payment" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_payment', 'Bonifico')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Carica Distinta"</th>
                            <td>
                                <input type="text" name="ui_section_upload" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_upload', 'Carica distinta')); ?>" style="width:100%; max-width: 600px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Distinta Caricata"</th>
                            <td>
                                <input type="text" name="ui_section_uploaded" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_uploaded', 'Distinta ricevuta')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Azioni"</th>
                            <td>
                                <input type="text" name="ui_section_actions" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_actions', 'Azioni')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Descrizione Azioni (Distinta presente)</th>
                            <td>
                                <input type="text" name="ui_group_actions_ready" value="<?php echo esc_attr(get_option('paguro_msg_ui_group_actions_ready', 'Gestisci le date')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Descrizione Azioni (Distinta assente)</th>
                            <td>
                                <textarea name="ui_group_actions_locked" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_group_actions_locked', "Quest'area sarà disponibile dopo aver caricato la distinta.")); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Label Ospite</th>
                            <td>
                                <input type="text" name="ui_label_guest" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_guest', 'Ospite')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Nome (Waitlist)</th>
                            <td>
                                <input type="text" name="ui_label_name" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_name', 'Nome')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Email</th>
                            <td>
                                <input type="text" name="ui_label_email" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_email', 'Email')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Telefono</th>
                            <td>
                                <input type="text" name="ui_label_phone" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_phone', 'Telefono')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Appartamento</th>
                            <td>
                                <input type="text" name="ui_label_apartment" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_apartment', 'Appartamento')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Check-in</th>
                            <td>
                                <input type="text" name="ui_label_checkin" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_checkin', 'Check-in')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Check-out</th>
                            <td>
                                <input type="text" name="ui_label_checkout" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_checkout', 'Check-out')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Suffisso Giorno (Check-in/out)</th>
                            <td>
                                <input type="text" name="ui_label_check_suffix" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_check_suffix', ' (sabato)')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Costo Totale</th>
                            <td>
                                <input type="text" name="ui_label_total_cost" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_total_cost', 'Totale soggiorno')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Acconto</th>
                            <td>
                                <input type="text" name="ui_label_deposit" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_deposit', 'Acconto (30%)')); ?>" style="width:100%; max-width: 400px;">
                                <p class="description">Puoi usare <code>{deposit_percent}</code> per mostrare la percentuale corrente.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Label Saldo</th>
                            <td>
                                <input type="text" name="ui_label_remaining" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_remaining', 'Saldo in struttura')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Intestatario</th>
                            <td>
                                <input type="text" name="ui_label_owner" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_owner', 'Intestatario')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label IBAN</th>
                            <td>
                                <input type="text" name="ui_label_iban" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_iban', 'IBAN')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Importo</th>
                            <td>
                                <input type="text" name="ui_label_amount" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_amount', 'Importo')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Upload e Distinta</h3>
                    <table class="form-table">
                        <tr>
                            <th>Testo Dropzone Upload</th>
                            <td>
                                <input type="text" name="ui_upload_drop" value="<?php echo esc_attr(get_option('paguro_msg_ui_upload_drop', 'Trascina qui il file o seleziona')); ?>" style="width:100%; max-width: 800px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Upload Riuscito</th>
                            <td>
                                <input type="text" name="ui_upload_success_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_upload_success_title', 'Distinta ricevuta')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Upload Riuscito</th>
                            <td>
                                <textarea name="ui_upload_success_text" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_upload_success_text', 'Date bloccate in attesa di verifica.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Avviso Distinta in Validazione</th>
                            <td>
                                <input type="text" name="ui_receipt_pending" value="<?php echo esc_attr(get_option('paguro_msg_ui_receipt_pending', 'Distinta in verifica (entro 24h).')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Distinta Caricata</th>
                            <td>
                                <textarea name="ui_receipt_uploaded_text" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_receipt_uploaded_text', 'Distinta ricevuta. Verifica entro 24h.')); ?></textarea>
                                <p class="description">Le nuove righe vengono convertite in <code>&lt;br&gt;</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone "Visualizza Distinta"</th>
                            <td>
                                <input type="text" name="ui_receipt_view_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_receipt_view_cta', 'Vedi distinta')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Cancellazione</h3>
                    <table class="form-table">
                        <tr>
                            <th>Label IBAN Rimborso</th>
                            <td>
                                <input type="text" name="ui_cancel_label" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_label', 'IBAN rimborso *')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Placeholder IBAN</th>
                            <td>
                                <input type="text" name="ui_cancel_placeholder" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_placeholder', 'IT00X0000000000000000000000')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Informativo IBAN</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_cancel_help', "Con l'invio rinunci al soggiorno. Rimborso sul conto indicato."),
                                    'ui_cancel_help',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Puoi usare <code>{guest_name}</code> e <code>{guest_email}</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Conferma Richiesta Cancellazione (Overlay)</th>
                            <td>
                                <textarea name="ui_cancel_confirm" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_confirm', "Confermi la richiesta di cancellazione? Rimborso sul conto indicato.")); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Conferma Apertura Settimana (Multi)</th>
                            <td>
                                <textarea name="ui_group_week_confirm" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_group_week_confirm', 'Attenzione: se annulli una settimana, il preventivo verrà ricalcolato e lo sconto multi‑settimana non sarà applicato.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone Cancella Prenotazione</th>
                            <td>
                                <input type="text" name="ui_cancel_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_cta', 'Richiedi cancellazione')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Nota Scadenza Cancellazione</th>
                            <td>
                                <textarea name="ui_cancel_deadline_note" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_deadline_note', 'Cancellazione possibile entro {cancel_deadline}.')); ?></textarea>
                                <p class="description">Usa <code>{cancel_deadline}</code> per la data limite.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione Non Disponibile (Blocco)</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_cancel_unavailable', 'Cancellazione non disponibile (entro {cancel_deadline}).'),
                                    'ui_cancel_unavailable',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Usa <code>{cancel_deadline}</code> per la data limite.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Richiesta Cancellazione Inviata</th>
                            <td>
                                <textarea name="ui_cancel_requested_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_requested_notice', 'Cancellazione richiesta. Riceverai conferma email.')); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Waitlist</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Sezione "Dettagli Iscrizione"</th>
                            <td>
                                <input type="text" name="ui_waitlist_section_info" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_section_info', 'Dettagli iscrizione')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Date Richieste"</th>
                            <td>
                                <input type="text" name="ui_waitlist_section_dates" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_section_dates', 'Date richieste')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Cronologia"</th>
                            <td>
                                <input type="text" name="ui_waitlist_section_history" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_section_history', 'Cronologia')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone "Esci dalla Lista d'Attesa"</th>
                            <td>
                                <input type="text" name="ui_waitlist_exit_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_exit_cta', 'Esci dalla lista')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Conferma Uscita Lista d'Attesa (Overlay)</th>
                            <td>
                                <textarea name="ui_waitlist_exit_confirm" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_waitlist_exit_confirm', "Confermi l'uscita dalla lista d'attesa?")); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <h3>Login Area Riservata</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contenuto Login</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_login_page', '<div>...</div>'),
                                    'ui_login_page',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Deve includere <code>{nonce_field}</code> e <code>{token}</code>.</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="paguro_save_web_templates" class="button button-primary">Salva Web Templates</button>
                    </p>
                </form>
            </div>
        <?php } ?>

        <!-- TAB: IMPOSTAZIONI -->
        <?php if ($current_tab === 'settings') { ?>
            <h2>Configurazione Plugin</h2>

            <form method="POST" class="card">
                <?php wp_nonce_field('paguro_admin_opts'); ?>

                <table class="form-table">
                    <tr>
                        <th>Inizio Stagione</th>
                        <td>
                            <input type="date" name="season_start" value="<?php echo esc_attr(get_option('paguro_season_start', '2026-06-01')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Fine Stagione</th>
                        <td>
                            <input type="date" name="season_end" value="<?php echo esc_attr(get_option('paguro_season_end', '2026-09-30')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>% Acconto</th>
                        <td>
                            <input type="number" name="deposit_percent" value="<?php echo esc_attr(get_option('paguro_deposit_percent', 30)); ?>" min="1" max="100"> %
                        </td>
                    </tr>
                    <tr>
                        <th>Sconto Multi‑Settimana</th>
                        <td>
                            <?php
                            $discount_raw = get_option('paguro_group_discount_map', '');
                            $discount_fmt = function_exists('paguro_format_group_discount_map') ? paguro_format_group_discount_map($discount_raw) : $discount_raw;
                            ?>
                            <input type="text" name="group_discount_map" value="<?php echo esc_attr($discount_fmt); ?>" style="width: 100%; max-width: 400px;">
                            <p class="description">
                                Formato base: <code>2=50, 3=120, 4=200</code> (default).
                                Per stagione: <code>A2=300, M2=200, B2=100</code> (A=Alta, M=Media, B=Bassa).
                                Per settimane con stagioni miste si applica il valore migliore tra le stagioni coinvolte (es. B+M su 2 settimane: <code>max(B2, M2)</code>).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>IBAN Bonifico</th>
                        <td>
                            <input type="text" name="bank_iban" value="<?php echo esc_attr(get_option('paguro_bank_iban', '')); ?>" style="font-family: monospace;">
                        </td>
                    </tr>
                    <tr>
                        <th>Intestatario</th>
                        <td>
                            <input type="text" name="bank_owner" value="<?php echo esc_attr(get_option('paguro_bank_owner', '')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>URL API ChatBot</th>
                        <td>
                            <input type="url" name="paguro_api_url" value="<?php echo esc_attr(get_option('paguro_api_url', 'https://api.example.com')); ?>" style="width: 100%; max-width: 400px;">
                        </td>
                    </tr>
                    <tr>
                        <th>URL Struttura Amica</th>
                        <td>
                            <input type="url" name="friend_structure_url" value="<?php echo esc_attr(get_option('paguro_friend_structure_url', '')); ?>" style="width: 100%; max-width: 500px;" placeholder="https://www.example.com/struttura-amica">
                            <p class="description">Se impostato, nelle date non disponibili il chatbot mostra il link in grassetto: <strong>Disponibile presso un'altra struttura</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Provider AI</th>
                        <td>
                            <?php $ai_provider = get_option('paguro_ai_provider', 'api'); ?>
                            <select name="ai_provider">
                                <option value="api" <?php selected($ai_provider, 'api'); ?>>API Remota</option>
                                <option value="ollama" <?php selected($ai_provider, 'ollama'); ?>>Ollama (Locale)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Ollama URL</th>
                        <td>
                            <input type="url" name="ollama_url" value="<?php echo esc_attr(get_option('paguro_ollama_url', 'http://localhost:11434')); ?>" style="width: 100%; max-width: 400px;">
                        </td>
                    </tr>
                    <tr>
                        <th>Ollama Model</th>
                        <td>
                            <input type="text" name="ollama_model" value="<?php echo esc_attr(get_option('paguro_ollama_model', 'llama3.1')); ?>" style="width: 100%; max-width: 300px;">
                        </td>
                    </tr>
                    <tr>
                        <th>reCAPTCHA - Site Key</th>
                        <td>
                            <input type="text" name="recaptcha_site" value="<?php echo esc_attr(get_option('paguro_recaptcha_site', '')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>reCAPTCHA - Secret Key</th>
                        <td>
                            <input type="password" name="recaptcha_secret" value="<?php echo esc_attr(get_option('paguro_recaptcha_secret', '')); ?>">
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="paguro_save_opts" class="button button-primary">Salva Configurazione</button>
                </p>
            </form>

        <?php } ?>

        <!-- TAB: IA -->
        <?php if ($current_tab === 'ai') { ?>
            <h2>IA - Risposte Automatiche</h2>

            <div class="card paguro-full-width-card">
                <p style="color: #666;">Definisci parole chiave e risposte personalizzate. Se il messaggio dell'utente contiene una delle parole chiave, il bot risponderà con il testo associato.</p>

                <form method="POST" id="paguro-ai-form">
                    <?php wp_nonce_field('paguro_ai_rules'); ?>

                    <div id="paguro-ai-rules">
                        <?php
                        $ai_rules = get_option('paguro_ai_rules', []);
                        if (!is_array($ai_rules)) $ai_rules = [];
                        if (empty($ai_rules)) {
                            $ai_rules = [['keywords' => '', 'response' => '']];
                        }
                        foreach ($ai_rules as $idx => $rule) {
                            $kw = esc_attr($rule['keywords'] ?? '');
                        ?>
                            <div class="paguro-ai-rule">
                                <label>Parole chiave (separate da virgola)</label>
                                <input type="text" name="ai_rule_keywords[]" value="<?php echo $kw; ?>" style="width:100%; max-width: 800px;">
                                <label style="margin-top:8px;">Risposta (HTML consentito)</label>
                                <?php
                                $editor_id = 'ai_rule_response_' . $idx;
                                wp_editor(
                                    $rule['response'] ?? '',
                                    $editor_id,
                                    ['textarea_name' => 'ai_rule_response[]', 'textarea_rows' => 4]
                                );
                                ?>
                                <button type="button" class="button paguro-ai-remove">Rimuovi</button>
                            </div>
                        <?php
                        }
                        ?>
                    </div>

                    <p>
                        <button type="button" class="button" id="paguro-ai-add">Aggiungi regola</button>
                    </p>

                    <p>
                        <button type="submit" name="paguro_save_ai_rules" class="button button-primary">Salva Regole IA</button>
                    </p>
                </form>
            </div>

            <script>
                document.addEventListener('click', function(e) {
                    if (e.target && e.target.id === 'paguro-ai-add') {
                        var container = document.getElementById('paguro-ai-rules');
                        if (!container) return;
                        var block = document.createElement('div');
                        block.className = 'paguro-ai-rule';
                        var count = container.querySelectorAll('.paguro-ai-rule').length;
                        var editorId = 'ai_rule_response_new_' + count;
                        block.innerHTML = '' +
                            '<label>Parole chiave (separate da virgola)</label>' +
                            '<input type=\"text\" name=\"ai_rule_keywords[]\" value=\"\" style=\"width:100%; max-width: 800px;\">' +
                            '<label style=\"margin-top:8px;\">Risposta (HTML consentito)</label>' +
                            '<textarea id=\"' + editorId + '\" name=\"ai_rule_response[]\" rows=\"4\" style=\"width:100%; max-width: 900px;\"></textarea>' +
                            '<button type=\"button\" class=\"button paguro-ai-remove\">Rimuovi</button>';
                        container.appendChild(block);
                        if (window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function') {
                            window.wp.editor.initialize(editorId, {
                                tinymce: true,
                                quicktags: true,
                                mediaButtons: false
                            });
                        }
                    }

                    if (e.target && e.target.classList.contains('paguro-ai-remove')) {
                        var rule = e.target.closest('.paguro-ai-rule');
                        if (rule) rule.remove();
                    }
                });
            </script>
        <?php } ?>

    </div>
</div>

<style>
    .paguro-admin {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .paguro-admin h1 {
        margin-bottom: 30px;
    }

    .paguro-admin .card {
        background: white;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .paguro-admin .paguro-full-width-card {
        max-width: none;
        width: 100%;
    }

    .paguro-admin .paguro-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .paguro-admin .paguro-card-body.is-collapsed {
        display: none;
    }

    .paguro-admin .paguro-manual-form {
        max-width: none;
    }

    .paguro-admin .paguro-manual-form .form-table {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(2, minmax(240px, 1fr));
        gap: 12px 20px;
    }

    .paguro-admin .paguro-manual-form .form-table tr {
        display: contents;
    }

    .paguro-admin .paguro-manual-form .form-table th,
    .paguro-admin .paguro-manual-form .form-table td {
        display: block;
        padding: 0;
        margin: 0;
    }

    .paguro-admin .paguro-manual-form .form-table th {
        font-weight: 600;
    }

    .paguro-admin .paguro-manual-form .form-table td input,
    .paguro-admin .paguro-manual-form .form-table td select,
    .paguro-admin .paguro-manual-form .form-table td textarea {
        width: 100%;
    }

    .paguro-admin .paguro-manual-form .form-table .paguro-col-span-2 {
        grid-column: 1 / -1;
    }

    .paguro-admin .nav-tab-wrapper {
        border-bottom: 1px solid #ccc;
        margin: 0 0 20px 0;
    }

    .paguro-admin .nav-tab {
        color: #0073aa;
        border: 1px solid transparent;
        padding: 8px 15px;
        text-decoration: none;
    }

    .paguro-admin .nav-tab:hover {
        color: #0073aa;
        background: #f5f5f5;
    }

    .paguro-admin .nav-tab-active {
        border-bottom: 3px solid #0073aa;
        color: #0073aa;
    }

    .paguro-admin .form-table th {
        width: 200px;
    }

    .paguro-admin button {
        margin-right: 5px;
    }

    .paguro-admin .button-success {
        background-color: #28a745;
        border-color: #28a745;
        color: white;
    }

    .paguro-admin .button-success:hover {
        background-color: #218838;
    }

    .paguro-admin .button-danger {
        background-color: #dc3545;
        border-color: #dc3545;
        color: white;
    }

    .paguro-admin .button-danger:hover {
        background-color: #c82333;
    }

    .paguro-admin table.widefat {
        margin-bottom: 20px;
    }

    .paguro-admin table.widefat code {
        background: #f5f5f5;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 12px;
    }

    .paguro-admin .paguro-filter-links {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 10px 0 15px;
    }

    .paguro-admin .paguro-filter-links .button {
        margin: 0;
    }

    .paguro-admin .paguro-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.4;
        border: 1px solid transparent;
    }

    .paguro-admin .paguro-badge--green {
        background: #d9f5df;
        color: #136b2e;
        border-color: #a5e2b8;
    }

    .paguro-admin .paguro-badge--yellow {
        background: #fff4c2;
        color: #7a5a00;
        border-color: #f1d26b;
    }

    .paguro-admin .paguro-badge--orange {
        background: #ffe1c2;
        color: #8a3d00;
        border-color: #ffc28a;
    }

    .paguro-admin .paguro-badge--red {
        background: #ffd6d6;
        color: #7a0000;
        border-color: #f0a0a0;
    }

    .paguro-admin .paguro-badge--gray {
        background: #eef1f4;
        color: #505a64;
        border-color: #d6dde5;
    }

    .paguro-admin .paguro-badge--blue {
        background: #dbeafe;
        color: #1e3a8a;
        border-color: #bfdbfe;
    }

    .paguro-finance-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        margin: 12px 0 18px;
    }

    .paguro-finance-card {
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 8px;
        padding: 12px 14px;
    }

    .paguro-finance-card .label {
        display: block;
        font-size: 12px;
        color: #667085;
        margin-bottom: 6px;
    }

    .paguro-finance-card .value {
        font-size: 16px;
        color: #111827;
    }

    .paguro-finance-table td,
    .paguro-finance-table th {
        vertical-align: top;
    }

    .paguro-finance-editable {
        border: 0;
        background: transparent;
        color: #005a9c;
        cursor: pointer;
        font-weight: 600;
        padding: 0;
        text-decoration: underline;
    }

    .paguro-finance-editable:hover {
        color: #003f6d;
    }

    .paguro-finance-static {
        font-weight: 600;
    }

    .paguro-finance-table tr.is-saving {
        opacity: 0.7;
    }

    .paguro-finance-table tr.is-save-error {
        outline: 2px solid #dc2626;
        outline-offset: -2px;
    }

    .paguro-table-filters th {
        padding: 6px;
    }

    .paguro-table-filters input,
    .paguro-table-filters select {
        width: 100%;
        min-width: 80px;
    }

    .paguro-admin .paguro-ai-rule {
        margin: 16px 0;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        background: #fafafa;
    }

    .paguro-admin .paguro-ai-rule label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .paguro-admin .paguro-ai-rule .paguro-ai-remove {
        margin-top: 10px;
    }

    .paguro-admin .paguro-season-cell {
        min-width: 180px;
        white-space: nowrap;
    }

    .paguro-admin .paguro-week-cell {
        min-width: 200px;
        white-space: nowrap;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var toggleBtn = document.querySelector('.paguro-toggle-manual');
        var body = document.getElementById('paguro-manual-body');
        if (!toggleBtn || !body) return;

        toggleBtn.addEventListener('click', function() {
            var isCollapsed = body.classList.toggle('is-collapsed');
            toggleBtn.setAttribute('aria-expanded', (!isCollapsed).toString());
            toggleBtn.textContent = isCollapsed ? 'Apri' : 'Chiudi';
        });

        document.querySelectorAll('.paguro-group-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var group = btn.getAttribute('data-group');
                if (!group) return;
                document.querySelectorAll('.paguro-group-child[data-group="' + group + '"]').forEach(function(row) {
                    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
                });
            });
        });

        function setupTableFilters(tableSelector) {
            var table = document.querySelector(tableSelector);
            if (!table) return;
            var filterRow = table.querySelector('thead tr.paguro-table-filters');
            if (!filterRow) return;
            var controls = filterRow.querySelectorAll('input[data-col], select[data-col]');
            if (!controls.length) return;
            var applyFilters = function() {
                var active = [];
                controls.forEach(function(ctrl) {
                    var value = (ctrl.value || '').trim().toLowerCase();
                    if (!value) return;
                    var col = parseInt(ctrl.getAttribute('data-col') || '-1', 10);
                    if (col < 0) return;
                    active.push({
                        col: col,
                        value: value
                    });
                });
                table.querySelectorAll('tbody tr').forEach(function(row) {
                    var cells = row.children;
                    var match = true;
                    for (var i = 0; i < active.length; i++) {
                        var f = active[i];
                        if (!cells[f.col]) {
                            match = false;
                            break;
                        }
                        var cellText = (cells[f.col].innerText || cells[f.col].textContent || '').toLowerCase();
                        if (cellText.indexOf(f.value) === -1) {
                            match = false;
                            break;
                        }
                    }
                    row.style.display = match ? '' : 'none';
                });
            };
            controls.forEach(function(ctrl) {
                ctrl.addEventListener('input', applyFilters);
                ctrl.addEventListener('change', applyFilters);
            });
        }

        setupTableFilters('.paguro-bookings-table');
        setupTableFilters('.paguro-finance-table');
        setupTableFilters('.paguro-cancelled-table');

        var cancelledCheckAll = document.querySelector('.paguro-cancelled-check-all');
        var cancelledChecks = Array.prototype.slice.call(document.querySelectorAll('.paguro-cancelled-check'));
        if (cancelledCheckAll && cancelledChecks.length) {
            var refreshCancelledCheckAll = function() {
                var checked = cancelledChecks.filter(function(cb) {
                    return cb.checked;
                }).length;
                cancelledCheckAll.checked = checked === cancelledChecks.length;
                cancelledCheckAll.indeterminate = checked > 0 && checked < cancelledChecks.length;
            };
            cancelledCheckAll.addEventListener('change', function() {
                cancelledChecks.forEach(function(cb) {
                    cb.checked = cancelledCheckAll.checked;
                });
                cancelledCheckAll.indeterminate = false;
            });
            cancelledChecks.forEach(function(cb) {
                cb.addEventListener('change', refreshCancelledCheckAll);
            });
            refreshCancelledCheckAll();
        }

        function formatDateInput(date) {
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        document.querySelectorAll('.paguro-admin input[type="date"]').forEach(function(input) {
            input.addEventListener('focus', function() {
                if (!input.value) {
                    input.value = formatDateInput(new Date());
                    input.dataset.paguroAutofill = '1';
                }
            });
            input.addEventListener('input', function() {
                if (input.dataset.paguroAutofill) {
                    delete input.dataset.paguroAutofill;
                }
            });
            input.addEventListener('blur', function() {
                if (input.dataset.paguroAutofill) {
                    input.value = '';
                    delete input.dataset.paguroAutofill;
                }
            });
        });
    });
</script>
