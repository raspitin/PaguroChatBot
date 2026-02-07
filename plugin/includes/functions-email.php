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
    if (!$is_admin) {
        $privacy_txt = get_option('paguro_msg_ui_privacy_notice', '');
        $content .= $privacy_txt;
    }
    
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
    $cancel_deadline_dt = (clone $arrival_dt)->modify('-15 days');
    $cancel_deadline = $cancel_deadline_dt->format('d/m/Y');
    $cancel_deadline_raw = $cancel_deadline_dt->format('Y-m-d');

    $total_cost = function_exists('paguro_calculate_quote')
        ? paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end)
        : 0;
    $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
    $deposit = $total_cost > 0 ? ceil($total_cost * ($deposit_percent / 100)) : 0;
    $remaining = $total_cost - $deposit;

    $total_cost_fmt = number_format($total_cost, 2, ',', '.');
    $deposit_cost_fmt = number_format($deposit, 2, ',', '.');
    $remaining_cost_fmt = number_format($remaining, 2, ',', '.');

    $customer_iban = isset($b->customer_iban) ? $b->customer_iban : '';
    $customer_iban_norm = strtoupper(preg_replace('/\s+/', '', $customer_iban));
    $customer_iban_priv = paguro_mask_iban($customer_iban_norm);

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
    
    $admin_email = get_option('admin_email');
    
    $placeholders = paguro_escape_user_placeholders(paguro_get_email_placeholders($b));
    $placeholders['admin_link'] = admin_url('admin.php?page=paguro-booking&tab=bookings');
    $placeholders['booking_id'] = $b->id;
    
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

    $subject_tpl = get_option('paguro_msg_email_refund_subj', 'Bonifico disposto - {apt_name}');
    if ($subject_tpl === '') {
        $subject_tpl = 'Bonifico disposto - {apt_name}';
    }
    $subject = paguro_parse_template($subject_tpl, $placeholders);

    $body_tpl = get_option('paguro_msg_email_refund_body',
        'Ciao {guest_name},<br><br>' .
        'Ti confermiamo che il bonifico di rimborso è stato disposto sul seguente IBAN:<br>' .
        '<strong>{customer_iban_priv}</strong><br><br>' .
        'I tempi di accredito possono variare in base alla tua banca.<br><br>' .
        'Grazie comunque per averci contattato e per la fiducia riposta.<br>' .
        'Speriamo di poterti ospitare in futuro.'
    );
    if ($body_tpl === '') {
        $body_tpl = 'Ciao {guest_name},<br><br>' .
            'Ti confermiamo che il bonifico di rimborso è stato disposto sul seguente IBAN:<br>' .
            '<strong>{customer_iban_priv}</strong><br><br>' .
            'I tempi di accredito possono variare in base alla tua banca.<br><br>' .
            'Grazie comunque per averci contattato e per la fiducia riposta.<br>' .
            'Speriamo di poterti ospitare in futuro.';
    }
    $body = paguro_parse_template($body_tpl, $placeholders);

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
    
    $subject = "✨ Buone notizie! {apt_name} è disponibile";
    
    $body = "Ciao {guest_name},<br><br>" .
            "Il periodo che ti interessava ({date_start} - {date_end}) per {apt_name} si è appena liberato!<br>" .
            "Se sei ancora interessato, affrettati a prenotare prima che venga bloccato nuovamente.<br><br>" .
            "<a href='{booking_url}' style='display:inline-block; background:#28a745; color:#fff; padding:12px 25px; text-decoration:none; border-radius:5px; font-weight:bold;'>PRENOTA ORA</a>";
    
    return paguro_send_email($b->guest_email, paguro_parse_template($subject, $placeholders), paguro_parse_template($body, $placeholders), false);
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
    
    $subject = "✅ Sei in Lista d'Attesa: {apt_name}";
    
    $body = "Ciao {guest_name},<br><br>" .
            "Ti abbiamo registrato nella lista d'attesa per {apt_name} ({date_start} - {date_end}).<br>" .
            "Ti avviseremo via email non appena il periodo si libererà.<br><br>" .
            "<a href='{link_riepilogo}' style='display:inline-block; background:#0073aa; color:#fff; padding:12px 25px; text-decoration:none; border-radius:5px; font-weight:bold;'>VISUALIZZA DETTAGLI</a>";
    
    return paguro_send_email($b->guest_email, paguro_parse_template($subject, $placeholders), paguro_parse_template($body, $placeholders), false);
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
    
    $subject = "Nuova Iscrizione Waitlist: {apt_name}";
    
    $body = "Utente {guest_name} si è iscritto alla lista d'attesa per {apt_name} ({date_start} - {date_end}).";
    
    return paguro_send_email($admin_email, paguro_parse_template($subject, $placeholders), paguro_parse_template($body, $placeholders), true);
}

?>
