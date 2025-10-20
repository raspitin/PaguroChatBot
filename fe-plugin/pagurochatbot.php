<?php
/**
 * Plugin Name: Paguro Chat Bot
 * Plugin URI: https://www.villaceli.it/
 * Description: Un assistente virtuale per la verifica e la prenotazione di appartamenti con fallback LLM (Ollama).
 * Version: 1.0.0
 * Author: [Il Tuo Nome/Azienda]
 * Text Domain: paguro-chat-bot
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

/**
 * Funzione di attivazione.
 */
function activate_paguro_chat_bot() {
    // Potrebbe essere necessario creare tabelle DB custom in WP per alcune configurazioni,
    // ma per ora useremo le opzioni di WP.
}
register_activation_hook( __FILE__, 'activate_paguro_chat_bot' );

/**
 * La classe principale del plugin.
 */
class Paguro_Chat_Bot {

    protected static $instance = null;

    /**
     * Singleton Pattern.
     * @return Paguro_Chat_Bot
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Costruttore.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Carica le dipendenze necessarie.
     */
    private function load_dependencies() {
        // Classi per l'Admin
        require_once PAGURO_PLUGIN_DIR . 'admin/class-paguro-admin.php';

        // Classi per l'interazione API
        require_once PAGURO_PLUGIN_DIR . 'includes/class-paguro-api-client.php';
    }

    /**
     * Registra tutti gli hook relativi all'area Admin.
     */
    private function define_admin_hooks() {
        $paguro_admin = new Paguro_Admin();
        
        // Aggiunge il menu e le pagine di configurazione
        add_action( 'admin_menu', array( $paguro_admin, 'add_plugin_admin_menu' ) );
        
        // Registra le impostazioni (Token, URL BE, ecc.)
        add_action( 'admin_init', array( $paguro_admin, 'register_settings' ) );
        
        // Carica script e stili per l'area admin
        add_action( 'admin_enqueue_scripts', array( $paguro_admin, 'enqueue_styles_and_scripts' ) );

        // Hook per l'endpoint di test della connettività
        add_action( 'wp_ajax_paguro_test_connection', array( $paguro_admin, 'test_backend_connection' ) );
    }

    /**
     * Registra tutti gli hook pubblici (Frontend).
     */
    private function define_public_hooks() {
        // Carica script e stili per il frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_styles_and_scripts' ) );
        
        // Aggiunge il markup del chatbot al footer (o altra posizione)
        add_action( 'wp_footer', array( $this, 'render_chatbot_interface' ) );
        
        // Hook AJAX per l'interazione del chatbot (disponibilità/ollama)
        add_action( 'wp_ajax_paguro_chatbot_query', array( $this, 'handle_chatbot_query' ) );
        add_action( 'wp_ajax_nopriv_paguro_chatbot_query', array( $this, 'handle_chatbot_query' ) );
    }

    /**
     * Carica stili e script per il Frontend.
     */
    public function enqueue_public_styles_and_scripts() {
        wp_enqueue_style( 'paguro-chatbot-style', PAGURO_PLUGIN_URL . 'assets/css/paguro-styles.css', array(), PAGURO_VERSION, 'all' );
        
        wp_enqueue_script( 'paguro-chatbot-script', PAGURO_PLUGIN_URL . 'assets/js/paguro-chatbot.js', array( 'jquery' ), PAGURO_VERSION, true );

        // Passa variabili PHP a JS (fondamentale per AJAX)
        $config = get_option( 'paguro_chatbot_config', array() );
        wp_localize_script( 'paguro-chatbot-script', 'PaguroConfig', array(
            'ajaxurl'             => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'paguro_chatbot_nonce' ),
            'welcome_message'     => isset( $config['welcome_message'] ) ? $config['welcome_message'] : 'Ciao! Sono Paguro, il tuo assistente per Villa Celi. Posso aiutarti a verificare le date disponibili per i nostri appartamenti.',
            // Altre configurazioni del chatbot andranno qui...
        ) );
    }

    /**
     * Inietta il markup HTML per il chatbot nel frontend.
     */
    public function render_chatbot_interface() {
        // Il markup HTML del chatbot (un icona che si espande in una finestra)
        ?>
        <div id="paguro-chatbot-container">
            <button id="paguro-chatbot-toggle">🐚 Chat</button>
            <div id="paguro-chatbot-window" style="display:none;">
                <div id="paguro-chatbot-header">Paguro Chat Bot</div>
                <div id="paguro-chatbot-messages">
                    </div>
                <input type="text" id="paguro-chatbot-input" placeholder="Chiedi la disponibilità...">
                <button id="paguro-chatbot-send">Invia</button>
            </div>
        </div>
        <?php
    }

    /**
     * Gestisce le query del chatbot (AJAX).
     */
    public function handle_chatbot_query() {
        // Verifiche di sicurezza
        check_ajax_referer( 'paguro_chatbot_nonce', 'security' );

        if ( ! isset( $_POST['query'] ) ) {
            wp_send_json_error( array( 'message' => 'Query mancante.' ) );
        }
        
        $query = sanitize_text_field( $_POST['query'] );
        
        // Logica di gestione della query (implementata in un'altra classe/metodo)
        $response = $this->process_chatbot_query( $query );

        wp_send_json_success( $response );
    }
    
    /**
     * Placeholder per la logica di processamento del chatbot.
     */
    private function process_chatbot_query( $query ) {
        // 1. Tenta di abbinare parole chiave (logica interna)
        // 2. Se abbinamento OK: chiama Paguro_API_Client per /api/v1/disponibilita
        // 3. Se NON abbinamento: chiama Paguro_API_Client per /api/v1/ollama/query
        
        // Esempio fittizio:
        $api_client = new Paguro_API_Client();
        
        // ... (Logica di estrazione delle date) ...
        
        // Esempio: chiamata a Ollama
        $ollama_response = $api_client->send_ollama_query( $query );

        return array( 
            'type' => 'ollama', 
            'text' => $ollama_response 
        );
    }
}

// Inizializza il plugin.
Paguro_Chat_Bot::get_instance();
