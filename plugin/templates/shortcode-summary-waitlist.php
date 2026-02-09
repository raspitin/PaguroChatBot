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
    $ui = [
        'waitlist_title' => get_option('paguro_msg_ui_waitlist_title', "Riepilogo lista d'attesa"),
        'missing_token' => get_option('paguro_msg_ui_missing_token', 'Codice di accesso mancante.'),
        'auth_title' => get_option('paguro_msg_ui_auth_title', 'Area riservata'),
        'auth_help_waitlist' => get_option('paguro_msg_ui_auth_help_waitlist', "Inserisci l'email usata per la lista d'attesa."),
        'auth_email_label' => get_option('paguro_msg_ui_auth_email_label', 'Email *'),
        'auth_submit' => get_option('paguro_msg_ui_auth_submit', 'Accedi'),
        'auth_error' => get_option('paguro_msg_ui_auth_error', 'Email non trovata.'),
        'waitlist_cancelled' => get_option('paguro_msg_ui_waitlist_cancelled', "Iscrizione annullata."),
        'waitlist_not_found' => get_option('paguro_msg_ui_waitlist_not_found', "Iscrizione non trovata."),
        'waitlist_title_text' => get_option('paguro_msg_ui_waitlist_title_text', "Sei in lista d'attesa"),
        'waitlist_page_notice' => get_option('paguro_msg_ui_waitlist_page_notice', 'Ti avviseremo appena si libera.'),
        'waitlist_section_info' => get_option('paguro_msg_ui_waitlist_section_info', 'Dettagli iscrizione'),
        'waitlist_section_dates' => get_option('paguro_msg_ui_waitlist_section_dates', 'Date richieste'),
        'waitlist_section_history' => get_option('paguro_msg_ui_waitlist_section_history', 'Cronologia'),
        'section_actions' => get_option('paguro_msg_ui_section_actions', 'Azioni'),
        'label_name' => get_option('paguro_msg_ui_label_name', 'Nome'),
        'label_email' => get_option('paguro_msg_ui_label_email', 'Email'),
        'label_phone' => get_option('paguro_msg_ui_label_phone', 'Telefono'),
        'label_apartment' => get_option('paguro_msg_ui_label_apartment', 'Appartamento'),
        'label_checkin' => get_option('paguro_msg_ui_label_checkin', 'Check-in'),
        'label_checkout' => get_option('paguro_msg_ui_label_checkout', 'Check-out'),
        'waitlist_exit_cta' => get_option('paguro_msg_ui_waitlist_exit_cta', 'Esci dalla lista')
    ];
    
    ob_start(); ?>
    <div class="paguro-summary-wrapper">
        <div class="paguro-summary-container">
            <div class="paguro-summary-header">
                <h1><?php echo esc_html($ui['waitlist_title']); ?></h1>
            </div>
            
            <div class="paguro-summary-body">
                <?php if (!$token): ?>
                    <div class="paguro-alert paguro-alert-danger">
                        <?php echo esc_html($ui['missing_token']); ?>
                    </div>
                <?php else:
                    $expected_cookie = wp_hash($token . 'paguro_auth');
                    $auth_ok = (isset($_COOKIE['paguro_auth_' . $token]) && $_COOKIE['paguro_auth_' . $token] === $expected_cookie);
                    
                    if (!$auth_ok):
                        // MOSTRA FORM LOGIN
                        ?>
                        <div class="paguro-auth-section">
                            <div class="paguro-auth-card">
                                <h2><?php echo esc_html($ui['auth_title']); ?></h2>
                                <p><?php echo esc_html($ui['auth_help_waitlist']); ?></p>
                                
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
                                <?php echo esc_html($ui['waitlist_cancelled']); ?>
                            </div>
                            <?php
                        elseif (!$b):
                            ?>
                            <div class="paguro-alert paguro-alert-danger">
                                <?php echo esc_html($ui['waitlist_not_found']); ?>
                            </div>
                            <?php
                        else:
                            $history = paguro_get_history($b->id);
                            $waitlist_ph = function_exists('paguro_get_email_placeholders') ? paguro_get_email_placeholders($b) : [];
                            $waitlist_page_notice = paguro_parse_template($ui['waitlist_page_notice'], $waitlist_ph);
                            ?>
                            <div class="paguro-waitlist-summary">
                                <!-- HEADER ALERT -->
                                <div class="paguro-alert paguro-alert-info">
                                    <h3>✅ <?php echo esc_html($ui['waitlist_title_text']); ?></h3>
                                    <p><?php echo esc_html($waitlist_page_notice); ?></p>
                                </div>
                                    
                                    <!-- SEZIONE INFO -->
                                    <div class="paguro-section paguro-section-info">
                                        <h3><?php echo esc_html($ui['waitlist_section_info']); ?></h3>
                                        <div class="paguro-info-grid">
                                            <div class="info-item">
                                                <span class="info-label"><?php echo esc_html($ui['label_name']); ?></span>
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
                                        </div>
                                    </div>
                                    
                                    <!-- SEZIONE DATE RICHIESTE -->
                                    <div class="paguro-section paguro-section-dates">
                                        <h3><?php echo esc_html($ui['waitlist_section_dates']); ?></h3>
                                        <div class="paguro-dates-display">
                                            <div class="date-item">
                                                <label><?php echo esc_html($ui['label_apartment']); ?></label>
                                                <strong><?php echo esc_html(ucfirst($b->apt_name)); ?></strong>
                                            </div>
                                            <div class="date-item">
                                                <label><?php echo esc_html($ui['label_checkin']); ?></label>
                                                <strong><?php echo esc_html(date('d/m/Y (l)', strtotime($b->date_start))); ?></strong>
                                            </div>
                                            <div class="date-item">
                                                <label><?php echo esc_html($ui['label_checkout']); ?></label>
                                                <strong><?php echo esc_html(date('d/m/Y (l)', strtotime($b->date_end))); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- SEZIONE STORIA -->
                                    <?php if (!empty($history)): ?>
                                    <div class="paguro-section paguro-section-history">
                                        <h3><?php echo esc_html($ui['waitlist_section_history']); ?></h3>
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
                                        <h3><?php echo esc_html($ui['section_actions']); ?></h3>
                                        <div class="paguro-action-buttons">
                                            <form id="paguro-cancel-waitlist-form" method="POST">
                                            <?php wp_nonce_field('paguro_cancel_action', 'paguro_cancel_nonce'); ?>
                                            <input type="hidden" name="paguro_action" value="cancel_user_booking">
                                            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                                            <button id="paguro-cancel-waitlist-btn" class="paguro-btn paguro-btn-danger" type="submit">
                                                <?php echo esc_html($ui['waitlist_exit_cta']); ?>
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
