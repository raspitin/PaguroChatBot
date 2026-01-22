<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Versione 2.5.0 - Empathic Cancellation & UI Polish
 * Version: 2.5.0
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

// --- CONFIGURAZIONE ---
define('PAGURO_API_URL', 'https://api.viamerano24.it/chat'); 
define('PAGURO_VERSION', '2.5.0'); 

// 1. SETUP & DB
register_activation_hook(__FILE__, 'paguro_create_tables');
function paguro_create_tables() {
    global $wpdb; $charset = $wpdb->get_charset_collate();
    
    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_apartments (
        id mediumint(9) AUTO_INCREMENT, name varchar(100), base_price decimal(10,2), pricing_json longtext, PRIMARY KEY (id)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_availability (
        id mediumint(9) AUTO_INCREMENT, apartment_id mediumint(9), date_start date, date_end date, 
        status tinyint(1) DEFAULT 0, guest_name varchar(100), guest_email varchar(100), guest_phone varchar(50), guest_notes text, 
        lock_token varchar(50), lock_expires datetime, 
        receipt_url varchar(255), receipt_uploaded_at datetime,
        created_at datetime DEFAULT CURRENT_TIMESTAMP, 
        PRIMARY KEY (id), KEY apartment_id (apartment_id)
    ) $charset;";
        
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php'); dbDelta($sql1); dbDelta($sql2);
    
    if ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_apartments") == 0) {
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'corallo', 'base_price' => 500]);
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'tartaruga', 'base_price' => 500]);
    }
    if (!get_option('paguro_txt_email_confirm_subj')) paguro_set_defaults();
}

function paguro_set_defaults() {
    update_option('paguro_recaptcha_site', '');
    update_option('paguro_recaptcha_secret', '');
    update_option('paguro_txt_email_receipt_subj', 'Ricezione Distinta - {guest_name}');
    update_option('paguro_txt_email_receipt_body', "<h2>Ciao {guest_name},</h2><p>Abbiamo ricevuto la tua distinta. Stiamo verificando il pagamento.</p>");
    update_option('paguro_txt_email_confirm_subj', 'Conferma {apt_name} dal {date_start}');
    update_option('paguro_txt_email_confirm_body', "<h2>Confermata!</h2><p>Soggiorno confermato.</p>");
    update_option('paguro_txt_email_request_subj', 'Richiesta {apt_name} - {date_start}');
    update_option('paguro_txt_email_request_body', "<h2>Richiesta inviata</h2>");
}

add_action('plugins_loaded', 'paguro_update_db_structure');
function paguro_update_db_structure() {
    if (get_transient('paguro_db_check_250')) return;
    global $wpdb; 
    $t1 = $wpdb->prefix . 'paguro_availability'; $cols1 = $wpdb->get_col("DESC $t1", 0);
    if (!in_array('lock_expires', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN lock_expires DATETIME NULL");
    if (!in_array('receipt_url', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN receipt_url VARCHAR(255) NULL");
    if (!in_array('guest_notes', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN guest_notes TEXT NULL");
    if (!in_array('receipt_uploaded_at', $cols1)) $wpdb->query("ALTER TABLE $t1 ADD COLUMN receipt_uploaded_at DATETIME NULL");
    $t2 = $wpdb->prefix . 'paguro_apartments'; $cols2 = $wpdb->get_col("DESC $t2", 0);
    if (!in_array('pricing_json', $cols2)) $wpdb->query("ALTER TABLE $t2 ADD COLUMN pricing_json LONGTEXT NULL");
    set_transient('paguro_db_check_250', true, DAY_IN_SECONDS);
}

// 2. ASSETS
add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() {
    wp_enqueue_script('paguro-js', plugin_dir_url(__FILE__) . 'paguro-front.js', ['jquery'], PAGURO_VERSION, true);
    $site_key = get_option('paguro_recaptcha_site');
    if ($site_key) wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . $site_key, [], null, true);
    wp_localize_script('paguro-js', 'paguroData', [
        'ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('paguro_chat_nonce'),
        'booking_url' => site_url('/prenotazione/'), 'icon_url' => plugin_dir_url(__FILE__) . 'paguro_bot_icon.png', 'recaptcha_site' => $site_key
    ]);
}

// 3. ADMIN MENU
add_action('admin_menu', function() { add_menu_page('Gestione Paguro', 'Paguro Booking', 'manage_options', 'paguro-booking', function(){ require_once plugin_dir_path(__FILE__).'admin-page.php'; }, 'dashicons-building', 50); });

// 4. HELPER EMAIL & CALCOLO
function paguro_send_html_email($to, $subject, $content) {
    $site_name = "Villa Celi";
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:sans-serif;background:#f6f6f6;padding:20px}.container{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 5px rgba(0,0,0,.1)}.header{background:#0073aa;padding:20px;text-align:center}.header h1{color:#fff;margin:0;font-size:24px}.content{padding:30px;color:#333;line-height:1.6}.footer{background:#eee;padding:15px;text-align:center;font-size:12px;color:#777}.btn{display:inline-block;background:#28a745;color:#fff;text-decoration:none;padding:10px 20px;border-radius:5px;font-weight:bold;margin-top:15px}</style></head><body><div class="container"><div class="header"><h1>'.$site_name.'</h1></div><div class="content">'.$content.'</div><div class="footer">&copy; '.date('Y').' '.$site_name.' <br> <a href="mailto:info@villaceli.it">info@villaceli.it</a></div></div></body></html>';
    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Villa Celi <info@villaceli.it>');
    return wp_mail($to, $subject, $html, $headers);
}
function paguro_parse_template($text, $data) { foreach ($data as $key => $val) { $text = str_replace('{' . $key . '}', $val, $text); } return $text; }
function paguro_calculate_quote($apt_id, $date_start, $date_end) {
    global $wpdb; $apt = $wpdb->get_row($wpdb->prepare("SELECT base_price, pricing_json FROM {$wpdb->prefix}paguro_apartments WHERE id = %d", $apt_id));
    if (!$apt) return 0;
    $prices = json_decode($apt->pricing_json, true) ?: []; $total = 0;
    $current = new DateTime($date_start); $end = new DateTime($date_end); $interval = new DateInterval('P1W'); 
    while ($current < $end) { $key = $current->format('Y-m-d'); $total += (isset($prices[$key]) && $prices[$key]>0) ? floatval($prices[$key]) : floatval($apt->base_price); $current->add($interval); }
    return $total;
}

// 5. AUTH & CANCEL HANDLER (TESTO EMPATICO QUI)
add_action('init', 'paguro_handle_post_actions');
function paguro_handle_post_actions() {
    // LOGIN
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'verify_access') {
        if (!wp_verify_nonce($_POST['paguro_auth_nonce'], 'paguro_auth_action')) wp_die('Security.');
        global $wpdb; $token = sanitize_text_field($_POST['token']); $email = sanitize_email($_POST['verify_email']);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s AND guest_email=%s", $token, $email));
        if ($exists) {
            setcookie('paguro_auth_' . $token, hash('sha256', $token . 'salt'), time() + (7 * DAY_IN_SECONDS), '/');
            nocache_headers(); wp_redirect(add_query_arg('t', time(), remove_query_arg('auth_error'))); exit;
        } else { wp_redirect(add_query_arg('auth_error', '1')); exit; }
    }
    
    // CANCELLATION
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'cancel_user_booking') {
        if (!wp_verify_nonce($_POST['paguro_cancel_nonce'], 'paguro_cancel_action')) wp_die('Security.');
        global $wpdb; $token = sanitize_text_field($_POST['token']);
        
        if (!isset($_COOKIE['paguro_auth_' . $token])) wp_die('Accesso negato.');
        
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token));
        
        $is_status_1 = ($booking->status == 1);
        $is_status_2 = ($booking->status == 2 && $booking->receipt_url);
        $limit_ts = strtotime($booking->date_start) - (15 * 24 * 3600);
        $in_time = (time() < $limit_ts);

        if (($is_status_1 && $in_time) || $is_status_2) {
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3], ['id' => $booking->id]);
            
            $status_msg = ($is_status_1) ? "Rimborso Richiesto" : "Richiesta Ritirata";
            $adm_msg = "L'utente {$booking->guest_name} ha cancellato la prenotazione #{$booking->id}.\nStato: CANCELLATA DA UTENTE.\nTipo: $status_msg";
            wp_mail(get_option('admin_email'), "⚠️ Cancellazione - $status_msg", $adm_msg);
            
            // TESTO EMPATICO CANCELLAZIONE
            $user_subject = "Cancellazione Confermata - Villa Celi";
            $user_body = "<h2>Ci dispiace che tu non possa venire. 😔</h2>
            <p>Gentile {$booking->guest_name},</p>
            <p>Ti confermiamo che la tua prenotazione è stata <strong>annullata</strong> come da tua richiesta.</p>
            <p>Siamo sinceramente dispiaciuti di non poterti ospitare questa volta, ma siamo altrettanto felici di garantirti la massima serenità:</p>
            <div style='background:#e7f9e7; padding:15px; border:1px solid #28a745; border-radius:5px; margin:15px 0;'>
                <strong>✅ Rimborso Caparra Avviato</strong><br>
                Procederemo alla restituzione dell'intero importo versato sull'IBAN di provenienza.<br>
                I tempi tecnici dipendono dalla banca (solitamente 2-5 giorni lavorativi).
            </div>
            <p>Speriamo di poterti accogliere in futuro a Villa Celi!</p>
            <p>Un caro saluto,<br><em>Lo Staff</em></p>";

            paguro_send_html_email($booking->guest_email, $user_subject, $user_body);
            
            wp_redirect(add_query_arg('cancelled', '1')); exit;
        } else {
            wp_die('Impossibile cancellare.');
        }
    }
}

// 6. CHAT
add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat'); add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');
function paguro_handle_chat() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paguro_chat_nonce')) { wp_send_json_error(['reply' => "⚠️ Sessione scaduta."]); return; }
    global $wpdb;
    try {
        $msg = sanitize_text_field($_POST['message']); $sess = sanitize_text_field($_POST['session_id']); $offset = intval($_POST['offset']??0);
        $req_month = isset($_POST['filter_month']) ? sanitize_text_field($_POST['filter_month']) : '';
        if ($offset == 0) {
            $res = wp_remote_post(PAGURO_API_URL, ['body'=>json_encode(['message'=>$msg, 'session_id'=>$sess]), 'headers'=>['Content-Type'=>'application/json'], 'timeout'=>15]);
            $data = (!is_wp_error($res)) ? json_decode(wp_remote_retrieve_body($res), true) : ['type'=>'ACTION', 'action'=>'CHECK_AVAILABILITY'];
        } else $data = ['type'=>'ACTION', 'action'=>'CHECK_AVAILABILITY'];

        if (($data['type']??'') === 'ACTION') {
            $target_month = $req_month; if(empty($target_month)){ $mm=['giugno'=>'06','luglio'=>'07','agosto'=>'08','settembre'=>'09']; foreach($mm as $k=>$v) if(stripos($msg,$k)!==false) $target_month=$v; }
            $weeks = preg_match('/due sett|2 sett|14 giorn/i', $msg) ? 2 : 1;
            $s_start = new DateTime('2026-06-13'); $s_end = new DateTime('2026-10-03');
            $raw = $wpdb->get_results($wpdb->prepare("SELECT apartment_id, date_start, date_end, status, lock_expires, created_at FROM {$wpdb->prefix}paguro_availability WHERE (status=1 OR status=2) AND date_end > %s AND date_start < %s", $s_start->format('Y-m-d'), $s_end->format('Y-m-d')));
            $conf = []; $pend = []; $now = current_time('mysql');
            foreach($raw as $r) { if ($r->status==1) $conf[]=$r; else { $exp = $r->lock_expires ?: date('Y-m-d H:i:s', strtotime($r->created_at.' + 48 hours')); if($exp > $now) $pend[]=$r; } }
            $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
            $html = ($offset==0) ? "Ecco le disponibilità:<br><br>" : ""; $found=false;
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
                        if($shown<$limit) {
                            $social = ($viewers>0)?" <span style='color:#d35400;font-size:12px;'>⚡ {$viewers} valutano</span>":"";
                            $html .= "- {$dt->format('d/m')} - {$end->format('d/m')} <a href='#' class='paguro-book-btn' data-apt='".strtolower($apt->name)."' data-in='{$dt->format('d/m/Y')}' data-out='{$end->format('d/m/Y')}'>[Prenota]</a>{$social}<br>";
                            $shown++; $found=true;
                        } else { $html.="<a href='#' class='paguro-load-more' data-apt='{$apt->id}' data-offset='".($offset+$limit)."' data-month='{$target_month}' style='color:#0073aa;'>...altre</a><br>"; break; }
                    }
                }
                if($offset==0) $html.="<br>";
            }
            $data['reply'] = ($found||$offset==0) ? $html : "Fine date.";
        }
        wp_send_json_success($data);
    } catch (Exception $e) { wp_send_json_success(['reply' => "⚠️ Errore tecnico."]); }
}

// 7. LOCK
add_action('wp_ajax_paguro_lock_dates', 'paguro_handle_lock'); add_action('wp_ajax_nopriv_paguro_lock_dates', 'paguro_handle_lock');
function paguro_handle_lock() {
    if (!check_ajax_referer('paguro_chat_nonce', 'nonce', false)) wp_send_json_error(['msg' => "Scaduta."]); global $wpdb;
    try {
        $wpdb->query("DELETE FROM {$wpdb->prefix}paguro_availability WHERE status=2 AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)))");
        $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $_POST['apt_name']));
        $d_in = DateTime::createFromFormat('d/m/Y', $_POST['date_in'])->format('Y-m-d'); $d_out = DateTime::createFromFormat('d/m/Y', $_POST['date_out'])->format('Y-m-d');
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
    $recaptcha_secret = get_option('paguro_recaptcha_secret');
    if ($recaptcha_secret && !empty($_POST['recaptcha_token'])) {
        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', ['body' => ['secret' => $recaptcha_secret, 'response' => $_POST['recaptcha_token']]]);
        $result = json_decode(wp_remote_retrieve_body($response));
        if (!$result->success || $result->score < 0.5) wp_send_json_error(['msg' => 'Security Check Failed.']);
    }
    $token = sanitize_text_field($_POST['token']); $name = sanitize_text_field($_POST['guest_name']); $email = sanitize_email($_POST['guest_email']); $phone = sanitize_text_field($_POST['guest_phone']); $notes = sanitize_textarea_field($_POST['guest_notes']);
    if (!$token || !$name || !is_email($email) || !$phone) wp_send_json_error(['msg' => 'Dati mancanti.']);
    $updated = $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_name' => $name, 'guest_email' => $email, 'guest_phone' => $phone, 'guest_notes' => $notes], ['lock_token' => $token]);
    if ($updated === false) wp_send_json_error(['msg' => 'Errore DB.']);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id=a.id WHERE b.lock_token = %s", $token));
    $total_cost = paguro_calculate_quote($booking->apartment_id, $booking->date_start, $booking->date_end);
    $deposit_cost = ceil($total_cost * 0.30); 
    $link = site_url("/riepilogo-prenotazione/?token={$token}");
    $ph = ['guest_name' => $name, 'link_riepilogo' => $link, 'apt_name' => ucfirst($booking->apt_name), 'date_start' => date('d/m/Y', strtotime($booking->date_start)), 'date_end' => date('d/m/Y', strtotime($booking->date_end)), 'total_cost' => $total_cost, 'deposit_cost' => $deposit_cost];
    $subj = paguro_parse_template(get_option('paguro_txt_email_request_subj', 'Richiesta Ricevuta'), $ph);
    $body = paguro_parse_template(get_option('paguro_txt_email_request_body'), $ph);
    paguro_send_html_email($email, $subj, $body);
    $admin_msg = "Nuova Richiesta da $name.\nTotale: €$total_cost\nNote: $notes\nLink: $link";
    wp_mail(get_option('admin_email'), "Nuova Richiesta Paguro", $admin_msg);
    wp_send_json_success(['redirect' => $link]);
}

// 9. SHORTCODE FORM
add_shortcode('paguro_checkout', 'paguro_checkout_form');
function paguro_checkout_form() {
    $token = sanitize_text_field($_GET['token'] ?? ''); $apt = sanitize_text_field($_GET['apt'] ?? ''); $in = sanitize_text_field($_GET['in'] ?? ''); $out = sanitize_text_field($_GET['out'] ?? '');
    global $wpdb; $existing = $wpdb->get_var($wpdb->prepare("SELECT guest_email FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token));
    if ($existing) { $url = site_url("/riepilogo-prenotazione/?token={$token}"); echo "<script>window.location.replace('$url');</script>"; return; }
    ob_start(); ?>
    <div class="paguro-checkout-box" style="max-width:500px; margin:20px auto; padding:20px; border:1px solid #ddd; border-radius:8px; background:#fff;">
        <h3 style="text-align:center; color:#0073aa;">Conferma la tua richiesta</h3>
        <p style="text-align:center; font-size:14px; color:#666;">Appartamento <strong><?php echo ucfirst($apt); ?></strong><br>Dal <strong><?php echo $in; ?></strong> al <strong><?php echo $out; ?></strong></p>
        <form id="paguro-native-form" style="display:flex; flex-direction:column; gap:15px;">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <label>Nome e Cognome <span style="color:red">*</span><input type="text" name="guest_name" required style="width:100%; padding:8px;"></label>
            <label>Email <span style="color:red">*</span><input type="email" name="guest_email" required style="width:100%; padding:8px;"></label>
            <label>Telefono <span style="color:red">*</span><input type="tel" name="guest_phone" required style="width:100%; padding:8px;"></label>
            <label>Note aggiuntive<textarea name="guest_notes" style="width:100%; padding:8px; height:80px;"></textarea></label>
            <button type="submit" id="paguro-submit-btn" class="button" style="background:#28a745; color:#fff; border:none; padding:12px; cursor:pointer; font-size:16px; border-radius:4px;">Conferma Richiesta</button>
            <div id="paguro-form-msg" style="text-align:center;"></div>
        </form>
        <p style="font-size:12px; color:#999; text-align:center; margin-top:10px;">Dati protetti. Protezione Spam by Google.</p>
    </div>
    <?php return ob_get_clean();
}

// 10. UPLOAD
add_action('wp_ajax_paguro_upload_receipt', 'paguro_handle_receipt_upload'); add_action('wp_ajax_nopriv_paguro_upload_receipt', 'paguro_handle_receipt_upload');
function paguro_handle_receipt_upload() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    if (!isset($_FILES['file'])) wp_send_json_error(['msg' => "File mancante."]); global $wpdb; $token = sanitize_text_field($_POST['token']);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id=a.id WHERE b.lock_token = %s", $token));
    if (!$booking) wp_send_json_error(['msg' => "Token errato."]);
    $file = $_FILES['file'];
    if ($file['size'] > 5 * 1024 * 1024) wp_send_json_error(['msg' => "Max 5MB."]);
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $uploaded = wp_handle_upload($file, ['test_form' => false]);
    if (isset($uploaded['error'])) wp_send_json_error(['msg' => $uploaded['error']]);
    
    $wpdb->update("{$wpdb->prefix}paguro_availability", [
        'receipt_url' => $uploaded['url'], 
        'receipt_uploaded_at' => current_time('mysql')
    ], ['id' => $booking->id]);
    
    paguro_send_html_email(get_option('admin_email'), "💰 Distinta Caricata - #{$booking->id}", "Ospite: {$booking->guest_name}<br><a href='{$uploaded['url']}'>Vedi File</a>");
    if ($booking->guest_email) {
        $link = site_url('/riepilogo-prenotazione/?token=' . $token);
        $ph = ['guest_name' => $booking->guest_name, 'link_riepilogo' => $link, 'apt_name' => ucfirst($booking->apt_name), 'date_start' => date('d/m/Y', strtotime($booking->date_start)), 'date_end' => date('d/m/Y', strtotime($booking->date_end))];
        $subj = paguro_parse_template(get_option('paguro_txt_email_receipt_subj'), $ph);
        $body = paguro_parse_template(get_option('paguro_txt_email_receipt_body'), $ph);
        paguro_send_html_email($booking->guest_email, $subj, $body);
    }
    wp_send_json_success(['url' => $uploaded['url']]);
}

// 11. SUMMARY (AGGIORNATO CON POLICY)
add_shortcode('paguro_summary', 'paguro_summary_render');
function paguro_summary_render() {
    global $wpdb; $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    ob_start(); ?>
    <div style="max-width:600px; margin:20px auto; font-family:sans-serif; border:1px solid #ddd; border-radius:8px; overflow:hidden;">
        <div style="background:#0073aa; color:#fff; padding:15px; font-size:18px; font-weight:bold;">📦 Riepilogo</div>
        <div style="padding:20px; background:#f9f9f9;">
            <?php if (!$token): ?><p>⚠️ Codice mancante.</p><?php else: 
                if (!isset($_COOKIE['paguro_auth_' . $token])) {
                    if (isset($_GET['auth_error'])) echo '<p style="color:red;">❌ Email errata.</p>';
                    echo '<p>🔒 <strong>Area Riservata</strong>. Conferma la tua email.</p><form method="post"><input type="hidden" name="paguro_action" value="verify_access">';
                    wp_nonce_field('paguro_auth_action', 'paguro_auth_nonce');
                    echo '<input type="hidden" name="token" value="'.$token.'"><input type="email" name="verify_email" placeholder="Email" required style="width:100%; padding:10px; margin-bottom:10px;"><button type="submit" class="button button-primary" style="width:100%;">Accedi</button></form>';
                } else {
                    $b = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id WHERE b.lock_token = %s", $token));
                    if (!$b): ?><p>Non trovata.</p><?php else: 
                        if (isset($_GET['cancelled'])) echo '<div style="background:#f8d7da; color:#721c24; padding:15px; border-radius:5px; margin-bottom:15px; text-align:center;">✅ <strong>Cancellazione Confermata</strong><br>Abbiamo ricevuto la tua richiesta. Riceverai presto i dettagli sul rimborso via email.</div>';
                        
                        $exp = $b->lock_expires ? strtotime($b->lock_expires) : (strtotime($b->created_at)+48*3600);
                        $can_up = ($b->status == 2 && time() < $exp);
                        $viewers = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE status=2 AND apartment_id=%d AND (date_start<%s AND date_end>%s) AND id!=%d", $b->apartment_id, $b->date_end, $b->date_start, $b->id));
                        $tot = paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end);
                        $dep = ceil($tot * 0.3);
                        
                        $is_status_1 = ($b->status == 1);
                        $is_status_2 = ($b->status == 2 && $b->receipt_url);
                        $limit_ts = strtotime($b->date_start) - (15 * 24 * 3600);
                        $can_cancel = (($is_status_1 && time() < $limit_ts) || $is_status_2);
                    ?>
                        <h3>Ciao <?php echo esc_html($b->guest_name); ?>,</h3>
                        <?php if ($viewers > 0 && $can_up): ?><div style="background:#fff3cd; color:#856404; padding:8px; border-radius:4px; margin-bottom:10px; font-size:13px;">⚡ <strong>Attenzione:</strong> Altre <?php echo $viewers; ?> persone stanno valutando queste date.</div><?php endif; ?>
                        
                        <ul style="background:#fff; padding:15px; border:1px solid #eee; list-style:none;">
                            <li>🏠 <strong>Apt:</strong> <?php echo ucfirst($b->apt_name); ?></li>
                            <li>📅 <strong>Date:</strong> <?php echo date('d/m', strtotime($b->date_start)).' - '.date('d/m', strtotime($b->date_end)); ?></li>
                            <li>💶 <strong>Totale:</strong> €<?php echo $tot; ?> (Caparra: €<?php echo $dep; ?>)</li>
                            <li>💰 <strong>Stato:</strong> <?php 
                                if($b->status==1) echo '<span style="color:green;font-weight:bold;">CONFERMATA</span>';
                                elseif($b->status==3) echo '<span style="color:red;font-weight:bold;">CANCELLATA</span>';
                                elseif($b->receipt_url) echo '<span style="color:blue;">IN VERIFICA</span>';
                                else echo '<span style="color:orange;">ATTESA BONIFICO</span>'; 
                            ?></li>
                        </ul>
                        
                        <?php if ($b->status == 3): ?>
                            <div style="background:#f1f1f1; color:#555; padding:15px; margin-top:20px; border-radius:5px; text-align:center;">
                                <p>Questa prenotazione è stata annullata.</p>
                                <p>Se hai versato una caparra, la procedura di rimborso è in corso.</p>
                            </div>

                        <?php elseif ($b->receipt_url && $b->status != 1): ?>
                             <div style="margin-top:20px; text-align:center; padding:20px; background:#e7f9e7; border:1px solid #28a745; border-radius:8px;">
                                <h2 style="color:#28a745;">Ricevuta Caricata! 🎉</h2>
                                <p>Ti abbiamo inviato una mail di conferma.</p>
                                <a href="<?php echo esc_url($b->receipt_url); ?>" target="_blank" class="button">Vedi file</a>
                             </div>
                             <div style="margin-top:15px; border-top:1px solid #eee; padding-top:10px; text-align:center;">
                                <form method="post" onsubmit="return confirm('Sei sicuro di voler ritirare la richiesta?');">
                                    <?php wp_nonce_field('paguro_cancel_action', 'paguro_cancel_nonce'); ?>
                                    <input type="hidden" name="paguro_action" value="cancel_user_booking">
                                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                                    <button type="submit" class="button" style="color:#a00; border:none; background:none; text-decoration:underline;">Ritira Richiesta</button>
                                </form>
                             </div>

                        <?php elseif ($can_up): ?>
                            <div id="paguro-upload-area" style="margin-top:20px; border:2px dashed #0073aa; padding:30px; text-align:center; background:#fff; cursor:pointer;">
                                <p>📂 <strong>Trascina qui la distinta (max 5Mb)</strong></p>
                                <input type="file" id="paguro-file-input" style="display:none;"><button class="button" onclick="document.getElementById('paguro-file-input').click()">Scegli</button>
                                <div id="paguro-upload-status" style="margin-top:10px;"></div>
                            </div>
                            <input type="hidden" id="paguro-token" value="<?php echo $token; ?>">
                            
                            <div style="margin-top:20px; background:#fff3cd; padding:15px; font-size:13px; border-left:4px solid #ffc107;">
                                <strong>📢 Politica di Cancellazione:</strong><br>
                                Cancellazione gratuita fino a 15gg prima della data di arrivo.
                            </div>

                        <?php elseif ($can_cancel && $b->status == 1): ?>
                            <div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px;">
                                <form method="post" onsubmit="return confirm('Sei sicuro di voler cancellare la prenotazione? Questa azione è irreversibile e avvierà la procedura di rimborso.');">
                                    <?php wp_nonce_field('paguro_cancel_action', 'paguro_cancel_nonce'); ?>
                                    <input type="hidden" name="paguro_action" value="cancel_user_booking">
                                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                                    <button type="submit" class="button" style="background:#dc3545; color:white; border:none; padding:12px 25px; font-size:15px; width:100%;">🚨 Cancella Prenotazione</button>
                                </form>
                                <p style="font-size:12px; color:#777; margin-top:10px; text-align:center;">
                                    Cancellazione gratuita disponibile ancora per <strong><?php echo floor(($start_ts - (15*24*3600) - time())/86400); ?> giorni</strong>.
                                </p>
                            </div>
                        <?php else: ?>
                            <?php if ($b->status != 1 && $b->status != 3): ?><p style="color:red">Link scaduto.</p><?php endif; ?>
                        <?php endif; ?>
                    <?php endif; } endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

// 12. RENDERER
function paguro_render_interface($mode='widget'){ $icon_url=plugin_dir_url(__FILE__).'paguro_bot_icon.png'; $cls=($mode==='inline')?'paguro-mode-inline':'paguro-mode-widget'; ob_start(); ?>
<style>
.paguro-chat-root *{box-sizing:border-box} .paguro-mode-widget .paguro-chat-launcher{position:fixed;bottom:20px;right:20px;width:60px;height:60px;background:#fff;border-radius:50%;box-shadow:0 4px 12px rgba(0,0,0,.2);cursor:pointer;z-index:99999;display:flex;align-items:center;justify-content:center} .paguro-mode-widget .paguro-chat-window{position:fixed;bottom:90px;right:20px;width:280px;height:380px;max-width:90%;max-height:80vh;background:#fff;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,.2);z-index:99999;display:none;flex-direction:column} .paguro-mode-inline .paguro-chat-launcher{display:none} .paguro-mode-inline .paguro-chat-window{position:relative;width:100%;height:600px;border:1px solid #ddd;background:#fff;border-radius:8px;display:flex;flex-direction:column} .paguro-chat-header{background:#0073aa;color:#fff;padding:10px 15px;display:flex;justify-content:space-between;align-items:center} .paguro-chat-body{flex:1;padding:15px;overflow-y:auto;background:#f9f9f9} .paguro-chat-footer{padding:10px;border-top:1px solid #eee;display:flex;gap:5px;background:#fff} .paguro-chat-footer input{flex:1;padding:8px;border:1px solid #ddd;border-radius:20px;font-size:13px} .paguro-msg{margin-bottom:10px;display:flex;gap:8px;align-items:flex-start} .paguro-msg-content{padding:8px 12px;border-radius:12px;font-size:13px;max-width:80%} .paguro-msg-user{flex-direction:row-reverse} .paguro-msg-user .paguro-msg-content{background:#0073aa;color:#fff} .paguro-msg-bot .paguro-msg-content{background:#e5e5ea;color:#000} .paguro-bot-avatar{width:30px;height:30px;border-radius:50%;flex-shrink:0;object-fit:cover;min-width:30px;min-height:30px} .paguro-book-btn{background:#28a745;color:#fff!important;padding:3px 6px;border-radius:4px;text-decoration:none;font-size:11px} .paguro-month-buttons{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px} .paguro-quick-btn{background:#fff;border:1px solid #0073aa;color:#0073aa;border-radius:15px;padding:3px 10px;font-size:11px;cursor:pointer;transition:0.2s;font-weight:500} .paguro-quick-btn:hover{background:#0073aa;color:#fff}
</style>
<div class="paguro-chat-root <?php echo $cls; ?>">
<div class="paguro-chat-launcher" onclick="jQuery('.paguro-chat-window').fadeToggle()"><img src="<?php echo $icon_url; ?>"></div>
<div class="paguro-chat-window"><div class="paguro-chat-header"><span style="font-weight:bold;">Paguro</span><span class="close-btn" onclick="jQuery('.paguro-chat-window').fadeOut()" style="cursor:pointer;font-size:20px;">&times;</span></div><div class="paguro-chat-body" id="paguro-chat-body"><div class="paguro-msg paguro-msg-bot"><img src="<?php echo $icon_url; ?>" class="paguro-bot-avatar"><div class="paguro-msg-content">Ciao Sono Paguro, il tuo assistente virtuale.<br>Chiedimi informazioni o clicca sui mesi per conoscere le attuali disponibilità:<div class="paguro-month-buttons"><span class="paguro-quick-btn" data-msg="Disponibilità Giugno">Giugno</span><span class="paguro-quick-btn" data-msg="Disponibilità Luglio">Luglio</span><span class="paguro-quick-btn" data-msg="Disponibilità Agosto">Agosto</span><span class="paguro-quick-btn" data-msg="Disponibilità Settembre">Settembre</span></div></div></div></div><div class="paguro-chat-footer"><input type="text" id="paguro-input" placeholder="Scrivi qui..."><button id="paguro-send-btn" style="border:none;background:none;font-size:18px;cursor:pointer;">➤</button></div></div></div>
<?php return ob_get_clean(); }
add_shortcode('paguro_chat', function(){ if(!defined('PAGURO_WIDGET_RENDERED')){ define('PAGURO_WIDGET_RENDERED',true); return paguro_render_interface('inline'); }});
add_action('wp_footer', function(){ if(!defined('PAGURO_WIDGET_RENDERED') && get_option('paguro_global_active',1)) echo paguro_render_interface('widget'); });