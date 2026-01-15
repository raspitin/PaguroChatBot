<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Versione 1.8.8 - Acconto, Thank You Page e Estensione Token
 * Version: 1.8.8
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

// --- CONFIGURAZIONE ---
define('PAGURO_API_URL', 'https://api.viamerano24.it/chat'); 
define('PAGURO_VERSION', '1.8.8'); 

// 1. CREAZIONE TABELLE
register_activation_hook(__FILE__, 'paguro_create_tables');
function paguro_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_apartments (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        base_price decimal(10,2) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_availability (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        apartment_id mediumint(9) NOT NULL,
        date_start date NOT NULL,
        date_end date NOT NULL,
        status tinyint(1) DEFAULT 0,
        guest_name varchar(100) NULL,
        guest_email varchar(100) NULL,
        guest_phone varchar(50) NULL,
        lock_token varchar(50) NULL,
        lock_expires datetime NULL, /* USATO PER LA SCADENZA */
        receipt_url varchar(255) NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY apartment_id (apartment_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    
    // Seed Appartamenti
    if ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_apartments") == 0) {
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'corallo', 'base_price' => 100]);
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'tartaruga', 'base_price' => 100]);
    }
}

// 2. CHECK DB (Aggiornamenti Schema)
add_action('plugins_loaded', 'paguro_update_db_structure');
function paguro_update_db_structure() {
    if (get_transient('paguro_db_check_188')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'paguro_availability';
    
    // Assicuriamoci che le colonne esistano
    $cols = $wpdb->get_col("DESC $table", 0);
    if (!in_array('lock_expires', $cols)) $wpdb->query("ALTER TABLE $table ADD COLUMN lock_expires DATETIME NULL");
    if (!in_array('receipt_url', $cols)) $wpdb->query("ALTER TABLE $table ADD COLUMN receipt_url VARCHAR(255) NULL");
    
    set_transient('paguro_db_check_188', true, DAY_IN_SECONDS);
}

// 3. ASSETS
add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() {
    wp_enqueue_script('paguro-js', plugin_dir_url(__FILE__) . 'paguro-front.js', ['jquery'], PAGURO_VERSION, true);
    wp_localize_script('paguro-js', 'paguroData', [
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('paguro_chat_nonce'),
        'booking_url' => site_url('/prenota'), 
        'icon_url'    => plugin_dir_url(__FILE__) . 'paguro_bot_icon.png' 
    ]);
}

// 4. ADMIN MENU
add_action('admin_menu', 'paguro_admin_menu');
function paguro_admin_menu() {
    add_menu_page('Gestione Paguro', 'Paguro Booking', 'manage_options', 'paguro-booking', 'paguro_render_admin', 'dashicons-building', 50);
}
function paguro_render_admin() {
    $file = plugin_dir_path(__FILE__) . 'admin-page.php';
    if(file_exists($file)) require_once $file;
    else echo "File admin-page.php non trovato.";
}

// 5. CHAT ENDPOINT
add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat');
add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');

function paguro_handle_chat() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paguro_chat_nonce')) { wp_send_json_error(['reply' => "⚠️ Sessione scaduta."]); return; }
    global $wpdb;
    try {
        $message = sanitize_text_field($_POST['message']);
        $session_id = sanitize_text_field($_POST['session_id']);
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $req_month = isset($_POST['filter_month']) ? sanitize_text_field($_POST['filter_month']) : '';
        $data = [];

        if ($offset == 0) {
            $response = wp_remote_post(PAGURO_API_URL, [
                'body' => json_encode(['message' => $message, 'session_id' => $session_id]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 15
            ]);
            if (is_wp_error($response)) throw new Exception("Brain offline.");
            $data = json_decode(wp_remote_retrieve_body($response), true);
        } else {
            $data = ['type' => 'ACTION', 'action' => 'CHECK_AVAILABILITY', 'reply' => ''];
        }

        if (isset($data['type']) && $data['type'] === 'ACTION' && $data['action'] === 'CHECK_AVAILABILITY') {
            $target_month = $req_month; 
            if (empty($target_month)) {
                $months_map = ['giugno'=>'06', 'luglio'=>'07', 'agosto'=>'08', 'settembre'=>'09'];
                foreach ($months_map as $m_name => $m_code) { if (stripos($message, $m_name) !== false) { $target_month = $m_code; break; } }
            }
            $weeks_to_check = preg_match('/due sett|2 sett|14 giorn|15 giorn|coppia/i', $message) ? 2 : 1;
            
            $season_start = new \DateTime('2026-06-13'); $season_end = new \DateTime('2026-10-03');
            $sql_s = $season_start->format('Y-m-d'); $sql_e = $season_end->format('Y-m-d');
            
            // LOGICA SCADENZA: Filtriamo le richieste scadute usando lock_expires
            // Query per disponibilità: Status 1 (Ok) OR (Status 2 AND Not Expired)
            $now = current_time('mysql');
            $raw = $wpdb->get_results($wpdb->prepare(
                "SELECT apartment_id, date_start, date_end, status, lock_expires, created_at
                 FROM {$wpdb->prefix}paguro_availability 
                 WHERE (status = 1 OR status = 2) 
                 AND date_end > %s AND date_start < %s",
                $sql_s, $sql_e
            ));
            
            $confirmed = []; $pending = [];
            foreach($raw as $rec) {
                if ($rec->status == '1') {
                    $confirmed[] = $rec;
                } else {
                    // Verifica scadenza dinamica
                    $expire_time = $rec->lock_expires ? $rec->lock_expires : date('Y-m-d H:i:s', strtotime($rec->created_at . ' + 48 hours'));
                    if ($expire_time > $now) {
                        $pending[] = $rec; // Ancora valida
                    }
                    // Se scaduta, la ignoriamo (verrà pulita dal cron/lock)
                }
            }

            $apartments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
            $html = ($offset > 0) ? "" : "Ecco le disponibilità:<br><br>";
            $found_any = false;

            foreach ($apartments as $apt) {
                $apt_conf = array_filter($confirmed, function($b) use ($apt) { return $b->apartment_id == $apt->id; });
                $apt_pend = array_filter($pending, function($b) use ($apt) { return $b->apartment_id == $apt->id; });
                
                $interval = new \DateInterval('P1W'); 
                $period = new \DatePeriod($season_start, $interval, $season_end);
                $slots_found = 0; $slots_shown = 0; $limit = 4;
                
                if ($offset == 0) $html .= "🏠 <b>{$apt->name}</b>:<br>";
                foreach ($period as $dt) {
                    if ($target_month && $dt->format('m') !== $target_month) continue; 
                    $curr_start = $dt->format('Y-m-d');
                    $end_obj = clone $dt; $end_obj->modify('+' . ($weeks_to_check * 7) . ' days');
                    $curr_end = $end_obj->format('Y-m-d');
                    if ($end_obj > $season_end) continue; 

                    $occupied = false;
                    foreach ($apt_conf as $b) { if ($b->date_start < $curr_end && $b->date_end > $curr_start) { $occupied = true; break; } }

                    if (!$occupied) {
                        $viewers = 0;
                        foreach ($apt_pend as $b) { if ($b->date_start < $curr_end && $b->date_end > $curr_start) $viewers++; }
                        $slots_found++;
                        if ($slots_found <= $offset) continue;
                        if ($slots_shown < $limit) {
                            $d_in = $dt->format('d/m/Y'); $d_out = $end_obj->format('d/m/Y');
                            $social = ($viewers > 0) ? " <span style='color:#d35400; font-size:12px;'>⚡ {$viewers} valutano</span>" : "";
                            $html .= "- {$dt->format('d/m')} - {$end_obj->format('d/m')} <a href='#' class='paguro-book-btn' data-apt='".strtolower($apt->name)."' data-in='{$d_in}' data-out='{$d_out}'>[Prenota]</a>{$social}<br>";
                            $slots_shown++; $found_any = true;
                        } else {
                            $html .= "<a href='#' class='paguro-load-more' data-apt='{$apt->id}' data-offset='".($offset+$limit)."' data-month='{$target_month}' style='color:#0073aa;'>...altre</a><br>";
                            break; 
                        }
                    }
                }
                if ($offset == 0) $html .= "<br>";
            }
            $data['reply'] = ($found_any || $offset==0) ? $html : "Fine date.";
            if (!$found_any && $offset == 0) $data['reply'] = "Nessuna disponibilità.";
        }
        wp_send_json_success($data);
    } catch (Exception $e) { wp_send_json_success(['reply' => "⚠️ Errore tecnico."]); }
}

// 6. LOCK DATES (Creazione & Pulizia)
add_action('wp_ajax_paguro_lock_dates', 'paguro_handle_lock');
add_action('wp_ajax_nopriv_paguro_lock_dates', 'paguro_handle_lock');
function paguro_handle_lock() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paguro_chat_nonce')) { wp_send_json_error(['msg' => "Sessione scaduta."]); return; }
    global $wpdb;
    try {
        // 1. PULIZIA: Elimina solo se lock_expires < NOW() O se lock_expires è NULL e created_at < 48h fa
        $wpdb->query("DELETE FROM {$wpdb->prefix}paguro_availability 
                      WHERE status = 2 
                      AND (
                          (lock_expires IS NOT NULL AND lock_expires < NOW()) 
                          OR 
                          (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR))
                      )");

        // 2. NUOVO LOCK
        $apt_name = sanitize_text_field($_POST['apt_name']);
        $dt_in = \DateTime::createFromFormat('d/m/Y', $_POST['date_in']);
        $dt_out = \DateTime::createFromFormat('d/m/Y', $_POST['date_out']);
        if (!$dt_in || !$dt_out) throw new Exception("Date invalide");
        
        $sql_in = $dt_in->format('Y-m-d'); $sql_out = $dt_out->format('Y-m-d');
        $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $apt_name));
        
        // Verifica Disponibilità
        $busy = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE apartment_id = %d AND status = 1 AND (date_start < %s AND date_end > %s)", 
            $apt_id, $sql_out, $sql_in
        ));
        if ($busy > 0) throw new Exception("Date già prenotate.");
        
        // Coda
        $queue = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE apartment_id = %d AND status = 2 AND (date_start < %s AND date_end > %s)",
            $apt_id, $sql_out, $sql_in
        ));
        $msg = ($queue > 0) ? "ATTENZIONE: {$queue} richieste in coda." : "Date libere! Opzione valida 48h.";

        // Inserimento con Scadenza Esplicita (+48h)
        $expires = date('Y-m-d H:i:s', time() + (48 * 3600));
        $token = wp_generate_password(20, false);
        
        $wpdb->insert("{$wpdb->prefix}paguro_availability", [
            'apartment_id' => $apt_id, 'date_start' => $sql_in, 'date_end' => $sql_out,
            'status' => 2, 'lock_token' => $token, 
            'created_at' => current_time('mysql'),
            'lock_expires' => $expires 
        ]);
        
        wp_send_json_success(['token' => $token, 'queue_msg' => $msg]);
    } catch (Exception $e) { wp_send_json_error(['msg' => $e->getMessage()]); }
}

// 7. NINJA FORM BRIDGE (Identico)
add_action('ninja_forms_after_submission', 'paguro_connect_ninja_to_db');
function paguro_connect_ninja_to_db($form_data) {
    global $wpdb; $token = ''; $name = ''; $email = ''; $phone = '';
    foreach ($form_data['fields'] as $field) {
        $k = $field['key']; $v = $field['value'];
        if ($k === 'nf_token') $token = $v;
        if (in_array($k, ['nome','name'])) $name .= $v . ' ';
        if (in_array($k, ['cognome','lastname'])) $name .= $v;
        if ($k === 'email') $email = $v;
        if (in_array($k, ['phone','telefono'])) $phone = $v;
    }
    if ($token) $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_name' => trim($name), 'guest_email' => $email, 'guest_phone' => $phone], ['lock_token' => $token]);
}

// 8. RENDERER (Widget/Inline) - IDENTICO A PRIMA
function paguro_render_interface($mode = 'widget') {
    $icon_url = plugin_dir_url(__FILE__) . 'paguro_bot_icon.png';
    $cls = ($mode === 'inline') ? 'paguro-mode-inline' : 'paguro-mode-widget';
    ob_start(); 
    ?>
    <style>
        .paguro-chat-root * { box-sizing: border-box; }
        .paguro-mode-widget .paguro-chat-launcher { position: fixed !important; bottom: 20px !important; right: 20px !important; width: 60px; height: 60px; background: #fff; border-radius: 50%; box-shadow: 0 4px 12px rgba(0,0,0,0.2); cursor: pointer; z-index: 99999; display: flex; align-items: center; justify-content: center; }
        .paguro-mode-widget .paguro-chat-window { position: fixed !important; bottom: 90px !important; right: 20px !important; width: 350px; height: 500px; max-width:90%; max-height:80vh; background: #fff; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); z-index: 99999; display: none; flex-direction: column; }
        .paguro-mode-inline .paguro-chat-launcher { display: none !important; } 
        .paguro-mode-inline .paguro-chat-window { position: relative !important; width: 100% !important; height: 600px !important; border: 1px solid #ddd; background: #fff; border-radius: 8px; display: flex !important; flex-direction: column; }
        .paguro-mode-inline .close-btn { display: none !important; }
        .paguro-chat-header { background: #0073aa; color: #fff; padding: 15px; display: flex; justify-content: space-between; }
        .paguro-chat-body { flex: 1; padding: 15px; overflow-y: auto; background: #f9f9f9; }
        .paguro-chat-footer { padding: 10px; border-top: 1px solid #eee; display: flex; gap: 10px; background:#fff; }
        .paguro-chat-footer input { flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 20px; }
        .paguro-msg { margin-bottom: 10px; display: flex; gap: 8px; }
        .paguro-msg-content { padding: 10px 14px; border-radius: 12px; font-size: 14px; max-width: 80%; }
        .paguro-msg-user { flex-direction: row-reverse; } .paguro-msg-user .paguro-msg-content { background: #0073aa; color: #fff; }
        .paguro-msg-bot .paguro-msg-content { background: #e5e5ea; color: #000; }
        .paguro-bot-avatar { width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; }
        .paguro-book-btn { background: #28a745; color: #fff !important; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 12px; }
    </style>
    <div class="paguro-chat-root <?php echo $cls; ?>">
        <div class="paguro-chat-launcher" onclick="jQuery('.paguro-chat-window').fadeToggle()"><img src="<?php echo $icon_url; ?>"></div>
        <div class="paguro-chat-window">
            <div class="paguro-chat-header"><span>Paguro</span><span class="close-btn" onclick="jQuery('.paguro-chat-window').fadeOut()">&times;</span></div>
            <div class="paguro-chat-body" id="paguro-chat-body"><div class="paguro-msg paguro-msg-bot"><img src="<?php echo $icon_url; ?>" class="paguro-bot-avatar"><div class="paguro-msg-content">Ciao! Chiedimi disponibilità.</div></div></div>
            <div class="paguro-chat-footer"><input type="text" id="paguro-input"><button id="paguro-send-btn">➤</button></div>
        </div>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('paguro_chat', 'paguro_render_interface_shortcode');
function paguro_render_interface_shortcode() { if (!defined('PAGURO_WIDGET_RENDERED')) { define('PAGURO_WIDGET_RENDERED', true); return paguro_render_interface('inline'); } }
add_action('wp_footer', function() { if (!defined('PAGURO_WIDGET_RENDERED') && get_option('paguro_global_active', 1)) echo paguro_render_interface('widget'); });

// 9. UPLOAD AJAX
add_action('wp_ajax_paguro_upload_receipt', 'paguro_handle_receipt_upload');
add_action('wp_ajax_nopriv_paguro_upload_receipt', 'paguro_handle_receipt_upload');
function paguro_handle_receipt_upload() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    if (!isset($_FILES['file']) || !isset($_POST['token'])) wp_send_json_error(['msg' => "Dati mancanti."]);
    
    global $wpdb;
    $token = sanitize_text_field($_POST['token']);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", $token));
    if (!$booking) wp_send_json_error(['msg' => "Token invalido."]);

    $file = $_FILES['file'];
    if ($file['size'] > 5 * 1024 * 1024) wp_send_json_error(['msg' => "Max 5MB."]);
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $uploaded = wp_handle_upload($file, ['test_form' => false]);
    if (isset($uploaded['error'])) wp_send_json_error(['msg' => $uploaded['error']]);

    $wpdb->update("{$wpdb->prefix}paguro_availability", ['receipt_url' => $uploaded['url']], ['id' => $booking->id]);
    
    // Mail Admin
    wp_mail(get_option('admin_email'), "💰 Nuova Distinta - Paguro", "Ospite: {$booking->guest_name}\nURL: {$uploaded['url']}");
    
    wp_send_json_success(['url' => $uploaded['url']]);
}

// 10. RIEPILOGO & DRAG-DROP (Con Thank You Page)
add_shortcode('paguro_summary', 'paguro_summary_render');
function paguro_summary_render() {
    global $wpdb;
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    ob_start();
    ?>
    <div style="max-width:600px; margin:20px auto; font-family:sans-serif; border:1px solid #ddd; border-radius:8px; overflow:hidden;">
        <div style="background:#0073aa; color:#fff; padding:15px; font-size:18px; font-weight:bold;">📦 Riepilogo Prenotazione</div>
        <div style="padding:20px; background:#f9f9f9;">
            <?php if (!$token): ?><p>⚠️ Codice mancante.</p><?php else: 
                $booking = $wpdb->get_row($wpdb->prepare("SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id WHERE b.lock_token = %s", $token));
                if (!$booking): ?><p style="color:red;">❌ Non trovata.</p><?php else: 
                    $start = date('d/m/Y', strtotime($booking->date_start));
                    $end = date('d/m/Y', strtotime($booking->date_end));
                    
                    // Calcolo validità
                    $expire_ts = $booking->lock_expires ? strtotime($booking->lock_expires) : (strtotime($booking->created_at) + (48*3600));
                    $can_upload = ($booking->status == 2 && time() < $expire_ts);
                ?>
                    <h3 style="margin-top:0;">Ciao <?php echo esc_html($booking->guest_name ?: 'Ospite'); ?>!</h3>
                    <ul style="background:#fff; padding:15px; border-radius:5px; border:1px solid #eee; list-style:none;">
                        <li>🏠 <strong>Appartamento:</strong> <?php echo ucfirst($booking->apt_name); ?></li>
                        <li>📅 <strong>Date:</strong> <?php echo $start; ?> ➝ <?php echo $end; ?></li>
                        <li>💰 <strong>Stato:</strong> 
                            <?php if ($booking->status == 1) echo "<span style='color:green;'>CONFERMATA ✅</span>";
                            elseif ($booking->receipt_url) echo "<span style='color:blue;'>IN VERIFICA 👮</span>";
                            elseif ($can_upload) echo "<span style='color:orange;'>IN ATTESA BONIFICO ⏳</span>";
                            else echo "<span style='color:red;'>SCADUTA ⛔</span>"; ?>
                        </li>
                    </ul>

                    <?php if ($booking->receipt_url): ?>
                         <div style="margin-top:20px; text-align:center; padding:20px; background:#e7f9e7; border:1px solid #28a745; border-radius:8px;">
                            <h2 style="color:#28a745; margin-bottom:10px;">Grazie! 🎉</h2>
                            <p>Abbiamo ricevuto la tua distinta.</p>
                            <p>Riceverai la conferma definitiva via email in breve tempo.</p>
                            <a href="<?php echo esc_url($booking->receipt_url); ?>" target="_blank" class="button" style="margin-top:10px;">Vedi il tuo documento</a>
                         </div>
                    
                    <?php elseif ($can_upload): ?>
                        <div id="paguro-upload-area" style="margin-top:20px; border:2px dashed #0073aa; padding:30px; text-align:center; background:#fff; cursor:pointer; transition:background 0.3s;">
                            <p style="margin:0; font-size:16px;">📂 <strong>Trascina qui la distinta del bonifico ( max 5Mb )</strong></p>
                            <p style="margin:5px 0 15px; color:#666; font-size:13px;">oppure clicca per selezionare (PDF, JPG, PNG)</p>
                            <input type="file" id="paguro-file-input" style="display:none;">
                            <button class="button" onclick="document.getElementById('paguro-file-input').click()">Seleziona File</button>
                            <div id="paguro-upload-status" style="margin-top:10px;"></div>
                        </div>
                        <input type="hidden" id="paguro-token" value="<?php echo $token; ?>">
                    <?php endif; ?>

                <?php endif; endif; ?>
        </div>
    </div>
    <?php return ob_get_clean();
}