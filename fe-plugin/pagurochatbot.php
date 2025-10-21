<?php
/**
 * Plugin Name: PaguroChatBot
 * Plugin URI: https://www.villaceli.it/
 * Description: Un assistente virtuale per la verifica e la prenotazione di appartamenti con fallback LLM (Ollama).
 * Version: 1.0.0
 * Author: [Il Tuo Nome/Azienda]
 * Text Domain: pagurochatbot
 * Domain Path: /languages
 */

// Evita l'accesso diretto ai file
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Costanti utili
define( 'PAGURO_VERSION', '1.0.0' );
define( 'PAGURO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAGURO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function activate_paguro_chat_bot() {}
register_activation_hook( __FILE__, 'activate_paguro_chat_bot' );

class Paguro_Chat_Bot {

    protected static $instance = null;
    private $is_shortcode_rendered = false; // Flag per prevenire il doppio rendering

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function load_dependencies() {
        require_once PAGURO_PLUGIN_DIR . 'admin/class-paguro-admin.php';
        require_once PAGURO_PLUGIN_DIR . 'includes/class-paguro-api-client.php';
    }

    private function define_admin_hooks() {
        $paguro_admin = new Paguro_Admin();
        
        add_action( 'admin_menu', array( $paguro_admin, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $paguro_admin, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $paguro_admin, 'enqueue_styles_and_scripts' ) );
        add_action( 'wp_ajax_paguro_test_connection', array( $paguro_admin, 'test_backend_connection' ) );
    }

    private function define_public_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles_and_scripts' ) );
        
        // Shortcode per l'incorporamento: [chat_paguro]
        add_shortcode( 'chat_paguro', array( $this, 'render_chatbot_interface' ) );
        
        // Hook nel footer per il widget flottante globale
        add_action( 'wp_footer', array( $this, 'maybe_render_global_chatbot' ) );
        
        add_action( 'wp_ajax_paguro_chatbot_query', array( $this, 'handle_chatbot_query' ) );
        add_action( 'wp_ajax_nopriv_paguro_chatbot_query', array( $this, 'handle_chatbot_query' ) );
    }

    public function enqueue_public_styles_and_scripts() {
        wp_enqueue_style( 'paguro-chatbot-style', PAGURO_PLUGIN_URL . 'assets/css/paguro-styles.css', array(), PAGURO_VERSION, 'all' );
        
        wp_enqueue_script( 'paguro-chatbot-script', PAGURO_PLUGIN_URL . 'assets/js/paguro-chatbot.js', array( 'jquery' ), PAGURO_VERSION, true );

        $config = get_option( 'paguro_chatbot_config', array() );
        wp_localize_script( 'paguro-chatbot-script', 'PaguroConfig', array(
            'ajaxurl'             => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'paguro_chatbot_nonce' ),
            'welcome_message'     => isset( $config['welcome_message'] ) ? $config['welcome_message'] : 'Ciao! Sono Paguro, il tuo assistente per Villa Celi. Posso aiutarti a verificare le date disponibili per i nostri appartamenti.',
        ) );
    }

    public function maybe_render_global_chatbot() {
        $options = get_option( 'paguro_settings' );
        $global_enabled = isset( $options['global_display_enabled'] ) && $options['global_display_enabled'];

        // Renderizza SOLO se globale è abilitato E lo Shortcode non è già stato eseguito
        if ( $global_enabled && ! $this->is_shortcode_rendered ) {
            $this->render_floating_chatbot_interface();
        }
    }

    public function render_floating_chatbot_interface() {
        ?>
        <div id="paguro-chatbot-container" class="paguro-floating">
            <button id="paguro-chatbot-toggle">🐚 CHAT</button>
            <div id="paguro-chatbot-window" style="display:none;">
                <div id="paguro-chatbot-header">Paguro Chat Bot</div>
                <div id="paguro-chatbot-messages"></div>
                <input type="text" id="paguro-chatbot-input" placeholder="Chiedi la disponibilità...">
                <button id="paguro-chatbot-send">Invia</button>
            </div>
        </div>
        <?php
    }

    public function render_chatbot_interface( $atts ) {
        $this->is_shortcode_rendered = true;
        
        ob_start();
        ?>
        <div id="paguro-chatbot-container" class="paguro-embedded"> 
            <button id="paguro-chatbot-toggle">🐚 CHAT</button>
            <div id="paguro-chatbot-window"> 
                <div id="paguro-chatbot-header">Paguro Chat Bot</div>
                <div id="paguro-chatbot-messages"></div>
                <input type="text" id="paguro-chatbot-input" placeholder="Chiedi la disponibilità...">
                <button id="paguro-chatbot-send">Invia</button>
            </div>
        </div>
        <?php
        return ob_get_clean(); 
    }

    public function handle_chatbot_query() {
        check_ajax_referer( 'paguro_chatbot_nonce', 'security' );

        if ( ! isset( $_POST['query'] ) ) {
            wp_send_json_error( array( 'message' => 'Query mancante.' ) );
        }
        
        $query = sanitize_text_field( $_POST['query'] );
        $response = $this->process_chatbot_query( $query );

        wp_send_json_success( $response );
    }
    
    private function process_chatbot_query( $query ) {
        $api_client = new Paguro_API_Client();
        
        $chatbot_config = get_option( 'paguro_chatbot_config', array() );
        $keywords_raw = $chatbot_config['keywords'] ?? 'disponibilità, libero, date';
        $keywords = array_map('trim', explode(',', $keywords_raw));
        $is_booking_query = false;

        foreach ($keywords as $keyword) {
            if (stripos($query, $keyword) !== false) {
                $is_booking_query = true;
                break;
            }
        }

        if ($is_booking_query) {
             // LOGICA DI DISPONIBILITÀ
             $data_inizio = '2025-11-01'; // Placeholder Sabato
             $data_fine = '2025-11-08'; // Placeholder Sabato successivo
             $appartamento_id = 1; // Placeholder
            
             $availability_result = $api_client->check_availability($data_inizio, $data_fine, $appartamento_id);

             if (is_wp_error($availability_result)) {
                return array( 
                    'type' => 'error', 
                    'message' => $availability_result->get_error_message() 
                );
             }

             return array( 
                'type' => 'availability', 
                'available' => $availability_result['available'],
                'message' => $availability_result['message']
             );

        } else {
            // FALLBACK OLLAMA
            $ollama_response = $api_client->send_ollama_query( $query );
            
            if (is_wp_error($ollama_response)) {
                return array( 
                    'type' => 'error', 
                    'message' => $ollama_response->get_error_message() 
                );
            }

            return array( 
                'type' => 'ollama', 
                'text' => $ollama_response 
            );
        }
    }
}

Paguro_Chat_Bot::get_instance();