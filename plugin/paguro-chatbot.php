<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Versione Stabile con Debug degli errori server.
 * Version: 1.7.0
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

// URL API Python - Se sei su Docker usa il nome del servizio
define('PAGURO_API_URL', 'http://paguro_brain:8000/chat'); 
define('PAGURO_VERSION', '1.7.0');

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
        lock_expires datetime NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY apartment_id (apartment_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    
    if ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_apartments") == 0) {
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'corallo', 'base_price' => 100]);
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'tartaruga', 'base_price' => 100]);
    }
}

// 2. CHECK STRUTTURA DB
add_action('plugins_loaded', 'paguro_update_db_structure');
function paguro_update_db_structure() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'paguro_availability';
    $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$table_name' AND column_name = 'created_at'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
}

// 3. ASSETS
add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() {
    // Carichiamo CSS e JS
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
    // Include il file admin se esiste
    $file = plugin_dir_path(__FILE__) . 'admin-page.php';
    if(file_exists($file)) require_once $file;
    else echo "File admin-page.php non trovato.";
}

// 5. CHAT LOGIC (CON TRY-CATCH PER EVITARE ERRORE SERVER)
add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat');
add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');

function paguro_handle_chat() {
    // A. Sicurezza
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    global $wpdb;

    // B. Safe Mode: Cattura errori fatali
    try {
        $message = sanitize_text_field($_POST['message']);
        $session_id = sanitize_text_field($_POST['session_id']);
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $req_month = isset($_POST['filter_month']) ? sanitize_text_field($_POST['filter_month']) : '';

        $data = [];

        // 1. CHIAMATA AL BRAIN (Ollama)
        if ($offset == 0) {
            $response = wp_remote_post(PAGURO_API_URL, [
                'body' => json_encode(['message' => $message, 'session_id' => $session_id]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 15 // Timeout breve per non bloccare
            ]);

            if (is_wp_error($response)) {
                // Errore connessione Python (Docker spento? URL sbagliato?)
                throw new Exception("Brain irraggiungibile: " . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!$data) {
                throw new Exception("Brain ha risposto con dati non validi. Code: " . wp_remote_retrieve_response_code($response));
            }
        } else {
            // Se è paginazione, simuliamo la risposta ACTION
            $data = ['type' => 'ACTION', 'action' => 'CHECK_AVAILABILITY', 'reply' => ''];
        }

        // 2. LOGICA DISPONIBILITÀ PHP
        if (isset($data['type']) && $data['type'] === 'ACTION' && $data['action'] === 'CHECK_AVAILABILITY') {
            
            $target_month = $req_month; 
            if (empty($target_month)) {
                $months_map = ['giugno' => '06', 'luglio' => '07', 'agosto' => '08', 'settembre' => '09'];
                foreach ($months_map as $m_name => $m_code) {
                    if (stripos($message, $m_name) !== false) { $target_month = $m_code; break; }
                }
            }

            $weeks_to_check = 1;
            $duration_label = "una settimana";
            if (preg_match('/due sett|2 sett|14 giorn|15 giorn|coppia/i', $message)) {
                $weeks_to_check = 2;
                $duration_label = "due settimane";
            }

            // Uso \DateTime per evitare conflitti di namespace
            $season_start = new \DateTime('2026-06-13');
            $season_end   = new \DateTime('2026-10-03');
            
            $apartments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
            
            $intro = $target_month ? " per il mese richiesto" : " per l'estate 2026";
            $html = ($offset > 0) ? "" : "Ecco le disponibilità ($duration_label){$intro}:<br><br>";
            
            $found_any_global = false;

            foreach ($apartments as $apt) {
                // Reset date loop
                $interval = \DateInterval::createFromDateString('1 week');
                $period   = new \DatePeriod($season_start, $interval, $season_end);
                
                $slots_found = 0;
                $slots_shown = 0;
                $limit = 4;

                if ($offset == 0) $html .= "🏠 <b>{$apt->name}</b>:<br>";

                foreach ($period as $dt) {
                    if ($target_month && $dt->format('m') !== $target_month) continue; 

                    $check_date_start = $dt->format('Y-m-d');
                    $end_date_obj = clone $dt;
                    $end_date_obj->modify('+' . ($weeks_to_check * 7) . ' days');
                    $check_date_end = $end_date_obj->format('Y-m-d');
                    
                    if ($end_date_obj > $season_end) continue; 

                    $is_occupied = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
                         WHERE apartment_id = %d AND status = 1 
                         AND (date_start < %s AND date_end > %s)", 
                        $apt->id, $check_date_end, $check_date_start
                    ));

                    if ($is_occupied == 0) {
                        $slots_found++;
                        if ($slots_found <= $offset) continue;

                        if ($slots_shown < $limit) {
                            $start_show = $dt->format('d/m');
                            $end_show = $end_date_obj->format('d/m');
                            $apt_val_form = strtolower($apt->name); 
                            $date_in_form = $dt->format('d/m/Y');
                            $date_out_form = $end_date_obj->format('d/m/Y');

                            $html .= "- {$start_show} - {$end_show} <a href='#' class='paguro-book-btn' data-apt='{$apt_val_form}' data-in='{$date_in_form}' data-out='{$date_out_form}'>[Prenota]</a><br>";
                            $slots_shown++;
                            $found_any_global = true;
                        } else {
                            $new_offset = $offset + $limit;
                            $html .= "<a href='#' class='paguro-load-more' data-apt='{$apt->id}' data-offset='{$new_offset}' data-month='{$target_month}' style='color:#0073aa;cursor:pointer;'>...altre date</a><br>";
                            break; 
                        }
                    }
                }
                if ($offset == 0) $html .= "<br>";
            }

            if (!$found_any_global && $offset == 0) {
                 $data['reply'] = "Non ho trovato date libere per questi criteri.";
            } else {
                $data['reply'] = ($offset > 0 && !$found_any_global) ? "Fine date disponibili." : $html;
            }
        }

        wp_send_json_success($data);

    } catch (Exception $e) {
        // C. GESTIONE ERRORE: Invece di crashare (500), inviamo l'errore al chat
        error_log("PAGURO CRASH: " . $e->getMessage());
        wp_send_json_success(['reply' => "⚠️ ERRORE TECNICO: " . $e->getMessage()]);
    }
}

// 6. LOCK DATES
add_action('wp_ajax_paguro_lock_dates', 'paguro_handle_lock');
add_action('wp_ajax_nopriv_paguro_lock_dates', 'paguro_handle_lock');
function paguro_handle_lock() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    global $wpdb;

    try {
        $apt_name = sanitize_text_field($_POST['apt_name']);
        $date_in  = sanitize_text_field($_POST['date_in']);
        $date_out = sanitize_text_field($_POST['date_out']);
        $dt_in = \DateTime::createFromFormat('d/m/Y', $date_in);
        $dt_out = \DateTime::createFromFormat('d/m/Y', $date_out);
        
        if (!$dt_in || !$dt_out) throw new Exception("Date non valide");

        $sql_in = $dt_in->format('Y-m-d');
        $sql_out = $dt_out->format('Y-m-d');
        $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $apt_name));
        
        if (!$apt_id) throw new Exception("Appartamento non trovato");

        $wpdb->query("DELETE FROM {$wpdb->prefix}paguro_availability WHERE status = 2 AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT status, created_at FROM {$wpdb->prefix}paguro_availability 
             WHERE apartment_id = %d AND (date_start < %s AND date_end > %s)
             ORDER BY created_at ASC", 
            $apt_id, $sql_out, $sql_in
        ));

        $msg = "Date libere! Opzione valida 48h.";
        $queue = 0;
        foreach ($existing as $row) {
            if ($row->status == '1') throw new Exception("Date già prenotate e confermate.");
            if ($row->status == '2') $queue++;
        }
        if ($queue > 0) $msg = "ATTENZIONE: Ci sono $queue richieste in coda.";

        $token = wp_generate_password(20, false);
        $res = $wpdb->insert("{$wpdb->prefix}paguro_availability", [
            'apartment_id' => $apt_id, 'date_start' => $sql_in, 'date_end' => $sql_out,
            'status' => 2, 'lock_token' => $token, 'created_at' => current_time('mysql')
        ]);

        if ($res === false) throw new Exception("Errore DB: " . $wpdb->last_error);

        wp_send_json_success(['token' => $token, 'queue_msg' => $msg]);

    } catch (Exception $e) {
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
}

// 7. NINJA BRIDGE
add_action('ninja_forms_after_submission', 'paguro_connect_ninja_to_db');
function paguro_connect_ninja_to_db($form_data) {
    global $wpdb;
    $token = ''; $name = ''; $email = ''; $phone = '';
    
    foreach ($form_data['fields'] as $field) {
        $k = $field['key']; $v = $field['value'];
        if ($k === 'nf_token') $token = $v;
        if (in_array($k, ['nome','firstname','name'])) $name .= $v . ' ';
        if (in_array($k, ['cognome','lastname'])) $name .= $v;
        if ($k === 'email') $email = $v;
        if (in_array($k, ['phone','telefono'])) $phone = $v;
    }
    
    if ($token) {
        $wpdb->update("{$wpdb->prefix}paguro_availability", 
            ['guest_name' => trim($name), 'guest_email' => $email, 'guest_phone' => $phone], 
            ['lock_token' => $token]
        );
    }
}

// 8. HTML WIDGET (STILE INLINE PER SICUREZZA)
add_action('wp_footer', 'paguro_render_chat_widget');
function paguro_render_chat_widget() {
    $icon_url = plugin_dir_url(__FILE__) . 'paguro_bot_icon.png';
    ?>
    <style>
        .paguro-chat-launcher { position: fixed !important; bottom: 20px !important; right: 20px !important; width: 60px; height: 60px; background: #fff; border-radius: 50%; box-shadow: 0 4px 12px rgba(0,0,0,0.2); cursor: pointer; z-index: 9999999; display: flex; align-items: center; justify-content: center; }
        .paguro-chat-launcher img { width: 40px; height: auto; }
        .paguro-chat-widget { position: fixed !important; bottom: 90px !important; right: 20px !important; width: 350px; height: 500px; max-width:90%; max-height:80vh; background: #fff; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); z-index: 9999999; display: none; flex-direction: column; overflow: hidden; font-family: sans-serif; }
        .paguro-chat-header { background: #0073aa; color: #fff; padding: 15px; display: flex; justify-content: space-between; align-items: center; }
        .paguro-chat-body { flex: 1; padding: 15px; overflow-y: auto; background: #f9f9f9; }
        .paguro-msg { margin-bottom: 10px; display: flex; gap: 8px; }
        .paguro-msg-content { padding: 10px 14px; border-radius: 12px; font-size: 14px; line-height: 1.4; max-width: 80%; }
        .paguro-msg-user { flex-direction: row-reverse; }
        .paguro-msg-user .paguro-msg-content { background: #0073aa; color: #fff; }
        .paguro-msg-bot .paguro-msg-content { background: #e5e5ea; color: #000; }
        .paguro-bot-avatar { width: 30px; height: 30px; border-radius: 50%; }
        .paguro-chat-footer { padding: 10px; border-top: 1px solid #eee; display: flex; gap: 10px; background:#fff;}
        .paguro-chat-footer input { flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 20px; }
        .paguro-book-btn { background: #28a745; color: #fff !important; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-size: 12px; display:inline-block; margin-top:5px;}
    </style>
    <div class="paguro-chat-launcher"><img src="<?php echo $icon_url; ?>"></div>
    <div class="paguro-chat-widget">
        <div class="paguro-chat-header"><span>Paguro</span><span class="close-btn" style="cursor:pointer;font-size:20px;">&times;</span></div>
        <div class="paguro-chat-body" id="paguro-chat-body">
            <div class="paguro-msg paguro-msg-bot"><img src="<?php echo $icon_url; ?>" class="paguro-bot-avatar"><div class="paguro-msg-content">Ciao! Chiedimi disponibilità.</div></div>
        </div>
        <div class="paguro-chat-footer"><input type="text" id="paguro-input"><button id="paguro-send-btn">➤</button></div>
    </div>
    <?php
}
?>