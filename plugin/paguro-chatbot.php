<?php

/**
 * PAGURO CHATBOT PLUGIN - v3.4.11
 * Refactored Architecture with Security & Performance Improvements
 * 
 * Plugin Name: Paguro ChatBot
 * Description: Versione 3.4.11 - cancellation IBAN + masked placeholder + schema update
 * Version: 3.4.11
 * Author: Dev Team
 * 
 * IMPROVEMENTS v3.4.0:
 * - [CRITICAL] Race condition protection in receipt upload (transactional hard lock)
 * - [CRITICAL] On-the-fly cleanup of expired quotes before availability checks
 * - [SECURITY] SameSite cookie attribute for CSRF protection
 * - [FEATURE] Complete waitlist alert system on all date releases
 * - Constants for magic numbers (PAGURO_SOFT_LOCK_HOURS, etc.)
 * - Improved error logging throughout
 */

if (!defined('ABSPATH')) exit;

// =========================================================
// CONSTANTS
// =========================================================

define('PAGURO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAGURO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PAGURO_VERSION', '3.4.19');

// =========================================================
// IBAN HELPERS
// =========================================================

if (!function_exists('paguro_normalize_iban')) {
    function paguro_normalize_iban($iban)
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));
        return $iban;
    }
}

if (!function_exists('paguro_is_valid_iban')) {
    function paguro_is_valid_iban($iban)
    {
        if (empty($iban)) return false;
        if (!preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban)) return false;
        if (strpos($iban, 'IT') === 0 && strlen($iban) !== 27) return false;
        return true;
    }
}

if (!function_exists('paguro_mask_iban')) {
    function paguro_mask_iban($iban)
    {
        $iban = paguro_normalize_iban($iban);
        if ($iban === '') return '';
        $len = strlen($iban);
        if ($len <= 8) return str_repeat('*', $len);
        $start = substr($iban, 0, 4);
        $end = substr($iban, -4);
        return $start . str_repeat('*', $len - 8) . $end;
    }
}

// Business logic constants
if (!defined('PAGURO_SOFT_LOCK_HOURS')) define('PAGURO_SOFT_LOCK_HOURS', 48);
if (!defined('PAGURO_CANCELLATION_DAYS')) define('PAGURO_CANCELLATION_DAYS', 15);
if (!defined('PAGURO_AUTH_COOKIE_DAYS')) define('PAGURO_AUTH_COOKIE_DAYS', 7);
if (!defined('PAGURO_DEFAULT_DEPOSIT_PERCENT')) define('PAGURO_DEFAULT_DEPOSIT_PERCENT', 30);

// =========================================================
// LOAD MODULES
// =========================================================

require_once PAGURO_PLUGIN_DIR . 'includes/functions-availability.php';
require_once PAGURO_PLUGIN_DIR . 'includes/functions-email.php';
require_once PAGURO_PLUGIN_DIR . 'templates/shortcode-chat.php';
require_once PAGURO_PLUGIN_DIR . 'templates/shortcode-quote-form.php';
require_once PAGURO_PLUGIN_DIR . 'templates/shortcode-waitlist-form.php';
require_once PAGURO_PLUGIN_DIR . 'templates/shortcode-summary-quote.php';
require_once PAGURO_PLUGIN_DIR . 'templates/shortcode-summary-waitlist.php';

// Fallback: ensure token generator exists even if include fails
if (!function_exists('paguro_generate_unique_token')) {
    function paguro_generate_unique_token() {
        global $wpdb;
        $max_attempts = 10;
        $attempts = 0;

        do {
            $token = bin2hex(random_bytes(32));
            $exists = 0;
            if ($wpdb && !empty($wpdb->prefix)) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s",
                    $token
                ));
            }
            $attempts++;
        } while ($exists > 0 && $attempts < $max_attempts);

        if ($exists > 0) {
            error_log('[Paguro] Failed to generate unique token after ' . $max_attempts . ' attempts');
            return false;
        }

        return $token;
    }
}

// =========================================================
// ACTIVATION & DEACTIVATION
// =========================================================

register_activation_hook(__FILE__, 'paguro_activate_plugin');
register_deactivation_hook(__FILE__, 'paguro_deactivate_plugin');

function paguro_activate_plugin()
{
    paguro_create_tables();
    if (!wp_next_scheduled('paguro_daily_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'paguro_daily_cleanup_event');
    }
}

function paguro_deactivate_plugin()
{
    wp_clear_scheduled_hook('paguro_daily_cleanup_event');
}

function paguro_create_tables()
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_apartments (
        id mediumint(9) AUTO_INCREMENT, name varchar(100), base_price decimal(10,2), pricing_json longtext, PRIMARY KEY (id)
    ) $charset;";

    // Status: 1=Confermato, 2=Preventivo(Soft), 3=Cancellato, 4=Waitlist, 5=Richiesta Cancellazione
    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}paguro_availability (
        id mediumint(9) AUTO_INCREMENT, apartment_id mediumint(9), date_start date, date_end date, 
        status tinyint(1) DEFAULT 0, guest_name varchar(100), guest_email varchar(100), guest_phone varchar(50), 
        customer_iban varchar(34),
        guest_notes text, history_log longtext,
        lock_token varchar(64), lock_expires datetime, 
        receipt_url varchar(255), receipt_uploaded_at datetime,
        created_at datetime DEFAULT CURRENT_TIMESTAMP, 
        PRIMARY KEY (id), KEY apartment_id (apartment_id)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);

    if ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}paguro_apartments") == 0) {
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'corallo', 'base_price' => 500]);
        $wpdb->insert("{$wpdb->prefix}paguro_apartments", ['name' => 'tartaruga', 'base_price' => 500]);
    }
    paguro_set_defaults();
}

// Ensure schema upgrades for existing installs
add_action('init', 'paguro_maybe_upgrade_schema');
function paguro_maybe_upgrade_schema()
{
    global $wpdb;
    $table = $wpdb->prefix . 'paguro_availability';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$table_exists) return;

    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'customer_iban'));
    if (!$col) {
        $wpdb->query("ALTER TABLE {$table} ADD customer_iban varchar(34) NULL");
    }
}

function paguro_set_defaults()
{
    add_option('paguro_recaptcha_site', '');
    add_option('paguro_recaptcha_secret', '');
    add_option('paguro_api_url', 'https://api.viamerano24.it/chat');

    add_option('paguro_season_start', '2026-06-01');
    add_option('paguro_season_end', '2026-09-30');
    add_option('paguro_deposit_percent', PAGURO_DEFAULT_DEPOSIT_PERCENT);

    add_option('paguro_bank_iban', 'IT00X0000000000000000000000');
    add_option('paguro_bank_owner', 'Chiara Maria Angela Celi');
    add_option('paguro_checkout_slug', 'prenotazione');
    add_option('paguro_page_slug', 'riepilogo-prenotazione');
    add_option('paguro_mail_from', 'info@villaceli.it');
    add_option('paguro_mail_from_name', 'Villa Celi');

    add_option('paguro_msg_ui_social_pressure', '⚡ <strong>Attenzione:</strong> Ci sono altre {count} richieste di preventivo in corso per queste date.');

    if (!get_option('paguro_msg_ui_summary_page')) add_option('paguro_msg_ui_summary_page', '<div>...</div>');
    if (!get_option('paguro_msg_ui_summary_confirm_page')) {
        $summary_confirm_default = '<p><strong>La tua prenotazione è confermata.</strong></p>' .
            '<p>Soggiorno: {date_start} - {date_end} presso {apt_name}.</p>' .
            '<p>Di seguito trovi i dettagli del pagamento e la distinta (se disponibile).</p>';
        add_option('paguro_msg_ui_summary_confirm_page', $summary_confirm_default);
    }
    if (!get_option('paguro_msg_ui_login_page')) add_option('paguro_msg_ui_login_page', paguro_default_login_template());
    if (!get_option('paguro_msg_ui_privacy_notice')) add_option('paguro_msg_ui_privacy_notice', 'I tuoi dati sono protetti.');
    if (!get_option('paguro_msg_ui_upload_instruction')) add_option('paguro_msg_ui_upload_instruction', 'Carica la distinta per bloccare le date.');
    if (!get_option('paguro_msg_email_refund_subj')) add_option('paguro_msg_email_refund_subj', 'Bonifico disposto - {apt_name}');
    if (!get_option('paguro_msg_email_refund_body')) {
        add_option('paguro_msg_email_refund_body',
            'Ciao {guest_name},<br><br>' .
            'Ti confermiamo che il bonifico di rimborso è stato disposto sul seguente IBAN:<br>' .
            '<strong>{customer_iban_priv}</strong><br><br>' .
            'I tempi di accredito possono variare in base alla tua banca.<br><br>' .
            'Grazie comunque per averci contattato e per la fiducia riposta.<br>' .
            'Speriamo di poterti ospitare in futuro.'
        );
    }
}

/**
 * Daily cleanup with waitlist notification
 * 
 * IMPROVEMENT v3.4.0: Now triggers waitlist alerts when cleaning expired quotes
 */
add_action('paguro_daily_cleanup_event', 'paguro_do_cleanup');
function paguro_do_cleanup()
{
    global $wpdb;
    
    // Get bookings that will be deleted (for waitlist notification)
    $expiring = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paguro_availability 
             WHERE status = %d AND receipt_url IS NULL 
             AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) 
                  OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)))",
            2,
            PAGURO_SOFT_LOCK_HOURS
        )
    );
    
    // Delete expired quotes
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}paguro_availability 
             WHERE status = %d AND receipt_url IS NULL 
             AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) 
                  OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)))",
            2,
            PAGURO_SOFT_LOCK_HOURS
        )
    );
    
    // IMPROVEMENT: Trigger waitlist alerts for freed dates
    if ($expiring && count($expiring) > 0) {
        foreach ($expiring as $booking) {
            paguro_trigger_waitlist_alerts(
                $booking->apartment_id,
                $booking->date_start,
                $booking->date_end
            );
        }
        error_log('[Paguro] Daily cleanup: ' . count($expiring) . ' expired quotes removed, waitlist alerts sent');
    }
}

add_action('init', 'paguro_prevent_caching');
function paguro_prevent_caching()
{
    if (isset($_GET['token']) && !empty($_GET['token'])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTMINIFY')) define('DONOTMINIFY', true);
        nocache_headers();
    }
}

// =========================================================
// HELPER FUNCTIONS
// =========================================================

function paguro_send_html_email($to, $subject, $content, $is_admin = false)
{
    if (!$is_admin) {
        $privacy_txt = get_option('paguro_msg_ui_privacy_notice');
        $content .= $privacy_txt;
    }
    $html = '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#f6f6f6;padding:20px"><div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;"><div style="background:#0073aa;padding:20px;text-align:center;"><h1 style="color:#fff;margin:0;">Villa Celi</h1></div><div style="padding:30px;color:#333;line-height:1.6">' . $content . '</div><div style="background:#eee;padding:15px;text-align:center;font-size:12px;color:#777">&copy; ' . date('Y') . ' Villa Celi</div></div></body></html>';

    $headers = array('Content-Type: text/html; charset=UTF-8', 'From: Villa Celi <info@villaceli.it>');
    return wp_mail($to, $subject, $html, $headers);
}

function paguro_parse_template($text, $data)
{
    if (empty($text)) return '';
    foreach ($data as $key => $val) {
        $text = str_replace('{' . $key . '}', (string)$val, $text);
    }
    return $text;
}

function paguro_sanitize_chat_reply($html)
{
    if ($html === null) return '';
    $allowed = [
        'br' => [],
        'p' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'code' => [],
        'div' => ['class' => true, 'style' => true],
        'span' => ['class' => true, 'style' => true, 'data-apt' => true, 'data-in' => true, 'data-out' => true, 'data-offset' => true, 'data-month' => true],
        'a' => ['href' => true, 'class' => true, 'data-apt' => true, 'data-in' => true, 'data-out' => true, 'data-offset' => true, 'data-month' => true],
    ];
    return wp_kses($html, $allowed);
}

function paguro_verify_recaptcha($token, $action = '')
{
    $secret = get_option('paguro_recaptcha_secret');
    if (empty($secret)) return true;

    if (empty($token)) {
        return new WP_Error('recaptcha_missing', 'Verifica anti-bot non valida. Riprova.');
    }

    $resp = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'body' => [
            'secret' => $secret,
            'response' => $token
        ],
        'timeout' => 10,
        'sslverify' => true
    ]);

    if (is_wp_error($resp)) {
        return new WP_Error('recaptcha_error', 'Errore verifica anti-bot. Riprova.');
    }

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($data['success'])) {
        return new WP_Error('recaptcha_failed', 'Verifica anti-bot non valida. Riprova.');
    }

    if (!empty($action) && !empty($data['action']) && $data['action'] !== $action) {
        return new WP_Error('recaptcha_action', 'Verifica anti-bot non valida. Riprova.');
    }

    if (isset($data['score']) && $data['score'] < 0.5) {
        return new WP_Error('recaptcha_score', 'Verifica anti-bot non valida. Riprova.');
    }

    return true;
}

function paguro_default_login_template()
{
    return '<div class="paguro-auth-section">' .
        '<div class="paguro-auth-card">' .
        '<h2>🔐 Area Riservata</h2>' .
        '<p>Per motivi di sicurezza, inserisci la tua email per accedere alla prenotazione.</p>' .
        '<form id="paguro-auth-form" method="POST">' .
        '{nonce_field}' .
        '<input type="hidden" name="paguro_action" value="verify_access">' .
        '<input type="hidden" name="token" value="{token}">' .
        '<label>Email Registrata *</label>' .
        '<input type="email" name="verify_email" required autocomplete="email" style="width:100%; padding:8px; margin:10px 0;">' .
        '<button class="button" type="submit" style="background:#0073aa;color:#fff;border:none;padding:10px 20px;font-size:16px;border-radius:4px;cursor:pointer">Accedi</button>' .
        '</form>' .
        '</div>' .
        '</div>';
}

function paguro_login_template_is_valid($template)
{
    if (empty($template)) return false;
    $required = ['{nonce_field}', '{token}', 'verify_email', 'paguro_action', 'verify_access', '<form'];
    foreach ($required as $needle) {
        if (stripos($template, $needle) === false) return false;
    }
    return true;
}

function paguro_calculate_quote($apt_id, $date_start, $date_end)
{
    global $wpdb;
    $apt = $wpdb->get_row($wpdb->prepare("SELECT base_price, pricing_json FROM {$wpdb->prefix}paguro_apartments WHERE id = %d", $apt_id));
    if (!$apt) return 0;
    $prices = ($apt->pricing_json) ? json_decode($apt->pricing_json, true) : [];
    $total = 0;
    $current = new DateTime($date_start);
    $end = new DateTime($date_end);
    while ($current < $end) {
        $week_key = $current->format('Y-m-d');
        $weekly_price = (isset($prices[$week_key]) && $prices[$week_key] > 0) ? floatval($prices[$week_key]) : floatval($apt->base_price);
        $total += $weekly_price;
        $current->modify('+1 week');
    }
    return $total;
}

/**
 * Set secure cookie with SameSite attribute
 * 
 * IMPROVEMENT v3.4.0: Added SameSite=Lax for CSRF protection
 * 
 * @param string $name Cookie name
 * @param string $value Cookie value
 * @param int $expire Expiration timestamp
 */
function paguro_set_auth_cookie($name, $value, $expire = null) {
    if ($expire === null) {
        $expire = time() + (PAGURO_AUTH_COOKIE_DAYS * DAY_IN_SECONDS);
    }
    
    // PHP 7.3+ array syntax
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, [
            'expires' => $expire,
            'path' => '/',
            'domain' => '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'  // CSRF protection
        ]);
    } else {
        // Fallback for older PHP versions
        setcookie($name, $value, $expire, '/', '', is_ssl(), true);
    }
}

// =========================================================
// ADMIN MENU
// =========================================================

add_action('admin_menu', 'paguro_register_admin_menu');
function paguro_register_admin_menu()
{
    add_menu_page('Gestione Paguro', 'Paguro Booking', 'manage_options', 'paguro-booking', 'paguro_render_admin_page', 'dashicons-building', 50);
}

function paguro_render_admin_page()
{
    $file = plugin_dir_path(__FILE__) . 'admin/admin-dashboard.php';
    if (file_exists($file)) require_once $file;
}

// =========================================================
// ASSETS
// =========================================================

add_action('wp_enqueue_scripts', 'paguro_enqueue_scripts');
function paguro_enqueue_scripts()
{
    $plugin_url = plugin_dir_url(__FILE__);

    // CSS Modules
    wp_enqueue_style('paguro-chat-css', $plugin_url . 'assets/css/paguro-chat.css', [], PAGURO_VERSION);
    wp_enqueue_style('paguro-form-css', $plugin_url . 'assets/css/paguro-form.css', [], PAGURO_VERSION);
    wp_enqueue_style('paguro-summary-css', $plugin_url . 'assets/css/paguro-summary.css', [], PAGURO_VERSION);

    // JavaScript Modules
    wp_enqueue_script('jquery');
    wp_enqueue_script('paguro-chat-js', $plugin_url . 'assets/js/paguro-chat.js', ['jquery'], PAGURO_VERSION, true);
    wp_enqueue_script('paguro-form-js', $plugin_url . 'assets/js/paguro-form.js', ['jquery'], PAGURO_VERSION, true);
    wp_enqueue_script('paguro-upload-js', $plugin_url . 'assets/js/paguro-upload.js', ['jquery'], PAGURO_VERSION, true);
    wp_enqueue_script('paguro-auth-js', $plugin_url . 'assets/js/paguro-auth.js', ['jquery'], PAGURO_VERSION, true);

    $site_key = get_option('paguro_recaptcha_site');
    if ($site_key) wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . $site_key, [], null, true);

    $booking_slug = get_option('paguro_checkout_slug', 'prenotazione');

    wp_localize_script('paguro-chat-js', 'paguroData', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('paguro_chat_nonce'),
        'booking_url' => site_url("/{$booking_slug}/"),
        'icon_url' => plugin_dir_url(__FILE__) . 'paguro_bot_icon.png',
        'recaptcha_site' => $site_key,
        'msgs' => [
            'upload_loading' => '⏳ Caricamento...',
            'upload_success' => '✅ Distinta Caricata!',
            'upload_error'   => '❌ Errore server.',
            'form_success'   => 'Richiesta inviata!',
            'form_conn_error' => 'Errore connessione.',
            'btn_locking'    => 'Attendere...',
            'btn_book'       => '[Richiedi Preventivo]'
        ]
    ]);
}

// =========================================================
// NONCE REFRESH (JS fallback)
// =========================================================

add_action('wp_ajax_paguro_refresh_nonce', 'paguro_refresh_nonce');
add_action('wp_ajax_nopriv_paguro_refresh_nonce', 'paguro_refresh_nonce');
function paguro_refresh_nonce()
{
    wp_send_json_success(['nonce' => wp_create_nonce('paguro_chat_nonce')]);
}
// CONTINUATION OF paguro-chatbot.php - PART 2

// =========================================================
// ACTIONS HANDLER
// =========================================================

add_action('init', 'paguro_handle_post_actions');
function paguro_handle_post_actions()
{
    // ===== LOGIN =====
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'verify_access') {
        if (!wp_verify_nonce($_POST['paguro_auth_nonce'], 'paguro_auth_action')) wp_die('Security Check Failed.');
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['verify_email'] ?? ''));
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s AND guest_email=%s", $token, $email));
        
        if ($row) {
            $cookie_val = wp_hash($token . 'paguro_auth');
            
            // IMPROVEMENT: Use secure cookie with SameSite
            paguro_set_auth_cookie('paguro_auth_' . $token, $cookie_val);
            
            paguro_add_history($row->id, 'USER_ACCESS', 'Accesso area riservata');
            nocache_headers();
            wp_redirect(add_query_arg('t', time(), remove_query_arg('auth_error')));
            exit;
        } else {
            wp_redirect(add_query_arg('auth_error', '1'));
            exit;
        }
    }

    // ===== RESOLVE RACE (ADMIN) =====
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'resolve_race_conflict') {
        if (!wp_verify_nonce($_POST['paguro_race_nonce'], 'paguro_race_action')) wp_die('Security.');
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token));

        if (!$booking) {
            wp_die('Prenotazione non trovata.');
        }

        $choice = sanitize_text_field(wp_unslash($_POST['race_choice'] ?? ''));
        $note = sanitize_textarea_field(wp_unslash($_POST['race_note'] ?? ''));
        $new_notes = $booking->guest_notes . "\n\n[" . date('d/m H:i') . " ADMIN]: " . $note;

        if ($choice === 'refund') {
            // Admin sceglie di rimborsare -> Libera le date
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3, 'guest_notes' => $new_notes], ['id' => $booking->id]);
            paguro_add_history($booking->id, 'ADM_REFUND', 'Conflitto risolto con rimborso');

            // IMPROVEMENT: Trigger waitlist alerts
            paguro_trigger_waitlist_alerts($booking->apartment_id, $booking->date_start, $booking->date_end);
            
            error_log('[Paguro] Admin refund: Booking ' . $booking->id . ' cancelled, waitlist alerted');

            wp_redirect(add_query_arg('msg', 'refund_ok'));
            exit;
        } elseif ($choice === 'wait') {
            // Admin dice di aspettare
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['guest_notes' => $new_notes], ['id' => $booking->id]);
            paguro_add_history($booking->id, 'ADM_WAIT', 'In attesa risoluzione conflitto');
            wp_redirect(add_query_arg('msg', 'wait_ok'));
            exit;
        }
    }

    // ===== CANCEL (USER SELF-SERVICE) =====
    if (isset($_POST['paguro_action']) && $_POST['paguro_action'] === 'cancel_user_booking') {
        if (!wp_verify_nonce($_POST['paguro_cancel_nonce'], 'paguro_cancel_action')) wp_die('Security.');
        global $wpdb;
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token=%s", $token));

        if (!$booking) {
            wp_die('Prenotazione non trovata.');
        }

        // Waitlist: cancellazione immediata
        if (intval($booking->status) === 4) {
            $wpdb->update("{$wpdb->prefix}paguro_availability", ['status' => 3], ['id' => $booking->id]);
            paguro_add_history($booking->id, 'USER_CANCEL_WAITLIST', 'Uscita lista d\'attesa');
            wp_redirect(add_query_arg('cancelled', 'waitlist'));
            exit;
        }

        // Già in stato di richiesta cancellazione
        if (intval($booking->status) === 5) {
            wp_redirect(add_query_arg('cancelled', 'pending'));
            exit;
        }

        // Check deadline (15 giorni prima arrivo)
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $arrival_dt = new DateTime($booking->date_start, $tz);
        $cancel_deadline_dt = (clone $arrival_dt)->modify('-' . PAGURO_CANCELLATION_DAYS . ' days');
        $can_cancel = current_time('timestamp') <= $cancel_deadline_dt->getTimestamp();

        if (intval($booking->status) === 1 && !$can_cancel) {
            paguro_add_history($booking->id, 'USER_CANCEL_DENIED', 'Fuori finestra cancellazione');
            wp_redirect(add_query_arg('cancelled', 'denied'));
            exit;
        }

        $customer_iban_raw = sanitize_text_field(wp_unslash($_POST['customer_iban'] ?? ''));
        $customer_iban = paguro_normalize_iban($customer_iban_raw);
        if (empty($customer_iban)) {
            wp_redirect(add_query_arg('cancelled', 'iban_required'));
            exit;
        }
        if (!paguro_is_valid_iban($customer_iban)) {
            wp_redirect(add_query_arg('cancelled', 'iban_invalid'));
            exit;
        }

        // Richiesta cancellazione (in attesa conferma admin)
        $wpdb->update(
            "{$wpdb->prefix}paguro_availability",
            ['status' => 5, 'customer_iban' => $customer_iban],
            ['id' => $booking->id]
        );
        paguro_add_history($booking->id, 'USER_CANCEL_REQUEST', "Richiesta cancellazione utente");
        
        // Send emails
        if (function_exists('paguro_send_cancel_request_to_user')) {
            paguro_send_cancel_request_to_user($booking->id);
        }
        if (function_exists('paguro_send_cancel_request_to_admin')) {
            paguro_send_cancel_request_to_admin($booking->id);
        }

        wp_redirect(add_query_arg('cancelled', 'requested'));
        exit;
    }
}

// =========================================================
// CHAT AJAX
// =========================================================

add_action('wp_ajax_paguro_chat_request', 'paguro_handle_chat');
add_action('wp_ajax_nopriv_paguro_chat_request', 'paguro_handle_chat');
function paguro_handle_chat()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'paguro_chat_nonce')) {
        wp_send_json_error(['reply' => "⚠️ Sessione scaduta."]);
        return;
    }
    
    global $wpdb;
    $api_url = get_option('paguro_api_url');
    $msg = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));

    if (substr($api_url, -4) === '/api') $api_url = str_replace('/api', '/chat', $api_url);
    elseif (substr($api_url, -5) !== '/chat') $api_url = rtrim($api_url, '/') . '/chat';

    try {
        // IMPROVEMENT: Cleanup expired quotes before checking availability
        paguro_cleanup_expired_quotes();
        
        $offset = intval($_POST['offset'] ?? 0);
        $direct_action = !empty($_POST['direct_action']);
        $force_action = $direct_action || preg_match('/\b(giugno|luglio|agosto|settembre)\b/i', $msg) || stripos($msg, 'dispon') !== false;
        $apt_filter = intval($_POST['apt_id'] ?? 0);

        if ($offset == 0 && !$force_action) {
            // Call AI API
            $args = [
                'body' => json_encode(['message' => $msg, 'session_id' => $_POST['session_id']]), 
                'headers' => ['Content-Type' => 'application/json'], 
                'timeout' => 30, 
                'sslverify' => true  // IMPROVEMENT: Enable SSL verification
            ];
            
            $res = wp_remote_post($api_url, $args);
            
            if (is_wp_error($res)) {
                error_log('[Paguro] AI API error: ' . $res->get_error_message());
                wp_send_json_error(['reply' => "Errore Connessione AI."]);
                return;
            }
            
            $data = json_decode(wp_remote_retrieve_body($res), true);
        } else {
            $data = ['type' => 'ACTION', 'action' => 'CHECK_AVAILABILITY'];
        }

        // AVAILABILITY CHECK
        if (($data['type'] ?? '') === 'ACTION') {
            $s_start_str = get_option('paguro_season_start');
            $s_end_str = get_option('paguro_season_end');
            $req_month = isset($_POST['filter_month']) ? sanitize_text_field(wp_unslash($_POST['filter_month'])) : '';
            $target_month = $req_month;
            
            if (empty($target_month)) {
                $mm = ['giugno' => '06', 'luglio' => '07', 'agosto' => '08', 'settembre' => '09'];
                foreach ($mm as $k => $v) if (stripos($msg, $k) !== false) $target_month = $v;
            }
            
            $weeks = preg_match('/due sett|2 sett|14 giorn|coppia/i', $msg) ? 2 : 1;

            $s_start = new DateTime($s_start_str);
            $s_end = new DateTime($s_end_str);
            if ($s_start->format('N') != 6) {
                $s_start->modify('next saturday');
            }

            // Get all bookings in season
            $raw = $wpdb->get_results($wpdb->prepare(
                "SELECT apartment_id, date_start, date_end, status, receipt_url 
                 FROM {$wpdb->prefix}paguro_availability 
                 WHERE (status=1 OR status=2 OR status=5) 
                 AND date_end > %s AND date_start < %s", 
                $s_start->format('Y-m-d'), 
                $s_end->format('Y-m-d')
            ));

            // Categorize bookings
            $hard_locked = [];
            $soft_locked = [];
            foreach ($raw as $r) {
                if ($r->status == 1 || $r->status == 5 || ($r->status == 2 && !empty($r->receipt_url))) {
                    $hard_locked[] = $r;
                } else {
                    $soft_locked[] = $r;
                }
            }

            // Get apartments
            if ($apt_filter) {
                $apts = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}paguro_apartments WHERE id = %d", $apt_filter));
            } else {
                $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments ORDER BY name ASC");
            }
            
            $html = ($offset == 0) ? "Ecco le disponibilità (Sab-Sab):<br><br>" : "";
            $found = false;
            $txt_quote = get_option('paguro_js_btn_book', '[Richiedi Preventivo]');

            foreach ($apts as $apt) {
                if ($offset == 0) $html .= "🏠 <b>" . esc_html($apt->name) . "</b>:<br>";
                
                $period = new DatePeriod($s_start, new DateInterval('P1W'), $s_end);
                $shown = 0;
                $limit = 4;
                $count = 0;
                
                foreach ($period as $dt) {
                    if ($target_month && $dt->format('m') !== $target_month) continue;
                    
                    $end = clone $dt;
                    $end->modify("+$weeks weeks");
                    if ($end > $s_end) continue;

                    // CHECK HARD LOCK
                    $hard_match = null;
                    $hard_priority = 0;
                    foreach ($hard_locked as $b) {
                        if ($b->apartment_id == $apt->id && $b->date_start < $end->format('Y-m-d') && $b->date_end > $dt->format('Y-m-d')) {
                            $priority = 1;
                            if ($b->status == 1) $priority = 3;
                            elseif ($b->status == 5) $priority = 2;
                            if ($priority > $hard_priority) {
                                $hard_priority = $priority;
                                $hard_match = $b;
                                if ($hard_priority === 3) break;
                            }
                        }
                    }

                    if ($hard_match) {
                        // OCCUPIED - Show waitlist button
                        $d_in = $dt->format('d/m/Y');
                        $d_out = $end->format('d/m/Y');
                        $count++;
                        if ($count <= $offset) continue;
                        if ($shown < $limit) {
                            $status_label = 'Non disponibile';
                            if ($hard_match->status == 1) {
                                $status_label = 'Confermata';
                            } elseif ($hard_match->status == 5) {
                                $status_label = 'Cancellazione in corso';
                            } elseif ($hard_match->status == 2 && !empty($hard_match->receipt_url)) {
                                $status_label = 'In validazione';
                            }
                            
                            $apt_attr = esc_attr(strtolower($apt->name));
                            $d_in_attr = esc_attr($d_in);
                            $d_out_attr = esc_attr($d_out);
                            $html .= "- <span class='paguro-availability-line'><span style='color:#777;text-decoration:line-through'>{$dt->format('d/m')} - {$end->format('d/m')}</span> <span class='paguro-availability-status'>" . esc_html($status_label) . "</span> <a href='#' class='paguro-waitlist-btn' data-apt='{$apt_attr}' data-in='{$d_in_attr}' data-out='{$d_out_attr}'>[🔔 Avvisami se si libera]</a></span><br>";
                            $shown++;
                            $found = true;
                        }
                    } else {
                        // AVAILABLE - Show book button
                        // Count competing quotes (improved with DISTINCT guest_email)
                        $viewers = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(DISTINCT guest_email) FROM {$wpdb->prefix}paguro_availability
                             WHERE apartment_id = %d
                             AND status = 2
                             AND receipt_url IS NULL
                             AND (lock_expires IS NULL OR lock_expires > NOW())
                             AND date_start < %s AND date_end > %s",
                            $apt->id,
                            $end->format('Y-m-d'),
                            $dt->format('Y-m-d')
                        ));

                        $count++;
                        if ($count <= $offset) continue;
                        if ($shown < $limit) {
                            $d_in = $dt->format('d/m/Y');
                            $d_out = $end->format('d/m/Y');
                            $social = ($viewers > 0) ? " <span style='color:#d35400;font-size:11px;'>🔥 {$viewers} preventivi attivi</span>" : "";
                            $apt_attr = esc_attr(strtolower($apt->name));
                            $d_in_attr = esc_attr($d_in);
                            $d_out_attr = esc_attr($d_out);
                            $html .= "- {$dt->format('d/m')} - {$end->format('d/m')} <a href='#' class='paguro-book-btn' data-apt='{$apt_attr}' data-in='{$d_in_attr}' data-out='{$d_out_attr}'>" . esc_html($txt_quote) . "</a>{$social}<br>";
                            $shown++;
                            $found = true;
                        } else {
                            $html .= "<a href='#' class='paguro-load-more' data-apt='" . esc_attr($apt->id) . "' data-offset='" . esc_attr($offset + $limit) . "' data-month='" . esc_attr($target_month) . "'>...altre</a><br>";
                            break;
                        }
                    }
                }
                
                if ($offset == 0) $html .= "<br>";
            }
            
            $data['reply'] = ($found || $offset == 0) ? $html : "Nessuna data trovata.";
        }

        if (isset($data['reply'])) {
            $data['reply'] = paguro_sanitize_chat_reply($data['reply']);
        }
        
        wp_send_json_success($data);
        
    } catch (Exception $e) {
        error_log('[Paguro] Chat exception: ' . $e->getMessage());
        wp_send_json_error(['reply' => "Errore server."]);
    }
}

// =========================================================
// START WAITLIST FLOW
// =========================================================

add_action('wp_ajax_paguro_start_waitlist', 'paguro_handle_start_waitlist');
add_action('wp_ajax_nopriv_paguro_start_waitlist', 'paguro_handle_start_waitlist');
function paguro_handle_start_waitlist()
{
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    global $wpdb;
    
    $recaptcha_token = sanitize_text_field(wp_unslash($_POST['recaptcha_token'] ?? ''));
    $recaptcha_check = paguro_verify_recaptcha($recaptcha_token, 'paguro_waitlist');
    if (is_wp_error($recaptcha_check)) {
        wp_send_json_error(['msg' => $recaptcha_check->get_error_message()]);
    }
    
    $apt_name = sanitize_text_field(wp_unslash($_POST['apt_name'] ?? ''));
    $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $apt_name));
    $in_raw = sanitize_text_field(wp_unslash($_POST['date_in'] ?? ''));
    $out_raw = sanitize_text_field(wp_unslash($_POST['date_out'] ?? ''));
    $d_in_obj = DateTime::createFromFormat('d/m/Y', $in_raw);
    $d_out_obj = DateTime::createFromFormat('d/m/Y', $out_raw);

    if (!$apt_id || !$d_in_obj || !$d_out_obj) {
        wp_send_json_error(['msg' => 'Dati non validi.']);
    }

    $d_in = $d_in_obj->format('Y-m-d');
    $d_out = $d_out_obj->format('Y-m-d');

    if ($d_in >= $d_out) {
        wp_send_json_error(['msg' => 'Intervallo date non valido.']);
    }

    $token = paguro_generate_unique_token();
    if (!$token) {
        error_log('[Paguro] Failed to generate token for waitlist');
        wp_send_json_error(['msg' => 'Errore interno.']);
    }
    
    $wpdb->insert("{$wpdb->prefix}paguro_availability", [
        'apartment_id' => $apt_id,
        'date_start' => $d_in,
        'date_end' => $d_out,
        'status' => 4,
        'lock_token' => $token,
        'created_at' => current_time('mysql')
    ]);

    wp_send_json_success(['redirect_params' => "?token={$token}&waitlist=1&apt=" . urlencode($apt_name) . "&in={$in_raw}&out={$out_raw}"]);
}

// =========================================================
// LOCK DATES (STANDARD)
// =========================================================

add_action('wp_ajax_paguro_lock_dates', 'paguro_handle_lock');
add_action('wp_ajax_nopriv_paguro_lock_dates', 'paguro_handle_lock');
function paguro_handle_lock()
{
    if (!check_ajax_referer('paguro_chat_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => "Sessione scaduta."]);
    }
    
    global $wpdb;

    $recaptcha_token = sanitize_text_field(wp_unslash($_POST['recaptcha_token'] ?? ''));
    $recaptcha_check = paguro_verify_recaptcha($recaptcha_token, 'paguro_lock');
    if (is_wp_error($recaptcha_check)) {
        wp_send_json_error(['msg' => $recaptcha_check->get_error_message()]);
    }

    $apt_name = sanitize_text_field(wp_unslash($_POST['apt_name'] ?? ''));
    $apt_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}paguro_apartments WHERE name LIKE %s", $apt_name));
    $in_raw = sanitize_text_field(wp_unslash($_POST['date_in'] ?? ''));
    $out_raw = sanitize_text_field(wp_unslash($_POST['date_out'] ?? ''));
    $d_in_obj = DateTime::createFromFormat('d/m/Y', $in_raw);
    $d_out_obj = DateTime::createFromFormat('d/m/Y', $out_raw);

    if (!$apt_id || !$d_in_obj || !$d_out_obj) {
        wp_send_json_error(['msg' => 'Dati non validi.']);
    }

    $d_in = $d_in_obj->format('Y-m-d');
    $d_out = $d_out_obj->format('Y-m-d');

    if ($d_in >= $d_out) {
        wp_send_json_error(['msg' => 'Intervallo date non valido.']);
    }

    $wpdb->query('START TRANSACTION');
    try {
        // IMPROVEMENT: Cleanup expired quotes on-the-fly
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}paguro_availability 
             WHERE status=2 AND receipt_url IS NULL 
             AND ((lock_expires IS NOT NULL AND lock_expires < NOW()) 
                  OR (lock_expires IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL " . PAGURO_SOFT_LOCK_HOURS . " HOUR)))"
        );

        // Check availability with FOR UPDATE lock
        $busy = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability 
             WHERE apartment_id=%d 
             AND (status=1 OR status=5 OR (status=2 AND receipt_url IS NOT NULL)) 
             AND (date_start<%s AND date_end>%s) 
             FOR UPDATE", 
            $apt_id, $d_out, $d_in
        ));

        if ($busy) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['msg' => "Data non più disponibile."]);
        }

        // Generate unique token
        $token = paguro_generate_unique_token();
        if (!$token) {
            $wpdb->query('ROLLBACK');
            error_log('[Paguro] Failed to generate token for lock');
            wp_send_json_error(['msg' => 'Errore interno.']);
        }
        
        $lock_expires = date('Y-m-d H:i:s', time() + (PAGURO_SOFT_LOCK_HOURS * 3600));
        
        $wpdb->insert("{$wpdb->prefix}paguro_availability", [
            'apartment_id' => $apt_id, 
            'date_start' => $d_in, 
            'date_end' => $d_out, 
            'status' => 2, 
            'lock_token' => $token, 
            'lock_expires' => $lock_expires
        ]);
        
        $wpdb->query('COMMIT');
        
        error_log('[Paguro] Soft lock created: Booking ' . $wpdb->insert_id . ' (Apt: ' . $apt_id . ', Dates: ' . $d_in . ' - ' . $d_out . ')');

        $booking_slug = get_option('paguro_checkout_slug', 'prenotazione');
        $redirect_params = "?token={$token}&apt=" . urlencode($apt_name) . "&in={$in_raw}&out={$out_raw}";
        $redirect_url = site_url("/{$booking_slug}/") . $redirect_params;
        
        wp_send_json_success([
            'token' => $token, 
            'redirect_params' => $redirect_params, 
            'redirect_url' => $redirect_url
        ]);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('[Paguro] Lock exception: ' . $e->getMessage());
        wp_send_json_error(['msg' => "Errore DB."]);
    }
}
// CONTINUATION OF paguro-chatbot.php - PART 3

// =========================================================
// SUBMIT BOOKING (Quote & Waitlist)
// =========================================================

add_action('wp_ajax_paguro_submit_booking', 'paguro_submit_booking');
add_action('wp_ajax_nopriv_paguro_submit_booking', 'paguro_submit_booking');
function paguro_submit_booking()
{
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    global $wpdb;

    $recaptcha_token = sanitize_text_field(wp_unslash($_POST['recaptcha_token'] ?? ''));
    $recaptcha_check = paguro_verify_recaptcha($recaptcha_token, 'paguro_booking');
    if (is_wp_error($recaptcha_check)) {
        wp_send_json_error(['msg' => $recaptcha_check->get_error_message()]);
    }
    
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $name = sanitize_text_field(wp_unslash($_POST['guest_name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));
    $phone = sanitize_text_field(wp_unslash($_POST['guest_phone'] ?? ''));
    $notes = sanitize_textarea_field(wp_unslash($_POST['guest_notes'] ?? ''));

    $wpdb->update(
        "{$wpdb->prefix}paguro_availability", 
        [
            'guest_name' => $name, 
            'guest_email' => $email, 
            'guest_phone' => $phone, 
            'guest_notes' => $notes
        ], 
        ['lock_token' => $token]
    );
    
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", 
        $token
    ));
    
    if (!$booking) {
        wp_send_json_error(['msg' => 'Prenotazione non trovata.']);
    }

    // IMPROVEMENT: Use secure cookie
    $cookie_val = wp_hash($token . 'paguro_auth');
    paguro_set_auth_cookie('paguro_auth_' . $token, $cookie_val);

    $link_riepilogo = site_url("/" . get_option('paguro_page_slug') . "/?token={$token}");

    if ($booking->status == 4) {
        // WAITLIST
        paguro_add_history($booking->id, 'WAITLIST_SUB', "Iscritto in Waitlist");
        
        if (function_exists('paguro_send_waitlist_confirmation_to_user')) {
            paguro_send_waitlist_confirmation_to_user($booking->id);
        }
        if (function_exists('paguro_send_waitlist_confirmation_to_admin')) {
            paguro_send_waitlist_confirmation_to_admin($booking->id);
        }
        
        error_log('[Paguro] Waitlist subscription: Booking ' . $booking->id);
    } else {
        // PREVENTIVO
        paguro_add_history($booking->id, 'QUOTE_REQ', "Preventivo richiesto");
        
        if (function_exists('paguro_send_quote_request_to_user')) {
            paguro_send_quote_request_to_user($booking->id);
        }
        if (function_exists('paguro_send_quote_request_to_admin')) {
            paguro_send_quote_request_to_admin($booking->id);
        }
        
        error_log('[Paguro] Quote request: Booking ' . $booking->id);
    }

    wp_send_json_success(['redirect' => $link_riepilogo]);
}

// =========================================================
// UPLOAD RECEIPT (Hard Lock Trigger)
// =========================================================

add_action('wp_ajax_paguro_upload_receipt', 'paguro_handle_receipt_upload');
add_action('wp_ajax_nopriv_paguro_upload_receipt', 'paguro_handle_receipt_upload');
function paguro_handle_receipt_upload()
{
    check_ajax_referer('paguro_chat_nonce', 'nonce');
    
    if (!isset($_FILES['file'])) {
        wp_send_json_error(['msg' => 'No file.']);
    }

    $file = $_FILES['file'];
    if (!empty($file['error'])) {
        wp_send_json_error(['msg' => 'Errore upload file.']);
    }

    $max_size = 5 * 1024 * 1024; // 5MB
    if (!empty($file['size']) && $file['size'] > $max_size) {
        wp_send_json_error(['msg' => 'File troppo grande. Massimo 5MB.']);
    }

    $allowed_mimes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
    ];

    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
    if (empty($check['ext']) || empty($check['type'])) {
        wp_send_json_error(['msg' => 'Formato file non supportato. Usa PDF, JPG, PNG o GIF.']);
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    
    $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => $allowed_mimes]);
    
    if (isset($uploaded['error'])) {
        error_log('[Paguro] Upload error: ' . $uploaded['error']);
        wp_send_json_error(['msg' => $uploaded['error']]);
    }

    global $wpdb;
    $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", 
        $token
    ));
    
    if (!$booking) {
        wp_send_json_error(['msg' => 'Prenotazione non trovata.']);
    }

    // IMPROVEMENT: Hard lock with race condition protection
    $success = paguro_hard_lock_booking($booking->id, $uploaded['url']);
    
    if (!$success) {
        // Race condition detected!
        wp_send_json_error([
            'msg' => 'Ops! Qualcun altro ha prenotato le stesse date contemporaneamente. Ti contatteremo a breve per risolvere la situazione.'
        ]);
    }
    
    // Send emails
    if (function_exists('paguro_send_receipt_received_to_user')) {
        paguro_send_receipt_received_to_user($booking->id);
    }
    if (function_exists('paguro_send_receipt_received_to_admin')) {
        paguro_send_receipt_received_to_admin($booking->id);
    }

    wp_send_json_success(['url' => $uploaded['url']]);
}

// =========================================================
// CHECKOUT FORM SHORTCODE
// =========================================================

add_shortcode('paguro_checkout', 'paguro_checkout_form');
function paguro_checkout_form()
{
    $token = sanitize_text_field($_GET['token'] ?? '');
    $is_waitlist = isset($_GET['waitlist']);
    $apt = sanitize_text_field($_GET['apt'] ?? '');
    $in = sanitize_text_field($_GET['in'] ?? '');
    $out = sanitize_text_field($_GET['out'] ?? '');

    global $wpdb;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT guest_email FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", 
        $token
    ));
    
    if ($existing) {
        $slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
        echo "<script>window.location.replace('" . esc_url(site_url("/{$slug}/?token={$token}")) . "');</script>";
        return;
    }

    if ($is_waitlist) {
        $title = "Iscrizione Lista d'Attesa: " . esc_html(ucfirst($apt));
        $intro = "Le date selezionate sono in attesa di conferma da parte di un altro utente. Inserisci i tuoi dati: se si liberano ti avviseremo subito via mail.";
        $social_html = "";
    } else {
        $title = "Richiesta Preventivo: " . esc_html(ucfirst($apt));
        $intro = "Completa i dati per ricevere il preventivo.";

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s", 
            $token
        ));
        
        if ($booking) {
            $competitors = paguro_count_competing_quotes(
                $booking->apartment_id,
                $booking->date_start,
                $booking->date_end,
                $booking->id
            );
            
            if ($competitors > 0) {
                $msg = paguro_parse_template(
                    get_option('paguro_msg_ui_social_pressure'), 
                    ['count' => $competitors]
                );
                $social_html = "<div style='background:#fff3cd; color:#856404; padding:10px; margin-bottom:15px; border-radius:4px; font-size:13px;'>" . wp_kses_post($msg) . "</div>";
            } else {
                $social_html = "";
            }
        } else {
            $social_html = "";
        }
    }

    ob_start(); ?>
    <div class="paguro-checkout-box" style="max-width:500px; margin:20px auto; padding:20px; border:1px solid #ddd; background:#fff;">
        <h3 style="text-align:center; margin-bottom:5px;"><?php echo $title; ?></h3>
        <p style="text-align:center; color:#666; margin-bottom:20px;"><?php echo esc_html($in); ?> - <?php echo esc_html($out); ?></p>

        <p style="font-size:14px; margin-bottom:15px;"><?php echo esc_html($intro); ?></p>
        <?php echo $social_html; ?>

        <form id="paguro-native-form" method="post" style="display:flex; flex-direction:column; gap:15px;">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
            <label>Nome <input type="text" name="guest_name" required autocomplete="name" style="width:100%; padding:8px;"></label>
            <label>Email <input type="email" name="guest_email" required autocomplete="email" style="width:100%; padding:8px;"></label>
            <label>Telefono <input type="tel" name="guest_phone" required autocomplete="tel" style="width:100%; padding:8px;"></label>
            <label>Note <textarea name="guest_notes" autocomplete="off" style="width:100%; padding:8px;"></textarea></label>
            <button type="submit" id="paguro-submit-btn" class="button" style="background:#28a745; color:#fff; padding:12px;">
                <?php echo $is_waitlist ? 'Avvisami se si libera' : 'Invia Richiesta'; ?>
            </button>
            <div id="paguro-form-msg"></div>
        </form>
        <p style="font-size:12px; margin-top:10px;"><?php echo wp_kses_post(get_option('paguro_msg_ui_privacy_notice')); ?></p>
    </div>
    <?php return ob_get_clean();
}

// =========================================================
// SUMMARY SHORTCODE (SECURE VIEW)
// =========================================================

add_shortcode('paguro_summary', 'paguro_summary_render');
function paguro_summary_render()
{
    if (function_exists('paguro_shortcode_summary_quote')) {
        return paguro_shortcode_summary_quote();
    }
    return '<div class="paguro-alert paguro-alert-danger">Template riepilogo non disponibile.</div>';
}

// =========================================================
// RENDER INTERFACE (Chat Widget)
// =========================================================

function paguro_render_interface($mode = 'widget')
{
    $icon_url = plugin_dir_url(__FILE__) . 'paguro_bot_icon.png';
    $cls = ($mode === 'inline') ? 'paguro-mode-inline' : 'paguro-mode-widget';
    
    ob_start(); ?>
    <div class="paguro-chat-root <?php echo esc_attr($cls); ?>">
        <div class="paguro-chat-launcher">
            <img src="<?php echo esc_url($icon_url); ?>" alt="Paguro Bot">
        </div>
        <div class="paguro-chat-window">
            <div class="paguro-chat-header">
                <span>Paguro</span>
                <span class="close-btn">&times;</span>
            </div>
            <div class="paguro-chat-body">
                <div class="paguro-msg paguro-msg-bot">
                    <img src="<?php echo esc_url($icon_url); ?>" class="paguro-bot-avatar" alt="Paguro">
                    <div class="paguro-msg-content">
                        Ciao! Sono Paguro 🦀<br>Chiedimi disponibilità per i mesi estivi (es. "Agosto").<br>
                        <div class="paguro-month-buttons">
                            <button class="paguro-quick-btn" data-msg="Disponibilità Giugno">📅 Giugno</button>
                            <button class="paguro-quick-btn" data-msg="Disponibilità Luglio">📅 Luglio</button>
                            <button class="paguro-quick-btn" data-msg="Disponibilità Agosto">📅 Agosto</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="paguro-chat-footer">
                <input type="text" class="paguro-input-field" placeholder="Scrivi qui..." autocomplete="off">
                <button type="button" class="paguro-send-btn" title="Invia">➤</button>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

add_shortcode('paguro_chat', function () {
    if (!defined('PAGURO_WIDGET_RENDERED')) define('PAGURO_WIDGET_RENDERED', true);
    return paguro_render_interface('inline');
});

add_action('wp_footer', function () {
    if (!defined('PAGURO_WIDGET_RENDERED') && get_option('paguro_global_active', 1)) {
        echo paguro_render_interface('widget');
    }
});

?>
