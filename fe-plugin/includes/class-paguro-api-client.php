<?php
// Evita l'accesso diretto ai file
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Paguro_API_Client {

    private $base_url;
    private $api_token;

    public function __construct() {
        // Recupera le configurazioni dal DB di WordPress (salvate tramite il pannello admin)
        $settings = get_option( 'paguro_settings' );
        $this->base_url = isset( $settings['backend_url'] ) ? trailingslashit( $settings['backend_url'] ) . 'api/v1/' : '';
        $this->api_token = isset( $settings['api_token'] ) ? $settings['api_token'] : '';
    }

    /**
     * Metodo generico per effettuare chiamate API.
     * @param string $endpoint L'endpoint da chiamare (es. 'disponibilita').
     * @param array $args Parametri aggiuntivi per wp_remote_request.
     * @return array|WP_Error La risposta decodificata o un errore di WP.
     */
    private function remote_request( $endpoint, $args ) {
        if ( empty( $this->base_url ) ) {
            return new WP_Error( 'paguro_api_error', 'URL del Backend non configurato.' );
        }

        $url = $this->base_url . $endpoint;
        
        // Intestazioni richieste (inclusa l'autorizzazione con Token)
        $headers = array(
            'Content-Type' => 'application/json',
            // Per le API amministrative che richiedono sicurezza (CRUD/Test)
            'Authorization' => 'Bearer ' . $this->api_token, 
        );

        // Aggiungi o sovrascrivi le intestazioni fornite
        $args['headers'] = array_merge( $headers, ( isset( $args['headers'] ) ? $args['headers'] : array() ) );
        
        // Imposta il timeout
        $args['timeout'] = 30; // 30 secondi per Ollama/operazioni lunghe

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

    /**
     * Effettua un test di connettività semplice.
     */
    public function test_connection() {
        // Endpoint di test semplice che DEVE richiedere il Token API per verificare l'autenticazione.
        return $this->remote_request( 'admin/status', array( 'method' => 'GET' ) );
    }

    /**
     * Invia una query generica al servizio Ollama.
     */
    public function send_ollama_query( $query ) {
        $body = json_encode( array( 'query' => $query ) );
        
        // Questo endpoint Ollama NON necessita del Token (solo CORS/HTTPS per il frontend)
        $args = array(
            'method' => 'POST',
            'body' => $body,
            'headers' => array( 'Authorization' => '' ), // Rimuove il token, se necessario
        );
        
        return $this->remote_request( 'ollama/query', $args );
    }

    /**
     * Verifica la disponibilità degli appartamenti.
     */
    public function check_availability( $data_inizio, $data_fine, $appartamento_id ) {
        $body = json_encode( array( 
            'data_inizio' => $data_inizio, 
            'data_fine' => $data_fine,
            'appartamento_id' => $appartamento_id
        ) );
        
        $args = array(
            'method' => 'POST',
            'body' => $body,
            'headers' => array( 'Authorization' => '' ), // Non richiede Token
        );

        return $this->remote_request( 'disponibilita', $args );
    }
}
