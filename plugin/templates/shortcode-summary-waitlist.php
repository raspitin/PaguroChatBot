<?php
/**
 * SHORTCODE: Summary Page - Waitlist
 * [paguro_summary_waitlist]
 * Mostra il riepilogo della lista d'attesa
 */

if (!defined('ABSPATH')) exit;

function paguro_shortcode_summary_waitlist() {
    global $wpdb;
    
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    
    ob_start(); ?>
    <div class="paguro-summary-wrapper">
        <div class="paguro-summary-container">
            <div class="paguro-summary-header">
                <h1>🔔 Lista d'Attesa</h1>
            </div>
            
            <div class="paguro-summary-body">
                <?php if (!$token): ?>
                    <div class="paguro-alert paguro-alert-danger">
                        ⚠️ Codice di accesso mancante.
                    </div>
                <?php else:
                    $expected_cookie = wp_hash($token . 'paguro_auth');
                    $auth_ok = (isset($_COOKIE['paguro_auth_' . $token]) && $_COOKIE['paguro_auth_' . $token] === $expected_cookie);
                    
                    if (!$auth_ok):
                        // MOSTRA FORM LOGIN
                        ?>
                        <div class="paguro-auth-section">
                            <div class="paguro-auth-card">
                                <h2>🔐 Accedi alla tua Area</h2>
                                <p>Inserisci l'email per accedere alla tua lista d'attesa.</p>
                                
                                <form id="paguro-auth-form" method="POST">
                                    <?php wp_nonce_field('paguro_auth_action', 'paguro_auth_nonce'); ?>
                                    <input type="hidden" name="paguro_action" value="verify_access">
                                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                    
                                    <div class="form-group">
                                        <label for="verify_email">Email Registrata *</label>
                                        <input type="email" id="verify_email" name="verify_email" required autocomplete="email">
                                    </div>
                                    
                                    <button type="submit" class="paguro-btn paguro-btn-primary">
                                        Accedi
                                    </button>
                                    
                                    <?php if (isset($_GET['auth_error'])): ?>
                                        <div class="paguro-alert paguro-alert-danger" style="margin-top:15px;">
                                            ❌ Email non trovata. Verifica e riprova.
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <?php
                    else:
                        $cancel_notice = isset($_GET['cancelled']) ? sanitize_text_field(wp_unslash($_GET['cancelled'])) : '';
                        // MOSTRA DETTAGLI LISTA D'ATTESA
                        $b = $wpdb->get_row($wpdb->prepare(
                            "SELECT b.*, a.name as apt_name FROM {$wpdb->prefix}paguro_availability b
                             JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id
                             WHERE b.lock_token = %s AND b.status = 4",
                            $token
                        ));
                        
                        if (!$b && $cancel_notice === 'waitlist'):
                            ?>
                            <div class="paguro-alert paguro-alert-success">
                                ✅ Iscrizione alla lista d'attesa annullata.
                            </div>
                            <?php
                        elseif (!$b):
                            ?>
                            <div class="paguro-alert paguro-alert-danger">
                                ❌ Iscrizione non trovata o non è una lista d'attesa.
                            </div>
                            <?php
                        else:
                            $history = paguro_get_history($b->id);
                            ?>
                            <div class="paguro-waitlist-summary">
                                <!-- HEADER ALERT -->
                                <div class="paguro-alert paguro-alert-info">
                                    <h3>✅ Sei in Lista d'Attesa</h3>
                                    <p>Ti avviseremo via email non appena il periodo si libererà!</p>
                                </div>
                                
                                <!-- SEZIONE INFO -->
                                <div class="paguro-section paguro-section-info">
                                    <h3>📋 Dettagli Iscrizione</h3>
                                    <div class="paguro-info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Nome:</span>
                                            <span class="info-value"><?php echo esc_html($b->guest_name); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Email:</span>
                                            <span class="info-value"><?php echo esc_html($b->guest_email); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Telefono:</span>
                                            <span class="info-value"><?php echo esc_html($b->guest_phone); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEZIONE DATE RICHIESTE -->
                                <div class="paguro-section paguro-section-dates">
                                    <h3>📅 Date Richieste</h3>
                                    <div class="paguro-dates-display">
                                        <div class="date-item">
                                            <label>Appartamento:</label>
                                            <strong><?php echo esc_html(ucfirst($b->apt_name)); ?></strong>
                                        </div>
                                        <div class="date-item">
                                            <label>Check-in:</label>
                                            <strong><?php echo esc_html(date('d/m/Y (l)', strtotime($b->date_start))); ?></strong>
                                        </div>
                                        <div class="date-item">
                                            <label>Check-out:</label>
                                            <strong><?php echo esc_html(date('d/m/Y (l)', strtotime($b->date_end))); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEZIONE STORIA -->
                                <?php if (!empty($history)): ?>
                                <div class="paguro-section paguro-section-history">
                                    <h3>📜 Cronologia</h3>
                                    <div class="paguro-timeline">
                                        <?php foreach (array_reverse($history) as $entry): ?>
                                            <div class="timeline-item">
                                                <span class="timeline-time">
                                                    <?php echo esc_html(date('d/m H:i', strtotime($entry['time']))); ?>
                                                </span>
                                                <span class="timeline-action">
                                                    <?php echo esc_html($entry['action']); ?>
                                                </span>
                                                <?php if (!empty($entry['details'])): ?>
                                                    <span class="timeline-details">
                                                        <?php echo esc_html($entry['details']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- SEZIONE AZIONI -->
                                <div class="paguro-section paguro-section-actions">
                                    <h3>⚙️ Azioni</h3>
                                    <div class="paguro-action-buttons">
                                        <form id="paguro-cancel-waitlist-form" method="POST">
                                            <?php wp_nonce_field('paguro_cancel_action', 'paguro_cancel_nonce'); ?>
                                            <input type="hidden" name="paguro_action" value="cancel_user_booking">
                                            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                            <button id="paguro-cancel-waitlist-btn" class="paguro-btn paguro-btn-danger" type="submit">
                                                ❌ Esci dalla Lista d'Attesa
                                            </button>
                                        </form>
                                    </div>
                                    <div id="paguro-cancel-msg"></div>
                                </div>
                            </div>
                            <?php
                        endif;
                    endif;
                endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('paguro_summary_waitlist', 'paguro_shortcode_summary_waitlist');

?>
