<?php
// Evita l'accesso diretto ai file
if ( ! defined( 'WPINC' ) ) {
    die;
}

// FIX: Aggiunto controllo di esistenza per prevenire "Cannot redeclare class"
if ( ! class_exists( 'Paguro_Admin' ) ) {

    class Paguro_Admin {

        public function add_plugin_admin_menu() {
            add_menu_page(
                'Paguro ChatBot',
                'Paguro ChatBot',
                'manage_options',
                'paguro-main',
                array( $this, 'display_settings_page' ),
                'dashicons-chart-bar',
                6 
            );

            add_submenu_page(
                'paguro-main',
                'Impostazioni API',
                'Impostazioni API',
                'manage_options',
                'paguro-main',
                array( $this, 'display_settings_page' )
            );

            add_submenu_page(
                'paguro-main',
                'Gestione Occupazioni',
                'Gestione Occupazioni',
                'manage_options',
                'paguro-occupazioni',
                array( $this, 'display_occupazioni_page' )
            );

            add_submenu_page(
                'paguro-main',
                'Configurazione Chatbot',
                'Configurazione Chatbot',
                'manage_options',
                'paguro-chatbot-config',
                array( $this, 'display_chatbot_config_page' )
            );
        }

        public function register_settings() {
            // Registra le impostazioni per la pagina generale
            register_setting( 'paguro_settings_group', 'paguro_settings', array( 'sanitize_callback' => array( $this, 'sanitize_paguro_settings' ) ) );

            add_settings_section(
                'paguro_api_section', 
                'Configurazione Backend API', 
                array( $this, 'api_section_callback' ), 
                'paguro-main'
            );
            
            // NUOVA SEZIONE: Controllo Visualizzazione
            add_settings_section(
                'paguro_display_section', 
                'Controllo Visualizzazione Chatbot', 
                array( $this, 'display_section_callback' ), 
                'paguro-main'
            );

            add_settings_field(
                'backend_url', 
                'URL API (Backend)', 
                array( $this, 'backend_url_callback' ), 
                'paguro-main', 
                'paguro_api_section'
            );

            add_settings_field(
                'api_token', 
                'Token API Segreto', 
                array( $this, 'api_token_callback' ), 
                'paguro-main', 
                'paguro_api_section'
            );

            // NUOVO CAMPO: Interruttore Globale
            add_settings_field(
                'global_display_enabled', 
                'Mostra Chatbot su tutte le pagine', 
                array( $this, 'global_display_callback' ), 
                'paguro-main', 
                'paguro_display_section'
            );
        }

        public function api_section_callback() {
            echo '<p>Inserisci l\'URL del tuo Backend Dockerizzato (es. <code>https://api.viamerano24.it</code>) e il Token di sicurezza.</p>';
        }

        public function backend_url_callback() {
            $options = get_option( 'paguro_settings' );
            $url = isset( $options['backend_url'] ) ? esc_url( $options['backend_url'] ) : '';
            echo "<input type='url' name='paguro_settings[backend_url]' value='{$url}' class='regular-text' placeholder='https://api.viamerano24.it' />";
            echo '<p class="description">Non includere <code>/api/v1</code>. Verrà aggiunto automaticamente.</p>';
        }

        public function api_token_callback() {
            $options = get_option( 'paguro_settings' );
            $token = isset( $options['api_token'] ) ? $options['api_token'] : '';
            $display_token = (strlen($token) > 4) ? '************' . substr($token, -4) : $token;
            echo "<input type='text' id='paguro_api_token' name='paguro_settings[api_token]' value='{$token}' class='regular-text' placeholder='Il Token deve essere uguale a PAGURO_API_TOKEN nel file .env del BE' />";
            echo '<p class="description">Questo token è usato per l\'autenticazione delle richieste amministrative (CRUD).</p>';
        }

        public function display_section_callback() {
            echo '<p>Gestisci come e dove il chatbot dovrebbe apparire sul tuo sito.</p>';
        }

        public function global_display_callback() {
            $options = get_option( 'paguro_settings' );
            $checked = isset( $options['global_display_enabled'] ) ? checked( 1, $options['global_display_enabled'], false ) : '';
            echo "<input type='checkbox' name='paguro_settings[global_display_enabled]' value='1' {$checked} />";
            echo '<p class="description">Se abilitato, il chatbot apparirà come icona flottante su ogni pagina (tranne quelle che usano lo Shortcode per l\'incorporamento).</p>';
        }
        
        public function sanitize_paguro_settings( $input ) {
            $output = array();
            if ( isset( $input['backend_url'] ) ) {
                $output['backend_url'] = esc_url_raw( $input['backend_url'] );
            }
            if ( isset( $input['api_token'] ) ) {
                $output['api_token'] = sanitize_text_field( $input['api_token'] );
            }
            if ( isset( $input['global_display_enabled'] ) ) {
                $output['global_display_enabled'] = 1;
            } else {
                $output['global_display_enabled'] = 0; 
            }
            return $output;
        }

        public function enqueue_styles_and_scripts( $hook_suffix ) {
            if ( strpos( $hook_suffix, 'paguro-' ) !== false ) {
                wp_enqueue_style( 'paguro-admin-style', PAGURO_PLUGIN_URL . 'assets/css/paguro-styles.css', array(), PAGURO_VERSION );
                
                wp_enqueue_script( 'paguro-admin-script', PAGURO_PLUGIN_URL . 'assets/js/paguro-admin.js', array( 'jquery' ), PAGURO_VERSION, true );
                
                wp_localize_script( 'paguro-admin-script', 'PaguroAdmin', array(
                    'ajaxurl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'paguro_test_nonce' ),
                    'token'   => get_option( 'paguro_settings' )['api_token'] ?? '', 
                    'url'     => get_option( 'paguro_settings' )['backend_url'] ?? '',
                ));
            }
        }

        public function display_settings_page() {
            include PAGURO_PLUGIN_DIR . 'admin/admin-settings.php';
        }

        public function display_occupazioni_page() {
            include PAGURO_PLUGIN_DIR . 'admin/admin-crud-occupazioni.php';
        }

        public function display_chatbot_config_page() {
            include PAGURO_PLUGIN_DIR . 'admin/admin-crud-chatbot.php';
        }

        public function test_backend_connection() {
            check_ajax_referer( 'paguro_test_nonce', 'security' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => 'Permesso negato.' ) );
            }

            $api_client = new Paguro_API_Client();
            $result = $api_client->test_connection();

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 
                    'message' => 'Errore di connessione o Token non valido. ' . $result->get_error_message() 
                ) );
            }
            
            if ( isset( $result['status'] ) && $result['status'] === 'OK' ) {
                wp_send_json_success( array( 'message' => 'Connessione al Backend riuscita e Token API accettato!' ) );
            } else {
                 wp_send_json_error( array( 'message' => 'Risposta API inattesa. Controlla il log BE.' ) );
            }
        }
    }
}