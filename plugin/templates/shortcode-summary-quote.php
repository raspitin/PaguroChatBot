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
    $ui = [
        'summary_title' => get_option('paguro_msg_ui_summary_title', 'Riepilogo preventivo'),
        'missing_token' => get_option('paguro_msg_ui_missing_token', 'Codice di accesso mancante.'),
        'auth_title' => get_option('paguro_msg_ui_auth_title', 'Area riservata'),
        'auth_help_quote' => get_option('paguro_msg_ui_auth_help_quote', "Inserisci l'email usata per la richiesta."),
        'auth_email_label' => get_option('paguro_msg_ui_auth_email_label', 'Email *'),
        'auth_submit' => get_option('paguro_msg_ui_auth_submit', 'Accedi'),
        'auth_error' => get_option('paguro_msg_ui_auth_error', 'Email non trovata.'),
        'booking_not_found' => get_option('paguro_msg_ui_booking_not_found', 'Prenotazione non trovata.'),
        'cancel_requested' => get_option('paguro_msg_ui_cancel_requested', 'Richiesta di cancellazione inviata.'),
        'cancel_pending' => get_option('paguro_msg_ui_cancel_pending', 'Cancellazione già richiesta.'),
        'cancel_iban_required' => get_option('paguro_msg_ui_cancel_iban_required', 'Inserisci un IBAN valido.'),
        'cancel_iban_invalid' => get_option('paguro_msg_ui_cancel_iban_invalid', 'IBAN non valido.'),
        'cancel_denied' => get_option('paguro_msg_ui_cancel_denied', 'Cancellazione non disponibile oltre {cancel_deadline}.'),
        'waitlist_cancelled' => get_option('paguro_msg_ui_waitlist_cancelled', "Iscrizione annullata."),
        'waitlist_title_text' => get_option('paguro_msg_ui_waitlist_title_text', "Sei in lista d'attesa"),
        'waitlist_inline_notice' => get_option('paguro_msg_ui_waitlist_inline_notice', 'Ti avviseremo appena si libera.'),
        'waitlist_inline_dates_label' => get_option('paguro_msg_ui_waitlist_inline_dates_label', 'Periodo:'),
        'section_booking' => get_option('paguro_msg_ui_section_booking', 'Dettagli soggiorno'),
        'section_pricing' => get_option('paguro_msg_ui_section_pricing', 'Prezzo'),
        'section_payment' => get_option('paguro_msg_ui_section_payment', 'Bonifico'),
        'section_upload' => get_option('paguro_msg_ui_section_upload', 'Carica distinta'),
        'section_uploaded' => get_option('paguro_msg_ui_section_uploaded', 'Distinta ricevuta'),
        'section_actions' => get_option('paguro_msg_ui_section_actions', 'Azioni'),
        'label_guest' => get_option('paguro_msg_ui_label_guest', 'Ospite'),
        'label_email' => get_option('paguro_msg_ui_label_email', 'Email'),
        'label_phone' => get_option('paguro_msg_ui_label_phone', 'Telefono'),
        'label_apartment' => get_option('paguro_msg_ui_label_apartment', 'Appartamento'),
        'label_checkin' => get_option('paguro_msg_ui_label_checkin', 'Check-in'),
        'label_checkout' => get_option('paguro_msg_ui_label_checkout', 'Check-out'),
        'label_check_suffix' => get_option('paguro_msg_ui_label_check_suffix', ' (sabato)'),
        'label_total_cost' => get_option('paguro_msg_ui_label_total_cost', 'Totale soggiorno'),
        'label_deposit' => get_option('paguro_msg_ui_label_deposit', 'Acconto (30%)'),
        'label_remaining' => get_option('paguro_msg_ui_label_remaining', 'Saldo in struttura'),
        'label_owner' => get_option('paguro_msg_ui_label_owner', 'Intestatario'),
        'label_iban' => get_option('paguro_msg_ui_label_iban', 'IBAN'),
        'label_amount' => get_option('paguro_msg_ui_label_amount', 'Importo'),
        'upload_drop' => get_option('paguro_msg_ui_upload_drop', 'Trascina qui il file o seleziona'),
        'upload_success_title' => get_option('paguro_msg_ui_upload_success_title', 'Distinta ricevuta'),
        'upload_success_text' => get_option('paguro_msg_ui_upload_success_text', 'Date bloccate in attesa di verifica.'),
        'receipt_pending' => get_option('paguro_msg_ui_receipt_pending', 'Distinta in verifica (entro 24h).'),
        'receipt_uploaded_text' => get_option('paguro_msg_ui_receipt_uploaded_text', 'Distinta ricevuta. Verifica entro 24h.'),
        'receipt_view_cta' => get_option('paguro_msg_ui_receipt_view_cta', 'Vedi distinta'),
        'cancel_label' => get_option('paguro_msg_ui_cancel_label', 'IBAN rimborso *'),
        'cancel_placeholder' => get_option('paguro_msg_ui_cancel_placeholder', 'IT00X0000000000000000000000'),
        'cancel_help' => get_option('paguro_msg_ui_cancel_help', "Con l'invio rinunci al soggiorno. Rimborso sul conto indicato."),
        'cancel_cta' => get_option('paguro_msg_ui_cancel_cta', 'Richiedi cancellazione'),
        'cancel_deadline_note' => get_option('paguro_msg_ui_cancel_deadline_note', 'Cancellazione possibile entro {cancel_deadline}.'),
        'cancel_unavailable' => get_option('paguro_msg_ui_cancel_unavailable', 'Cancellazione non disponibile (entro {cancel_deadline}).'),
        'cancel_requested_notice' => get_option('paguro_msg_ui_cancel_requested_notice', 'Cancellazione richiesta. Riceverai conferma email.')
    ];
    
    ob_start(); ?>
    <div class="paguro-summary-wrapper">
        <div class="paguro-summary-container">
            <div class="paguro-summary-header">
                <h1><?php echo esc_html($ui['summary_title']); ?></h1>
            </div>
            
            <div class="paguro-summary-body">
                <?php if (!$token): ?>
                    <div class="paguro-alert paguro-alert-danger">
                        <?php echo esc_html($ui['missing_token']); ?>
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
                                <h2><?php echo esc_html($ui['auth_title']); ?></h2>
                                <p><?php echo esc_html($ui['auth_help_quote']); ?></p>
                                
                                <form id="paguro-auth-form" method="POST">
                                    <?php wp_nonce_field('paguro_auth_action', 'paguro_auth_nonce'); ?>
                                    <input type="hidden" name="paguro_action" value="verify_access">
                                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                    
                                    <div class="form-group">
                                        <label for="verify_email"><?php echo esc_html($ui['auth_email_label']); ?></label>
                                        <input type="email" id="verify_email" name="verify_email" required autocomplete="email">
                                    </div>
                                    
                                    <button type="submit" class="paguro-btn paguro-btn-primary">
                                        <?php echo esc_html($ui['auth_submit']); ?>
                                    </button>
                                    
                                    <?php if (isset($_GET['auth_error'])): ?>
                                        <div class="paguro-alert paguro-alert-danger" style="margin-top:15px;">
                                            <?php echo esc_html($ui['auth_error']); ?>
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
                                <?php echo esc_html($ui['booking_not_found']); ?>
                            </div>
                            <?php
                        else:
                            $cancel_notice = isset($_GET['cancelled']) ? sanitize_text_field(wp_unslash($_GET['cancelled'])) : '';
                            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
                            $arrival_dt = new DateTime($b->date_start, $tz);
                            $cancel_deadline_dt = (clone $arrival_dt)->modify('-15 days');
                            $cancel_deadline_fmt = $cancel_deadline_dt->format('d/m/Y');
                            $created_at_fmt = '';
                            if (!empty($b->created_at)) {
                                $created_at_dt = new DateTime($b->created_at, $tz);
                                $created_at_fmt = $created_at_dt->format('d/m/Y H:i');
                            }
                            $cancel_denied_text = paguro_parse_template($ui['cancel_denied'], [
                                'cancel_deadline' => $cancel_deadline_fmt
                            ]);

                            if ($cancel_notice === 'requested') {
                                echo "<div class='paguro-alert paguro-alert-success' style='margin-bottom:15px;'>" . esc_html($ui['cancel_requested']) . "</div>";
                            } elseif ($cancel_notice === 'pending') {
                                echo "<div class='paguro-alert paguro-alert-warning' style='margin-bottom:15px;'>" . esc_html($ui['cancel_pending']) . "</div>";
                            } elseif ($cancel_notice === 'iban_required') {
                                echo "<div class='paguro-alert paguro-alert-danger' style='margin-bottom:15px;'>" . esc_html($ui['cancel_iban_required']) . "</div>";
                            } elseif ($cancel_notice === 'iban_invalid') {
                                echo "<div class='paguro-alert paguro-alert-danger' style='margin-bottom:15px;'>" . esc_html($ui['cancel_iban_invalid']) . "</div>";
                            } elseif ($cancel_notice === 'denied') {
                                echo "<div class='paguro-alert paguro-alert-danger' style='margin-bottom:15px;'>" . esc_html($cancel_denied_text) . "</div>";
                            } elseif ($cancel_notice === 'waitlist') {
                                echo "<div class='paguro-alert paguro-alert-success' style='margin-bottom:15px;'>" . esc_html($ui['waitlist_cancelled']) . "</div>";
                            }

                            if ($b->status == 4):
                            // WAITLIST VIEW
                            ?>
                            <div class="paguro-waitlist-summary">
                                <div class="paguro-alert paguro-alert-info">
                                    ✅ <strong><?php echo esc_html($ui['waitlist_title_text']); ?></strong>
                                </div>
                                <?php
                                $waitlist_ph = function_exists('paguro_get_email_placeholders') ? paguro_get_email_placeholders($b) : [];
                                $waitlist_inline_notice = paguro_parse_template($ui['waitlist_inline_notice'], $waitlist_ph);
                                $waitlist_inline_dates_label = paguro_parse_template($ui['waitlist_inline_dates_label'], $waitlist_ph);
                                ?>
                                <div class="paguro-summary-info">
                                    <p><strong><?php echo esc_html($ui['label_apartment']); ?></strong> <?php echo esc_html(ucfirst($b->apt_name)); ?></p>
                                    <p><strong><?php echo esc_html($waitlist_inline_dates_label); ?></strong> <?php echo esc_html(date('d/m/Y', strtotime($b->date_start))); ?> — <?php echo esc_html(date('d/m/Y', strtotime($b->date_end))); ?></p>
                                    <p><strong><?php echo esc_html($ui['label_guest']); ?></strong> <?php echo esc_html($b->guest_name); ?></p>
                                    <p style="color:#d35400; margin-top:15px;"><?php echo esc_html($waitlist_inline_notice); ?></p>
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
                                'created_at_fmt' => $created_at_fmt,
                                'lock_expires' => $b->lock_expires,
                                'cancel_deadline' => $cancel_deadline_fmt,
                                'cancel_deadline_raw' => $cancel_deadline_dt->format('Y-m-d'),
                                'link_riepilogo' => site_url("/" . get_option('paguro_page_slug') . "/?token={$b->lock_token}"),
                                'booking_url' => site_url("/" . get_option('paguro_checkout_slug', 'prenotazione') . "/")
                            ];
                            $ph_safe = [];
                            foreach ($ph as $key => $val) {
                                $ph_safe[$key] = esc_html((string) $val);
                            }
                            ?>
                            <div class="paguro-quote-summary">
                                <!-- SEZIONE INFO -->
                                <div class="paguro-section paguro-section-info">
                                    <h3><?php echo esc_html($ui['section_booking']); ?></h3>
                                    <div class="paguro-info-grid">
                                        <div class="info-item">
                                            <span class="info-label"><?php echo esc_html($ui['label_guest']); ?></span>
                                            <span class="info-value"><?php echo esc_html($b->guest_name); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo esc_html($ui['label_email']); ?></span>
                                            <span class="info-value"><?php echo esc_html($b->guest_email); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo esc_html($ui['label_phone']); ?></span>
                                            <span class="info-value"><?php echo esc_html($b->guest_phone); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo esc_html($ui['label_apartment']); ?></span>
                                            <span class="info-value"><?php echo esc_html(ucfirst($b->apt_name)); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo esc_html($ui['label_checkin']); ?></span>
                                            <span class="info-value"><?php echo esc_html(date('d/m/Y', strtotime($b->date_start))); ?><?php echo esc_html($ui['label_check_suffix']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label"><?php echo esc_html($ui['label_checkout']); ?></span>
                                            <span class="info-value"><?php echo esc_html(date('d/m/Y', strtotime($b->date_end))); ?><?php echo esc_html($ui['label_check_suffix']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEZIONE PREZZO -->
                                <div class="paguro-section paguro-section-pricing">
                                    <h3><?php echo esc_html($ui['section_pricing']); ?></h3>
                                    <div class="paguro-pricing-table">
                                        <div class="pricing-row">
                                            <span class="pricing-label"><?php echo esc_html($ui['label_total_cost']); ?></span>
                                            <span class="pricing-value">€ <?php echo number_format($total_cost, 2, ',', '.'); ?></span>
                                        </div>
                                        <div class="pricing-row pricing-highlight">
                                            <span class="pricing-label"><strong><?php echo esc_html(paguro_parse_template($ui['label_deposit'], $ph)); ?></strong></span>
                                            <span class="pricing-value"><strong>€ <?php echo number_format($deposit, 2, ',', '.'); ?></strong></span>
                                        </div>
                                        <div class="pricing-row">
                                            <span class="pricing-label"><?php echo esc_html($ui['label_remaining']); ?></span>
                                            <span class="pricing-value">€ <?php echo number_format($remaining, 2, ',', '.'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- SEZIONE PAGAMENTO -->
                                <?php if (empty($b->receipt_url)): ?>
                                    <div class="paguro-section paguro-section-payment">
                                        <h3><?php echo esc_html($ui['section_payment']); ?></h3>
                                        <div class="paguro-bank-info">
                                            <div class="bank-item">
                                                <label><?php echo esc_html($ui['label_owner']); ?></label>
                                                <strong><?php echo esc_html($ph['intestatario']); ?></strong>
                                            </div>
                                            <div class="bank-item">
                                                <label><?php echo esc_html($ui['label_iban']); ?></label>
                                                <code><?php echo esc_html($ph['iban']); ?></code>
                                            </div>
                                            <div class="bank-item">
                                                <label><?php echo esc_html($ui['label_amount']); ?></label>
                                                <strong>€ <?php echo number_format($deposit, 2, ',', '.'); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="paguro-custom-content">
                                            <?php
                                            $summary_tpl = get_option('paguro_msg_ui_summary_page', '<div>...</div>');
                                            $summary_confirm_tpl = get_option('paguro_msg_ui_summary_confirm_page', '');
                                            if ($summary_confirm_tpl === '') {
                                                $summary_confirm_tpl = '<p><strong>Prenotazione confermata.</strong></p>' .
                                                    '<p>{apt_name} | {date_start} - {date_end}</p>' .
                                                    '<p>Di seguito i dettagli di pagamento e la distinta (se presente).</p>';
                                            }
                                            $template_to_use = ($is_confirmed && !empty($summary_confirm_tpl)) ? $summary_confirm_tpl : $summary_tpl;
                                            echo wp_kses_post(paguro_parse_template($template_to_use, $ph));
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <!-- SEZIONE UPLOAD -->
                                    <div class="paguro-section paguro-section-upload">
                                        <h3><?php echo esc_html($ui['section_upload']); ?></h3>
                                        <p class="paguro-upload-instruction">
                                            <?php echo esc_html(get_option('paguro_msg_ui_upload_instruction', 'Carica la distinta per bloccare le date.')); ?>
                                        </p>
                                        
                                        <label id="paguro-upload-area" class="paguro-upload-drop-zone" for="paguro-file-input">
                                            <div class="upload-icon">📁</div>
                                            <p class="upload-text"><?php echo esc_html($ui['upload_drop']); ?></p>
                                            <input type="file" id="paguro-file-input" style="display:none;" accept=".pdf,.jpg,.jpeg,.png,.gif">
                                        </label>
                                        
                                        <div id="paguro-upload-status" class="paguro-upload-status"></div>
                                        
                                        <div id="paguro-upload-success-container" class="paguro-alert paguro-alert-success" style="display:none; margin-top:20px;">
                                            <h4><?php echo esc_html($ui['upload_success_title']); ?></h4>
                                            <p><?php echo esc_html($ui['upload_success_text']); ?></p>
                                            <div id="paguro-view-link"></div>
                                        </div>
                                        
                                        <input type="hidden" id="paguro-token" value="<?php echo esc_attr($token); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="paguro-section paguro-section-uploaded">
                                        <div class="paguro-alert paguro-alert-info" style="margin-bottom:20px;">
                                            <?php echo esc_html($ui['receipt_pending']); ?>
                                        </div>
                                        <h3><?php echo esc_html($ui['section_uploaded']); ?></h3>
                                        <div class="paguro-alert paguro-alert-success">
                                            <?php echo nl2br(esc_html($ui['receipt_uploaded_text'])); ?>
                                        </div>
                                        <a href="#" data-receipt-url="<?php echo esc_url($b->receipt_url); ?>" class="paguro-btn paguro-btn-secondary paguro-receipt-link">
                                            <?php echo esc_html($ui['receipt_view_cta']); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <?php if ($is_confirmed && $can_cancel && !$is_cancel_requested): ?>
                                    <div class="paguro-section paguro-section-actions">
                                        <h3><?php echo esc_html($ui['section_actions']); ?></h3>
                                        <form id="paguro-cancel-booking-form" method="POST">
                                            <?php wp_nonce_field('paguro_cancel_action', 'paguro_cancel_nonce'); ?>
                                            <input type="hidden" name="paguro_action" value="cancel_user_booking">
                                            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                            <div class="form-group" style="margin-bottom:10px;">
                                                <label for="paguro-customer-iban"><?php echo esc_html($ui['cancel_label']); ?></label>
                                                <input type="text" id="paguro-customer-iban" name="customer_iban" required
                                                       placeholder="<?php echo esc_attr($ui['cancel_placeholder']); ?>"
                                                       value="<?php echo esc_attr($b->customer_iban ?? ''); ?>">
                                                <div class="paguro-cancel-help">
                                                    <?php echo wp_kses_post(paguro_parse_template($ui['cancel_help'], $ph_safe)); ?>
                                                </div>
                                            </div>
                                            <button class="paguro-btn paguro-btn-danger" type="submit"><?php echo esc_html($ui['cancel_cta']); ?></button>
                                        </form>
                                        <p style="font-size:12px; color:#666; margin-top:8px;"><?php echo esc_html(paguro_parse_template($ui['cancel_deadline_note'], $ph)); ?></p>
                                    </div>
                                <?php elseif ($is_confirmed && !$can_cancel): ?>
                                    <div class="paguro-section paguro-section-actions">
                                        <div class="paguro-alert paguro-alert-info">
                                            <?php echo wp_kses_post(paguro_parse_template($ui['cancel_unavailable'], $ph_safe)); ?>
                                        </div>
                                    </div>
                                <?php elseif ($is_cancel_requested): ?>
                                    <div class="paguro-section paguro-section-actions">
                                        <div class="paguro-alert paguro-alert-warning">
                                            <?php echo esc_html($ui['cancel_requested_notice']); ?>
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
