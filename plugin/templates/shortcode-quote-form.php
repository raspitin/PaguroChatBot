<?php
/**
 * SHORTCODE: Quote Form
 * [paguro_quote_form]
 * Mostra il form per richiedere un preventivo
 */

if (!defined('ABSPATH')) exit;

function paguro_shortcode_quote_form() {
    global $wpdb;
    
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $apt = isset($_GET['apt']) ? sanitize_text_field($_GET['apt']) : '';
    $date_in = isset($_GET['in']) ? sanitize_text_field($_GET['in']) : '';
    $date_out = isset($_GET['out']) ? sanitize_text_field($_GET['out']) : '';
    
    // Se non hai token, non sei qui
    if (!$token || !$apt || !$date_in || !$date_out) {
        return '<div class="paguro-error">Parametri mancanti. Torna al chat per selezionare le date.</div>';
    }
    
    // Controlla se la prenotazione esiste già
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT guest_email FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s",
        $token
    ));
    
    if ($existing) {
        // Redirect alla pagina di riepilogo
        $slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
        echo "<script>window.location.replace('" . esc_url(site_url("/{$slug}/?token={$token}")) . "');</script>";
        return '';
    }
    
    // Prendi info booking per verificare social pressure
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s",
        $token
    ));
    
    $social_html = '';
    if ($booking) {
        $competitors = paguro_count_competing_quotes(
            $booking->apartment_id,
            $booking->date_start,
            $booking->date_end,
            $booking->id
        );
        
        if ($competitors > 0) {
            $msg = paguro_parse_template(
                get_option('paguro_msg_ui_social_pressure', '⚡ Ci sono {count} altre richieste attive per queste date.'),
                ['count' => $competitors]
            );
            $social_html = "<div class='paguro-social-pressure'>$msg</div>";
        }
    }
    
    ob_start(); ?>
    <div class="paguro-form-wrapper">
        <div class="paguro-form-card">
            <div class="paguro-form-header">
                <h2>Richiesta Preventivo</h2>
                <p class="paguro-form-subtitle"><?php echo esc_html(ucfirst($apt)); ?></p>
            </div>
            
            <div class="paguro-form-dates">
                <strong>📅 Date Selezionate:</strong>
                <span><?php echo esc_html($date_in); ?> — <?php echo esc_html($date_out); ?></span>
            </div>
            
            <div class="paguro-form-body">
                <?php echo $social_html; ?>
                
                <p class="paguro-form-instruction">Completa i dati per ricevere il preventivo dettagliato con i dati per il bonifico.</p>
                
                <form id="paguro-quote-form" class="paguro-native-form">
                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                    
                    <div class="form-group">
                        <label for="guest_name">Nome Completo *</label>
                        <input type="text" id="guest_name" name="guest_name" required autocomplete="name">
                    </div>
                    
                    <div class="form-group">
                        <label for="guest_email">Email *</label>
                        <input type="email" id="guest_email" name="guest_email" required autocomplete="email">
                    </div>
                    
                    <div class="form-group">
                        <label for="guest_phone">Telefono *</label>
                        <input type="tel" id="guest_phone" name="guest_phone" required autocomplete="tel">
                    </div>
                    
                    <div class="form-group">
                        <label for="guest_notes">Note (opzionale)</label>
                        <textarea id="guest_notes" name="guest_notes" rows="3" autocomplete="off"></textarea>
                    </div>
                    
                    <button type="submit" class="paguro-btn paguro-btn-primary" id="paguro-submit-btn">
                        Richiedi Preventivo
                    </button>
                    
                    <div id="paguro-form-msg" class="paguro-form-message"></div>
                </form>
                
                <p class="paguro-form-privacy">
                    <?php echo wp_kses_post(get_option('paguro_msg_ui_privacy_notice', 'I tuoi dati sono protetti.')); ?>
                </p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('paguro_quote_form', 'paguro_shortcode_quote_form');

?>
