<?php
/**
 * SHORTCODE: Summary Page - Quote
 * [paguro_summary_quote]
 * Mostra il riepilogo della quotazione con opzione upload ricevuta
 */

if (!defined('ABSPATH')) exit;

function paguro_shortcode_summary_quote() {
    global $wpdb;
    
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    
    ob_start(); ?>
    <div class="paguro-summary-wrapper">
        <div class="paguro-summary-container">
            <div class="paguro-summary-header">
                <h1>📦 Riepilogo Prenotazione</h1>
            </div>
            
            <div class="paguro-summary-body">
                <?php if (!$token): ?>
                    <div class="paguro-alert paguro-alert-danger">
                        ⚠️ Codice di accesso mancante.
                    </div>
                <?php else:
                    // Verifica cookie per sicurezza
                    $expected_cookie = wp_hash($token . 'paguro_auth');
                    $auth_ok = (isset($_COOKIE['paguro_auth_' . $token]) &&
                               $_COOKIE['paguro_auth_' . $token] === $expected_cookie);
                    
                    if (!$auth_ok):
                        // MOSTRA FORM LOGIN
                        ?>
                        <div class="paguro-auth-section">
                            <div class="paguro-auth-card">
                                <h2>🔐 Accedi alla tua Area</h2>
                                <p>Inserisci i tuoi dati per accedere al riepilogo della prenotazione.</p>
                                
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
                        // MOSTRA DETTAGLI PRENOTAZIONE
                        $b = $wpdb->get_row($wpdb->prepare(
                            "SELECT b.*, a.name as apt_name, a.base_price FROM {$wpdb->prefix}paguro_availability b
                             JOIN {$wpdb->prefix}paguro_apartments a ON b.apartment_id = a.id
                             WHERE b.lock_token = %s",
                            $token
                        ));
                        
                        if (!$b):
                            ?>
                            <div class="paguro-alert paguro-alert-danger">
                                ❌ Prenotazione non trovata.
                            </div>
                            <?php
                        else:
                            $cancel_notice = isset($_GET['cancelled']) ? sanitize_text_field(wp_unslash($_GET['cancelled'])) : '';
                            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
                            $arrival_dt = new DateTime($b->date_start, $tz);
                            $cancel_deadline_dt = (clone $arrival_dt)->modify('-15 days');
                            $cancel_deadline_fmt = $cancel_deadline_dt->format('d/m/Y');

                            if ($cancel_notice === 'requested') {
                                echo "<div class='paguro-alert paguro-alert-success' style='margin-bottom:15px;'>✅ Richiesta di cancellazione inviata.</div>";
                            } elseif ($cancel_notice === 'pending') {
                                echo "<div class='paguro-alert paguro-alert-warning' style='margin-bottom:15px;'>⏳ Hai già una richiesta di cancellazione in attesa.</div>";
                            } elseif ($cancel_notice === 'iban_required') {
                                echo "<div class='paguro-alert paguro-alert-danger' style='margin-bottom:15px;'>❌ Inserisci un IBAN valido per il rimborso.</div>";
                            } elseif ($cancel_notice === 'iban_invalid') {
                                echo "<div class='paguro-alert paguro-alert-danger' style='margin-bottom:15px;'>❌ IBAN non valido. Verifica e riprova.</div>";
                            } elseif ($cancel_notice === 'denied') {
                                echo "<div class='paguro-alert paguro-alert-danger' style='margin-bottom:15px;'>❌ Cancellazione non disponibile. Era possibile fino al {$cancel_deadline_fmt}.</div>";
                            } elseif ($cancel_notice === 'waitlist') {
                                echo "<div class='paguro-alert paguro-alert-success' style='margin-bottom:15px;'>✅ Iscrizione alla lista d'attesa annullata.</div>";
                            }

                            if ($b->status == 4):
                            // WAITLIST VIEW
                            ?>
                            <div class="paguro-waitlist-summary">
                                <div class="paguro-alert paguro-alert-info">
                                    ✅ <strong>Sei in Lista d'Attesa</strong>
                                </div>
                                <div class="paguro-summary-info">
                                    <p><strong>Appartamento:</strong> <?php echo esc_html(ucfirst($b->apt_name)); ?></p>
                                    <p><strong>Date Richieste:</strong> <?php echo esc_html(date('d/m/Y', strtotime($b->date_start))); ?> — <?php echo esc_html(date('d/m/Y', strtotime($b->date_end))); ?></p>
                                    <p><strong>Ospite:</strong> <?php echo esc_html($b->guest_name); ?></p>
                                    <p style="color:#d35400; margin-top:15px;">Ti avviseremo via email non appena il periodo si libererà.</p>
                                </div>
                            </div>
                            <?php
                        else:
                            // QUOTE VIEW
                            $total_cost = paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end);
                            $deposit_percent = intval(get_option('paguro_deposit_percent', 30)) / 100;
                            $deposit = ceil($total_cost * $deposit_percent);
                            $remaining = $total_cost - $deposit;
                            $is_confirmed = ($b->status == 1);
                            $is_cancel_requested = ($b->status == 5);
                            $can_cancel = current_time('timestamp') <= $cancel_deadline_dt->getTimestamp();
                            
                            $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
                            $ph = [
                                'guest_name' => $b->guest_name,
                                'guest_email' => $b->guest_email,
                                'guest_phone' => $b->guest_phone,
                                'customer_iban' => $b->customer_iban ?? '',
                                'customer_iban_priv' => isset($b->customer_iban) ? paguro_mask_iban($b->customer_iban) : '',
                                'apt_name' => ucfirst($b->apt_name),
                                'date_start' => date('d/m/Y', strtotime($b->date_start)),
                                'date_end' => date('d/m/Y', strtotime($b->date_end)),
                                'date_start_raw' => $b->date_start,
                                'date_end_raw' => $b->date_end,
                                'total_cost' => $total_cost,
                                'deposit_cost' => $deposit,
                                'remaining_cost' => $remaining,
                                'total_cost_fmt' => number_format($total_cost, 2, ',', '.'),
                                'deposit_cost_fmt' => number_format($deposit, 2, ',', '.'),
                                'remaining_cost_fmt' => number_format($remaining, 2, ',', '.'),
                                'deposit_percent' => $deposit_percent,
                                'iban' => get_option('paguro_bank_iban'),
                                'intestatario' => get_option('paguro_bank_owner'),
                                'receipt_url' => $b->receipt_url,
                                'receipt_uploaded_at' => $b->receipt_uploaded_at,
                                'receipt_uploaded_at_fmt' => $b->receipt_uploaded_at ? date('d/m/Y H:i', strtotime($b->receipt_uploaded_at)) : '',
                                'booking_id' => $b->id,
                                'apartment_id' => $b->apartment_id,
                                'status' => $b->status,
                                'token' => $b->lock_token,
                                'created_at' => $b->created_at,
                                'lock_expires' => $b->lock_expires,
                                'link_riepilogo' => site_url("/" . get_option('paguro_page_slug') . "/?token={$b->lock_token}"),
                                'booking_url' => site_url("/" . get_option('paguro_checkout_slug', 'prenotazione') . "/")
                            ];
                            ?>
                            <div class="paguro-quote-summary">
                                <!-- SEZIONE INFO -->
                                <div class="paguro-section paguro-section-info">
                                    <h3>📋 Dati Prenotazione</h3>
                                    <div class="paguro-info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Ospite:</span>
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
                                        <div class="info-item">
                                            <span class="info-label">Appartamento:</span>
                                            <span class="info-value"><?php echo esc_html(ucfirst($b->apt_name)); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Check-in:</span>
                                            <span class="info-value"><?php echo esc_html(date('d/m/Y', strtotime($b->date_start))); ?> (Sabato)</span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Check-out:</span>
                                            <span class="info-value"><?php echo esc_html(date('d/m/Y', strtotime($b->date_end))); ?> (Sabato)</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEZIONE PREZZO -->
                                <div class="paguro-section paguro-section-pricing">
                                    <h3>💰 Calcolo Prezzo</h3>
                                    <div class="paguro-pricing-table">
                                        <div class="pricing-row">
                                            <span class="pricing-label">Costo Totale:</span>
                                            <span class="pricing-value">€ <?php echo number_format($total_cost, 2, ',', '.'); ?></span>
                                        </div>
                                        <div class="pricing-row pricing-highlight">
                                            <span class="pricing-label"><strong>Acconto Richiesto (30%):</strong></span>
                                            <span class="pricing-value"><strong>€ <?php echo number_format($deposit, 2, ',', '.'); ?></strong></span>
                                        </div>
                                        <div class="pricing-row">
                                            <span class="pricing-label">Saldo (da pagare all'arrivo):</span>
                                            <span class="pricing-value">€ <?php echo number_format($remaining, 2, ',', '.'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEZIONE PAGAMENTO -->
                                <?php if (empty($b->receipt_url)): ?>
                                    <div class="paguro-section paguro-section-payment">
                                        <h3>🏦 Dati per il Bonifico</h3>
                                        <div class="paguro-bank-info">
                                            <div class="bank-item">
                                                <label>Intestatario:</label>
                                                <strong><?php echo esc_html($ph['intestatario']); ?></strong>
                                            </div>
                                            <div class="bank-item">
                                                <label>IBAN:</label>
                                                <code><?php echo esc_html($ph['iban']); ?></code>
                                            </div>
                                            <div class="bank-item">
                                                <label>Importo:</label>
                                                <strong>€ <?php echo number_format($deposit, 2, ',', '.'); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="paguro-custom-content">
                                            <?php
                                            $summary_tpl = get_option('paguro_msg_ui_summary_page', '<div>...</div>');
                                            $summary_confirm_tpl = get_option('paguro_msg_ui_summary_confirm_page', '');
                                            if ($summary_confirm_tpl === '') {
                                                $summary_confirm_tpl = '<p><strong>La tua prenotazione è confermata.</strong></p>' .
                                                    '<p>Soggiorno: {date_start} - {date_end} presso {apt_name}.</p>' .
                                                    '<p>Di seguito trovi i dettagli del pagamento e la distinta (se disponibile).</p>';
                                            }
                                            $template_to_use = ($is_confirmed && !empty($summary_confirm_tpl)) ? $summary_confirm_tpl : $summary_tpl;
                                            echo wp_kses_post(paguro_parse_template($template_to_use, $ph));
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <!-- SEZIONE UPLOAD -->
                                    <div class="paguro-section paguro-section-upload">
                                        <h3>📄 Carica Distinta del Bonifico</h3>
                                        <p class="paguro-upload-instruction">
                                            <?php echo esc_html(get_option('paguro_msg_ui_upload_instruction', 'Carica la distinta per bloccare le date.')); ?>
                                        </p>
                                        
                                        <div id="paguro-upload-area" class="paguro-upload-drop-zone">
                                            <div class="upload-icon">📁</div>
                                            <p class="upload-text">Trascina il file qui oppure fai clic per selezionare</p>
                                            <input type="file" id="paguro-file-input" style="display:none;" accept=".pdf,.jpg,.jpeg,.png,.gif">
                                        </div>
                                        
                                        <div id="paguro-upload-status" class="paguro-upload-status"></div>
                                        
                                        <div id="paguro-upload-success-container" class="paguro-alert paguro-alert-success" style="display:none; margin-top:20px;">
                                            <h4>✅ Distinta Ricevuta!</h4>
                                            <p>Le date sono ora bloccate in attesa di validazione da parte del nostro team.</p>
                                            <div id="paguro-view-link"></div>
                                        </div>
                                        
                                        <input type="hidden" id="paguro-token" value="<?php echo esc_attr($token); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="paguro-section paguro-section-uploaded">
                                        <div class="paguro-alert paguro-alert-info" style="margin-bottom:20px;">
                                            📄 Distinta ricevuta — validazione entro 24 ore.
                                        </div>
                                        <h3>✅ Distinta Caricata</h3>
                                        <div class="paguro-alert paguro-alert-success">
                                            La tua distinta è stata ricevuta e il nostro team la sta validando.<br>
                                            Ti contatteremo entro 24 ore per la conferma finale.
                                        </div>
                                        <a href="<?php echo esc_url($b->receipt_url); ?>" target="_blank" class="paguro-btn paguro-btn-secondary">
                                            📄 Visualizza Distinta
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <?php if ($is_confirmed && $can_cancel && !$is_cancel_requested): ?>
                                    <div class="paguro-section paguro-section-actions">
                                        <h3>⚙️ Azioni</h3>
                                        <form id="paguro-cancel-booking-form" method="POST">
                                            <?php wp_nonce_field('paguro_cancel_action', 'paguro_cancel_nonce'); ?>
                                            <input type="hidden" name="paguro_action" value="cancel_user_booking">
                                            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                            <div class="form-group" style="margin-bottom:10px;">
                                                <label for="paguro-customer-iban">IBAN per rimborso *</label>
                                                <input type="text" id="paguro-customer-iban" name="customer_iban" required
                                                       placeholder="IT00X0000000000000000000000"
                                                       value="<?php echo esc_attr($b->customer_iban ?? ''); ?>">
                                                <p style="font-size:12px; color:#666; margin-top:6px;">
                                                    Inviando la richiesta con l'IBAN indicato rinunci al soggiorno; una volta confermata la cancellazione, il rimborso sarà disposto su questo conto.
                                                </p>
                                            </div>
                                            <button class="paguro-btn paguro-btn-danger" type="submit">❌ Cancella prenotazione</button>
                                        </form>
                                        <p style="font-size:12px; color:#666; margin-top:8px;">Puoi effettuare la cancellazione entro <?php echo esc_html($cancel_deadline_fmt); ?>.</p>
                                    </div>
                                <?php elseif ($is_confirmed && !$can_cancel): ?>
                                    <div class="paguro-section paguro-section-actions">
                                        <div class="paguro-alert paguro-alert-info">
                                            La cancellazione non è più disponibile. Era possibile fino al <?php echo esc_html($cancel_deadline_fmt); ?>.
                                        </div>
                                    </div>
                                <?php elseif ($is_cancel_requested): ?>
                                    <div class="paguro-section paguro-section-actions">
                                        <div class="paguro-alert paguro-alert-warning">
                                            Richiesta di cancellazione inviata. Riceverai conferma via email.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                        endif;
                    endif;
                endif;
                endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('paguro_summary_quote', 'paguro_shortcode_summary_quote');

?>
