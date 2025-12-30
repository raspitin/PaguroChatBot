<?php
/**
 * Plugin Name: Paguro ChatBot
 * Description: Assistente virtuale con gestione prezzi stagionali, disponibilità e integrazione Ninja Forms.
 * Version: 1.3.1
 * Author: Andrea G.
 */

if (!defined('ABSPATH')) exit;

define('PAGURO_API_URL', 'https://api.viamerano24.it/chat'); 
define('PAGURO_VERSION', '1.3.1');

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
        PRIMARY KEY  (id),
        KEY apartment_id (apartment_id)
    ) $charset_collate;";

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

add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts() {
    wp_enqueue_style('paguro-css', plugin_dir_url(__FILE__) . 'paguro-style.css', [], PAGURO_VERSION);
    wp_enqueue_script('paguro-js', plugin_dir_url(__FILE__) . 'paguro-front.js', ['jquery'], PAGURO_VERSION, true);
    
    wp_localize_script('paguro-js', 'paguroData', [
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('paguro_chat_nonce'),
        'booking_url' => site_url('/prenota'),
        'icon_url'    => plugin_dir_url(__FILE__) . 'paguro_bot_icon.png' 
    ]);
}

add_action('admin_menu', 'paguro_admin_menu');
function paguro_admin_menu() {
    add_menu_page('Gestione Paguro', 'Paguro Booking', 'manage_options', 'paguro-booking', 'paguro_render_admin', 'dashicons-building', 50);
}
function paguro_render_admin() {
    require_once plugin_dir_path(__FILE__) . 'admin-page.php';
}

add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat');
add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');

function paguro_handle_chat() {
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    global $wpdb;
    
    $message = sanitize_text_field($_POST['message']);
    $session_id = sanitize_text_field($_POST['session_id']);
    
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $req_apt_id = isset($_POST['apt_id']) ? intval($_POST['apt_id']) : 0;
    $req_month = isset($_POST['filter_month']) ? sanitize_text_field($_POST['filter_month']) : '';

    $data = [];

    if ($offset > 0) {
        $data = ['type' => 'ACTION', 'action' => 'CHECK_AVAILABILITY', 'reply' => ''];
    } else {
        $response = wp_remote_post(PAGURO_API_URL, [
            'body' => json_encode(['message' => $message, 'session_id' => $session_id]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['reply' => 'Il Paguro è momentaneamente irraggiungibile.']);
            return;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    }

    if (isset($data['type']) && $data['type'] === 'ACTION' && $data['action'] === 'CHECK_AVAILABILITY') {
        
        $target_month = $req_month; 
        if (empty($target_month)) {
            $months_map = ['giugno' => '06', 'luglio' => '07', 'agosto' => '08', 'settembre' => '09'];
            foreach ($months_map as $m_name => $m_code) {
                if (stripos($message, $m_name) !== false) {
                    $target_month = $m_code;
                    break;
                }
            }
        }

        $weeks_to_check = 1;
        $duration_label = "una settimana";
        if (preg_match('/due sett|2 sett|14 giorn|15 giorn|coppia di sett/i', $message)) {
            $weeks_to_check = 2;
            $duration_label = "due settimane consecutive";
        }

        $season_start = new DateTime('2026-06-13');
        $season_end   = new DateTime('2026-10-03');
        
        $apartments = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
        
        $intro = $target_month ? " per il mese richiesto" : " per l'estate 2026";
        $html = ($offset > 0) ? "" : "Ecco le disponibilità ($duration_label){$intro}:<br><br>";
        
        $found_any_global = false;

        foreach ($apartments as $apt) {
            if ($req_apt_id > 0 && $apt->id != $req_apt_id) continue;

            if ($offset == 0) {
                $html .= "🏠 <b>{$apt->name}</b>:<br>";
            }

            $interval = DateInterval::createFromDateString('1 week');
            $period   = new DatePeriod($season_start, $interval, $season_end);
            
            $slots_found = 0;
            $slots_shown = 0;
            $limit = 4;

            foreach ($period as $dt) {
                if ($target_month && $dt->format('m') !== $target_month) continue; 

                $check_date_start = $dt->format('Y-m-d');
                $end_date_obj = clone $dt;
                $end_date_obj->modify('+' . ($weeks_to_check * 7) . ' days');
                $check_date_end = $end_date_obj->format('Y-m-d');
                
                if ($end_date_obj > $season_end) continue; 

                $is_occupied = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
                     WHERE apartment_id = %d 
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

                        $html .= "- {$start_show} - {$end_show} <a href='#' class='paguro-book-btn' 
                                    data-apt='{$apt_val_form}' 
                                    data-in='{$date_in_form}' 
                                    data-out='{$date_out_form}'>[Prenota]</a><br>";
                        
                        $slots_shown++;
                        $found_any_global = true;
                    } else {
                        $new_offset = $offset + $limit;
                        $html .= "<a href='#' class='paguro-load-more' data-apt='{$apt->id}' data-offset='{$new_offset}' data-month='{$target_month}' style='color:#0073aa; font-weight:bold; cursor:pointer;'>...e altre date successive</a><br>";
                        break; 
                    }
                }
            }
            if ($offset == 0) $html .= "<br>";
        }

        if (!$found_any_global && $offset == 0) {
            if ($weeks_to_check > 1) {
                $data['reply'] = "Non ho trovato disponibilità per 2 settimane consecutive" . ($target_month ? " nel mese richiesto." : ".");
            } elseif ($target_month) {
                $data['reply'] = "Mi dispiace, non ho trovato date libere per il mese richiesto.";
            } else {
                $data['reply'] = "Mi dispiace, per la stagione 2026 è tutto completo!";
            }
        } else {
            if ($offset > 0 && !$found_any_global) {
                $data['reply'] = "Non ci sono altre date disponibili.";
            } else {
                $data['reply'] = $html;
            }
        }
    }

    wp_send_json_success($data);
}
?>