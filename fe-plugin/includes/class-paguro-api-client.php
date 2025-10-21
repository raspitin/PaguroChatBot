<?php
// Evita l'accesso diretto ai file
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Paguro_API_Client {

    private $base_url;
    private $api_token;

    public function __construct() {
        $settings = get_option( 'paguro_settings' );
        $this->base_url = isset( $settings['backend_url'] ) ? trailingslashit( $settings['backend_url'] ) . 'api/v1/' : '';
        $this->api_token = isset( $settings['api_token'] ) ? $settings['api_token'] : '';
    }

    /**
     * Metodo generico per effettuare chiamate API.
     */
    private function remote_request( $endpoint, $args ) {
        if ( empty( $this->base_url ) ) {
            return new WP_Error( 'paguro_api_error', 'URL del Backend non configurato.' );
        }

        $url = $this->base_url . $endpoint;
        
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_token, 
        );

        $args['headers'] = array_merge( $headers, ( isset( $args['headers'] ) ? $args['headers'] : array() ) );
        
        // Timeout aumentato a 300s per il cold start di Ollama
        $args['timeout'] = 300; 

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 ) {
            return $data;
        } else {
            return new WP_Error( 'paguro_api_http_error', 'Errore API HTTP (' . $code . '): ' . ( isset( $data['message'] ) ? $data['message'] : 'Errore sconosciuto.' ), array( 'status' => $code ) );
        }
    }

    public function test_connection() {
        return $this->remote_request( 'admin/status', array( 'method' => 'GET' ) );
    }

    public function send_ollama_query( $query ) {
        $body = json_encode( array( 'query' => $query ) );
        
        $args = array(
            'method' => 'POST',
            'body' => $body,
            'headers' => array( 'Authorization' => '' ), // Rimuove il token
        );
        
        return $this->remote_request( 'ollama/query', $args );
    }

    public function check_availability( $data_inizio, $data_fine, $appartamento_id ) {
        $body = json_encode( array( 
            'data_inizio' => $data_inizio, 
            'data_fine' => $data_fine,
            'appartamento_id' => $appartamento_id
        ) );
        
        $args = array(
            'method' => 'POST',
            'body' => $body,
            'headers' => array( 'Authorization' => '' ), 
        );

        return $this->remote_request( 'disponibilita', $args );
    }
}
// FINE DEL FILE. NESSUNA PARENTESI GRFFA AGGIUNTIVA.