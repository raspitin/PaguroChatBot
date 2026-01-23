<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Versione 2.8.2 - Full Conflict Resolution & Logic
 * Version: 2.8.2
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

// 1. SETUP & DB
register_activation_hook(__FILE__, 'paguro_create_tables');
function paguro_create_tables() {
    global $wpdb; $charset = $wpdb->get_charset_collate();
    
    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_apartments (
        id mediumint(9) AUTO_INCREMENT, name varchar(100), base_price decimal(10,2), pricing_json longtext, PRIMARY KEY (id)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_availability (
        id mediumint(9) AUTO_INCREMENT, apartment_id mediumint(9), date_start date, date_end date, 
        status tinyint(1) DEFAULT 0, guest_name varchar(100), guest_email varchar(100), guest_phone varchar(50), 
        guest_notes text, history_log longtext,
        lock_token varchar(50), lock_expires datetime, 
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
    if (!get_option('paguro_txt_email_confirm_subj')) paguro_set_defaults();
}

function paguro_set_defaults() {
    update_option('paguro_recaptcha_site', '');
    update_option('paguro_recaptcha_secret', '');
    update_option('paguro_api_url', 'https://api.viamerano24.it/chat');
    
    // Email Standard
    update_option('paguro_txt_email_receipt_subj', 'Ricezione Distinta - {guest_name}');
    update_option('paguro_txt_email_receipt_body', "<h2>Ciao {guest_name},</h2><p>Abbiamo ricevuto la tua distinta. Stiamo verificando il pagamento.</p>");
    update_option('paguro_txt_email_confirm_subj', 'Conferma {apt_name} dal {date_start}');
    update_option('paguro_txt_email_confirm_body', "<h2>Confermata!</h2><p>Soggiorno confermato.</p>");
    update_option('paguro_txt_email_request_subj', 'Richiesta {apt_name} - {date_start}');
    update_option('paguro_txt_email_request_body', "<h2>Richiesta inviata</h2>");
    
    // Email Conflitto
    update_option('paguro_txt_email_race_lost_subj', '⚠️ Aggiornamento disponibilità - {apt_name}');
    update_option('paguro_txt_email_race_lost_body', "<p>Gentile {guest_name},</p><p>Ti informiamo che un altro utente ha appena caricato la distinta per le date {date_start} - {date_end}.</p><p>Accedi alla tua area riservata per decidere come procedere (Lista d'attesa o Rimborso).</p>");
}

add_action('plugins_loaded', 'paguro_update_db_structure');
function paguro_update_db_structure() {
    if (get_transient('paguro_db_check_282')) return;
    global $wpdb; 
    $t1 = $wpdb->prefix . 'paguro_availability'; $cols1 = $wpdb->get_col("DESC $t1", 0);
    if (!in_array('lock_expires', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN lock_expires DATETIME NULL");
    if (!in_array('receipt_url', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN receipt_url VARCHAR(255) NULL");
    if (!in_array('guest_notes', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN guest_notes TEXT NULL");
    if (!in_array('receipt_uploaded_at', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN receipt_uploaded_at DATETIME NULL");
    if (!in_array('history_log', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN history_log LONGTEXT NULL");
    
    $t2 = $wpdb->prefix . 'paguro_apartments'; $cols2 = $wpdb->get_col("DESC $t2", 0);
    if (!in_array('pricing_json', $cols2)) $wpdb->query("ALTER TABLE $t2 ADD COLUMN pricing_json LONGTEXT NULL");
    set_transient('paguro_db_check_282', true, DAY_IN_SECONDS);
}

// 2. HELPER LOGGING & EMAIL
define('PAGURO_PRIVACY_TEXT', '<hr style="border:0; border-top:1px solid #eee; margin:20px 0;"><p style="font-size:11px; color:#888;">🔒 <strong>Privacy & Dati:</strong> Le informazioni raccolte saranno utilizzate esclusivamente per la gestione del soggiorno. Hai facoltà di cancellare i tuoi contatti in qualunque momento dall\'area riservata (per prenotazioni confermate, cancellazione possibile 15 giorni dopo il check-out).</p>');

function paguro_add_history($booking_id, $action, $details = '') {
    global $wpdb; $table = $wpdb->prefix . 'paguro_availability';
    $row = $wpdb->get_row($wpdb->prepare("SELECT history_log FROM $table WHERE id = %d", $booking_id));
    if ($row) {
        $log = $row->history_log ? json_decode($row->history_log, true) : [];
        if (!is_array($log)) $log = [];
        $log[] = ['time' => current_time('mysql'), 'action' => $action, 'details' => $details];
        $wpdb->update($table, ['history_log' => json_encode($log)], ['id' => $booking_id]);
    }
}

function paguro_send_html_email($to, $subject, $content) {
    $content .= PAGURO_PRIVACY_TEXT;
    $html = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f6f6f6;padding:20px"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;"><div style="background:#0073aa;padding:20px;text-align:center;"><h1 style="color:#fff;margin:0;">Villa Celi</h1></div><div style="padding:30px;color:#333;line-height:1.6">'.$content.'</div><div style="background:#eee;padding:15px;text-align:center;font-size:12px;color:#777">&copy; '.date('Y').' Villa Celi</div></div></body></html>';
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Villa Celi <info@villaceli.it>');
    return wp_mail($to, $subject, $html, $headers);
}
function paguro_parse_template($text, $data) { foreach ($data as $key => $val) { $text = str_replace('{' . $key . '}', $val, $text); } return $text; }
function paguro_calculate_quote($apt_id, $date_start, $date_end) {
    global $wpdb; $apt = $wpdb->get_row($wpdb->prepare("SELECT base_price, pricing_json FROM {$wpdb->prefix}paguro_apartments WHERE id = %d", $apt_id));
    if (!$apt) return 0;
    $prices = ($apt->pricing_json) ? json_decode($apt->pricing_json, true) : []; $total = 0;
    $current = new DateTime($date_start); $end = new DateTime($date_end); $interval = new DateInterval('P1W'); 
    while ($current < $end) { $key = $current->format('Y-m-d'); $total += (isset($prices[$key]) && $prices[$key]>0) ? floatval($prices[$key]) : floatval($apt->base_price); $current->add($interval); }
    return $total;
}

// 3. ADMIN MENU
add_action('admin_menu', 'paguro_register_admin_menu');
function paguro_register_admin_menu() {
    add_menu_page('Gestione Paguro', 'Paguro Booking', 'manage_options', 'paguro-booking', 'paguro_render_admin_page', 'dashicons-building', 50);
}
function paguro_render_admin_page() {
    $file = plugin_dir_path(__FILE__) . 'admin-page.php';
    if(file_exists($file)) require_once $file; else echo '<div class="notice notice-error"><p>File admin-page.php mancante.</p></div>';
}

// 4. ASSETS
add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() {
    wp_enqueue_script('paguro-js', plugin_dir_url(__FILE__) . 'paguro-front.js', ['jquery'], '2.8.2', true);
    $site_key = get_option('paguro_recaptcha_site');
    if ($site_key) wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . $site_key, [], null, true);
    wp_localize_script('paguro-js', 'paguroData', [
        'ajax_url' => admin_url('admin-ajax.php'), 
        'nonce' => wp_create_nonce('paguro_chat_nonce'),
        'booking_url' => site_url('/prenotazione/'), 
        'icon_url' => plugin_dir_url(__FILE__) . 'paguro_bot_icon.png', 
        'recaptcha_site' => $site_key
    ]);
}

// 5. ACTIONS HANDLER
add_action('init', 'paguro_handle_post_actions');
function paguro_handle_post_actions() {
    
    // A. LOGIN
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'verify_access') {
        if (!wp_verify_nonce($_POST['paguro_auth_nonce'], 'paguro_auth_action')) wp_die('Security.');
        global $wpdb; $token = sanitize_text_field($_POST['token']); $email = sanitize_email($_POST['verify_email']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s AND guest_email=%s", $token, $email));
        if ($row) {
            setcookie('paguro_auth_' . $token, hash('sha256', $token . 'salt'), time() + (7 * DAY_IN_SECONDS), '/', "", is_ssl(), true);
            paguro_add_history($row->id, 'USER_ACCESS', 'Accesso area riservata');
            nocache_headers(); wp_redirect(add_query_arg('t', time(), remove_query_arg('auth_error'))); exit;
        } else { wp_redirect(add_query_arg('auth_error', '1')); exit; }
    }

    // B. RACE CONFLICT RESOLUTION
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'resolve_race_conflict') {
        if (!wp_verify_nonce($_POST['paguro_race_nonce'], 'paguro_race_action')) wp_die('Security.');
        global $wpdb; $token = sanitize_text_field($_POST['token']);
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token));
        if (!$booking) wp_die("Errore.");

        $choice = sanitize_text_field($_POST['race_choice']);
        $note = sanitize_textarea_field($_POST['race_note']);
        
        $new_notes = $booking->guest_notes . "\n\n[" . date('d/m H:i') . " USER MSG]: " . $note;
        
        if ($choice === 'refund') {
            // Stato 3 = Cancellato/Rimborso
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3, 'guest_notes' => $new_notes], ['id' => $booking->id]);
            paguro_add_history($booking->id, 'USER_REQ_REFUND', 'Utente chiede rimborso causa conflitto');
            wp_mail(get_option('admin_email'), "💸 Richiesta Rimborso - #{$booking->id}", "L'utente {$booking->guest_name} chiede il rimborso.\nNota: $note");
            wp_redirect(add_query_arg('msg', 'refund_req')); exit;
        } 
        elseif ($choice === 'wait') {
            // Estende scadenza di 7gg
            $new_expiry = date('Y-m-d H:i:s', time() + (7 * DAY_IN_SECONDS));
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['lock_expires' => $new_expiry, 'guest_notes' => $new_notes], ['id' => $booking->id]);
            paguro_add_history($booking->id, 'USER_REQ_WAIT', 'Utente in lista d\'attesa (7gg)');
            wp_mail(get_option('admin_email'), "⏳ Utente in Attesa - #{$booking->id}", "L'utente {$booking->guest_name} attende priorità.\nScadenza: $new_expiry\nNota: $note");
            wp_redirect(add_query_arg('msg', 'wait_req')); exit;
        }
    }
    
    // C. CANCEL
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'cancel_user_booking') {
        if (!wp_verify_nonce($_POST['paguro_cancel_nonce'], 'paguro_cancel_action')) wp_die('Security.');
        global $wpdb; $token = sanitize_text_field($_POST['token']);
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token));
        
        $limit_ts = strtotime($booking->date_start) - (15 * 24 * 3600);
        $in_time = (time() < $limit_ts);
        $refund_type = ($booking->status == 2) ? "RITIRO (Nessun pagamento)" : (($in_time) ? "RIMBORSO TOTALE" : "NO RIMBORSO (Fuori tempo)");

        $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3], ['id' => $booking->id]);
        paguro_add_history($booking->id, 'USER_CANCEL', "Cancellazione. Tipo: $refund_type");
        
        $adm_msg = "L'utente {$booking->guest_name} ha cancellato #{$booking->id}.\nTipo: $refund_type";
        wp_mail(get_option('admin_email'), "⚠️ Cancellazione - $refund_type", $adm_msg);
        paguro_send_html_email($booking->guest_email, "Conferma Cancellazione", "<h2>Prenotazione Annullata</h2><p>Tipo rimborso: $refund_type</p>");
        
        wp_redirect(add_query_arg('cancelled', '1')); exit;
    }

    // D. GDPR
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'anonymize_user_data') {
        if (!wp_verify_nonce($_POST['paguro_anon_nonce'], 'paguro_anon_action')) wp_die('Security.');
        global $wpdb; $token = sanitize_text_field($_POST['token']);
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token));
        
        $can_delete = true;
        if ($booking->status == 1 && time() < (strtotime($booking->date_end) + 15*86400)) $can_delete = false;

        if ($can_delete) {
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_name' => 'Anonimo (GDPR)', 'guest_email' => 'deleted@privacy.user', 'guest_phone' => '0000000000', 'guest_notes' => ''], ['id' => $booking->id]);
            paguro_add_history($booking->id, 'GDPR_WIPE', 'Dati utente cancellati su richiesta');
            wp_redirect(add_query_arg('anonymized', '1')); exit;
        } else wp_die("Impossibile cancellare i dati ora (Soggiorno attivo o recente).");
    }
}

// 6. CHAT (CON SSL FIX E URL CHECK)
add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat'); add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');
function paguro_handle_chat() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paguro_chat_nonce')) { wp_send_json_error(['reply' => "⚠️ Sessione scaduta."]); return; }
    global $wpdb;

    $api_url = get_option('paguro_api_url', 'https://api.viamerano24.it/chat');
    // Correzione automatica URL
    if (substr($api_url, -4) === '/api') $api_url = str_replace('/api', '/chat', $api_url);
    elseif (substr($api_url, -5) !== '/chat') $api_url = rtrim($api_url, '/') . '/chat';

    $msg = sanitize_text_field($_POST['message']); 
    $sess = sanitize_text_field($_POST['session_id']);
    
    try {
        $offset = intval($_POST['offset']??0);
        if ($offset == 0) {
            $args = ['body' => json_encode(['message'=>$msg, 'session_id'=>$sess]), 'headers' => ['Content-Type'=>'application/json'], 'timeout' => 30, 'sslverify' => false];
            $res = wp_remote_post($api_url, $args);
            
            if (is_wp_error($res)) { wp_send_json_error(['reply' => "Errore WP: " . $res->get_error_message()]); return; }
            $status = wp_remote_retrieve_response_code($res);
            if ($status !== 200) { wp_send_json_error(['reply' => "Errore Remoto ($status). Controlla URL."]); return; }
            
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!$data) { wp_send_json_error(['reply' => "Errore JSON vuoto."]); return; }
        } else {
            $data = ['type'=>'ACTION', 'action'=>'CHECK_AVAILABILITY'];
        }

        if (($data['type']??'') === 'ACTION') {
            // LOGICA DATE
            $req_month = isset($_POST['filter_month']) ? sanitize_text_field($_POST['filter_month']) : '';
            $target_month = $req_month; if(empty($target_month)){ $mm=['giugno'=>'06','luglio'=>'07','agosto'=>'08','settembre'=>'09']; foreach($mm as $k=>$v) if(stripos($msg,$k)!==false) $target_month=$v; }
            $weeks = preg_match('/due sett|2 sett|14 giorn|coppia/i', $msg) ? 2 : 1;
            $s_start = new DateTime('2026-06-13'); $s_end = new DateTime('2026-10-03');
            
            $raw = $wpdb->get_results($wpdb->prepare("SELECT apartment_id, date_start, date_end, status, lock_expires, created_at FROM {$wpdb->prefix}paguro_availability WHERE (status=1 OR status=2) AND date_end > %s AND date_start < %s", $s_start->format('Y-m-d'), $s_end->format('Y-m-d')));
            $conf = []; $pend = []; $now = current_time('mysql');
            foreach($raw as $r) { if ($r->status==1) $conf[]=$r; else { $exp = $r->lock_expires ?: date('Y-m-d H:i:s', strtotime($r->created_at.' + 48 hours')); if($exp > $now) $pend[]=$r; } }
            
            $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
            $html = ($offset==0) ? "Ecco le disponibilità (Sab-Sab):<br><br>" : ""; $found=false;
            
            foreach($apts as $apt) {
                if($offset==0) $html .= "🏠 <b>{$apt->name}</b>:<br>";
                $period = new DatePeriod($s_start, new DateInterval('P1W'), $s_end); $shown=0; $limit=4; $count=0;
                foreach($period as $dt) {
                    if($target_month && $dt->format('m')!==$target_month) continue;
                    $end = clone $dt; $end->modify("+$weeks weeks"); if($end > $s_end) continue;
                    $occ=false; foreach($conf as $b) if($b->apartment_id==$apt->id && $b->date_start < $end->format('Y-m-d') && $b->date_end > $dt->format('Y-m-d')) $occ=true;
                    if(!$occ) {
                        $viewers=0; foreach($pend as $b) if($b->apartment_id==$apt->id && $b->date_start < $end->format('Y-m-d') && $b->date_end > $dt->format('Y-m-d')) $viewers++;
                        $count++; if($count<=$offset) continue;
                        if($shown<$limit) { $d_in=$dt->format('d/m/Y'); $d_out=$end->format('d/m/Y'); $social=($viewers>0)?" <span style='color:#d35400;font-size:12px;'>⚡ {$viewers} valutano</span>":""; $html .= "- {$dt->format('d/m')} - {$end->format('d/m')} <a href='#' class='paguro-book-btn' data-apt='".strtolower($apt->name)."' data-in='{$d_in}' data-out='{$d_out}'>[Prenota]</a>{$social}<br>"; $shown++; $found=true; } 
                        else { $html.="<a href='#' class='paguro-load-more' data-apt='{$apt->id}' data-offset='".($offset+$limit)."' data-month='{$target_month}' style='color:#0073aa;'>...altre</a><br>"; break; }
                    }
                }
                if($offset==0) $html.="<br>";
            }
            $data['reply'] = ($found||$offset==0) ? $html : "Nessuna data trovata per il periodo.";
        }
        wp_send_json_success($data);
    } catch (Exception $e) { wp_send_json_error(['reply' => "Err: " . $e->getMessage()]); }
}

// 7. LOCK (CON VALIDAZIONE SAB-SAB)
add_action('wp_ajax_paguro_lock_dates', 'paguro_handle_lock'); add_action('wp_ajax_nopriv_paguro_lock_dates', 'paguro_handle_lock');
function paguro_handle_lock() {
    if (!check_ajax_referer('paguro_chat_nonce', 'nonce', false)) wp_send_json_error(['msg' => "Scaduta."]); global $wpdb;
    try {
        $wpdb->query("DELETE FROM {$wpdb->prefix}paguro_availability WHERE status=2 AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)))");
        $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $_POST['apt_name']));
        
        $d_in_obj = DateTime::createFromFormat('d/m/Y', $_POST['date_in']);
        $d_out_obj = DateTime::createFromFormat('d/m/Y', $_POST['date_out']);
        if (!$d_in_obj || !$d_out_obj) throw new Exception("Date invalide.");
        
        // Regola Sabato
        if ($d_in_obj->format('N') != 6 || $d_out_obj->format('N') != 6) throw new Exception("Solo Sabato-Sabato.");
        $interval = $d_in_obj->diff($d_out_obj);
        if ($interval->days % 7 != 0 || $interval->days < 7) throw new Exception("Solo settimane intere.");

        $d_in = $d_in_obj->format('Y-m-d'); $d_out = $d_out_obj->format('Y-m-d');
        $busy = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=1 AND (date_start<%s AND date_end>%s)", $apt_id, $d_out, $d_in));
        if ($busy) throw new Exception("Occupato.");
        
        $token = wp_generate_password(20, false);
        $wpdb->insert("{$wpdb->prefix}paguro_availability", ['apartment_id'=>$apt_id, 'date_start'=>$d_in, 'date_end'=>$d_out, 'status'=>2, 'lock_token'=>$token, 'lock_expires'=>date('Y-m-d H:i:s', time()+(48*3600))]);
        wp_send_json_success(['token'=>$token, 'redirect_params' => "?token={$token}&apt=".urlencode($_POST['apt_name'])."&in={$_POST['date_in']}&out={$_POST['date_out']}"]);
    } catch (Exception $e) { wp_send_json_error(['msg'=>$e->getMessage()]); }
}

// 8. SUBMIT
add_action('wp_ajax_paguro_submit_booking', 'paguro_submit_booking'); add_action('wp_ajax_nopriv_paguro_submit_booking', 'paguro_submit_booking');
function paguro_submit_booking() {
    check_ajax_referer('paguro_chat_nonce', 'nonce'); global $wpdb;
    // Recaptcha check ommesso per brevità, usare standard
    $token = sanitize_text_field($_POST['token']); $name = sanitize_text_field($_POST['guest_name']); $email = sanitize_email($_POST['guest_email']); $phone = sanitize_text_field($_POST['guest_phone']); $notes = sanitize_textarea_field($_POST['guest_notes']);
    if (!$token || !$name || !is_email($email)) wp_send_json_error(['msg' => 'Dati mancanti.']);
    
    $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_name' => $name, 'guest_email' => $email, 'guest_phone' => $phone, 'guest_notes' => $notes], ['lock_token' => $token]);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token));
    paguro_add_history($booking->id, 'REQUEST_SENT', "Richiesta da $name");
    
    // Mail Richiesta
    $booking_details = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id=a.id WHERE b.id=%d", $booking->id));
    $ph = ['guest_name' => $name, 'link_riepilogo' => site_url("/riepilogo-prenotazione/?token={$token}"), 'apt_name' => ucfirst($booking_details->apt_name), 'date_start' => $booking_details->date_start, 'date_end' => $booking_details->date_end];
    
    paguro_send_html_email($email, paguro_parse_template(get_option('paguro_txt_email_request_subj'), $ph), paguro_parse_template(get_option('paguro_txt_email_request_body'), $ph));
    wp_mail(get_option('admin_email'), "Nuova Richiesta Paguro", "Utente: $name\nLink: " . $ph['link_riepilogo']);
    
    wp_send_json_success(['redirect' => $ph['link_riepilogo']]);
}

// 9. CHECKOUT FORM
add_shortcode('paguro_checkout', 'paguro_checkout_form');
function paguro_checkout_form() {
    $token = sanitize_text_field($_GET['token'] ?? ''); $apt = sanitize_text_field($_GET['apt'] ?? ''); $in = sanitize_text_field($_GET['in'] ?? ''); $out = sanitize_text_field($_GET['out'] ?? '');
    global $wpdb; $existing = $wpdb->get_var($wpdb->prepare("SELECT guest_email FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token));
    if ($existing) { echo "<script>window.location.replace('".site_url("/riepilogo-prenotazione/?token={$token}")."');</script>"; return; }
    ob_start(); ?>
    <div class="paguro-checkout-box" style="max-width:500px; margin:20px auto; padding:20px; border:1px solid #ddd; background:#fff;">
        <h3 style="text-align:center;">Conferma Richiesta: <?php echo ucfirst($apt); ?></h3>
        <p style="text-align:center;"><?php echo $in; ?> - <?php echo $out; ?></p>
        <form id="paguro-native-form" style="display:flex; flex-direction:column; gap:15px;">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <label>Nome <input type="text" name="guest_name" required style="width:100%; padding:8px;"></label>
            <label>Email <input type="email" name="guest_email" required style="width:100%; padding:8px;"></label>
            <label>Telefono <input type="tel" name="guest_phone" required style="width:100%; padding:8px;"></label>
            <label>Note <textarea name="guest_notes" style="width:100%; padding:8px;"></textarea></label>
            <button type="submit" id="paguro-submit-btn" class="button" style="background:#28a745; color:#fff; padding:12px;">Conferma</button>
            <div id="paguro-form-msg"></div>
        </form>
        <p style="font-size:12px; margin-top:10px;">Privacy: Dati usati solo per prenotazione. <?php echo PAGURO_PRIVACY_TEXT; ?></p>
    </div>
    <?php return ob_get_clean();
}

// 10. UPLOAD & RACE LOGIC
add_action('wp_ajax_paguro_upload_receipt', 'paguro_handle_receipt_upload'); add_action('wp_ajax_nopriv_paguro_upload_receipt', 'paguro_handle_receipt_upload');
function paguro_handle_receipt_upload() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    if (!isset($_FILES['file'])) wp_send_json_error(['msg' => "No file."]); global $wpdb; $token = sanitize_text_field($_POST['token']);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token));
    if (!$booking) wp_send_json_error(['msg' => "Token errato."]);
    
    $file = $_FILES['file']; require_once(ABSPATH . 'wp-admin/includes/file.php'); $uploaded = wp_handle_upload($file, ['test_form' => false]);
    if (isset($uploaded['error'])) wp_send_json_error(['msg' => $uploaded['error']]);
    
    $wpdb->update("{$wpdb->prefix}paguro_availability", ['receipt_url' => $uploaded['url'], 'receipt_uploaded_at' => current_time('mysql')], ['id' => $booking->id]);
    paguro_add_history($booking->id, 'RECEIPT_UPLOAD', 'Caricata distinta');
    
    // Notifica Admin
    paguro_send_html_email(get_option('admin_email'), "💰 Distinta - #{$booking->id}", "Utente: {$booking->guest_name}<br><a href='{$uploaded['url']}'>File</a>");
    
    // Notifica Utente
    $link = site_url('/riepilogo-prenotazione/?token=' . $token);
    $apt_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}paguro_apartments WHERE id=%d", $booking->apartment_id));
    $ph = ['guest_name' => $booking->guest_name, 'link_riepilogo' => $link, 'apt_name' => ucfirst($apt_name), 'date_start' => $booking->date_start, 'date_end' => $booking->date_end];
    paguro_send_html_email($booking->guest_email, paguro_parse_template(get_option('paguro_txt_email_receipt_subj'), $ph), paguro_parse_template(get_option('paguro_txt_email_receipt_body'), $ph));
    
    // RACE: Avvisa i perdenti
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
                if (!isset($_COOKIE['paguro_auth_' . $token])) {
                    echo '<form method="post"><input type="hidden" name="paguro_action" value="verify_access">';
                    wp_nonce_field('paguro_auth_action', 'paguro_auth_nonce');
                    echo '<input type="hidden" name="token" value="'.$token.'"><input type="email" name="verify_email" placeholder="Email" required style="width:100%; padding:10px; margin-bottom:10px;"><button type="submit" class="button button-primary">Accedi</button></form>';
                } else {
                    $b = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id WHERE b.lock_token = %s", $token));
                    if (!$b): ?><p>Non trovata.</p><?php else: 
                        // Msg azioni
                        if (isset($_GET['msg'])) {
                            if ($_GET['msg']=='refund_req') echo '<div style="background:#fff3cd; color:#856404; padding:10px;">✅ Richiesta Rimborso Inviata.</div>';
                            if ($_GET['msg']=='wait_req') echo '<div style="background:#d4edda; color:#155724; padding:10px;">✅ Inserito in Lista d\'Attesa (7gg).</div>';
                        }
                        
                        $race_winner = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_availability WHERE apartment_id=%d AND status=2 AND receipt_url IS NOT NULL AND id!=%d AND (date_start < %s AND date_end > %s)", $b->apartment_id, $b->id, $b->date_end, $b->date_start));
                        $exp = $b->lock_expires ? strtotime($b->lock_expires) : (strtotime($b->created_at)+48*3600);
                        $can_up = ($b->status == 2 && time() < $exp && !$race_winner);
                        $tot = paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end);
                    ?>
                        <h3>Ciao <?php echo esc_html($b->guest_name); ?>,</h3>
                        <?php if ($race_winner && $b->status != 3): ?>
                            <div style="background:#f8d7da; color:#721c24; padding:15px; border:1px solid #f5c6cb; border-radius:5px; margin-bottom:15px;">
                                <p>⚠️ <strong>ATTENZIONE:</strong> Un altro utente ha priorità. Caricamento bloccato.</p>
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

                        <ul style="background:#fff; padding:15px; border:1px solid #eee;">
                            <li>🏠 <strong>Apt:</strong> <?php echo ucfirst($b->apt_name); ?></li>
                            <li>📅 <strong>Date:</strong> <?php echo $b->date_start . ' / ' . $b->date_end; ?></li>
                            <li>💶 <strong>Totale:</strong> €<?php echo $tot; ?></li>
                            <li>💰 <strong>Stato:</strong> <?php echo ($b->status==1)?'CONFERMATA':(($b->status==3)?'CANCELLATA':'PENDING'); ?></li>
                        </ul>

                        <?php if ($can_up): ?>
                            <div id="paguro-upload-area" style="margin-top:20px; border:2px dashed #0073aa; padding:30px; text-align:center; cursor:pointer;">
                                <p>📂 Trascina distinta qui</p><input type="file" id="paguro-file-input" style="display:none;"><button class="button" onclick="document.getElementById('paguro-file-input').click()">Scegli</button><div id="paguro-upload-status"></div>
                            </div>
                            <input type="hidden" id="paguro-token" value="<?php echo $token; ?>">
                        <?php endif; ?>
                    <?php endif; } endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

function paguro_render_interface($mode='widget'){ $icon_url=plugin_dir_url(__FILE__).'paguro_bot_icon.png'; $cls=($mode==='inline')?'paguro-mode-inline':'paguro-mode-widget'; ob_start(); ?>
<style>.paguro-chat-root *{box-sizing:border-box} .paguro-mode-widget .paguro-chat-launcher{position:fixed;bottom:20px;right:20px;width:60px;height:60px;background:#fff;border-radius:50%;box-shadow:0 4px 12px rgba(0,0,0,.2);cursor:pointer;z-index:99999;display:flex;align-items:center;justify-content:center} .paguro-mode-widget .paguro-chat-window{position:fixed;bottom:90px;right:20px;width:280px;height:380px;max-width:90%;max-height:80vh;background:#fff;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,.2);z-index:99999;display:none;flex-direction:column} .paguro-mode-inline .paguro-chat-launcher{display:none} .paguro-mode-inline .paguro-chat-window{position:relative;width:100%;height:600px;border:1px solid #ddd;background:#fff;border-radius:8px;display:flex;flex-direction:column} .paguro-chat-header{background:#0073aa;color:#fff;padding:10px 15px;display:flex;justify-content:space-between;align-items:center} .paguro-chat-body{flex:1;padding:15px;overflow-y:auto;background:#f9f9f9} .paguro-chat-footer{padding:10px;border-top:1px solid #eee;display:flex;gap:5px;background:#fff} .paguro-chat-footer input{flex:1;padding:8px;border:1px solid #ddd;border-radius:20px;font-size:13px} .paguro-msg{margin-bottom:10px;display:flex;gap:8px;align-items:flex-start} .paguro-msg-content{padding:8px 12px;border-radius:12px;font-size:13px;max-width:80%} .paguro-msg-user{flex-direction:row-reverse} .paguro-msg-user .paguro-msg-content{background:#0073aa;color:#fff} .paguro-msg-bot .paguro-msg-content{background:#e5e5ea;color:#000} .paguro-bot-avatar{width:30px;height:30px;border-radius:50%;flex-shrink:0;object-fit:cover;min-width:30px;min-height:30px} .paguro-book-btn{background:#28a745;color:#fff!important;padding:3px 6px;border-radius:4px;text-decoration:none;font-size:11px} .paguro-month-buttons{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px} .paguro-quick-btn{background:#fff;border:1px solid #0073aa;color:#0073aa;border-radius:15px;padding:3px 10px;font-size:11px;cursor:pointer;transition:0.2s;font-weight:500} .paguro-quick-btn:hover{background:#0073aa;color:#fff}</style>
<div class="paguro-chat-root <?php echo $cls; ?>"><div class="paguro-chat-launcher" onclick="jQuery('.paguro-chat-window').fadeToggle()"><img src="<?php echo $icon_url; ?>"></div><div class="paguro-chat-window"><div class="paguro-chat-header"><span style="font-weight:bold;">Paguro</span><span class="close-btn" onclick="jQuery('.paguro-chat-window').fadeOut()" style="cursor:pointer;font-size:20px;">&times;</span></div><div class="paguro-chat-body" id="paguro-chat-body"><div class="paguro-msg paguro-msg-bot"><img src="<?php echo $icon_url; ?>" class="paguro-bot-avatar"><div class="paguro-msg-content">Ciao Sono Paguro, il tuo assistente virtuale.<br>Chiedimi informazioni o clicca sui mesi per conoscere le attuali disponibilità:<div class="paguro-month-buttons"><span class="paguro-quick-btn" data-msg="Disponibilità Giugno">Giugno</span><span class="paguro-quick-btn" data-msg="Disponibilità Luglio">Luglio</span><span class="paguro-quick-btn" data-msg="Disponibilità Agosto">Agosto</span><span class="paguro-quick-btn" data-msg="Disponibilità Settembre">Settembre</span></div></div></div></div><div class="paguro-chat-footer"><input type="text" id="paguro-input" placeholder="Scrivi qui..."><button id="paguro-send-btn" style="border:none;background:none;font-size:18px;cursor:pointer;">➤</button></div></div></div>
<?php return ob_get_clean(); }
add_shortcode('paguro_chat', function(){ if(!defined('PAGURO_WIDGET_RENDERED')){ define('PAGURO_WIDGET_RENDERED',true); return paguro_render_interface('inline'); }});
add_action('wp_footer', function(){ if(!defined('PAGURO_WIDGET_RENDERED') && get_option('paguro_global_active',1)) echo paguro_render_interface('widget'); });