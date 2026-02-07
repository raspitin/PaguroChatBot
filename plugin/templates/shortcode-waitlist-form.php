<?php
/**
 * SHORTCODE: Waitlist Form
 * [paguro_waitlist_form]
 * Mostra il form per iscriversi alla lista d'attesa
 */

if (!defined('ABSPATH')) exit;

function paguro_shortcode_waitlist_form() {
    global $wpdb;
    
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $apt = isset($_GET['apt']) ? sanitize_text_field($_GET['apt']) : '';
    $date_in = isset($_GET['in']) ? sanitize_text_field($_GET['in']) : '';
    $date_out = isset($_GET['out']) ? sanitize_text_field($_GET['out']) : '';
    
    // Se non hai token, non sei qui
    if (!$token || !$apt || !$date_in || !$date_out) {
        return '<div class="paguro-error">Parametri mancanti. Torna al chat.</div>';
    }
    
    // Controlla se la prenotazione esiste già
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT guest_email FROM {$wpdb->prefix}paguro_availability WHERE lock_token = %s",
        $token
    ));
    
    if ($existing) {
        // Redirect alla pagina di riepilogo waitlist
        $slug = get_option('paguro_page_slug', 'riepilogo-prenotazione');
        echo "<script>window.location.replace('" . esc_url(site_url("/{$slug}/?token={$token}&waitlist=1")) . "');</script>";
        return '';
    }
    
    ob_start(); ?>
    <div class="paguro-form-wrapper paguro-waitlist-wrapper">
        <div class="paguro-form-card paguro-waitlist-card">
            <div class="paguro-form-header paguro-waitlist-header">
                <h2>🔔 Lista d'Attesa</h2>
                <p class="paguro-form-subtitle"><?php echo esc_html(ucfirst($apt)); ?></p>
            </div>
            
            <div class="paguro-waitlist-info">
                <p>Le date selezionate sono al momento occupate da un altro utente.</p>
                <div class="paguro-form-dates">
                    <strong>📅 Date Richieste:</strong>
                    <span><?php echo esc_html($date_in); ?> — <?php echo esc_html($date_out); ?></span>
                </div>
                <p><strong>Iscriviti alla lista d'attesa:</strong> Ti avviseremo via email non appena il periodo si libererà!</p>
            </div>
            
            <div class="paguro-form-body">
                <form id="paguro-waitlist-form" class="paguro-native-form">
                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                    <input type="hidden" name="is_waitlist" value="1">
                    
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
                    
                    <button type="submit" class="paguro-btn paguro-btn-secondary" id="paguro-submit-btn">
                        ✅ Aggiungimi alla Lista d'Attesa
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

add_shortcode('paguro_waitlist_form', 'paguro_shortcode_waitlist_form');

?>
