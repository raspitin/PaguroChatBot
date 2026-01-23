<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Versione 3.0.5 - Competition Mode (First-to-Pay logic)
 * Version: 3.0.5
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

// 1. SETUP & DB & CRON
register_activation_hook(__FILE__, 'paguro_activate_plugin');
register_deactivation_hook(__FILE__, 'paguro_deactivate_plugin');

function paguro_activate_plugin() {
    paguro_create_tables();
    if (!wp_next_scheduled('paguro_daily_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'paguro_daily_cleanup_event');
    }
}

function paguro_deactivate_plugin() {
    wp_clear_scheduled_hook('paguro_daily_cleanup_event');
}

function paguro_create_tables() {
    global $wpdb; $charset = $wpdb->get_charset_collate();
    
    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_apartments (
        id mediumint(9) AUTO_INCREMENT, name varchar(100), base_price decimal(10,2), pricing_json longtext, PRIMARY KEY (id)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_availability (
        id mediumint(9) AUTO_INCREMENT, apartment_id mediumint(9), date_start date, date_end date, 
        status tinyint(1) DEFAULT 0, guest_name varchar(100), guest_email varchar(100), guest_phone varchar(50), 
        guest_notes text, history_log longtext,
        lock_token varchar(64), lock_expires datetime, 
        receipt_url varchar(255), receipt_uploaded_at datetime,
        created_at datetime DEFAULT CURRENT_TIMESTAMP, 
        PRIMARY KEY (id), KEY apartment_id (apartment_id)
    ) $charset;";
        
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php'); 
    dbDelta($sql1); dbDelta($sql2);
    
    if ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_apartments") == 0) {
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'corallo', 'base_price' => 500]);
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'tartaruga', 'base_price' => 500]);
    }
    
    paguro_set_defaults();
}

function paguro_set_defaults() {
    add_option('paguro_recaptcha_site', '');
    add_option('paguro_recaptcha_secret', '');
    add_option('paguro_api_url', 'https://api.viamerano24.it/chat');
    add_option('paguro_season_start', '2026-06-01');
    add_option('paguro_season_end', '2026-09-30');
    add_option('paguro_deposit_percent', '30');
    
    add_option('paguro_bank_iban', 'IT00X0000000000000000000000');
    add_option('paguro_bank_owner', 'Chiara Maria Angela Celi');
    add_option('paguro_page_slug', 'riepilogo-prenotazione');
    
    add_option('paguro_msg_ui_social_pressure', '⚡ <strong>Affrettati!</strong> Altre {count} richieste in corso per queste date.');
    add_option('paguro_txt_email_refund_ok_subj', 'Conferma Rimborso - {apt_name}');
    add_option('paguro_txt_email_refund_ok_body', "<h2>Gestione Rimborso - {apt_name}</h2><p>Gentile {guest_name},</p><p>Abbiamo ricevuto la tua richiesta. Per sicurezza, <strong>non inviare l'IBAN via email.</strong></p><p>Ti contatteremo al numero <strong>{guest_phone}</strong> per concordare il rimborso.</p>");

    $summary_default = '
    <div style="background:#d4edda; color:#155724; padding:15px; margin-bottom:20px; border:1px solid #c3e6cb; border-radius:5px; text-align:center;">
        ✅ <strong>Richiesta Inviata!</strong> Abbiamo inviato una copia di questo preventivo a <strong>{guest_email}</strong>.
    </div>

    <h2 style="color:#0073aa;">Ciao {guest_name},</h2>
    <p>Ecco il riepilogo per il soggiorno dal <strong>{date_start}</strong> al <strong>{date_end}</strong> nell\'appartamento <strong>{apt_name}</strong>.</p>

    <div style="background:#e7f5fe; border-left: 4px solid #0073aa; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <div style="background:#fff; padding:10px; border:1px solid #cce5ff; border-radius:4px;">
            <strong>La nostra offerta:</strong><br>
            💰 Costo totale soggiorno: <strong>€{total_cost}</strong><br>
            ✨ <strong>Acconto richiesto (30%): €{deposit_cost}</strong>
        </div>
    </div>

    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin: 20px 0; background-color: #f9f9f9; border-radius: 8px; border:1px solid #eee;">
        <tr>
            <td valign="top" width="50%" style="padding: 15px; border-right: 1px solid #eee;">
                <h4 style="margin: 0 0 10px; color: #28a745; text-transform: uppercase; font-size: 12px;">✅ Incluso</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 13px; line-height: 1.5; color:#444;">
                    <li>Posto auto</li>
                    <li>Pulizie finali</li>
                    <li>Consumi</li>
                    <li><strong>🐾 Animali ammessi</strong></li>
                </ul>
            </td>
            <td valign="top" width="50%" style="padding: 15px;">
                <h4 style="margin: 0 0 10px; color: #dc3545; text-transform: uppercase; font-size: 12px;">❌ Non incluso</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 13px; line-height: 1.5; color:#444;">
                    <li>Biancheria (letto/bagno)</li>
                    <li>Servizio spiaggia</li>
                    <li>Ristorazione</li>
                </ul>
            </td>
        </tr>
    </table>

    <h3 style="color:#0073aa; margin-top:25px;">🛡️ Garanzia "Zero Pensieri"</h3>
    <p>Puoi cancellare autonomamente fino a <strong>15 giorni</strong> prima dell\'arrivo con rimborso 100%.</p>

    <hr style="border:0; border-top:1px solid #eee; margin:25px 0;">

    <div style="background:#fffcf0; border:1px solid #e0d8b6; padding:15px; border-radius:5px;">
        <p style="margin-top:0;"><strong>Dati per il bonifico:</strong></p>
        <p style="margin-bottom:0; line-height:1.6;">
            Importo da versare: <span style="font-size:18px; color:#d35400;"><strong>€{deposit_cost}</strong></span><br>
            IBAN: <strong>{iban}</strong><br>
            Intestato a: <strong>{intestatario}</strong><br>
            Causale: <em>Prenotazione {guest_name} - {apt_name}</em>
        </p>
    </div>';
    
    add_option('paguro_msg_ui_summary_page', $summary_default);

    $login_default = '
    <div style="max-width:400px; margin:50px auto; padding:30px; background:#fff; border:1px solid #ddd; border-radius:8px; text-align:center;">
        <h3 style="margin-top:0;">🔐 Area Riservata</h3>
        <p>Per motivi di sicurezza, inserisci la tua email per accedere alla prenotazione.</p>
        <form method="post">
            <input type="hidden" name="paguro_action" value="verify_access">
            {nonce_field}
            <input type="hidden" name="token" value="{token}">
            <input type="email" name="verify_email" placeholder="La tua email" required style="width:100%; padding:10px; margin-bottom:15px; border:1px solid #ccc; border-radius:4px;">
            <button type="submit" class="button" style="background:#0073aa; color:#fff; border:none; padding:10px 20px; font-size:16px; border-radius:4px; cursor:pointer;">Accedi</button>
        </form>
    </div>';
    add_option('paguro_msg_ui_login_page', $login_default);
}

// CRON JOB FUNCTION
add_action('paguro_daily_cleanup_event', 'paguro_do_cleanup');
function paguro_do_cleanup() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->prefix}paguro_availability WHERE status=2 AND receipt_url IS NULL AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)))");
}

add_action('plugins_loaded', 'paguro_update_db_structure');
function paguro_update_db_structure() {
    if (get_transient('paguro_db_check_305')) return;
    global $wpdb; 
    $t1 = $wpdb->prefix . 'paguro_availability'; 
    $wpdb->query("ALTER TABLE $t1 MODIFY COLUMN lock_token VARCHAR(64)");
    paguro_set_defaults();
    set_transient('paguro_db_check_305', true, DAY_IN_SECONDS);
}

// CACHING FIX
add_action('init', 'paguro_prevent_caching');
function paguro_prevent_caching() {
    if (isset($_GET['token']) && !empty($_GET['token'])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTMINIFY')) define('DONOTMINIFY', true);
        nocache_headers();
    }
}

// 2. HELPER FUNCTIONS
function paguro_add_history($booking_id, $action, $details = '') { global $wpdb; $table = $wpdb->prefix . 'paguro_availability'; $row = $wpdb->get_row($wpdb->prepare("SELECT history_log FROM $table WHERE id = %d", $booking_id)); if ($row) { $log = $row->history_log ? json_decode($row->history_log, true) : []; if (!is_array($log)) $log = []; $log[] = ['time' => current_time('mysql'), 'action' => $action, 'details' => $details]; $wpdb->update($table, ['history_log' => json_encode($log)], ['id' => $booking_id]); } }
function paguro_send_html_email($to, $subject, $content) { $privacy_txt = get_option('paguro_msg_ui_privacy_notice'); $content .= $privacy_txt; $html = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f6f6f6;padding:20px"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;"><div style="background:#0073aa;padding:20px;text-align:center;"><h1 style="color:#fff;margin:0;">Villa Celi</h1></div><div style="padding:30px;color:#333;line-height:1.6">'.$content.'</div><div style="background:#eee;padding:15px;text-align:center;font-size:12px;color:#777">&copy; '.date('Y').' Villa Celi</div></div></body></html>'; $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Villa Celi <info@villaceli.it>'); return wp_mail($to, $subject, $html, $headers); }
function paguro_parse_template($text, $data) { if (empty($text)) return ''; foreach ($data as $key => $val) { $text = str_replace('{' . $key . '}', (string)$val, $text); } return $text; }
function paguro_calculate_quote($apt_id, $date_start, $date_end) { global $wpdb; $apt = $wpdb->get_row($wpdb->prepare("SELECT base_price, pricing_json FROM {$wpdb->prefix}paguro_apartments WHERE id = %d", $apt_id)); if (!$apt) return 0; $prices = ($apt->pricing_json) ? json_decode($apt->pricing_json, true) : []; $total = 0; $current = new DateTime($date_start); $end = new DateTime($date_end); while ($current < $end) { $week_key = $current->format('Y-m-d'); $weekly_price = (isset($prices[$week_key]) && $prices[$week_key]>0) ? floatval($prices[$week_key]) : floatval($apt->base_price); $total += $weekly_price; $current->modify('+1 week'); } return $total; }

// 3. ADMIN MENU
add_action('admin_menu', 'paguro_register_admin_menu');
function paguro_register_admin_menu() { add_menu_page('Gestione Paguro', 'Paguro Booking', 'manage_options', 'paguro-booking', 'paguro_render_admin_page', 'dashicons-building', 50); }
function paguro_render_admin_page() { $file = plugin_dir_path(__FILE__) . 'admin-page.php'; if(file_exists($file)) require_once $file; }

// 4. ASSETS
add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() { wp_enqueue_script('paguro-js', plugin_dir_url(__FILE__) . 'paguro-front.js', ['jquery'], '3.0.5', true); $site_key = get_option('paguro_recaptcha_site'); if ($site_key) wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . $site_key, [], null, true); wp_localize_script('paguro-js', 'paguroData', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('paguro_chat_nonce'), 'booking_url' => site_url('/'.get_option('paguro_page_slug', 'riepilogo-prenotazione').'/'), 'icon_url' => plugin_dir_url(__FILE__) . 'paguro_bot_icon.png', 'recaptcha_site' => $site_key, 'msgs' => ['upload_loading' => get_option('paguro_js_upload_loading', '⏳ Caricamento in corso...'), 'upload_success' => get_option('paguro_js_upload_success', '✅ Distinta Caricata!'), 'upload_error' => get_option('paguro_js_upload_error', '❌ Errore server.'), 'form_success' => get_option('paguro_js_form_success', 'Richiesta inviata! Reindirizzamento...'), 'form_conn_error'=> get_option('paguro_js_form_conn_error', 'Errore di connessione.'), 'btn_locking' => get_option('paguro_js_btn_locking', 'Blocco...'), 'btn_book' => get_option('paguro_js_btn_book', '[Prenota]') ] ]); }

// 5. ACTIONS HANDLER
add_action('init', 'paguro_handle_post_actions');
function paguro_handle_post_actions() {
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'verify_access') { if (!wp_verify_nonce($_POST['paguro_auth_nonce'], 'paguro_auth_action')) wp_die('Security Check Failed.'); global $wpdb; $token = sanitize_text_field($_POST['token']); $email = sanitize_email($_POST['verify_email']); $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s AND guest_email=%s", $token, $email)); if ($row) { $cookie_val = wp_hash($token . 'paguro_auth'); setcookie('paguro_auth_' . $token, $cookie_val, time() + (7 * DAY_IN_SECONDS), '/', "", is_ssl(), true); paguro_add_history($row->id, 'USER_ACCESS', 'Accesso area riservata'); nocache_headers(); wp_redirect(add_query_arg('t', time(), remove_query_arg('auth_error'))); exit; } else { wp_redirect(add_query_arg('auth_error', '1')); exit; } }
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'resolve_race_conflict') { if (!wp_verify_nonce($_POST['paguro_race_nonce'], 'paguro_race_action')) wp_die('Security.'); global $wpdb; $token = sanitize_text_field($_POST['token']); $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token)); if(!$booking) wp_die("Errore."); $choice = sanitize_text_field($_POST['race_choice']); $note = sanitize_textarea_field($_POST['race_note']); $new_notes = $booking->guest_notes . "\n\n[" . date('d/m H:i') . " USER MSG]: " . $note; $ph=['id'=>$booking->id,'guest_name'=>$booking->guest_name,'note'=>$note, 'guest_phone'=>$booking->guest_phone]; if ($choice === 'refund') { $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3, 'guest_notes' => $new_notes], ['id' => $booking->id]); paguro_add_history($booking->id, 'USER_REQ_REFUND', 'Rimborso req'); wp_mail(get_option('admin_email'), paguro_parse_template(get_option('paguro_msg_email_adm_refund_subj'), $ph), paguro_parse_template(get_option('paguro_msg_email_adm_refund_body'), $ph)); wp_redirect(add_query_arg('msg', 'refund_req')); exit; } elseif ($choice === 'wait') { $new_expiry = date('Y-m-d H:i:s', time() + (7 * DAY_IN_SECONDS)); $wpdb->update("{$wpdb->prefix}paguro_availability", ['lock_expires' => $new_expiry, 'guest_notes' => $new_notes], ['id' => $booking->id]); paguro_add_history($booking->id, 'USER_REQ_WAIT', 'Waitlist'); $ph['expiry'] = $new_expiry; wp_mail(get_option('admin_email'), paguro_parse_template(get_option('paguro_msg_email_adm_wait_subj'), $ph), paguro_parse_template(get_option('paguro_msg_email_adm_wait_body'), $ph)); wp_redirect(add_query_arg('msg', 'wait_req')); exit; } }
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'cancel_user_booking') { if (!wp_verify_nonce($_POST['paguro_cancel_nonce'], 'paguro_cancel_action')) wp_die('Security.'); global $wpdb; $token = sanitize_text_field($_POST['token']); $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token)); $limit_ts = strtotime($booking->date_start) - (15 * 24 * 3600); $in_time = (time() < $limit_ts); $refund_type = ($booking->status == 2) ? "RITIRO (No pay)" : (($in_time) ? "RIMBORSO TOTALE" : "NO RIMBORSO"); $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3], ['id' => $booking->id]); paguro_add_history($booking->id, 'USER_CANCEL', "Cancellazione. Tipo: $refund_type"); $ph=['id'=>$booking->id,'guest_name'=>$booking->guest_name,'refund_type'=>$refund_type, 'guest_phone'=>$booking->guest_phone]; wp_mail(get_option('admin_email'), paguro_parse_template(get_option('paguro_msg_email_adm_cancel_subj'), $ph), paguro_parse_template(get_option('paguro_msg_email_adm_cancel_body'), $ph)); paguro_send_html_email($booking->guest_email, get_option('paguro_msg_email_cancel_subj'), paguro_parse_template(get_option('paguro_msg_email_cancel_body'), $ph)); wp_redirect(add_query_arg('cancelled', '1')); exit; }
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'anonymize_user_data') { if (!wp_verify_nonce($_POST['paguro_anon_nonce'], 'paguro_anon_action')) wp_die('Security.'); global $wpdb; $token = sanitize_text_field($_POST['token']); $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token)); $can_delete = true; if ($booking->status == 1 && time() < (strtotime($booking->date_end) + 15*86400)) $can_delete = false; if ($can_delete) { $cleaned_log = json_encode([['time'=>current_time('mysql'), 'action'=>'GDPR_WIPE', 'details'=>'Data wiped']]); $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_name' => 'Anonimo (GDPR)', 'guest_email' => 'deleted@privacy.user', 'guest_phone' => '0000000000', 'guest_notes' => '', 'history_log' => $cleaned_log], ['id' => $booking->id]); wp_redirect(add_query_arg('anonymized', '1')); exit; } else wp_die("Impossibile."); }
}

// 6. CHAT
add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat'); add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');
function paguro_handle_chat() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paguro_chat_nonce')) { wp_send_json_error(['reply' => "⚠️ Sessione scaduta."]); return; } global $wpdb;
    $api_url = get_option('paguro_api_url', 'https://api.viamerano24.it/chat');
    if (substr($api_url, -4) === '/api') $api_url = str_replace('/api', '/chat', $api_url); elseif (substr($api_url, -5) !== '/chat') $api_url = rtrim($api_url, '/') . '/chat';
    $msg = sanitize_text_field($_POST['message']); $sess = sanitize_text_field($_POST['session_id']);
    try {
        $offset = intval($_POST['offset']??0);
        if ($offset == 0) {
            $args = ['body' => json_encode(['message'=>$msg, 'session_id'=>$sess]), 'headers' => ['Content-Type'=>'application/json'], 'timeout' => 30, 'sslverify' => false];
            $res = wp_remote_post($api_url, $args);
            if (is_wp_error($res)) { wp_send_json_error(['reply' => "Errore WP."]); return; } $status = wp_remote_retrieve_response_code($res); if ($status !== 200) { wp_send_json_error(['reply' => "Errore Remoto."]); return; } $data = json_decode(wp_remote_retrieve_body($res), true); if (!$data) { wp_send_json_error(['reply' => "Errore JSON."]); return; }
        } else { $data = ['type'=>'ACTION', 'action'=>'CHECK_AVAILABILITY']; }
        if (($data['type']??'') === 'ACTION') {
            $s_start_str = get_option('paguro_season_start', '2026-06-01'); $s_end_str = get_option('paguro_season_end', '2026-09-30');
            $req_month = isset($_POST['filter_month']) ? sanitize_text_field($_POST['filter_month']) : '';
            $target_month = $req_month; if(empty($target_month)){ $mm=['giugno'=>'06','luglio'=>'07','agosto'=>'08','settembre'=>'09']; foreach($mm as $k=>$v) if(stripos($msg,$k)!==false) $target_month=$v; }
            $weeks = preg_match('/due sett|2 sett|14 giorn|coppia/i', $msg) ? 2 : 1;
            $s_start = new DateTime($s_start_str); $s_end = new DateTime($s_end_str); if ($s_start->format('N') != 6) { $s_start->modify('next saturday'); }
            $raw = $wpdb->get_results($wpdb->prepare("SELECT apartment_id, date_start, date_end, status, lock_expires, created_at FROM {$wpdb->prefix}paguro_availability WHERE (status=1 OR status=2) AND date_end > %s AND date_start < %s", $s_start->format('Y-m-d'), $s_end->format('Y-m-d')));
            $conf = []; $pend = []; $now = current_time('mysql'); foreach($raw as $r) { if ($r->status==1) $conf[]=$r; else { $exp = $r->lock_expires ?: date('Y-m-d H:i:s', strtotime($r->created_at.' + 48 hours')); if($exp > $now) $pend[]=$r; } }
            $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
            $html = ($offset==0) ? "Ecco le disponibilità (Sab-Sab):<br><br>" : ""; $found=false;
            $txt_book = get_option('paguro_js_btn_book', '[Prenota]');
            foreach($apts as $apt) { if($offset==0) $html .= "🏠 <b>{$apt->name}</b>:<br>"; $period = new DatePeriod($s_start, new DateInterval('P1W'), $s_end); $shown=0; $limit=4; $count=0; foreach($period as $dt) { if($target_month && $dt->format('m')!==$target_month) continue; $end = clone $dt; $end->modify("+$weeks weeks"); if($end > $s_end) continue; $occ=false; foreach($conf as $b) if($b->apartment_id==$apt->id && $b->date_start < $end->format('Y-m-d') && $b->date_end > $dt->format('Y-m-d')) $occ=true; if(!$occ) { $viewers=0; foreach($pend as $b) if($b->apartment_id==$apt->id && $b->date_start < $end->format('Y-m-d') && $b->date_end > $dt->format('Y-m-d')) $viewers++; $count++; if($count<=$offset) continue; if($shown<$limit) { $d_in=$dt->format('d/m/Y'); $d_out=$end->format('d/m/Y'); $social=($viewers>0)?" <span style='color:#d35400;font-size:12px;'>⚡ {$viewers} valutano</span>":""; $html .= "- {$dt->format('d/m')} - {$end->format('d/m')} <a href='#' class='paguro-book-btn' data-apt='".strtolower($apt->name)."' data-in='{$d_in}' data-out='{$d_out}'>$txt_book</a>{$social}<br>"; $shown++; $found=true; } else { $html.="<a href='#' class='paguro-load-more' data-apt='{$apt->id}' data-offset='".($offset+$limit)."' data-month='{$target_month}' style='color:#0073aa;'>...altre</a><br>"; break; } } } if($offset==0) $html.="<br>"; }
            $data['reply'] = ($found||$offset==0) ? $html : "Nessuna data trovata.";
        }
        wp_send_json_success($data);
    } catch (Exception $e) { wp_send_json_error(['reply' => "Err."]); }
}

// 7. LOCK (LOGIC V3.0.5: BLOCK ONLY IF CONFIRMED)
add_action('wp_ajax_paguro_lock_dates', 'paguro_handle_lock'); add_action('wp_ajax_nopriv_paguro_lock_dates', 'paguro_handle_lock');
function paguro_handle_lock() {
    if (!check_ajax_referer('paguro_chat_nonce', 'nonce', false)) wp_send_json_error(['msg' => "Scaduta."]); global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $wpdb->query("DELETE FROM {$wpdb->prefix}paguro_availability WHERE status=2 AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)))");
        $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $_POST['apt_name']));
        $d_in_obj = DateTime::createFromFormat('d/m/Y', $_POST['date_in']); $d_out_obj = DateTime::createFromFormat('d/m/Y', $_POST['date_out']);
        if (!$d_in_obj || !$d_out_obj) throw new Exception(get_option('paguro_err_dates_invalid', "Date invalide."));
        
        $d_in = $d_in_obj->format('Y-m-d'); $d_out = $d_out_obj->format('Y-m-d');
        
        // MODIFICA CRITICA V3.0.5: Blocca SOLO se status=1 (Confermato)
        $busy = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=1 AND (date_start<%s AND date_end>%s) FOR UPDATE", 
            $apt_id, $d_out, $d_in
        ));
        
        if ($busy) { $wpdb->query('ROLLBACK'); throw new Exception(get_option('paguro_err_occupied', "Occupato.")); }
        
        $token = bin2hex(random_bytes(32));
        $wpdb->insert("{$wpdb->prefix}paguro_availability", ['apartment_id'=>$apt_id, 'date_start'=>$d_in, 'date_end'=>$d_out, 'status'=>2, 'lock_token'=>$token, 'lock_expires'=>date('Y-m-d H:i:s', time()+(48*3600))]);
        $wpdb->query('COMMIT');
        
        wp_send_json_success(['token'=>$token, 'redirect_params' => "?token={$token}&apt=".urlencode($_POST['apt_name'])."&in={$_POST['date_in']}&out={$_POST['date_out']}"]);
    } catch (Exception $e) { $wpdb->query('ROLLBACK'); wp_send_json_error(['msg'=>$e->getMessage()]); }
}

// 8. SUBMIT
add_action('wp_ajax_paguro_submit_booking', 'paguro_submit_booking'); add_action('wp_ajax_nopriv_paguro_submit_booking', 'paguro_submit_booking');
function paguro_submit_booking() {
    check_ajax_referer('paguro_chat_nonce', 'nonce'); global $wpdb;
    $token = sanitize_text_field($_POST['token']); $name = sanitize_text_field($_POST['guest_name']); $email = sanitize_email($_POST['guest_email']); $phone = sanitize_text_field($_POST['guest_phone']); $notes = sanitize_textarea_field($_POST['guest_notes']);
    if (!$token || !$name || !is_email($email)) wp_send_json_error(['msg' => 'Dati mancanti.']);
    
    $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_name' => $name, 'guest_email' => $email, 'guest_phone' => $phone, 'guest_notes' => $notes], ['lock_token' => $token]);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token));
    paguro_add_history($booking->id, 'REQUEST_SENT', "Richiesta da $name");
    
    $cookie_val = wp_hash($token . 'paguro_auth');
    setcookie('paguro_auth_' . $token, $cookie_val, time() + (7 * DAY_IN_SECONDS), '/', "", is_ssl(), true);

    $booking_details = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id=a.id WHERE b.id=%d", $booking->id));
    $tot = paguro_calculate_quote($booking->apartment_id, $booking->date_start, $booking->date_end);
    $dep_percent = intval(get_option('paguro_deposit_percent', 30)) / 100; $dep = ceil($tot * $dep_percent);
    $competitors = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=2 AND receipt_url IS NULL AND id!=%d AND (date_start < %s AND date_end > %s)", $booking->apartment_id, $booking->id, $booking->date_end, $booking->date_start));

    $iban = get_option('paguro_bank_iban');
    $owner = get_option('paguro_bank_owner');
    $slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');

    $ph = [
        'guest_name' => $name, 
        'link_riepilogo' => site_url("/{$slug}/?token={$token}"), 
        'apt_name' => ucfirst($booking_details->apt_name), 
        'date_start' => date('d/m/Y', strtotime($booking_details->date_start)), 
        'date_end' => date('d/m/Y', strtotime($booking_details->date_end)),
        'total_cost' => $tot, 'deposit_cost' => $dep, 'count' => $competitors, 'guest_phone' => $phone, 'guest_email' => $email,
        'iban' => $iban, 'intestatario' => $owner
    ];
    
    paguro_send_html_email($email, paguro_parse_template(get_option('paguro_txt_email_request_subj'), $ph), paguro_parse_template(get_option('paguro_txt_email_request_body'), $ph));
    
    $adm_subj = paguro_parse_template(get_option('paguro_msg_email_adm_new_req_subj'), $ph);
    $adm_body = paguro_parse_template(get_option('paguro_msg_email_adm_new_req_body'), $ph);
    wp_mail(get_option('admin_email'), $adm_subj, $adm_body);
    
    wp_send_json_success(['redirect' => $ph['link_riepilogo']]);
}

// 10. UPLOAD
add_action('wp_ajax_paguro_upload_receipt', 'paguro_handle_receipt_upload'); add_action('wp_ajax_nopriv_paguro_upload_receipt', 'paguro_handle_receipt_upload');
function paguro_handle_receipt_upload() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    if (!isset($_FILES['file'])) wp_send_json_error(['msg' => get_option('paguro_err_no_file', 'No file.')]); 
    global $wpdb; $token = sanitize_text_field($_POST['token']); $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token)); if (!$booking) wp_send_json_error(['msg' => 'Token errato.']);
    $file = $_FILES['file']; $max_size_mb = 5; if ($file['size'] > $max_size_mb * 1024 * 1024) { wp_send_json_error(['msg' => "File troppo grande."]); } $finfo = finfo_open(FILEINFO_MIME_TYPE); $real_mime = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo); $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg']; if (!in_array($real_mime, $allowed)) { wp_send_json_error(['msg' => "Formato non valido."]); }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION); $file['name'] = md5(time() . $file['name']) . '.' . $ext;
    require_once(ABSPATH . 'wp-admin/includes/file.php'); $uploaded = wp_handle_upload($file, ['test_form' => false]); if (isset($uploaded['error'])) wp_send_json_error(['msg' => $uploaded['error']]);
    $wpdb->update("{$wpdb->prefix}paguro_availability", ['receipt_url' => $uploaded['url'], 'receipt_uploaded_at' => current_time('mysql')], ['id' => $booking->id]); paguro_add_history($booking->id, 'RECEIPT_UPLOAD', 'Caricata distinta');
    $adm_subj = paguro_parse_template(get_option('paguro_msg_email_adm_receipt_subj'), ['id'=>$booking->id]); $adm_body = paguro_parse_template(get_option('paguro_msg_email_adm_receipt_body'), ['guest_name'=>$booking->guest_name, 'receipt_url'=>$uploaded['url'], 'guest_phone'=>$booking->guest_phone]);
    paguro_send_html_email(get_option('admin_email'), $adm_subj, $adm_body);
    $slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
    $link = site_url("/{$slug}/?token={$token}");
    $apt_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}paguro_apartments WHERE id=%d", $booking->apartment_id));
    $ph = ['guest_name' => $booking->guest_name, 'link_riepilogo' => $link, 'apt_name' => ucfirst($apt_name), 'date_start' => $booking->date_start, 'date_end' => $booking->date_end, 'guest_phone'=>$booking->guest_phone];
    paguro_send_html_email($booking->guest_email, paguro_parse_template(get_option('paguro_txt_email_receipt_subj'), $ph), paguro_parse_template(get_option('paguro_txt_email_receipt_body'), $ph));
    $losers = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=2 AND receipt_url IS NULL AND id!=%d AND (date_start < %s AND date_end > %s)", $booking->apartment_id, $booking->id, $booking->date_end, $booking->date_start));
    if ($losers) {
        $subj_lost = get_option('paguro_txt_email_race_lost_subj'); $body_lost_tpl = get_option('paguro_txt_email_race_lost_body');
        foreach ($losers as $loser) {
            if ($loser->guest_email) {
                $ph_l = ['guest_name' => $loser->guest_name, 'apt_name' => ucfirst($apt_name), 'date_start' => $loser->date_start, 'date_end' => $loser->date_end];
                paguro_send_html_email($loser->guest_email, paguro_parse_template($subj_lost, $ph_l), paguro_parse_template($body_lost_tpl, $ph_l));
                paguro_add_history($loser->id, 'RACE_LOST_ALERT', "Avvisato di priorità persa");
            }
        }
    }
    wp_send_json_success(['url' => $uploaded['url']]);
}

// 11. SUMMARY
add_shortcode('paguro_summary', 'paguro_summary_render');
function paguro_summary_render() {
    global $wpdb; $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    ob_start(); ?>
    <div style="max-width:600px; margin:20px auto; font-family:sans-serif; border:1px solid #ddd; border-radius:8px; overflow:hidden;">
        <div style="background:#0073aa; color:#fff; padding:15px; font-size:18px; font-weight:bold;">📦 Riepilogo</div>
        <div style="padding:20px; background:#f9f9f9;">
            <?php if (!$token): ?><p>⚠️ Codice mancante.</p><?php else: 
                $expected_cookie = wp_hash($token . 'paguro_auth');
                $auth_ok = (isset($_COOKIE['paguro_auth_' . $token]) && $_COOKIE['paguro_auth_' . $token] === $expected_cookie);

                if (!$auth_ok) {
                    $login_tpl = get_option('paguro_msg_ui_login_page');
                    $login_html = paguro_parse_template($login_tpl, [
                        'nonce_field' => wp_nonce_field('paguro_auth_action', 'paguro_auth_nonce', true, false),
                        'token' => esc_attr($token)
                    ]);
                    echo $login_html;
                } else {
                    $b = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id WHERE b.lock_token = %s", $token));
                    if (!$b): ?><p>Non trovata.</p><?php else: 
                        if (isset($_GET['msg'])) {
                            if ($_GET['msg']=='refund_req') echo '<div style="background:#fff3cd; color:#856404; padding:10px;">✅ Richiesta Rimborso Inviata.</div>';
                            if ($_GET['msg']=='wait_req') echo '<div style="background:#d4edda; color:#155724; padding:10px;">✅ Inserito in Lista d\'Attesa (7gg).</div>';
                        }
                        
                        // RACE LOGIC: Winner = Someone with receipt. Competitors = Pending no receipt.
                        $race_winner = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=2 AND receipt_url IS NOT NULL AND id!=%d AND (date_start < %s AND date_end > %s)", $b->apartment_id, $b->id, $b->date_end, $b->date_start));
                        $competitors = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=2 AND receipt_url IS NULL AND id!=%d AND (date_start < %s AND date_end > %s)", $b->apartment_id, $b->id, $b->date_end, $b->date_start));
                        $exp = $b->lock_expires ? strtotime($b->lock_expires) : (strtotime($b->created_at)+48*3600);
                        $can_up = ($b->status == 2 && time() < $exp && !$race_winner);
                        $tot = paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end);
                        $dep_percent = intval(get_option('paguro_deposit_percent', 30)) / 100;
                        $dep = ceil($tot * $dep_percent);
                        
                        $iban = get_option('paguro_bank_iban');
                        $owner = get_option('paguro_bank_owner');
                        $slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');

                        $ph = [
                            'guest_name' => $b->guest_name, 'guest_email' => $b->guest_email, 'guest_phone' => $b->guest_phone,
                            'apt_name' => ucfirst($b->apt_name), 'date_start' => date('d/m/Y', strtotime($b->date_start)), 'date_end' => date('d/m/Y', strtotime($b->date_end)),
                            'count' => $competitors, 'total_cost' => $tot, 'deposit_cost' => $dep,
                            'iban' => $iban, 'intestatario' => $owner, 'link_riepilogo' => site_url("/{$slug}/?token={$token}")
                        ];
                    ?>
                        <?php if ($competitors > 0 && !$race_winner && $b->status == 2): 
                            $msg_press = paguro_parse_template(get_option('paguro_msg_ui_social_pressure'), $ph);
                        ?>
                            <div style="background:#fff3cd; color:#856404; padding:15px; border:1px solid #ffeeba; border-radius:5px; margin-bottom:15px;">
                                <?php echo $msg_press; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($race_winner && $b->status != 3): 
                            $msg_race = paguro_parse_template(get_option('paguro_msg_ui_race_warning'), $ph);
                        ?>
                            <div style="background:#f8d7da; color:#721c24; padding:15px; border:1px solid #f5c6cb; border-radius:5px; margin-bottom:15px;">
                                <?php echo $msg_race; ?>
                                <form method="post" style="margin-top:10px;">
                                    <?php wp_nonce_field('paguro_race_action', 'paguro_race_nonce'); ?>
                                    <input type="hidden" name="paguro_action" value="resolve_race_conflict">
                                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                                    <textarea name="race_note" placeholder="Messaggio (es. Ho già bonificato...)" style="width:100%; height:60px; margin-bottom:10px;"></textarea>
                                    <button type="submit" name="race_choice" value="wait" class="button" style="margin-right:5px;">⏳ Attendi 7gg</button>
                                    <button type="submit" name="race_choice" value="refund" class="button" style="background:#dc3545; color:white;">💸 Richiedi Rimborso</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php 
                            $main_content = get_option('paguro_msg_ui_summary_page');
                            echo paguro_parse_template($main_content, $ph);
                        ?>

                        <?php if ($can_up): ?>
                            <div id="paguro-upload-area" style="margin-top:20px; border:2px dashed #0073aa; padding:30px; text-align:center; cursor:pointer;">
                                <p><?php echo get_option('paguro_msg_ui_upload_instruction','📂 Trascina distinta qui'); ?></p>
                                <input type="file" id="paguro-file-input" style="display:none;" accept=".pdf,.jpg,.jpeg,.png">
                                <button class="button" onclick="document.getElementById('paguro-file-input').click()"><?php echo get_option('paguro_msg_ui_upload_btn','Scegli'); ?></button>
                                <div id="paguro-upload-status"></div>
                            </div>
                            <input type="hidden" id="paguro-token" value="<?php echo $token; ?>">
                            <p style="text-align:center; font-size:14px; color:#555; margin-top:15px;">⏳ Manterremo questo preventivo valido per <strong>48 ore</strong>.<br>Puoi chiudere questa pagina e tornare quando sei pronto per il pagamento.</p>
                        <?php endif; ?>

                    <?php endif; } endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}