<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Assistente virtuale con gestione prezzi stagionali e dati ospiti.
 * Version: 1.1.1
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

define('PAGURO_API_URL', 'https://api.viamerano24.it/chat'); 
define('PAGURO_VERSION', '1.1.1');

// 1. CREAZIONE TABELLE (DATABASE)
register_activation_hook(__FILE__, 'paguro_create_tables');
function paguro_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Tabella 1: Appartamenti
    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_apartments (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        base_price decimal(10,2) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Tabella 2: Disponibilità/Prenotazioni (Aggiornata con dati ospite)
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
        PRIMARY KEY  (id),
        KEY apartment_id (apartment_id)
    ) $charset_collate;";

    // Tabella 3: Listino Prezzi Stagionali (NUOVA)
    $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_prices (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        apartment_id mediumint(9) NOT NULL,
        date_start date NOT NULL,
        date_end date NOT NULL,
        weekly_price decimal(10,2) NOT NULL,
        PRIMARY KEY  (id),
        KEY apartment_id (apartment_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
}

// 2. CARICAMENTO SCRIPT FRONTEND
add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() {
    wp_enqueue_style('paguro-css', plugin_dir_url(__FILE__) . 'paguro-style.css', [], PAGURO_VERSION);
    wp_enqueue_script('paguro-js', plugin_dir_url(__FILE__) . 'paguro-front.js', ['jquery'], PAGURO_VERSION, true);
    
    wp_localize_script('paguro-js', 'paguroData', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('paguro_chat_nonce')
    ]);
}

// 3. MENU ADMIN
add_action('admin_menu', 'paguro_admin_menu');
function paguro_admin_menu() {
    add_menu_page(
        'Gestione Paguro',
        'Paguro Booking',
        'manage_options',
        'paguro-booking',
        'paguro_render_admin',
        'dashicons-building',
        50
    );
}

function paguro_render_admin() {
    require_once plugin_dir_path(__FILE__) . 'admin-page.php';
}

// 4. GESTORE RICHIESTE CHAT
add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat');
add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');

function paguro_handle_chat() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    global $wpdb;
    
    $message = sanitize_text_field($_POST['message']);
    $session_id = sanitize_text_field($_POST['session_id']);

    // Chiamata al Server Python (Geekom)
    $response = wp_remote_post(PAGURO_API_URL, [
        'body' => json_encode(['message' => $message, 'session_id' => $session_id]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 60
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['reply' => 'Il Paguro è momentaneamente irraggiungibile.']);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // --- LOGICA DISPONIBILITÀ E PREZZI ---
    if (isset($data['type']) && $data['type'] === 'ACTION' && $data['action'] === 'CHECK_AVAILABILITY') {
        
        // CONFIGURAZIONE STAGIONE 2026
        $season_start = new DateTime('2026-06-13');
        $season_end   = new DateTime('2026-10-03');
        
        $apartments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
        $prices     = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_prices");

        $html = "Ecco le disponibilità per l'estate 2026:<br><br>";
        $found_any = false;

        foreach ($apartments as $apt) {
            $html .= "🏠 <b>{$apt->name}</b>:<br>";
            
            $interval = DateInterval::createFromDateString('1 week');
            $period   = new DatePeriod($season_start, $interval, $season_end);
            
            $slots_found = 0;
            
            foreach ($period as $dt) {
                $check_date = $dt->format('Y-m-d');
                $end_date_obj = clone $dt;
                $end_date_obj->modify('+7 days');
                $end_date_fmt = $end_date_obj->format('d/m');
                
                // 1. CHECK OCCUPATO (La settimana cade dentro un periodo bloccato?)
                $is_occupied = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
                     WHERE apartment_id = %d 
                     AND (date_start <= %s AND date_end > %s)", 
                    $apt->id, $check_date, $check_date
                ));

                if ($is_occupied == 0) {
                    // 2. CALCOLO PREZZO (Logica Base vs Stagionale)
                    $current_price = $apt->base_price; 
                    
                    foreach($prices as $p) {
                        // Se la data di inizio settimana rientra in un periodo speciale
                        if($p->apartment_id == $apt->id && $check_date >= $p->date_start && $check_date < $p->date_end) {
                            $current_price = $p->weekly_price;
                            break; // Trovato prezzo speciale, stop
                        }
                    }

                    $start_fmt = $dt->format('d/m');
                    // Genera link prenotazione (per ora placeholder)
                    $html .= "- {$start_fmt} - {$end_date_fmt}: <b>€" . number_format($current_price, 0) . "</b> <a href='#' class='paguro-book-btn' data-apt='{$apt->id}' data-date='{$check_date}'>[Prenota]</a><br>";
                    $slots_found++;
                    $found_any = true;
                }
                
                // Limitiamo output visivo
                if($slots_found >= 4) { $html .= "...e date successive.<br>"; break; }
            }
            $html .= "<br>";
        }

        if (!$found_any) {
            $data['reply'] = "Mi dispiace, per la stagione 2026 è tutto completo!";
        } else {
            $data['reply'] = $html;
        }
    }

    wp_send_json_success($data);
}