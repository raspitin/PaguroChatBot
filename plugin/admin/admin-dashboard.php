<?php
/**
 * Paguro ChatBot - Admin Dashboard
 * Gestione prenotazioni, configurazione, email templates
 * 
 * @version 3.3.0
 */

if (!defined('ABSPATH')) exit;

global $wpdb;

// Verifica permessi
if (!current_user_can('manage_options')) {
    wp_die(__('Non autorizzato.', 'paguro'));
}

// Variabili globali
$base_url = admin_url('admin.php?page=paguro-booking');
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt = $wpdb->prefix . 'paguro_apartments';
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'bookings';

// ====== SALVATAGGIO OPZIONI ======
if (isset($_POST['paguro_save_opts']) && check_admin_referer('paguro_admin_opts')) {
    update_option('paguro_season_start', sanitize_text_field(wp_unslash($_POST['season_start'] ?? '')));
    update_option('paguro_season_end', sanitize_text_field(wp_unslash($_POST['season_end'] ?? '')));
    update_option('paguro_deposit_percent', intval(wp_unslash($_POST['deposit_percent'] ?? 0)));
    update_option('paguro_group_discount_map', sanitize_text_field(wp_unslash($_POST['group_discount_map'] ?? '')));
    update_option('paguro_bank_iban', sanitize_text_field(wp_unslash($_POST['bank_iban'] ?? '')));
    update_option('paguro_bank_owner', sanitize_text_field(wp_unslash($_POST['bank_owner'] ?? '')));
    update_option('paguro_api_url', esc_url_raw(wp_unslash($_POST['paguro_api_url'] ?? '')));
    update_option('paguro_recaptcha_site', sanitize_text_field(wp_unslash($_POST['recaptcha_site'] ?? '')));
    update_option('paguro_recaptcha_secret', sanitize_text_field(wp_unslash($_POST['recaptcha_secret'] ?? '')));
    update_option('paguro_ai_provider', sanitize_text_field(wp_unslash($_POST['ai_provider'] ?? 'api')));
    update_option('paguro_ollama_url', esc_url_raw(wp_unslash($_POST['ollama_url'] ?? '')));
    update_option('paguro_ollama_model', sanitize_text_field(wp_unslash($_POST['ollama_model'] ?? '')));

    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Configurazione Salvata.</p></div>';
}

// ====== GESTIONE EMAIL TEMPLATES ======
if (isset($_POST['paguro_save_emails']) && check_admin_referer('paguro_email_opts')) {
    update_option('paguro_txt_email_request_subj', sanitize_text_field(wp_unslash($_POST['email_req_subj'] ?? '')));
    update_option('paguro_txt_email_request_body', wp_kses_post(wp_unslash($_POST['email_req_body'] ?? '')));
    update_option('paguro_txt_email_receipt_subj', sanitize_text_field(wp_unslash($_POST['email_rec_subj'] ?? '')));
    update_option('paguro_txt_email_receipt_body', wp_kses_post(wp_unslash($_POST['email_rec_body'] ?? '')));
    update_option('paguro_txt_email_confirm_subj', sanitize_text_field(wp_unslash($_POST['email_conf_subj'] ?? '')));
    update_option('paguro_txt_email_confirm_body', wp_kses_post(wp_unslash($_POST['email_conf_body'] ?? '')));
    update_option('paguro_txt_email_cancel_req_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_req_subj'] ?? '')));
    update_option('paguro_txt_email_cancel_req_body', wp_kses_post(wp_unslash($_POST['email_cancel_req_body'] ?? '')));
    update_option('paguro_txt_email_cancel_req_adm_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_req_adm_subj'] ?? '')));
    update_option('paguro_txt_email_cancel_req_adm_body', wp_kses_post(wp_unslash($_POST['email_cancel_req_adm_body'] ?? '')));
    update_option('paguro_msg_email_cancel_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_subj'] ?? '')));
    update_option('paguro_msg_email_cancel_body', wp_kses_post(wp_unslash($_POST['email_cancel_body'] ?? '')));
    update_option('paguro_msg_email_adm_cancel_subj', sanitize_text_field(wp_unslash($_POST['email_cancel_adm_subj'] ?? '')));
    update_option('paguro_msg_email_adm_cancel_body', wp_kses_post(wp_unslash($_POST['email_cancel_adm_body'] ?? '')));
    update_option('paguro_msg_email_refund_subj', sanitize_text_field(wp_unslash($_POST['email_refund_subj'] ?? '')));
    update_option('paguro_msg_email_refund_body', wp_kses_post(wp_unslash($_POST['email_refund_body'] ?? '')));
    update_option('paguro_txt_email_waitlist_subj', sanitize_text_field(wp_unslash($_POST['email_waitlist_subj'] ?? '')));
    update_option('paguro_txt_email_waitlist_body', wp_kses_post(wp_unslash($_POST['email_waitlist_body'] ?? '')));
    update_option('paguro_txt_email_waitlist_adm_subj', sanitize_text_field(wp_unslash($_POST['email_waitlist_adm_subj'] ?? '')));
    update_option('paguro_txt_email_waitlist_adm_body', wp_kses_post(wp_unslash($_POST['email_waitlist_adm_body'] ?? '')));
    update_option('paguro_txt_email_waitlist_alert_subj', sanitize_text_field(wp_unslash($_POST['email_waitlist_alert_subj'] ?? '')));
    update_option('paguro_txt_email_waitlist_alert_body', wp_kses_post(wp_unslash($_POST['email_waitlist_alert_body'] ?? '')));

    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Email Templates Salvati.</p></div>';
}

// ====== GESTIONE WEB TEMPLATES ======
if (isset($_POST['paguro_save_web_templates']) && check_admin_referer('paguro_web_templates')) {
    update_option('paguro_msg_ui_social_pressure', wp_kses_post(wp_unslash($_POST['ui_social_pressure'] ?? '')));
    update_option('paguro_msg_ui_privacy_notice', wp_kses_post(wp_unslash($_POST['ui_privacy_notice'] ?? '')));
    update_option('paguro_msg_ui_upload_instruction', sanitize_text_field(wp_unslash($_POST['ui_upload_instruction'] ?? '')));
    update_option('paguro_msg_ui_summary_page', wp_kses_post(wp_unslash($_POST['ui_summary_page'] ?? '')));
    update_option('paguro_msg_ui_summary_confirm_page', wp_kses_post(wp_unslash($_POST['ui_summary_confirm_page'] ?? '')));
    update_option('paguro_msg_ui_login_page', wp_kses_post(wp_unslash($_POST['ui_login_page'] ?? '')));
    update_option('paguro_js_btn_book', sanitize_text_field(wp_unslash($_POST['ui_btn_book'] ?? '')));
    update_option('paguro_msg_ui_summary_title', sanitize_text_field(wp_unslash($_POST['ui_summary_title'] ?? '')));
    update_option('paguro_msg_ui_waitlist_title', sanitize_text_field(wp_unslash($_POST['ui_waitlist_title'] ?? '')));
    update_option('paguro_msg_ui_quote_title', sanitize_text_field(wp_unslash($_POST['ui_quote_title'] ?? '')));
    update_option('paguro_msg_ui_quote_subtitle', sanitize_text_field(wp_unslash($_POST['ui_quote_subtitle'] ?? '')));
    update_option('paguro_msg_ui_quote_name_help', sanitize_text_field(wp_unslash($_POST['ui_quote_name_help'] ?? '')));
    update_option('paguro_msg_ui_missing_token', sanitize_text_field(wp_unslash($_POST['ui_missing_token'] ?? '')));
    update_option('paguro_msg_ui_auth_title', sanitize_text_field(wp_unslash($_POST['ui_auth_title'] ?? '')));
    update_option('paguro_msg_ui_auth_help_quote', sanitize_textarea_field(wp_unslash($_POST['ui_auth_help_quote'] ?? '')));
    update_option('paguro_msg_ui_auth_help_waitlist', sanitize_textarea_field(wp_unslash($_POST['ui_auth_help_waitlist'] ?? '')));
    update_option('paguro_msg_ui_auth_email_label', sanitize_text_field(wp_unslash($_POST['ui_auth_email_label'] ?? '')));
    update_option('paguro_msg_ui_auth_submit', sanitize_text_field(wp_unslash($_POST['ui_auth_submit'] ?? '')));
    update_option('paguro_msg_ui_auth_error', sanitize_text_field(wp_unslash($_POST['ui_auth_error'] ?? '')));
    update_option('paguro_msg_ui_booking_not_found', sanitize_text_field(wp_unslash($_POST['ui_booking_not_found'] ?? '')));
    update_option('paguro_msg_ui_waitlist_not_found', sanitize_text_field(wp_unslash($_POST['ui_waitlist_not_found'] ?? '')));
    update_option('paguro_msg_ui_cancel_requested', sanitize_text_field(wp_unslash($_POST['ui_cancel_requested'] ?? '')));
    update_option('paguro_msg_ui_cancel_pending', sanitize_text_field(wp_unslash($_POST['ui_cancel_pending'] ?? '')));
    update_option('paguro_msg_ui_cancel_iban_required', sanitize_text_field(wp_unslash($_POST['ui_cancel_iban_required'] ?? '')));
    update_option('paguro_msg_ui_cancel_iban_invalid', sanitize_text_field(wp_unslash($_POST['ui_cancel_iban_invalid'] ?? '')));
    update_option('paguro_msg_ui_cancel_denied', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_denied'] ?? '')));
    update_option('paguro_msg_ui_waitlist_cancelled', sanitize_text_field(wp_unslash($_POST['ui_waitlist_cancelled'] ?? '')));
    update_option('paguro_msg_ui_waitlist_title_text', sanitize_text_field(wp_unslash($_POST['ui_waitlist_title_text'] ?? '')));
    update_option('paguro_msg_ui_waitlist_inline_notice', sanitize_textarea_field(wp_unslash($_POST['ui_waitlist_inline_notice'] ?? '')));
    update_option('paguro_msg_ui_waitlist_page_notice', sanitize_textarea_field(wp_unslash($_POST['ui_waitlist_page_notice'] ?? '')));
    update_option('paguro_msg_ui_waitlist_inline_dates_label', sanitize_text_field(wp_unslash($_POST['ui_waitlist_inline_dates_label'] ?? '')));
    update_option('paguro_msg_ui_section_booking', sanitize_text_field(wp_unslash($_POST['ui_section_booking'] ?? '')));
    update_option('paguro_msg_ui_section_pricing', sanitize_text_field(wp_unslash($_POST['ui_section_pricing'] ?? '')));
    update_option('paguro_msg_ui_section_payment', sanitize_text_field(wp_unslash($_POST['ui_section_payment'] ?? '')));
    update_option('paguro_msg_ui_section_upload', sanitize_text_field(wp_unslash($_POST['ui_section_upload'] ?? '')));
    update_option('paguro_msg_ui_section_uploaded', sanitize_text_field(wp_unslash($_POST['ui_section_uploaded'] ?? '')));
    update_option('paguro_msg_ui_section_actions', sanitize_text_field(wp_unslash($_POST['ui_section_actions'] ?? '')));
    update_option('paguro_msg_ui_label_guest', sanitize_text_field(wp_unslash($_POST['ui_label_guest'] ?? '')));
    update_option('paguro_msg_ui_label_name', sanitize_text_field(wp_unslash($_POST['ui_label_name'] ?? '')));
    update_option('paguro_msg_ui_label_email', sanitize_text_field(wp_unslash($_POST['ui_label_email'] ?? '')));
    update_option('paguro_msg_ui_label_phone', sanitize_text_field(wp_unslash($_POST['ui_label_phone'] ?? '')));
    update_option('paguro_msg_ui_label_apartment', sanitize_text_field(wp_unslash($_POST['ui_label_apartment'] ?? '')));
    update_option('paguro_msg_ui_label_checkin', sanitize_text_field(wp_unslash($_POST['ui_label_checkin'] ?? '')));
    update_option('paguro_msg_ui_label_checkout', sanitize_text_field(wp_unslash($_POST['ui_label_checkout'] ?? '')));
    update_option('paguro_msg_ui_label_check_suffix', sanitize_text_field(wp_unslash($_POST['ui_label_check_suffix'] ?? '')));
    update_option('paguro_msg_ui_label_total_cost', sanitize_text_field(wp_unslash($_POST['ui_label_total_cost'] ?? '')));
    update_option('paguro_msg_ui_label_deposit', sanitize_text_field(wp_unslash($_POST['ui_label_deposit'] ?? '')));
    update_option('paguro_msg_ui_label_remaining', sanitize_text_field(wp_unslash($_POST['ui_label_remaining'] ?? '')));
    update_option('paguro_msg_ui_label_owner', sanitize_text_field(wp_unslash($_POST['ui_label_owner'] ?? '')));
    update_option('paguro_msg_ui_label_iban', sanitize_text_field(wp_unslash($_POST['ui_label_iban'] ?? '')));
    update_option('paguro_msg_ui_label_amount', sanitize_text_field(wp_unslash($_POST['ui_label_amount'] ?? '')));
    update_option('paguro_msg_ui_upload_drop', sanitize_text_field(wp_unslash($_POST['ui_upload_drop'] ?? '')));
    update_option('paguro_msg_ui_upload_success_title', sanitize_text_field(wp_unslash($_POST['ui_upload_success_title'] ?? '')));
    update_option('paguro_msg_ui_upload_success_text', sanitize_textarea_field(wp_unslash($_POST['ui_upload_success_text'] ?? '')));
    update_option('paguro_msg_ui_receipt_pending', sanitize_text_field(wp_unslash($_POST['ui_receipt_pending'] ?? '')));
    update_option('paguro_msg_ui_receipt_uploaded_text', sanitize_textarea_field(wp_unslash($_POST['ui_receipt_uploaded_text'] ?? '')));
    update_option('paguro_msg_ui_receipt_view_cta', sanitize_text_field(wp_unslash($_POST['ui_receipt_view_cta'] ?? '')));
    update_option('paguro_msg_ui_cancel_label', sanitize_text_field(wp_unslash($_POST['ui_cancel_label'] ?? '')));
    update_option('paguro_msg_ui_cancel_placeholder', sanitize_text_field(wp_unslash($_POST['ui_cancel_placeholder'] ?? '')));
    update_option('paguro_msg_ui_cancel_help', wp_kses_post(wp_unslash($_POST['ui_cancel_help'] ?? '')));
    update_option('paguro_msg_ui_cancel_confirm', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_confirm'] ?? '')));
    update_option('paguro_msg_ui_cancel_cta', sanitize_text_field(wp_unslash($_POST['ui_cancel_cta'] ?? '')));
    update_option('paguro_msg_ui_group_week_confirm', sanitize_textarea_field(wp_unslash($_POST['ui_group_week_confirm'] ?? '')));
    update_option('paguro_msg_ui_cancel_deadline_note', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_deadline_note'] ?? '')));
    update_option('paguro_msg_ui_cancel_unavailable', wp_kses_post(wp_unslash($_POST['ui_cancel_unavailable'] ?? '')));
    update_option('paguro_msg_ui_cancel_requested_notice', sanitize_textarea_field(wp_unslash($_POST['ui_cancel_requested_notice'] ?? '')));
    update_option('paguro_msg_ui_waitlist_section_info', sanitize_text_field(wp_unslash($_POST['ui_waitlist_section_info'] ?? '')));
    update_option('paguro_msg_ui_waitlist_section_dates', sanitize_text_field(wp_unslash($_POST['ui_waitlist_section_dates'] ?? '')));
    update_option('paguro_msg_ui_waitlist_section_history', sanitize_text_field(wp_unslash($_POST['ui_waitlist_section_history'] ?? '')));
    update_option('paguro_msg_ui_waitlist_exit_cta', sanitize_text_field(wp_unslash($_POST['ui_waitlist_exit_cta'] ?? '')));
    update_option('paguro_msg_ui_waitlist_exit_confirm', sanitize_textarea_field(wp_unslash($_POST['ui_waitlist_exit_confirm'] ?? '')));

    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Web Templates Salvati.</p></div>';
}

// ====== GESTIONE IA RULES ======
if (isset($_POST['paguro_save_ai_rules']) && check_admin_referer('paguro_ai_rules')) {
    $keywords = isset($_POST['ai_rule_keywords']) && is_array($_POST['ai_rule_keywords']) ? $_POST['ai_rule_keywords'] : [];
    $responses = isset($_POST['ai_rule_response']) && is_array($_POST['ai_rule_response']) ? $_POST['ai_rule_response'] : [];
    $rules = [];
    $count = max(count($keywords), count($responses));
    for ($i = 0; $i < $count; $i++) {
        $kw = sanitize_text_field(wp_unslash($keywords[$i] ?? ''));
        $resp = wp_kses_post(wp_unslash($responses[$i] ?? ''));
        if ($kw === '' || $resp === '') continue;
        $rules[] = [
            'keywords' => $kw,
            'response' => $resp
        ];
    }
    update_option('paguro_ai_rules', $rules);
    echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Regole IA Salvate.</p></div>';
}

// ====== GESTIONE APPARTAMENTI ======
if (isset($_POST['paguro_apt_action']) && check_admin_referer('paguro_apt_nonce')) {
    if ($_POST['paguro_apt_action'] === 'add_apt') {
        $name = sanitize_text_field(wp_unslash($_POST['apt_name'] ?? ''));
        $price = floatval(wp_unslash($_POST['apt_price'] ?? 0));
        if ($name && $price > 0) {
            $wpdb->insert($table_apt, [
                'name' => $name,
                'base_price' => $price,
                'pricing_json' => json_encode([])
            ]);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Appartamento aggiunto.</p></div>';
        }
    }
    if ($_POST['paguro_apt_action'] === 'update_apt') {
        $id = intval($_POST['apt_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['apt_name'] ?? ''));
        $price = floatval(wp_unslash($_POST['apt_price'] ?? 0));
        $pricing_week = isset($_POST['pricing_week']) && is_array($_POST['pricing_week']) ? $_POST['pricing_week'] : [];
        $pricing = [];
        foreach ($pricing_week as $k => $v) {
            $key = sanitize_text_field(wp_unslash($k));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) continue;
            $val = floatval(wp_unslash($v));
            if ($val > 0) {
                $pricing[$key] = $val;
            }
        }
        if ($id > 0 && $name && $price > 0) {
            $wpdb->update($table_apt, [
                'name' => $name,
                'base_price' => $price,
                'pricing_json' => json_encode($pricing)
            ], ['id' => $id]);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Appartamento aggiornato.</p></div>';
        }
    }
    if ($_POST['paguro_apt_action'] === 'delete_apt') {
        $id = intval($_POST['apt_id']);
        $wpdb->delete($table_apt, ['id' => $id]);
        echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Appartamento eliminato.</p></div>';
    }
}

// ====== AZIONI SULLE PRENOTAZIONI ======
if (isset($_POST['paguro_action']) && check_admin_referer('paguro_admin_action')) {
    $req_id = intval($_POST['booking_id']);
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id=%d", $req_id));

    if (!$booking) {
        echo '<div class="notice notice-error"><p>Prenotazione non trovata.</p></div>';
    } else {
        $apt = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_apt WHERE id=%d", $booking->apartment_id));

        if ($_POST['paguro_action'] === 'confirm_booking') {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);
            
            // Elimina preventivi in conflitto (non confermati)
            $losers = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status=2 AND (date_start<%s AND date_end>%s)",
                $booking->apartment_id, $req_id, $booking->date_end, $booking->date_start
            ));
            foreach ($losers as $l) {
                $wpdb->delete($table_avail, ['id' => $l->id]);
            }

            paguro_send_booking_confirmed_to_user($req_id);

            paguro_add_history($req_id, 'ADMIN_CONFIRM', 'Confermata da amministratore');
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione Confermata.</p></div>';
        }

        if ($_POST['paguro_action'] === 'validate_receipt') {
            if (!empty($booking->receipt_url) && intval($booking->status) === 2) {
                $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);

                // Elimina preventivi in conflitto (non confermati)
                $losers = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status=2 AND (date_start<%s AND date_end>%s)",
                    $booking->apartment_id, $req_id, $booking->date_end, $booking->date_start
                ));
                foreach ($losers as $l) {
                    $wpdb->delete($table_avail, ['id' => $l->id]);
                }

                paguro_send_booking_confirmed_to_user($req_id);
                paguro_add_history($req_id, 'ADMIN_VALIDATE_RECEIPT', 'Distinta validata da amministratore');
                echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Distinta validata. Prenotazione confermata.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Distinta non valida o stato non corretto.</p></div>';
            }
        }

        if ($_POST['paguro_action'] === 'confirm_cancel') {
            $wpdb->update($table_avail, ['status' => 3], ['id' => $req_id]);
            paguro_add_history($req_id, 'ADMIN_CANCEL_CONFIRM', 'Cancellazione confermata da amministratore');
            paguro_trigger_waitlist_alerts($booking->apartment_id, $booking->date_start, $booking->date_end);
            $refund_sent = paguro_send_refund_sent_to_user($req_id);
            if ($refund_sent) {
                paguro_add_history($req_id, 'ADMIN_REFUND_SENT', 'Email bonifico disposto inviata');
            } else {
                paguro_add_history($req_id, 'ADMIN_REFUND_SEND_FAIL', 'Invio email bonifico disposto fallito');
            }
            paguro_send_cancellation_to_admin($req_id);
            if (!empty($booking->group_id) && function_exists('paguro_maybe_update_group_quote_after_cancel')) {
                paguro_maybe_update_group_quote_after_cancel($booking->group_id);
            }
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Cancellazione confermata.</p></div>';
        }

        if ($_POST['paguro_action'] === 'delete_booking') {
            $wpdb->delete($table_avail, ['id' => $req_id]);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione Eliminata.</p></div>';
        }

        if ($_POST['paguro_action'] === 'resend_email') {
            $sent = false;
            $label = 'email';
            $status = intval($booking->status);
            $has_receipt = !empty($booking->receipt_url);

            $history = paguro_get_history($req_id);
            $is_confirmed = false;
            foreach ($history as $entry) {
                if (!empty($entry['action']) && $entry['action'] === 'ADMIN_CONFIRM') {
                    $is_confirmed = true;
                    break;
                }
            }

            if ($status === 3) {
                $sent = paguro_send_refund_sent_to_user($req_id);
                $label = 'bonifico disposto';
                if ($sent) {
                    paguro_add_history($req_id, 'ADMIN_REFUND_SENT', 'Email bonifico disposto inviata (reinvia)');
                } else {
                    paguro_add_history($req_id, 'ADMIN_REFUND_SEND_FAIL', 'Invio email bonifico disposto fallito (reinvia)');
                }
            } elseif ($status === 5) {
                $sent = paguro_send_cancel_request_to_user($req_id);
                $label = 'richiesta cancellazione';
            } elseif ($status === 1) {
                $sent = paguro_send_booking_confirmed_to_user($req_id);
                $label = 'prenotazione confermata';
            } elseif ($status === 4) {
                $sent = paguro_send_waitlist_confirmation_to_user($req_id);
                $label = 'lista d\'attesa';
            } elseif ($status === 2) {
                if ($has_receipt && !$is_confirmed) {
                    $sent = paguro_send_receipt_received_to_user($req_id);
                    $label = 'distinta ricevuta';
                } else {
                    $sent = paguro_send_quote_request_to_user($req_id);
                    $label = 'preventivo';
                }
            }

            if ($sent) {
                paguro_add_history($req_id, 'ADMIN_RESEND_EMAIL', 'Email reinviata da amministratore (' . $label . ')');
                echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Email Reinviata (' . esc_html($label) . ').</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Impossibile reinviare la mail per questo stato.</p></div>';
            }
        }

        if ($_POST['paguro_action'] === 'anonymize') {
            // GDPR Compliance: anonymize personal data, keep booking record
            $anonymized_notes = 'ANONIMIZZATO';
            $wpdb->update(
                $table_avail,
                [
                    'guest_name' => 'Anonimizzato',
                    'guest_email' => '',
                    'guest_phone' => '',
                    'guest_notes' => '',
                    'customer_iban' => '',
                    'lock_token' => '',
                    'receipt_url' => '',
                    'receipt_uploaded_at' => null
                ],
                ['id' => $req_id]
            );
            paguro_add_history($req_id, 'ADMIN_ANONYMIZE', $anonymized_notes);
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione anonimizzata (GDPR).</p></div>';
        }
    }
}

// ====== NOTIFICHE ADMIN ======
$pending_receipts = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE status = 2 AND receipt_url IS NOT NULL"
);
if ($pending_receipts > 0) {
    $link = admin_url('admin.php?page=paguro-booking&tab=bookings&filter=receipts');
    echo '<div class="notice notice-warning"><p><strong>📄 Distinte in attesa di validazione:</strong> ' .
        intval($pending_receipts) .
        ' — <a href="' . esc_url($link) . '">Apri prenotazioni</a></p></div>';
}

$pending_cancel_requests = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}paguro_availability WHERE status = 5"
);
if ($pending_cancel_requests > 0) {
    $link = admin_url('admin.php?page=paguro-booking&tab=bookings&filter=cancel_requests');
    echo '<div class="notice notice-warning"><p><strong>⚠️ Richieste di cancellazione in attesa:</strong> ' .
        intval($pending_cancel_requests) .
        ' — <a href="' . esc_url($link) . '">Apri prenotazioni</a></p></div>';
}

// ====== INSERIMENTO MANUALE PRENOTAZIONE ======
if (isset($_POST['paguro_manual_booking']) && check_admin_referer('paguro_manual_nonce', 'paguro_manual_nonce')) {
    $apt_id = intval(wp_unslash($_POST['apt_id'] ?? 0));
    $start = sanitize_text_field(wp_unslash($_POST['date_start'] ?? ''));
    $end = sanitize_text_field(wp_unslash($_POST['date_end'] ?? ''));
    $guest_name = sanitize_text_field(wp_unslash($_POST['guest_name'] ?? ''));
    $guest_email = sanitize_email(wp_unslash($_POST['guest_email'] ?? ''));
    $guest_phone = sanitize_text_field(wp_unslash($_POST['guest_phone'] ?? ''));
    $guest_notes = sanitize_textarea_field(wp_unslash($_POST['guest_notes'] ?? ''));

    if ($start >= $end) {
        echo '<div class="notice notice-error"><p>Errore: La data di arrivo deve essere precedente alla partenza.</p></div>';
    } else {
        // Controlla sovrapposizioni con prenotazioni confermate
        $overlap = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_avail WHERE apartment_id=%d AND status=1 AND (date_start < %s AND date_end > %s)",
            $apt_id, $end, $start
        ));

        if ($overlap > 0) {
            echo '<div class="notice notice-error"><p>Errore: Le date si sovrappongono a una prenotazione già confermata.</p></div>';
        } else {
            $token = bin2hex(random_bytes(16));
            $hist = json_encode([['time' => current_time('mysql'), 'action' => 'ADMIN_MANUAL_INSERT', 'details' => 'Inserimento manuale da pannello']]);

            $wpdb->insert($table_avail, [
                'apartment_id' => $apt_id,
                'date_start' => $start,
                'date_end' => $end,
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
                'guest_phone' => $guest_phone,
                'guest_notes' => $guest_notes,
                'status' => 1, // Confermato direttamente
                'lock_token' => $token,
                'history_log' => $hist,
                'created_at' => current_time('mysql')
            ]);

            echo '<div class="notice notice-success is-dismissible"><p><strong>✓</strong> Prenotazione Manuale Inserita (Confermata).</p></div>';
        }
    }
}

?>

<div class="wrap paguro-admin">
    <h1>📅 Paguro Booking - Amministrazione</h1>

    <script>
        (function() {
            function ensureOverlay() {
                var overlay = document.getElementById('paguro-admin-overlay');
                if (overlay) return overlay;
                overlay = document.createElement('div');
                overlay.id = 'paguro-admin-overlay';
                overlay.className = 'paguro-admin-overlay';
                overlay.innerHTML = '' +
                    '<div class=\"paguro-admin-overlay-backdrop\" aria-hidden=\"true\"></div>' +
                    '<div class=\"paguro-admin-overlay-card\" role=\"dialog\" aria-modal=\"true\">' +
                    '  <button type=\"button\" class=\"paguro-admin-overlay-close\" aria-label=\"Chiudi\">×</button>' +
                    '  <div class=\"paguro-admin-overlay-content\"></div>' +
                    '  <div class=\"paguro-admin-overlay-actions\"></div>' +
                    '</div>';
                document.body.appendChild(overlay);

                overlay.addEventListener('click', function(evt) {
                    if (evt.target.classList.contains('paguro-admin-overlay-backdrop') ||
                        evt.target.classList.contains('paguro-admin-overlay-close')) {
                        closeOverlay();
                    }
                });
                document.addEventListener('keydown', function(evt) {
                    if (evt.key === 'Escape' && overlay.classList.contains('is-open')) {
                        closeOverlay();
                    }
                });
                return overlay;
            }

            function openOverlay(contentHtml, actionsHtml) {
                var overlay = ensureOverlay();
                overlay.querySelector('.paguro-admin-overlay-content').innerHTML = contentHtml || '';
                overlay.querySelector('.paguro-admin-overlay-actions').innerHTML = actionsHtml || '';
                overlay.classList.add('is-open');
            }

            function closeOverlay() {
                var overlay = document.getElementById('paguro-admin-overlay');
                if (!overlay) return;
                overlay.classList.remove('is-open');
                overlay.querySelector('.paguro-admin-overlay-content').innerHTML = '';
                overlay.querySelector('.paguro-admin-overlay-actions').innerHTML = '';
            }

            function openConfirm(message, onConfirm) {
                var safe = (message || '').replace(/[&<>]/g, function(c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
                }).replace(/\\n/g, '<br>');
                var content = '<div style=\"line-height:1.5;\">' + safe + '</div>';
                var actions = '' +
                    '<button type=\"button\" class=\"button paguro-admin-confirm-cancel\">Annulla</button>' +
                    '<button type=\"button\" class=\"button button-primary paguro-admin-confirm-ok\">Conferma</button>';
                openOverlay(content, actions);
                var overlay = document.getElementById('paguro-admin-overlay');
                var okBtn = overlay.querySelector('.paguro-admin-confirm-ok');
                var cancelBtn = overlay.querySelector('.paguro-admin-confirm-cancel');
                okBtn.onclick = function() {
                    closeOverlay();
                    if (typeof onConfirm === 'function') onConfirm();
                };
                cancelBtn.onclick = function() {
                    closeOverlay();
                };
            }

            function showHistoryLog(btn) {
                if (!btn) return false;
                var raw = btn.getAttribute('data-history') || '';
                var logText = raw;
                try { raw = atob(raw); } catch (err) {}
                if (typeof raw === 'string' && raw.indexOf('\\n') !== -1 && raw.indexOf('\n') === -1) {
                    raw = raw.replace(/\\n/g, '\n');
                }
                try {
                    var parsed = JSON.parse(raw);
                    if (Array.isArray(parsed)) {
                        logText = parsed.map(function(entry) {
                            var time = entry.time || '';
                            var action = entry.action || '';
                            var details = entry.details || '';
                            return (time + ' - ' + action + (details ? ' - ' + details : '')).trim() + '\n';
                        }).join('');
                    }
                } catch (err) {}
                var safe = (logText || 'Nessun log disponibile').replace(/[&<>]/g, function(c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
                });
                openOverlay('<pre style=\"white-space:pre-wrap; margin:0;\">' + safe + '</pre>');
                return false;
            }

            function showReceipt(btn) {
                if (!btn) return false;
                var url = btn.getAttribute('data-receipt-url') || btn.getAttribute('href') || '';
                if (!url) return false;
                var safeUrl = url.replace(/\"/g, '&quot;');
                openOverlay('<iframe src=\"' + safeUrl + '\" style=\"width:90vw; max-width:900px; height:75vh; border:0;\"></iframe>');
                return false;
            }

            window.paguroAdminShowLog = showHistoryLog;
            window.paguroAdminShowReceipt = showReceipt;

            document.addEventListener('click', function(e) {
                var target = e.target;
                if (target && target.nodeType === 3) {
                    target = target.parentElement;
                }
                if (!target || typeof target.closest !== 'function') return;
                var btn = target.closest('.paguro-history-toggle');
                if (btn) {
                    e.preventDefault();
                    showHistoryLog(btn);
                    return;
                }

                var receipt = target.closest('.paguro-admin-receipt-link');
                if (receipt) {
                    e.preventDefault();
                    showReceipt(receipt);
                    return;
                }

                var confirmBtn = target.closest('.paguro-admin-confirm');
                if (confirmBtn) {
                    e.preventDefault();
                    var msg = confirmBtn.getAttribute('data-confirm') || 'Confermi l\'azione?';
                    openConfirm(msg, function() {
                        var form = confirmBtn.closest('form');
                        if (!form) return;
                        var name = confirmBtn.getAttribute('name');
                        var value = confirmBtn.getAttribute('value');
                        if (name) {
                            var hidden = form.querySelector('input[name=\"' + name + '\"]');
                            if (!hidden) {
                                hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = name;
                                form.appendChild(hidden);
                            }
                            hidden.value = value || '';
                        }
                        form.submit();
                    });
                }
            });

            document.addEventListener('DOMContentLoaded', function() {
                function wrapSections(containerId) {
                    var container = document.getElementById(containerId);
                    if (!container) return;
                    var headers = container.querySelectorAll('h3');
                    headers.forEach(function(h3) {
                        if (h3.closest('details')) return;
                        var next = h3.nextElementSibling;
                        if (!next) return;
                        var details = document.createElement('details');
                        details.className = 'paguro-details';
                        var summary = document.createElement('summary');
                        summary.textContent = h3.textContent;
                        details.appendChild(summary);
                        details.appendChild(next);
                        h3.replaceWith(details);
                    });
                }
                wrapSections('paguro-web-templates');
                wrapSections('paguro-email-templates');
            });
        })();
    </script>

    <style>
        .paguro-admin-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .paguro-admin-overlay.is-open { display: flex; }
        .paguro-admin-overlay-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
        }
        .paguro-admin-overlay-card {
            position: relative;
            z-index: 1;
            background: #fff;
            border-radius: 10px;
            padding: 18px;
            max-height: 85vh;
            overflow: auto;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }
        .paguro-admin-overlay-actions {
            margin-top: 16px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .paguro-admin-overlay-close {
            position: absolute;
            right: 8px;
            top: 6px;
            border: none;
            background: transparent;
            font-size: 20px;
            cursor: pointer;
        }
        .paguro-details {
            margin: 10px 0 16px;
            padding: 6px 0;
        }
        .paguro-details summary {
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
        }
        .paguro-details table {
            margin-top: 10px;
        }
        .paguro-group-base {
            background-color: #eef3ff !important;
        }
        .paguro-group-alt {
            background-color: #e3ecff !important;
        }
        .paguro-group-base td:first-child,
        .paguro-group-alt td:first-child {
            border-left: 3px solid #6f8fd6;
        }
    </style>

    <nav class="nav-tab-wrapper">
        <a href="<?php echo add_query_arg('tab', 'bookings'); ?>" class="nav-tab <?php echo $current_tab === 'bookings' ? 'nav-tab-active' : ''; ?>">📋 Prenotazioni</a>
        <a href="<?php echo add_query_arg('tab', 'apartments'); ?>" class="nav-tab <?php echo $current_tab === 'apartments' ? 'nav-tab-active' : ''; ?>">🏠 Appartamenti</a>
        <a href="<?php echo add_query_arg('tab', 'emails'); ?>" class="nav-tab <?php echo $current_tab === 'emails' ? 'nav-tab-active' : ''; ?>">📧 Email Templates</a>
        <a href="<?php echo add_query_arg('tab', 'web_templates'); ?>" class="nav-tab <?php echo $current_tab === 'web_templates' ? 'nav-tab-active' : ''; ?>">🖥️ Web Templates</a>
        <a href="<?php echo add_query_arg('tab', 'settings'); ?>" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Configurazione</a>
        <a href="<?php echo add_query_arg('tab', 'ai'); ?>" class="nav-tab <?php echo $current_tab === 'ai' ? 'nav-tab-active' : ''; ?>">🤖 IA</a>
    </nav>

    <div class="tab-content">

        <!-- TAB: PRENOTAZIONI -->
        <?php if ($current_tab === 'bookings') { ?>
            <h2>Gestione Prenotazioni</h2>

            <!-- INSERIMENTO MANUALE -->
            <div class="card paguro-manual-card">
                <div class="paguro-card-header">
                    <h3>➕ Inserisci Prenotazione Manuale</h3>
                    <button type="button" class="button paguro-toggle-manual" aria-expanded="false" aria-controls="paguro-manual-body">Apri</button>
                </div>
                <div id="paguro-manual-body" class="paguro-card-body is-collapsed">
                <form method="POST" class="paguro-manual-form">
                    <?php wp_nonce_field('paguro_manual_nonce', 'paguro_manual_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th>Appartamento</th>
                            <td>
                                <select name="apt_id" required>
                                    <option value="">-- Seleziona --</option>
                                    <?php $apts = $wpdb->get_results("SELECT * FROM $table_apt ORDER BY name");
                                    foreach ($apts as $apt) {
                                        echo '<option value="' . $apt->id . '">' . esc_html($apt->name) . ' (€' . number_format($apt->base_price, 2) . '/settimana)</option>';
                                    } ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Arrivo</th>
                            <td><input type="date" name="date_start" required></td>
                        </tr>
                        <tr>
                            <th>Partenza</th>
                            <td><input type="date" name="date_end" required></td>
                        </tr>
                        <tr>
                            <th>Nome Guest</th>
                            <td><input type="text" name="guest_name" required></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><input type="email" name="guest_email" required></td>
                        </tr>
                        <tr>
                            <th>Telefono</th>
                            <td><input type="text" name="guest_phone"></td>
                        </tr>
                        <tr class="paguro-col-span-2">
                            <th>Note</th>
                            <td><textarea name="guest_notes" rows="3"></textarea></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="paguro_manual_booking" class="button button-primary">Inserisci Prenotazione</button>
                    </p>
                </form>
                </div>
            </div>

            <!-- TABELLA PRENOTAZIONI -->
            <?php
            $filter = sanitize_text_field(wp_unslash($_GET['filter'] ?? ''));
            $filter = in_array($filter, ['receipts', 'cancel_requests'], true) ? $filter : 'all';
            $bookings_url = add_query_arg(['tab' => 'bookings'], $base_url);
            $filters = [
                'all' => ['label' => 'Tutte', 'url' => $bookings_url],
                'receipts' => ['label' => 'Solo distinte', 'url' => add_query_arg('filter', 'receipts', $bookings_url)],
                'cancel_requests' => ['label' => 'Solo richieste cancellazione', 'url' => add_query_arg('filter', 'cancel_requests', $bookings_url)],
            ];
            ?>
            <h3>Prenotazioni Attive</h3>
            <div class="paguro-filter-links">
                <?php foreach ($filters as $key => $info) { ?>
                    <a href="<?php echo esc_url($info['url']); ?>" class="button <?php echo ($filter === $key) ? 'button-primary' : ''; ?>">
                        <?php echo esc_html($info['label']); ?>
                    </a>
                <?php } ?>
            </div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th width="80">ID</th>
                        <th>Guest</th>
                        <th>Appartamento</th>
                        <th>Arrivo</th>
                        <th>Partenza</th>
                                <th width="120">Stato</th>
                                <th width="90">Distinta</th>
                                <th width="60">Log</th>
                                <th width="200">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $limit = 50;
                    $where = '';
                    if ($filter === 'receipts') {
                        $where = 'WHERE status=2 AND receipt_url IS NOT NULL';
                    } elseif ($filter === 'cancel_requests') {
                        $where = 'WHERE status=5';
                    }
                    if ($where) {
                        $bookings = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}paguro_availability $where ORDER BY created_at DESC LIMIT %d",
                            $limit
                        ));
                    } else {
                        $bookings = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}paguro_availability ORDER BY created_at DESC LIMIT %d",
                            $limit
                        ));
                    }
                    if ($bookings) {
                        $grouped = [];
                        foreach ($bookings as $b) {
                            if (!empty($b->group_id)) {
                                if (!isset($grouped[$b->group_id])) $grouped[$b->group_id] = [];
                                $grouped[$b->group_id][] = $b;
                            }
                        }
                        $rendered_groups = [];
                        $group_index = 0;
                        foreach ($bookings as $b) {
                            if (!empty($b->group_id)) {
                                if (isset($rendered_groups[$b->group_id])) {
                                    continue;
                                }
                                $rendered_groups[$b->group_id] = true;
                                $group_index++;
                                $group_class = ($group_index % 2 === 0) ? 'paguro-group-alt' : 'paguro-group-base';
                                $children = $grouped[$b->group_id];
                                $first = $children[0];
                                $apt = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_apt WHERE id=%d", $first->apartment_id));
                                $last = $children[count($children) - 1];
                                $all_confirmed = true;
                                $all_cancelled = true;
                                $any_confirmed = false;
                                $any_receipt = false;
                                $receipt_link = '';
                                foreach ($children as $cb) {
                                    if ($cb->status != 1) $all_confirmed = false;
                                    if ($cb->status == 1) $any_confirmed = true;
                                    if ($cb->status != 3) $all_cancelled = false;
                                    if (!empty($cb->receipt_url) && !$receipt_link) {
                                        $receipt_link = paguro_get_admin_receipt_link($cb->id, $cb->receipt_url);
                                        $any_receipt = true;
                                    }
                                }
                                $group_status = $all_cancelled ? 'Cancellata' : ($all_confirmed ? 'Confermata' : ($any_confirmed ? 'Parziale' : 'Preventivo'));
                                $group_status_class = $all_confirmed ? 'paguro-badge--green' : ($all_cancelled ? 'paguro-badge--red' : ($any_confirmed ? 'paguro-badge--orange' : 'paguro-badge--gray'));
                                $group_id_short = substr($b->group_id, 0, 8);
                                $group_link = site_url("/" . get_option('paguro_page_slug', 'riepilogo-prenotazione') . "/?token={$b->group_id}");
                                ?>
                                <tr class="paguro-group-row <?php echo esc_attr($group_class); ?>" data-group="<?php echo esc_attr($b->group_id); ?>">
                                    <td><strong>GRP-<?php echo esc_html($group_id_short); ?></strong></td>
                                    <td><?php echo esc_html($first->guest_name); ?><br><small><?php echo esc_html($first->guest_email); ?></small></td>
                                    <td><?php echo esc_html($apt->name ?? 'N/A'); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($children[0]->date_start)); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($last->date_end)); ?></td>
                                    <td><span class="paguro-badge <?php echo esc_attr($group_status_class); ?>"><?php echo esc_html($group_status); ?></span></td>
                                    <td>
                                        <?php if ($any_receipt) { ?>
                                            <a href="#" data-receipt-url="<?php echo esc_url($receipt_link); ?>" class="button button-small paguro-admin-receipt-link" onclick="return paguroAdminShowReceipt(this);">Vedi</a>
                                        <?php } else { ?>
                                            <span style="color:#999;">—</span>
                                        <?php } ?>
                                    </td>
                                    <td><span style="color:#999;">—</span></td>
                                    <td>
                                        <a href="<?php echo esc_url($group_link); ?>" class="button button-small">Riepilogo</a>
                                        <button type="button" class="button button-small paguro-group-toggle" data-group="<?php echo esc_attr($b->group_id); ?>">Dettagli</button>
                                    </td>
                                </tr>
                                <?php
                                foreach ($children as $cb) {
                                    $apt_child = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_apt WHERE id=%d", $cb->apartment_id));
                                    $status_labels = [
                                        1 => 'Confermata',
                                        2 => 'Preventivo',
                                        3 => 'Cancellata',
                                        4 => 'Waitlist',
                                        5 => 'Richiesta cancellazione',
                                    ];
                                    $status_classes = [
                                        1 => 'paguro-badge--green',
                                        2 => 'paguro-badge--gray',
                                        3 => 'paguro-badge--red',
                                        4 => 'paguro-badge--blue',
                                        5 => 'paguro-badge--orange',
                                    ];
                                    $status_label = $status_labels[$cb->status] ?? 'Sconosciuto';
                                    $status_class = $status_classes[$cb->status] ?? 'paguro-badge--gray';
                                    $status_action = '';
                                    $status_confirm = '';
                                    if ($cb->status == 2 && !empty($cb->receipt_url)) {
                                        $status_label = 'Distinta (in validazione)';
                                        $status_class = 'paguro-badge--yellow';
                                        $status_action = 'validate_receipt';
                                        $status_confirm = 'Validare la distinta e confermare la prenotazione?';
                                    } elseif ($cb->status == 5) {
                                        $status_action = 'confirm_cancel';
                                        $status_confirm = 'Confermare la cancellazione?';
                                    }
                                    if ($status_action) {
                                        $status_label = '<button type="submit" name="paguro_action" value="' . esc_attr($status_action) . '" class="button button-small paguro-admin-confirm" data-confirm="' . esc_attr($status_confirm) . '">' . esc_html($status_label) . '</button>';
                                    } else {
                                        $status_label = '<span class="paguro-badge ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
                                    }
                                    ?>
                                    <tr class="paguro-group-child <?php echo esc_attr($group_class); ?>" data-group="<?php echo esc_attr($b->group_id); ?>" style="display:none;">
                                        <td>&nbsp;&nbsp;↳ <?php echo $cb->id; ?></td>
                                        <td><?php echo esc_html($cb->guest_name); ?><br><small><?php echo esc_html($cb->guest_email); ?></small></td>
                                        <td><?php echo esc_html($apt_child->name ?? 'N/A'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cb->date_start)); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cb->date_end)); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <?php wp_nonce_field('paguro_admin_action'); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo $cb->id; ?>">
                                                <?php echo $status_label; ?>
                                            </form>
                                        </td>
                                        <td>
                                            <?php if (!empty($cb->receipt_url)) { ?>
                                                <a href="#" data-receipt-url="<?php echo esc_url(paguro_get_admin_receipt_link($cb->id, $cb->receipt_url)); ?>" class="button button-small paguro-admin-receipt-link" onclick="return paguroAdminShowReceipt(this);">Vedi</a>
                                            <?php } else { ?>
                                                <span style="color:#999;">—</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($cb->history_log)) { ?>
                                                <a href="#" class="button button-small paguro-history-toggle" data-history="<?php echo esc_attr(base64_encode($cb->history_log)); ?>" title="Mostra log" onclick="return paguroAdminShowLog(this);">🕒</a>
                                            <?php } else { ?>
                                                <span style="color:#999;">—</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <?php wp_nonce_field('paguro_admin_action'); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo $cb->id; ?>">
                                                <button type="submit" name="paguro_action" value="resend_email" class="button button-small">Reinvia Email</button>
                                                <button type="submit" name="paguro_action" value="anonymize" class="button button-small paguro-admin-confirm" data-confirm="Anonimizzare i dati?">GDPR</button>
                                                <button type="submit" name="paguro_action" value="delete_booking" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Elimina?">Elimina</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                continue;
                            }

                            $apt = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_apt WHERE id=%d", $b->apartment_id));
                            $status_labels = [
                                1 => 'Confermata',
                                2 => 'Preventivo',
                                3 => 'Cancellata',
                                4 => 'Waitlist',
                                5 => 'Richiesta cancellazione',
                            ];
                            $status_classes = [
                                1 => 'paguro-badge--green',
                                2 => 'paguro-badge--gray',
                                3 => 'paguro-badge--red',
                                4 => 'paguro-badge--blue',
                                5 => 'paguro-badge--orange',
                            ];
                            $status_label = $status_labels[$b->status] ?? 'Sconosciuto';
                            $status_class = $status_classes[$b->status] ?? 'paguro-badge--gray';
                            $status_action = '';
                            $status_confirm = '';
                            if ($b->status == 2 && !empty($b->receipt_url)) {
                                $status_label = 'Distinta (in validazione)';
                                $status_class = 'paguro-badge--yellow';
                                $status_action = 'validate_receipt';
                                $status_confirm = 'Validare la distinta e confermare la prenotazione?';
                            } elseif ($b->status == 5) {
                                $status_action = 'confirm_cancel';
                                $status_confirm = 'Confermare la cancellazione?';
                            }
                            if ($status_action) {
                                $status_label = '<button type="submit" name="paguro_action" value="' . esc_attr($status_action) . '" class="button button-small paguro-admin-confirm" data-confirm="' . esc_attr($status_confirm) . '">' . esc_html($status_label) . '</button>';
                            } else {
                                $status_label = '<span class="paguro-badge ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
                            }
                            ?>
                            <tr>
                                <td><?php echo $b->id; ?></td>
                                <td><?php echo esc_html($b->guest_name); ?><br><small><?php echo esc_html($b->guest_email); ?></small></td>
                                <td><?php echo esc_html($apt->name ?? 'N/A'); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($b->date_start)); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($b->date_end)); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <?php wp_nonce_field('paguro_admin_action'); ?>
                                        <input type="hidden" name="booking_id" value="<?php echo $b->id; ?>">
                                        <?php echo $status_label; ?>
                                    </form>
                                </td>
                                <td>
                                    <?php if (!empty($b->receipt_url)) { ?>
                                        <a href="#" data-receipt-url="<?php echo esc_url(paguro_get_admin_receipt_link($b->id, $b->receipt_url)); ?>" class="button button-small paguro-admin-receipt-link" onclick="return paguroAdminShowReceipt(this);">Vedi</a>
                                    <?php } else { ?>
                                        <span style="color:#999;">—</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if (!empty($b->history_log)) { ?>
                                        <a href="#" class="button button-small paguro-history-toggle" data-history="<?php echo esc_attr(base64_encode($b->history_log)); ?>" title="Mostra log" onclick="return paguroAdminShowLog(this);">🕒</a>
                                    <?php } else { ?>
                                        <span style="color:#999;">—</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <?php wp_nonce_field('paguro_admin_action'); ?>
                                        <input type="hidden" name="booking_id" value="<?php echo $b->id; ?>">
                                        <button type="submit" name="paguro_action" value="resend_email" class="button button-small">Reinvia Email</button>
                                        <button type="submit" name="paguro_action" value="anonymize" class="button button-small paguro-admin-confirm" data-confirm="Anonimizzare i dati?">GDPR</button>
                                        <button type="submit" name="paguro_action" value="delete_booking" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Elimina?">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="8" style="text-align: center; padding: 20px;">Nessuna prenotazione.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            <details class="paguro-details paguro-finance-details">
                <summary>Situazione Economica</summary>
            <?php
            $season_start_fin = get_option('paguro_season_start', '2026-06-01');
            $season_end_fin = get_option('paguro_season_end', '2026-09-30');
            $finance_bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}paguro_availability
                 WHERE status != 4 AND date_end >= %s AND date_start <= %s
                 ORDER BY date_start ASC",
                $season_start_fin,
                $season_end_fin
            ));
            $finance_grouped = [];
            foreach ($finance_bookings as $fb) {
                if (!empty($fb->group_id)) {
                    if (!isset($finance_grouped[$fb->group_id])) $finance_grouped[$fb->group_id] = [];
                    $finance_grouped[$fb->group_id][] = $fb;
                }
            }
            $finance_rendered_groups = [];
            $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
            $sum_deposit_received = 0;
            $sum_remaining = 0;
            $sum_forecast = 0;
            $sum_refunds = 0;

            $apt_rows = $wpdb->get_results("SELECT id, name FROM $table_apt ORDER BY name");
            $apt_map = [];
            foreach ($apt_rows as $apt_item) {
                $apt_map[$apt_item->id] = $apt_item->name;
            }
            ?>

            <div class="paguro-finance-summary">
                <div class="paguro-finance-card">
                    <span class="label">Acconti ricevuti</span>
                    <strong class="value" id="paguro-fin-deposit">€ 0,00</strong>
                </div>
                <div class="paguro-finance-card">
                    <span class="label">Saldi previsti</span>
                    <strong class="value" id="paguro-fin-remaining">€ 0,00</strong>
                </div>
                <div class="paguro-finance-card">
                    <span class="label">Previsione incasso</span>
                    <strong class="value" id="paguro-fin-forecast">€ 0,00</strong>
                </div>
                <div class="paguro-finance-card">
                    <span class="label">Caparre restituite</span>
                    <strong class="value" id="paguro-fin-refunds">€ 0,00</strong>
                </div>
            </div>

            <table class="widefat striped paguro-finance-table">
                <thead>
                    <tr>
                        <th width="80">ID</th>
                        <th>Guest</th>
                        <th>Appartamento</th>
                        <th>Arrivo</th>
                        <th>Partenza</th>
                        <th>Stato</th>
                        <th>Totale</th>
                        <th>Acconto</th>
                        <th>Acconto ricevuto</th>
                        <th>Saldo previsto</th>
                        <th>Incasso previsto</th>
                        <th>Rimborso</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($finance_bookings) {
                        foreach ($finance_bookings as $b) {
                            if (!empty($b->group_id)) {
                                if (isset($finance_rendered_groups[$b->group_id])) {
                                    continue;
                                }
                                $finance_rendered_groups[$b->group_id] = true;
                                $children = $finance_grouped[$b->group_id];
                                $first = $children[0];
                                $last = $children[count($children) - 1];
                                $apt_name = $apt_map[$first->apartment_id] ?? 'N/A';

                                $all_confirmed = true;
                                $all_cancelled = true;
                                $any_confirmed = false;
                                $any_receipt = false;
                                foreach ($children as $cb) {
                                    if ($cb->status != 1) $all_confirmed = false;
                                    if ($cb->status == 1) $any_confirmed = true;
                                    if ($cb->status != 3) $all_cancelled = false;
                                    if (!empty($cb->receipt_url) || $cb->status == 1) $any_receipt = true;
                                }
                                $group_status = $all_cancelled ? 'Cancellata' : ($all_confirmed ? 'Confermata' : ($any_confirmed ? 'Parziale' : 'Preventivo'));
                                $group_status_class = $all_confirmed ? 'paguro-badge--green' : ($all_cancelled ? 'paguro-badge--red' : ($any_confirmed ? 'paguro-badge--orange' : 'paguro-badge--gray'));

                                $totals = function_exists('paguro_calculate_group_totals') ? paguro_calculate_group_totals($b->group_id, $children) : [
                                    'weeks_count' => 0,
                                    'total_final' => 0,
                                    'deposit' => 0,
                                    'remaining' => 0
                                ];
                                $total_final = $totals['total_final'];
                                $deposit = $totals['deposit'];
                                $remaining = $totals['remaining'];

                                $deposit_received = $any_receipt ? $deposit : 0;
                                $active_for_forecast = !$all_cancelled && $totals['weeks_count'] > 0;
                                $deposit_pending = $active_for_forecast ? max(0, $deposit - $deposit_received) : 0;
                                $remaining_expected = $active_for_forecast ? $remaining : 0;
                                $forecast = $deposit_pending + $remaining_expected;

                                $refund_amount = 0;
                                foreach ($children as $cb) {
                                    if ($cb->status != 3) continue;
                                    $history = $cb->history_log ? json_decode($cb->history_log, true) : [];
                                    if (!is_array($history)) $history = [];
                                    $refund_sent = false;
                                    foreach ($history as $entry) {
                                        if (!empty($entry['action']) && $entry['action'] === 'ADMIN_REFUND_SENT') {
                                            $refund_sent = true;
                                            break;
                                        }
                                    }
                                    if ($refund_sent && function_exists('paguro_calculate_quote')) {
                                        $week_total = paguro_calculate_quote($cb->apartment_id, $cb->date_start, $cb->date_end);
                                        $refund_amount += ceil($week_total * ($deposit_percent / 100));
                                    }
                                }

                                if ($active_for_forecast) {
                                    $sum_deposit_received += $deposit_received;
                                    $sum_remaining += $remaining_expected;
                                    $sum_forecast += $forecast;
                                }
                                $sum_refunds += $refund_amount;

                                $group_id_short = substr($b->group_id, 0, 8);
                                ?>
                                <tr class="paguro-finance-group-row" data-group="<?php echo esc_attr($b->group_id); ?>">
                                    <td>
                                        <strong>GRP-<?php echo esc_html($group_id_short); ?></strong><br>
                                        <button type="button" class="button button-small paguro-finance-toggle" data-group="<?php echo esc_attr($b->group_id); ?>">Dettagli</button>
                                    </td>
                                    <td><?php echo esc_html($first->guest_name); ?><br><small><?php echo esc_html($first->guest_email); ?></small></td>
                                    <td><?php echo esc_html($apt_name); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($children[0]->date_start)); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($last->date_end)); ?></td>
                                    <td><span class="paguro-badge <?php echo esc_attr($group_status_class); ?>"><?php echo esc_html($group_status); ?></span></td>
                                    <td>€ <?php echo number_format($total_final, 2, ',', '.'); ?></td>
                                    <td>€ <?php echo number_format($deposit, 2, ',', '.'); ?></td>
                                    <td><?php echo $deposit_received > 0 ? ('€ ' . number_format($deposit_received, 2, ',', '.')) : '—'; ?></td>
                                    <td><?php echo $remaining_expected > 0 ? ('€ ' . number_format($remaining_expected, 2, ',', '.')) : '—'; ?></td>
                                    <td><?php echo $forecast > 0 ? ('€ ' . number_format($forecast, 2, ',', '.')) : '—'; ?></td>
                                    <td><?php echo $refund_amount > 0 ? ('€ ' . number_format($refund_amount, 2, ',', '.')) : '—'; ?></td>
                                </tr>
                                <?php
                                foreach ($children as $cb) {
                                    $apt_child = $apt_map[$cb->apartment_id] ?? 'N/A';
                                    $status_labels = [
                                        1 => 'Confermata',
                                        2 => 'Preventivo',
                                        3 => 'Cancellata',
                                        4 => 'Waitlist',
                                        5 => 'Richiesta cancellazione',
                                    ];
                                    $status_classes = [
                                        1 => 'paguro-badge--green',
                                        2 => 'paguro-badge--gray',
                                        3 => 'paguro-badge--red',
                                        4 => 'paguro-badge--blue',
                                        5 => 'paguro-badge--orange',
                                    ];
                                    $status_label = $status_labels[$cb->status] ?? 'Sconosciuto';
                                    $status_class = $status_classes[$cb->status] ?? 'paguro-badge--gray';

                                    $total_cost = function_exists('paguro_calculate_quote') ? paguro_calculate_quote($cb->apartment_id, $cb->date_start, $cb->date_end) : 0;
                                    $deposit_cb = ceil($total_cost * ($deposit_percent / 100));
                                    $remaining_cb = $total_cost - $deposit_cb;
                                    $deposit_received_cb = ($cb->status == 1 || !empty($cb->receipt_url)) ? $deposit_cb : 0;
                                    $active_cb = in_array((int) $cb->status, [1, 2, 5], true);
                                    $forecast_cb = $active_cb ? max(0, $deposit_cb - $deposit_received_cb) + $remaining_cb : 0;

                                    $refund_cb = 0;
                                    if ($cb->status == 3) {
                                        $history = $cb->history_log ? json_decode($cb->history_log, true) : [];
                                        if (!is_array($history)) $history = [];
                                        foreach ($history as $entry) {
                                            if (!empty($entry['action']) && $entry['action'] === 'ADMIN_REFUND_SENT') {
                                                $refund_cb = $deposit_cb;
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <tr class="paguro-finance-child" data-group="<?php echo esc_attr($b->group_id); ?>" style="display:none;">
                                        <td>&nbsp;&nbsp;↳ <?php echo $cb->id; ?></td>
                                        <td><?php echo esc_html($cb->guest_name); ?><br><small><?php echo esc_html($cb->guest_email); ?></small></td>
                                        <td><?php echo esc_html($apt_child); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cb->date_start)); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cb->date_end)); ?></td>
                                        <td><span class="paguro-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                        <td>€ <?php echo number_format($total_cost, 2, ',', '.'); ?></td>
                                        <td>€ <?php echo number_format($deposit_cb, 2, ',', '.'); ?></td>
                                        <td><?php echo $deposit_received_cb > 0 ? ('€ ' . number_format($deposit_received_cb, 2, ',', '.')) : '—'; ?></td>
                                        <td><?php echo $remaining_cb > 0 ? ('€ ' . number_format($remaining_cb, 2, ',', '.')) : '—'; ?></td>
                                        <td><?php echo $forecast_cb > 0 ? ('€ ' . number_format($forecast_cb, 2, ',', '.')) : '—'; ?></td>
                                        <td><?php echo $refund_cb > 0 ? ('€ ' . number_format($refund_cb, 2, ',', '.')) : '—'; ?></td>
                                    </tr>
                                    <?php
                                }
                                continue;
                            }

                            $apt_name = $apt_map[$b->apartment_id] ?? 'N/A';
                            $status_labels = [
                                1 => 'Confermata',
                                2 => 'Preventivo',
                                3 => 'Cancellata',
                                4 => 'Waitlist',
                                5 => 'Richiesta cancellazione',
                            ];
                            $status_classes = [
                                1 => 'paguro-badge--green',
                                2 => 'paguro-badge--gray',
                                3 => 'paguro-badge--red',
                                4 => 'paguro-badge--blue',
                                5 => 'paguro-badge--orange',
                            ];
                            $status_label = $status_labels[$b->status] ?? 'Sconosciuto';
                            $status_class = $status_classes[$b->status] ?? 'paguro-badge--gray';

                            $total_cost = function_exists('paguro_calculate_quote') ? paguro_calculate_quote($b->apartment_id, $b->date_start, $b->date_end) : 0;
                            $deposit = ceil($total_cost * ($deposit_percent / 100));
                            $remaining = $total_cost - $deposit;
                            $deposit_received = ($b->status == 1 || !empty($b->receipt_url)) ? $deposit : 0;
                            $active = in_array((int) $b->status, [1, 2, 5], true);
                            $deposit_pending = $active ? max(0, $deposit - $deposit_received) : 0;
                            $remaining_expected = $active ? $remaining : 0;
                            $forecast = $deposit_pending + $remaining_expected;

                            $refund_amount = 0;
                            if ($b->status == 3) {
                                $history = $b->history_log ? json_decode($b->history_log, true) : [];
                                if (!is_array($history)) $history = [];
                                foreach ($history as $entry) {
                                    if (!empty($entry['action']) && $entry['action'] === 'ADMIN_REFUND_SENT') {
                                        $refund_amount = $deposit;
                                        break;
                                    }
                                }
                            }

                            if ($active) {
                                $sum_deposit_received += $deposit_received;
                                $sum_remaining += $remaining_expected;
                                $sum_forecast += $forecast;
                            }
                            $sum_refunds += $refund_amount;
                            ?>
                            <tr>
                                <td><?php echo $b->id; ?></td>
                                <td><?php echo esc_html($b->guest_name); ?><br><small><?php echo esc_html($b->guest_email); ?></small></td>
                                <td><?php echo esc_html($apt_name); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($b->date_start)); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($b->date_end)); ?></td>
                                <td><span class="paguro-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                <td>€ <?php echo number_format($total_cost, 2, ',', '.'); ?></td>
                                <td>€ <?php echo number_format($deposit, 2, ',', '.'); ?></td>
                                <td><?php echo $deposit_received > 0 ? ('€ ' . number_format($deposit_received, 2, ',', '.')) : '—'; ?></td>
                                <td><?php echo $remaining_expected > 0 ? ('€ ' . number_format($remaining_expected, 2, ',', '.')) : '—'; ?></td>
                                <td><?php echo $forecast > 0 ? ('€ ' . number_format($forecast, 2, ',', '.')) : '—'; ?></td>
                                <td><?php echo $refund_amount > 0 ? ('€ ' . number_format($refund_amount, 2, ',', '.')) : '—'; ?></td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="12" style="text-align:center; padding: 18px;">Nessun dato economico per la stagione.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>

            <script>
            (function(){
                var summary = {
                    deposit: <?php echo json_encode(number_format($sum_deposit_received, 2, ',', '.')); ?>,
                    remaining: <?php echo json_encode(number_format($sum_remaining, 2, ',', '.')); ?>,
                    forecast: <?php echo json_encode(number_format($sum_forecast, 2, ',', '.')); ?>,
                    refunds: <?php echo json_encode(number_format($sum_refunds, 2, ',', '.')); ?>
                };
                var setText = function(id, value){
                    var el = document.getElementById(id);
                    if (el) el.textContent = '€ ' + value;
                };
                setText('paguro-fin-deposit', summary.deposit);
                setText('paguro-fin-remaining', summary.remaining);
                setText('paguro-fin-forecast', summary.forecast);
                setText('paguro-fin-refunds', summary.refunds);

                document.querySelectorAll('.paguro-finance-toggle').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var group = btn.getAttribute('data-group');
                        if (!group) return;
                        document.querySelectorAll('.paguro-finance-child[data-group="' + group + '"]').forEach(function(row) {
                            row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
                        });
                    });
                });
            })();
            </script>
            </details>
        <?php } ?>
<!-- TAB: APPARTAMENTI -->
        <?php if ($current_tab === 'apartments') { ?>
            <h2>Gestione Appartamenti</h2>
            <?php
            $edit_apt_id = isset($_GET['edit_apt']) ? intval($_GET['edit_apt']) : 0;
            $edit_apt = $edit_apt_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_apt WHERE id=%d", $edit_apt_id)) : null;
            $apt_cancel_url = add_query_arg('tab', 'apartments', admin_url('admin.php?page=paguro-booking'));
            $season_start = get_option('paguro_season_start', '2026-06-01');
            $season_end = get_option('paguro_season_end', '2026-09-30');
            $season_start_dt = new DateTime($season_start);
            $season_end_dt = new DateTime($season_end);
            if ($season_start_dt->format('N') != 6) {
                $season_start_dt->modify('next saturday');
            }
            $edit_prices = [];
            if ($edit_apt && !empty($edit_apt->pricing_json)) {
                $decoded = json_decode($edit_apt->pricing_json, true);
                if (is_array($decoded)) {
                    $edit_prices = $decoded;
                }
            }
            $all_apts = $wpdb->get_results("SELECT * FROM $table_apt ORDER BY name");
            $apt_pricing_map = [];
            foreach ($all_apts as $apt_item) {
                $decoded = $apt_item->pricing_json ? json_decode($apt_item->pricing_json, true) : [];
                if (!is_array($decoded)) {
                    $decoded = [];
                }
                $apt_pricing_map[$apt_item->id] = [
                    'base_price' => floatval($apt_item->base_price),
                    'pricing' => $decoded
                ];
            }
            $month_names = [
                '01' => 'Gennaio',
                '02' => 'Febbraio',
                '03' => 'Marzo',
                '04' => 'Aprile',
                '05' => 'Maggio',
                '06' => 'Giugno',
                '07' => 'Luglio',
                '08' => 'Agosto',
                '09' => 'Settembre',
                '10' => 'Ottobre',
                '11' => 'Novembre',
                '12' => 'Dicembre'
            ];
            ?>

            <?php if ($edit_apt) { ?>
                <div class="card">
                    <h3>✏️ Modifica Appartamento</h3>
                    <form method="POST" style="max-width: 900px;">
                        <?php wp_nonce_field('paguro_apt_nonce'); ?>
                        <input type="hidden" name="apt_id" value="<?php echo intval($edit_apt->id); ?>">
                        <table class="form-table">
                            <tr>
                                <th>Nome</th>
                                <td><input type="text" name="apt_name" value="<?php echo esc_attr($edit_apt->name); ?>" required></td>
                            </tr>
                            <tr>
                                <th>Prezzo Base (€/settimana)</th>
                                <td><input type="number" name="apt_price" step="0.01" min="0" value="<?php echo esc_attr($edit_apt->base_price); ?>" required></td>
                            </tr>
                        </table>
                        <div class="paguro-pricing-tools" style="margin: 10px 0 18px;">
                            <div style="margin-bottom:10px;">
                                <strong>Copia prezzo per mese</strong><br>
                                <label for="paguro-copy-month" class="screen-reader-text">Mese</label>
                                <select id="paguro-copy-month" style="margin-right:6px;">
                                    <?php foreach ($month_names as $m_key => $m_label) { ?>
                                        <option value="<?php echo esc_attr($m_key); ?>"><?php echo esc_html($m_label); ?></option>
                                    <?php } ?>
                                </select>
                                <label for="paguro-copy-month-price" class="screen-reader-text">Prezzo</label>
                                <input type="number" id="paguro-copy-month-price" step="0.01" min="0" placeholder="Prezzo €" style="max-width:140px; margin-right:6px;">
                                <button type="button" class="button" id="paguro-copy-month-btn">Copia mese</button>
                            </div>
                            <div>
                                <strong>Copia prezzi dall'altro appartamento</strong><br>
                                <label for="paguro-copy-apt" class="screen-reader-text">Appartamento</label>
                                <select id="paguro-copy-apt" style="margin-right:6px;">
                                    <option value="">Seleziona...</option>
                                    <?php foreach ($all_apts as $apt_item) {
                                        if ($apt_item->id == $edit_apt->id) continue;
                                        ?>
                                        <option value="<?php echo esc_attr($apt_item->id); ?>"><?php echo esc_html($apt_item->name); ?></option>
                                    <?php } ?>
                                </select>
                                <label style="margin-right:6px;">
                                    <input type="checkbox" id="paguro-copy-apt-base" checked> Copia anche prezzo base
                                </label>
                                <button type="button" class="button" id="paguro-copy-apt-btn">Copia appartamento</button>
                            </div>
                        </div>
                        <h4>Prezzi settimanali (sab-sab)</h4>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Settimana</th>
                                    <th>Prezzo (€)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $cur = clone $season_start_dt;
                                while ($cur < $season_end_dt) {
                                    $week_end = (clone $cur)->modify('+1 week');
                                    if ($week_end > $season_end_dt) break;
                                    $week_key = $cur->format('Y-m-d');
                                    $label = $cur->format('d/m/Y') . ' - ' . $week_end->format('d/m/Y');
                                    $value = isset($edit_prices[$week_key]) ? $edit_prices[$week_key] : '';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($label); ?></td>
                                        <td>
                                            <input type="number" name="pricing_week[<?php echo esc_attr($week_key); ?>]" step="0.01" min="0" value="<?php echo esc_attr($value); ?>" style="max-width: 160px;">
                                        </td>
                                    </tr>
                                    <?php
                                    $cur->modify('+1 week');
                                }
                                ?>
                            </tbody>
                        </table>
                        <p class="description">Lascia vuoto per usare il prezzo base settimanale.</p>
                        <p>
                            <button type="submit" name="paguro_apt_action" value="update_apt" class="button button-primary">Aggiorna</button>
                            <a href="<?php echo esc_url($apt_cancel_url); ?>" class="button">Annulla</a>
                        </p>
                    </form>
                </div>
            <?php } ?>

            <div class="card">
                <h3>➕ Aggiungi Appartamento</h3>
                <form method="POST" style="max-width: 500px;">
                    <?php wp_nonce_field('paguro_apt_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Nome</th>
                            <td><input type="text" name="apt_name" required></td>
                        </tr>
                        <tr>
                            <th>Prezzo Base (€/settimana)</th>
                            <td><input type="number" name="apt_price" step="0.01" min="0" required></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="paguro_apt_action" value="add_apt" class="button button-primary">Aggiungi</button>
                    </p>
                </form>
            </div>

            <h3>Appartamenti Registrati</h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Prezzo Base (€/settimana)</th>
                        <th width="150">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($all_apts as $apt) {
                        ?>
                        <tr>
                            <td><?php echo $apt->id; ?></td>
                            <td><?php echo esc_html($apt->name); ?></td>
                            <td>€<?php echo number_format($apt->base_price, 2); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <?php wp_nonce_field('paguro_apt_nonce'); ?>
                                    <input type="hidden" name="apt_id" value="<?php echo $apt->id; ?>">
                                    <a href="<?php echo esc_url(add_query_arg(['tab' => 'apartments', 'edit_apt' => $apt->id])); ?>" class="button button-small">Modifica</a>
                                    <button type="submit" name="paguro_apt_action" value="delete_apt" class="button button-small button-link-delete paguro-admin-confirm" data-confirm="Eliminare?">Elimina</button>
                                </form>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </tbody>
            </table>

            <?php if ($edit_apt) { ?>
                <script>
                window.paguroAptPricing = <?php echo wp_json_encode($apt_pricing_map); ?>;
                document.addEventListener('DOMContentLoaded', function () {
                    var monthBtn = document.getElementById('paguro-copy-month-btn');
                    var monthSelect = document.getElementById('paguro-copy-month');
                    var monthPrice = document.getElementById('paguro-copy-month-price');
                    var aptBtn = document.getElementById('paguro-copy-apt-btn');
                    var aptSelect = document.getElementById('paguro-copy-apt');
                    var aptBase = document.getElementById('paguro-copy-apt-base');
                    var baseInput = document.querySelector('input[name="apt_price"]');

                    function getWeekInputs() {
                        return Array.prototype.slice.call(document.querySelectorAll('input[name^="pricing_week["]'));
                    }

                    if (monthBtn) {
                        monthBtn.addEventListener('click', function () {
                            var month = monthSelect ? monthSelect.value : '';
                            var price = monthPrice ? monthPrice.value : '';
                            if (!month || !price) {
                                alert('Seleziona un mese e inserisci un prezzo.');
                                return;
                            }
                            getWeekInputs().forEach(function (input) {
                                var match = input.name.match(/pricing_week\\[(\\d{4}-\\d{2}-\\d{2})\\]/);
                                if (!match) return;
                                var date = match[1];
                                if (date.substring(5, 7) === month) {
                                    input.value = price;
                                }
                            });
                        });
                    }

                    if (aptBtn) {
                        aptBtn.addEventListener('click', function () {
                            var aptId = aptSelect ? aptSelect.value : '';
                            if (!aptId) {
                                alert('Seleziona un appartamento da cui copiare i prezzi.');
                                return;
                            }
                            var data = window.paguroAptPricing ? window.paguroAptPricing[aptId] : null;
                            if (!data) return;
                            var pricing = data.pricing || {};
                            getWeekInputs().forEach(function (input) {
                                var match = input.name.match(/pricing_week\\[(\\d{4}-\\d{2}-\\d{2})\\]/);
                                if (!match) return;
                                var date = match[1];
                                input.value = pricing.hasOwnProperty(date) ? pricing[date] : '';
                            });
                            if (aptBase && aptBase.checked && baseInput) {
                                baseInput.value = data.base_price || '';
                            }
                        });
                    }
                });
                </script>
            <?php } ?>



        <?php } ?>

        <!-- TAB: EMAIL TEMPLATES -->
        <?php if ($current_tab === 'emails') { ?>
            <h2>Email Templates</h2>

            <div class="card paguro-full-width-card" id="paguro-email-templates">
                <?php
                $default_cancel_req_body_old = 'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua richiesta di cancellazione per {apt_name} ({date_start} - {date_end}).<br>La richiesta è in verifica dal nostro staff.<br><br>IBAN per rimborso: {customer_iban_priv}<br>Se applicabile, il termine di cancellazione era {cancel_deadline}.';
                $default_cancel_req_body = 'Ciao {guest_name},<br><br>' .
                    'Abbiamo ricevuto la tua richiesta di cancellazione.<br><br>' .
                    '<strong>Appartamento:</strong> {apt_name}<br>' .
                    '<strong>Date soggiorno:</strong> {date_start} - {date_end}<br><br>' .
                    'La richiesta è in verifica dal nostro staff.<br><br>' .
                    '<strong>IBAN per rimborso:</strong> {customer_iban_priv}<br>' .
                    'Il rimborso, se confermato, verrà disposto su questo IBAN.<br>' .
                    'Non possiamo essere responsabili di eventuali errori nell\'IBAN comunicato.<br><br>' .
                    'Cancellazione applicabile fino al {cancel_deadline}.';

                $default_refund_subj = 'Bonifico disposto - {apt_name}';
                $default_refund_body = 'Ciao {guest_name},<br><br>' .
                    'Ti confermiamo che il bonifico di rimborso è stato disposto sul seguente IBAN:<br>' .
                    '<strong>{customer_iban_priv}</strong><br><br>' .
                    'I tempi di accredito possono variare in base alla tua banca.<br><br>' .
                    'Grazie comunque per averci contattato e per la fiducia riposta.<br>' .
                    'Speriamo di poterti ospitare in futuro.';

                $default_cancel_subj = 'Cancellazione Confermata';
                $default_cancel_body = 'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata cancellata.<br>Se applicabile, l\'acconto sarà rimborsato secondo le nostre condizioni.';
                $default_cancel_adm_subj = 'Cancellazione: {apt_name}';
                $default_cancel_adm_body = 'Cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end})';

                $current_cancel_req_body = get_option('paguro_txt_email_cancel_req_body', '');
                if ($current_cancel_req_body === '' || $current_cancel_req_body === $default_cancel_req_body_old) {
                    update_option('paguro_txt_email_cancel_req_body', $default_cancel_req_body);
                }
                if (get_option('paguro_msg_email_refund_subj', '') === '') {
                    update_option('paguro_msg_email_refund_subj', $default_refund_subj);
                }
                if (get_option('paguro_msg_email_refund_body', '') === '') {
                    update_option('paguro_msg_email_refund_body', $default_refund_body);
                }
                if (get_option('paguro_msg_email_cancel_subj', '') === '') {
                    update_option('paguro_msg_email_cancel_subj', $default_cancel_subj);
                }
                if (get_option('paguro_msg_email_cancel_body', '') === '') {
                    update_option('paguro_msg_email_cancel_body', $default_cancel_body);
                }
                if (get_option('paguro_msg_email_adm_cancel_subj', '') === '') {
                    update_option('paguro_msg_email_adm_cancel_subj', $default_cancel_adm_subj);
                }
                if (get_option('paguro_msg_email_adm_cancel_body', '') === '') {
                    update_option('paguro_msg_email_adm_cancel_body', $default_cancel_adm_body);
                }
                ?>

                <p style="color: #666;">Personalizza i modelli email. Variabili disponibili: <code>{guest_name}</code>, <code>{guest_email}</code>, <code>{guest_phone}</code>, <code>{customer_iban}</code>, <code>{customer_iban_priv}</code>, <code>{apt_name}</code>, <code>{date_start}</code>, <code>{date_end}</code>, <code>{total_cost}</code>, <code>{deposit_cost}</code>, <code>{remaining_cost}</code>, <code>{deposit_percent}</code>, <code>{iban}</code>, <code>{intestatario}</code>, <code>{link_riepilogo}</code>, <code>{cancel_deadline}</code></p>

                <form method="POST">
                    <?php wp_nonce_field('paguro_email_opts'); ?>

                    <h3>Email: Richiesta Quotazione (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-REQ</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_req_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_request_subj', 'La tua Quotazione - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
	                                wp_editor(
	                                    get_option('paguro_txt_email_request_body', 'Caro/a {guest_name},<br><br>Abbiamo ricevuto la tua richiesta di quotazione per {apt_name}.<br>Date: {date_start} - {date_end}<br><br><a href="{link_riepilogo}" class="button">Accedi alla tua prenotazione</a>'),
	                                    'email_req_body',
	                                    ['textarea_rows' => 6]
	                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Ricevimento Distinta (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-REC</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_rec_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_receipt_subj', 'Ricevimento Distinta Bonifico - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_txt_email_receipt_body', 'Caro/a {guest_name},\n\nAbbiamo ricevuto la distinta del bonifico per {apt_name}.\n\nImporto: €{deposit_cost}\n\nGrazie per la prenotazione!'),
                                    'email_rec_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Prenotazione Confermata (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-CONF</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_conf_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_confirm_subj', 'Prenotazione Confermata! - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_txt_email_confirm_body', 'Caro/a {guest_name},\n\nLa tua prenotazione per {apt_name} è confermata!\n\nArrivo: {date_start}\nPartenza: {date_end}\nTotale: €{total_cost}\n\nTi aspettiamo!'),
                                    'email_conf_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Richiesta Cancellazione (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-REQ</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_req_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_cancel_req_subj', 'Richiesta cancellazione ricevuta - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_txt_email_cancel_req_body', 'Ciao {guest_name},<br><br>Abbiamo ricevuto la tua richiesta di cancellazione per {apt_name} ({date_start} - {date_end}).<br>La richiesta è in verifica dal nostro staff.<br><br>Se applicabile, il termine di cancellazione era {cancel_deadline}.'),
                                    'email_cancel_req_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Richiesta Cancellazione (Admin) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-REQ-ADM</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_req_adm_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_cancel_req_adm_subj', 'Richiesta cancellazione - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_txt_email_cancel_req_adm_body', 'Richiesta cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end}).'),
                                    'email_cancel_req_adm_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Cancellazione Confermata (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-CONF</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_subj" value="<?php echo esc_attr(get_option('paguro_msg_email_cancel_subj', 'Cancellazione Confermata')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_msg_email_cancel_body', 'Ciao {guest_name},<br><br>La tua prenotazione per {apt_name} ({date_start} - {date_end}) è stata cancellata.<br>Se applicabile, l\'acconto sarà rimborsato secondo le nostre condizioni.'),
                                    'email_cancel_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Cancellazione Confermata (Admin) <span class="paguro-badge paguro-badge--gray">EMAIL-CAN-CONF-ADM</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_cancel_adm_subj" value="<?php echo esc_attr(get_option('paguro_msg_email_adm_cancel_subj', 'Cancellazione: {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_msg_email_adm_cancel_body', 'Cancellazione da {guest_name} per {apt_name} ({date_start} - {date_end})'),
                                    'email_cancel_adm_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Bonifico Disposto (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-REFUND</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_refund_subj" value="<?php echo esc_attr(get_option('paguro_msg_email_refund_subj', 'Rimborso disposto - {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php 
                                wp_editor(
                                    get_option('paguro_msg_email_refund_body', 'Gentile {guest_name},<br><br>Il rimborso è stato disposto su questo IBAN:<br><strong>{customer_iban_priv}</strong><br><br>I tempi di accredito dipendono dalla banca.<br><br>Grazie per averci contattato.'),
                                    'email_refund_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Lista d'Attesa (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-WAITLIST</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_waitlist_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_waitlist_subj', 'Lista d\'attesa {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_waitlist_body', 'Gentile {guest_name},<br><br>La tua richiesta per {apt_name} ({date_start} - {date_end}) è in lista d\'attesa.<br>Ti avviseremo appena si libera.<br><br><a href="{link_riepilogo}" class="button">APRI RIEPILOGO</a>'),
                                    'email_waitlist_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Lista d'Attesa (Admin) <span class="paguro-badge paguro-badge--gray">EMAIL-WAITLIST-ADM</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_waitlist_adm_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_waitlist_adm_subj', 'Nuova lista d\'attesa: {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_waitlist_adm_body', 'Nuova iscrizione: {guest_name} ({guest_email}) | {apt_name} {date_start} - {date_end}.'),
                                    'email_waitlist_adm_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <h3>Email: Avviso Disponibilità Waitlist (Utente) <span class="paguro-badge paguro-badge--gray">EMAIL-WAITLIST-ALERT</span></h3>
                    <table class="form-table">
                        <tr>
                            <th>Soggetto</th>
                            <td>
                                <input type="text" name="email_waitlist_alert_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_waitlist_alert_subj', 'Disponibilità {apt_name}')); ?>" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Corpo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_txt_email_waitlist_alert_body', 'Gentile {guest_name},<br><br>Si è liberato {apt_name} per {date_start} - {date_end}.<br>Se sei interessato puoi prenotare ora.<br><br><a href="{booking_url}" class="button">PRENOTA ORA</a>'),
                                    'email_waitlist_alert_body',
                                    ['textarea_rows' => 6]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="paguro_save_emails" class="button button-primary">Salva Email Templates</button>
                    </p>
                </form>
            </div>

        <?php } ?>

        <!-- TAB: WEB TEMPLATES -->
        <?php if ($current_tab === 'web_templates') { ?>
            <h2>Web Templates</h2>

            <div class="card paguro-full-width-card" id="paguro-web-templates">
                <p style="color: #666;">Messaggi e contenuti visibili all'utente nel browser.</p>

                <form method="POST">
                    <?php wp_nonce_field('paguro_web_templates'); ?>

                    <h3>Chat e Form</h3>
                    <table class="form-table">
                        <tr>
                            <th>Social Pressure</th>
                            <td>
                                <textarea name="ui_social_pressure" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_social_pressure', '')); ?></textarea>
                                <p class="description">Usa <code>{count}</code> per il numero di richieste attive.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Privacy Notice</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_privacy_notice', ''),
                                    'ui_privacy_notice',
                                    ['textarea_rows' => 4]
                                );
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Bottone Chat (singola settimana)</th>
                            <td>
                                <input type="text" name="ui_btn_book" value="<?php echo esc_attr(get_option('paguro_js_btn_book', '[Richiedi solo questa settimana]')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Istruzione Upload</th>
                            <td>
                                <input type="text" name="ui_upload_instruction" value="<?php echo esc_attr(get_option('paguro_msg_ui_upload_instruction', 'Carica la distinta per bloccare le date.')); ?>" style="width:100%; max-width: 600px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Riepilogo Prenotazione</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contenuto Riepilogo</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_summary_page', '<div>...</div>'),
                                    'ui_summary_page',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Puoi usare i placeholder del riepilogo (vedi DEPLOYMENT_GUIDE.md).</p>
                                <details style="margin-top:10px;">
                                    <summary>Placeholder disponibili</summary>
                                    <div style="margin-top:10px;">
                                        <code>{guest_name}</code> <code>{guest_email}</code> <code>{guest_phone}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{apt_name}</code>
                                        <code>{date_start}</code> <code>{date_end}</code> <code>{date_start_raw}</code> <code>{date_end_raw}</code>
                                        <code>{total_cost}</code> <code>{deposit_cost}</code> <code>{remaining_cost}</code>
                                        <code>{total_cost_fmt}</code> <code>{deposit_cost_fmt}</code> <code>{remaining_cost_fmt}</code>
                                        <code>{total_cost_raw}</code> <code>{deposit_cost_raw}</code> <code>{remaining_cost_raw}</code>
                                        <code>{deposit_percent}</code> <code>{iban}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{intestatario}</code>
                                        <code>{receipt_url}</code> <code>{receipt_uploaded_at}</code> <code>{receipt_uploaded_at_fmt}</code>
                                        <code>{booking_id}</code> <code>{apartment_id}</code> <code>{status}</code> <code>{token}</code>
                                        <code>{created_at}</code> <code>{created_at_fmt}</code> <code>{lock_expires}</code>
                                        <code>{link_riepilogo}</code> <code>{booking_url}</code>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    </table>

                    <?php
                    $summary_confirm_default = '<p><strong>Prenotazione confermata.</strong></p>' .
                        '<p>{apt_name} | {date_start} - {date_end}</p>' .
                        '<p>Di seguito i dettagli di pagamento e la distinta (se presente).</p>';
                    $summary_confirm_value = get_option('paguro_msg_ui_summary_confirm_page', '');
                    if ($summary_confirm_value === '') {
                        $summary_confirm_value = $summary_confirm_default;
                        update_option('paguro_msg_ui_summary_confirm_page', $summary_confirm_value);
                    }
                    ?>

                    <h3>Riepilogo Prenotazione Confermata</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contenuto Riepilogo (Confermata)</th>
                            <td>
                                <?php
                                wp_editor(
                                    $summary_confirm_value,
                                    'ui_summary_confirm_page',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Usato solo per prenotazioni con stato Confermata. Se vuoto, usa il riepilogo standard.</p>
                                <details style="margin-top:10px;">
                                    <summary>Placeholder disponibili</summary>
                                    <div style="margin-top:10px;">
                                        <code>{guest_name}</code> <code>{guest_email}</code> <code>{guest_phone}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{apt_name}</code>
                                        <code>{date_start}</code> <code>{date_end}</code> <code>{date_start_raw}</code> <code>{date_end_raw}</code>
                                        <code>{total_cost}</code> <code>{deposit_cost}</code> <code>{remaining_cost}</code>
                                        <code>{total_cost_fmt}</code> <code>{deposit_cost_fmt}</code> <code>{remaining_cost_fmt}</code>
                                        <code>{total_cost_raw}</code> <code>{deposit_cost_raw}</code> <code>{remaining_cost_raw}</code>
                                        <code>{deposit_percent}</code> <code>{iban}</code> <code>{customer_iban}</code> <code>{customer_iban_priv}</code> <code>{intestatario}</code>
                                        <code>{receipt_url}</code> <code>{receipt_uploaded_at}</code> <code>{receipt_uploaded_at_fmt}</code>
                                        <code>{booking_id}</code> <code>{apartment_id}</code> <code>{status}</code> <code>{token}</code>
                                        <code>{created_at}</code> <code>{created_at_fmt}</code> <code>{lock_expires}</code>
                                        <code>{link_riepilogo}</code> <code>{booking_url}</code>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Header e Login</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Riepilogo Preventivo</th>
                            <td>
                                <input type="text" name="ui_summary_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_summary_title', 'Riepilogo preventivo')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Riepilogo Lista d'Attesa</th>
                            <td>
                                <input type="text" name="ui_waitlist_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_title', "Riepilogo lista d'attesa")); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Token Mancante</th>
                            <td>
                                <input type="text" name="ui_missing_token" value="<?php echo esc_attr(get_option('paguro_msg_ui_missing_token', 'Codice di accesso mancante.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Login</th>
                            <td>
                                <input type="text" name="ui_auth_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_title', 'Area riservata')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Login (Preventivo)</th>
                            <td>
                                <textarea name="ui_auth_help_quote" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_auth_help_quote', "Inserisci l'email usata per la richiesta.")); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Login (Lista d'Attesa)</th>
                            <td>
                                <textarea name="ui_auth_help_waitlist" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_auth_help_waitlist', "Inserisci l'email usata per la lista d'attesa.")); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Label Email Login</th>
                            <td>
                                <input type="text" name="ui_auth_email_label" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_email_label', 'Email *')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone Login</th>
                            <td>
                                <input type="text" name="ui_auth_submit" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_submit', 'Accedi')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Errore Login</th>
                            <td>
                                <input type="text" name="ui_auth_error" value="<?php echo esc_attr(get_option('paguro_msg_ui_auth_error', 'Email non trovata.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Richiesta Preventivo (Form)</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Form Preventivo</th>
                            <td>
                                <input type="text" name="ui_quote_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_quote_title', 'Richiesta preventivo {apt_name}')); ?>" style="width:100%; max-width: 800px;">
                                <p class="description">Usa <code>{apt_name}</code> per il nome appartamento.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Sottotitolo Form Preventivo</th>
                            <td>
                                <input type="text" name="ui_quote_subtitle" value="<?php echo esc_attr(get_option('paguro_msg_ui_quote_subtitle', 'Compila i dati per ricevere il preventivo.')); ?>" style="width:100%; max-width: 800px;">
                                <p class="description">Se vuoto, non viene mostrato.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Nota sotto "Nome Completo"</th>
                            <td>
                                <input type="text" name="ui_quote_name_help" value="<?php echo esc_attr(get_option('paguro_msg_ui_quote_name_help', 'Intestatario IBAN')); ?>" style="width:100%; max-width: 800px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Alert e Stati</h3>
                    <table class="form-table">
                        <tr>
                            <th>Prenotazione Non Trovata</th>
                            <td>
                                <input type="text" name="ui_booking_not_found" value="<?php echo esc_attr(get_option('paguro_msg_ui_booking_not_found', 'Prenotazione non trovata.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Lista d'Attesa Non Trovata</th>
                            <td>
                                <input type="text" name="ui_waitlist_not_found" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_not_found', "Iscrizione non trovata.")); ?>" style="width:100%; max-width: 800px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Lista d'Attesa Annullata</th>
                            <td>
                                <input type="text" name="ui_waitlist_cancelled" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_cancelled', "Iscrizione annullata.")); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Stato Lista d'Attesa</th>
                            <td>
                                <input type="text" name="ui_waitlist_title_text" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_title_text', "Sei in lista d'attesa")); ?>" style="width:100%; max-width: 500px;">
                                <p class="description">Nel riepilogo viene aggiunta automaticamente l'icona ✅.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Stato Lista d'Attesa (Inline)</th>
                            <td>
                                <textarea name="ui_waitlist_inline_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_waitlist_inline_notice', 'Ti avviseremo appena si libera.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Stato Lista d'Attesa (Pagina)</th>
                            <td>
                                <textarea name="ui_waitlist_page_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_waitlist_page_notice', 'Ti avviseremo appena si libera.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Label "Date Richieste" (Inline)</th>
                            <td>
                                <input type="text" name="ui_waitlist_inline_dates_label" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_inline_dates_label', 'Periodo:')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione Richiesta</th>
                            <td>
                                <input type="text" name="ui_cancel_requested" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_requested', 'Richiesta di cancellazione inviata.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione In Attesa</th>
                            <td>
                                <input type="text" name="ui_cancel_pending" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_pending', 'Cancellazione già richiesta.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>IBAN Richiesto</th>
                            <td>
                                <input type="text" name="ui_cancel_iban_required" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_iban_required', 'Inserisci un IBAN valido.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>IBAN Non Valido</th>
                            <td>
                                <input type="text" name="ui_cancel_iban_invalid" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_iban_invalid', 'IBAN non valido.')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione Non Disponibile (Alert)</th>
                            <td>
                                <textarea name="ui_cancel_denied" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_denied', 'Cancellazione non disponibile oltre {cancel_deadline}.')); ?></textarea>
                                <p class="description">Usa <code>{cancel_deadline}</code> per la data limite.</p>
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Sezioni ed Etichette</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Sezione "Dati Prenotazione"</th>
                            <td>
                                <input type="text" name="ui_section_booking" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_booking', 'Dettagli soggiorno')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Calcolo Prezzo"</th>
                            <td>
                                <input type="text" name="ui_section_pricing" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_pricing', 'Prezzo')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Dati per il Bonifico"</th>
                            <td>
                                <input type="text" name="ui_section_payment" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_payment', 'Bonifico')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Carica Distinta"</th>
                            <td>
                                <input type="text" name="ui_section_upload" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_upload', 'Carica distinta')); ?>" style="width:100%; max-width: 600px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Distinta Caricata"</th>
                            <td>
                                <input type="text" name="ui_section_uploaded" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_uploaded', 'Distinta ricevuta')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Azioni"</th>
                            <td>
                                <input type="text" name="ui_section_actions" value="<?php echo esc_attr(get_option('paguro_msg_ui_section_actions', 'Azioni')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Ospite</th>
                            <td>
                                <input type="text" name="ui_label_guest" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_guest', 'Ospite')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Nome (Waitlist)</th>
                            <td>
                                <input type="text" name="ui_label_name" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_name', 'Nome')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Email</th>
                            <td>
                                <input type="text" name="ui_label_email" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_email', 'Email')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Telefono</th>
                            <td>
                                <input type="text" name="ui_label_phone" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_phone', 'Telefono')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Appartamento</th>
                            <td>
                                <input type="text" name="ui_label_apartment" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_apartment', 'Appartamento')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Check-in</th>
                            <td>
                                <input type="text" name="ui_label_checkin" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_checkin', 'Check-in')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Check-out</th>
                            <td>
                                <input type="text" name="ui_label_checkout" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_checkout', 'Check-out')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Suffisso Giorno (Check-in/out)</th>
                            <td>
                                <input type="text" name="ui_label_check_suffix" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_check_suffix', ' (sabato)')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Costo Totale</th>
                            <td>
                                <input type="text" name="ui_label_total_cost" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_total_cost', 'Totale soggiorno')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Acconto</th>
                            <td>
                                <input type="text" name="ui_label_deposit" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_deposit', 'Acconto (30%)')); ?>" style="width:100%; max-width: 400px;">
                                <p class="description">Puoi usare <code>{deposit_percent}</code> per mostrare la percentuale corrente.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Label Saldo</th>
                            <td>
                                <input type="text" name="ui_label_remaining" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_remaining', 'Saldo in struttura')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Intestatario</th>
                            <td>
                                <input type="text" name="ui_label_owner" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_owner', 'Intestatario')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label IBAN</th>
                            <td>
                                <input type="text" name="ui_label_iban" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_iban', 'IBAN')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Label Importo</th>
                            <td>
                                <input type="text" name="ui_label_amount" value="<?php echo esc_attr(get_option('paguro_msg_ui_label_amount', 'Importo')); ?>" style="width:100%; max-width: 300px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Upload e Distinta</h3>
                    <table class="form-table">
                        <tr>
                            <th>Testo Dropzone Upload</th>
                            <td>
                                <input type="text" name="ui_upload_drop" value="<?php echo esc_attr(get_option('paguro_msg_ui_upload_drop', 'Trascina qui il file o seleziona')); ?>" style="width:100%; max-width: 800px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Upload Riuscito</th>
                            <td>
                                <input type="text" name="ui_upload_success_title" value="<?php echo esc_attr(get_option('paguro_msg_ui_upload_success_title', 'Distinta ricevuta')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Upload Riuscito</th>
                            <td>
                                <textarea name="ui_upload_success_text" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_upload_success_text', 'Date bloccate in attesa di verifica.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Avviso Distinta in Validazione</th>
                            <td>
                                <input type="text" name="ui_receipt_pending" value="<?php echo esc_attr(get_option('paguro_msg_ui_receipt_pending', 'Distinta in verifica (entro 24h).')); ?>" style="width:100%; max-width: 700px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Distinta Caricata</th>
                            <td>
                                <textarea name="ui_receipt_uploaded_text" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_receipt_uploaded_text', 'Distinta ricevuta. Verifica entro 24h.')); ?></textarea>
                                <p class="description">Le nuove righe vengono convertite in <code>&lt;br&gt;</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone "Visualizza Distinta"</th>
                            <td>
                                <input type="text" name="ui_receipt_view_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_receipt_view_cta', 'Vedi distinta')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Cancellazione</h3>
                    <table class="form-table">
                        <tr>
                            <th>Label IBAN Rimborso</th>
                            <td>
                                <input type="text" name="ui_cancel_label" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_label', 'IBAN rimborso *')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Placeholder IBAN</th>
                            <td>
                                <input type="text" name="ui_cancel_placeholder" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_placeholder', 'IT00X0000000000000000000000')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Testo Informativo IBAN</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_cancel_help', "Con l'invio rinunci al soggiorno. Rimborso sul conto indicato."),
                                    'ui_cancel_help',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Puoi usare <code>{guest_name}</code> e <code>{guest_email}</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Conferma Richiesta Cancellazione (Overlay)</th>
                            <td>
                                <textarea name="ui_cancel_confirm" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_confirm', "Confermi la richiesta di cancellazione? Rimborso sul conto indicato.")); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Conferma Apertura Settimana (Multi)</th>
                            <td>
                                <textarea name="ui_group_week_confirm" rows="3" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_group_week_confirm', 'Attenzione: se annulli una settimana, il preventivo verrà ricalcolato e lo sconto multi‑settimana non sarà applicato.')); ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone Cancella Prenotazione</th>
                            <td>
                                <input type="text" name="ui_cancel_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_cancel_cta', 'Richiedi cancellazione')); ?>" style="width:100%; max-width: 400px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Nota Scadenza Cancellazione</th>
                            <td>
                                <textarea name="ui_cancel_deadline_note" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_deadline_note', 'Cancellazione possibile entro {cancel_deadline}.')); ?></textarea>
                                <p class="description">Usa <code>{cancel_deadline}</code> per la data limite.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Cancellazione Non Disponibile (Blocco)</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_cancel_unavailable', 'Cancellazione non disponibile (entro {cancel_deadline}).'),
                                    'ui_cancel_unavailable',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Usa <code>{cancel_deadline}</code> per la data limite.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Richiesta Cancellazione Inviata</th>
                            <td>
                                <textarea name="ui_cancel_requested_notice" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_cancel_requested_notice', 'Cancellazione richiesta. Riceverai conferma email.')); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <h3>Testi UI - Waitlist</h3>
                    <table class="form-table">
                        <tr>
                            <th>Titolo Sezione "Dettagli Iscrizione"</th>
                            <td>
                                <input type="text" name="ui_waitlist_section_info" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_section_info', 'Dettagli iscrizione')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Date Richieste"</th>
                            <td>
                                <input type="text" name="ui_waitlist_section_dates" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_section_dates', 'Date richieste')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Titolo Sezione "Cronologia"</th>
                            <td>
                                <input type="text" name="ui_waitlist_section_history" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_section_history', 'Cronologia')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Bottone "Esci dalla Lista d'Attesa"</th>
                            <td>
                                <input type="text" name="ui_waitlist_exit_cta" value="<?php echo esc_attr(get_option('paguro_msg_ui_waitlist_exit_cta', 'Esci dalla lista')); ?>" style="width:100%; max-width: 500px;">
                            </td>
                        </tr>
                        <tr>
                            <th>Conferma Uscita Lista d'Attesa (Overlay)</th>
                            <td>
                                <textarea name="ui_waitlist_exit_confirm" rows="2" style="width:100%; max-width: 900px;"><?php echo esc_textarea(get_option('paguro_msg_ui_waitlist_exit_confirm', "Confermi l'uscita dalla lista d'attesa?")); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <h3>Login Area Riservata</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contenuto Login</th>
                            <td>
                                <?php
                                wp_editor(
                                    get_option('paguro_msg_ui_login_page', '<div>...</div>'),
                                    'ui_login_page',
                                    ['textarea_rows' => 8]
                                );
                                ?>
                                <p class="description">Deve includere <code>{nonce_field}</code> e <code>{token}</code>.</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="paguro_save_web_templates" class="button button-primary">Salva Web Templates</button>
                    </p>
                </form>
            </div>
        <?php } ?>

        <!-- TAB: IMPOSTAZIONI -->
        <?php if ($current_tab === 'settings') { ?>
            <h2>Configurazione Plugin</h2>

            <form method="POST" class="card">
                <?php wp_nonce_field('paguro_admin_opts'); ?>

                <table class="form-table">
                    <tr>
                        <th>Inizio Stagione</th>
                        <td>
                            <input type="date" name="season_start" value="<?php echo esc_attr(get_option('paguro_season_start', '2026-06-01')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Fine Stagione</th>
                        <td>
                            <input type="date" name="season_end" value="<?php echo esc_attr(get_option('paguro_season_end', '2026-09-30')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>% Acconto</th>
                        <td>
                            <input type="number" name="deposit_percent" value="<?php echo esc_attr(get_option('paguro_deposit_percent', 30)); ?>" min="1" max="100"> %
                        </td>
                    </tr>
                    <tr>
                        <th>Sconto Multi‑Settimana</th>
                        <td>
                            <?php
                            $discount_raw = get_option('paguro_group_discount_map', '');
                            $discount_fmt = function_exists('paguro_format_group_discount_map') ? paguro_format_group_discount_map($discount_raw) : $discount_raw;
                            ?>
                            <input type="text" name="group_discount_map" value="<?php echo esc_attr($discount_fmt); ?>" style="width: 100%; max-width: 400px;">
                            <p class="description">Formato: <code>2=50, 3=120, 4=200</code> (importo in €). Si applica se settimane ≥ 2.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>IBAN Bonifico</th>
                        <td>
                            <input type="text" name="bank_iban" value="<?php echo esc_attr(get_option('paguro_bank_iban', '')); ?>" style="font-family: monospace;">
                        </td>
                    </tr>
                    <tr>
                        <th>Intestatario</th>
                        <td>
                            <input type="text" name="bank_owner" value="<?php echo esc_attr(get_option('paguro_bank_owner', '')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>URL API ChatBot</th>
                        <td>
                            <input type="url" name="paguro_api_url" value="<?php echo esc_attr(get_option('paguro_api_url', 'https://api.example.com')); ?>" style="width: 100%; max-width: 400px;">
                        </td>
                    </tr>
                    <tr>
                        <th>Provider AI</th>
                        <td>
                            <?php $ai_provider = get_option('paguro_ai_provider', 'api'); ?>
                            <select name="ai_provider">
                                <option value="api" <?php selected($ai_provider, 'api'); ?>>API Remota</option>
                                <option value="ollama" <?php selected($ai_provider, 'ollama'); ?>>Ollama (Locale)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Ollama URL</th>
                        <td>
                            <input type="url" name="ollama_url" value="<?php echo esc_attr(get_option('paguro_ollama_url', 'http://localhost:11434')); ?>" style="width: 100%; max-width: 400px;">
                        </td>
                    </tr>
                    <tr>
                        <th>Ollama Model</th>
                        <td>
                            <input type="text" name="ollama_model" value="<?php echo esc_attr(get_option('paguro_ollama_model', 'llama3.1')); ?>" style="width: 100%; max-width: 300px;">
                        </td>
                    </tr>
                    <tr>
                        <th>reCAPTCHA - Site Key</th>
                        <td>
                            <input type="text" name="recaptcha_site" value="<?php echo esc_attr(get_option('paguro_recaptcha_site', '')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>reCAPTCHA - Secret Key</th>
                        <td>
                            <input type="password" name="recaptcha_secret" value="<?php echo esc_attr(get_option('paguro_recaptcha_secret', '')); ?>">
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="paguro_save_opts" class="button button-primary">Salva Configurazione</button>
                </p>
            </form>

        <?php } ?>

        <!-- TAB: IA -->
        <?php if ($current_tab === 'ai') { ?>
            <h2>IA - Risposte Automatiche</h2>

            <div class="card paguro-full-width-card">
                <p style="color: #666;">Definisci parole chiave e risposte personalizzate. Se il messaggio dell'utente contiene una delle parole chiave, il bot risponderà con il testo associato.</p>

                <form method="POST" id="paguro-ai-form">
                    <?php wp_nonce_field('paguro_ai_rules'); ?>

                    <div id="paguro-ai-rules">
                        <?php
                        $ai_rules = get_option('paguro_ai_rules', []);
                        if (!is_array($ai_rules)) $ai_rules = [];
                        if (empty($ai_rules)) {
                            $ai_rules = [['keywords' => '', 'response' => '']];
                        }
                        foreach ($ai_rules as $idx => $rule) {
                            $kw = esc_attr($rule['keywords'] ?? '');
                            ?>
                            <div class="paguro-ai-rule">
                                <label>Parole chiave (separate da virgola)</label>
                                <input type="text" name="ai_rule_keywords[]" value="<?php echo $kw; ?>" style="width:100%; max-width: 800px;">
                                <label style="margin-top:8px;">Risposta (HTML consentito)</label>
                                <?php
                                $editor_id = 'ai_rule_response_' . $idx;
                                wp_editor(
                                    $rule['response'] ?? '',
                                    $editor_id,
                                    ['textarea_name' => 'ai_rule_response[]', 'textarea_rows' => 4]
                                );
                                ?>
                                <button type="button" class="button paguro-ai-remove">Rimuovi</button>
                            </div>
                            <?php
                        }
                        ?>
                    </div>

                    <p>
                        <button type="button" class="button" id="paguro-ai-add">Aggiungi regola</button>
                    </p>

                    <p>
                        <button type="submit" name="paguro_save_ai_rules" class="button button-primary">Salva Regole IA</button>
                    </p>
                </form>
            </div>

            <script>
                document.addEventListener('click', function(e) {
                    if (e.target && e.target.id === 'paguro-ai-add') {
                        var container = document.getElementById('paguro-ai-rules');
                        if (!container) return;
                        var block = document.createElement('div');
                        block.className = 'paguro-ai-rule';
                        var count = container.querySelectorAll('.paguro-ai-rule').length;
                        var editorId = 'ai_rule_response_new_' + count;
                        block.innerHTML = '' +
                            '<label>Parole chiave (separate da virgola)</label>' +
                            '<input type=\"text\" name=\"ai_rule_keywords[]\" value=\"\" style=\"width:100%; max-width: 800px;\">' +
                            '<label style=\"margin-top:8px;\">Risposta (HTML consentito)</label>' +
                            '<textarea id=\"' + editorId + '\" name=\"ai_rule_response[]\" rows=\"4\" style=\"width:100%; max-width: 900px;\"></textarea>' +
                            '<button type=\"button\" class=\"button paguro-ai-remove\">Rimuovi</button>';
                        container.appendChild(block);
                        if (window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function') {
                            window.wp.editor.initialize(editorId, {
                                tinymce: true,
                                quicktags: true,
                                mediaButtons: false
                            });
                        }
                    }

                    if (e.target && e.target.classList.contains('paguro-ai-remove')) {
                        var rule = e.target.closest('.paguro-ai-rule');
                        if (rule) rule.remove();
                    }
                });
            </script>
        <?php } ?>

    </div>
</div>

<style>
.paguro-admin {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.paguro-admin h1 { margin-bottom: 30px; }
.paguro-admin .card { background: white; border: 1px solid #ccc; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
.paguro-admin .paguro-full-width-card { max-width: none; width: 100%; }
.paguro-admin .paguro-card-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.paguro-admin .paguro-card-body.is-collapsed { display: none; }
.paguro-admin .paguro-manual-form { max-width: none; }
.paguro-admin .paguro-manual-form .form-table { width: 100%; display: grid; grid-template-columns: repeat(2, minmax(240px, 1fr)); gap: 12px 20px; }
.paguro-admin .paguro-manual-form .form-table tr { display: contents; }
.paguro-admin .paguro-manual-form .form-table th,
.paguro-admin .paguro-manual-form .form-table td { display: block; padding: 0; margin: 0; }
.paguro-admin .paguro-manual-form .form-table th { font-weight: 600; }
.paguro-admin .paguro-manual-form .form-table td input,
.paguro-admin .paguro-manual-form .form-table td select,
.paguro-admin .paguro-manual-form .form-table td textarea { width: 100%; }
.paguro-admin .paguro-manual-form .form-table .paguro-col-span-2 { grid-column: 1 / -1; }
.paguro-admin .nav-tab-wrapper { border-bottom: 1px solid #ccc; margin: 0 0 20px 0; }
.paguro-admin .nav-tab { color: #0073aa; border: 1px solid transparent; padding: 8px 15px; text-decoration: none; }
.paguro-admin .nav-tab:hover { color: #0073aa; background: #f5f5f5; }
.paguro-admin .nav-tab-active { border-bottom: 3px solid #0073aa; color: #0073aa; }
.paguro-admin .form-table th { width: 200px; }
.paguro-admin button { margin-right: 5px; }
.paguro-admin .button-success { background-color: #28a745; border-color: #28a745; color: white; }
.paguro-admin .button-success:hover { background-color: #218838; }
.paguro-admin .button-danger { background-color: #dc3545; border-color: #dc3545; color: white; }
.paguro-admin .button-danger:hover { background-color: #c82333; }
.paguro-admin table.widefat { margin-bottom: 20px; }
.paguro-admin table.widefat code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
.paguro-admin .paguro-filter-links { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 15px; }
.paguro-admin .paguro-filter-links .button { margin: 0; }
.paguro-admin .paguro-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
    border: 1px solid transparent;
}
.paguro-admin .paguro-badge--green { background: #d9f5df; color: #136b2e; border-color: #a5e2b8; }
.paguro-admin .paguro-badge--yellow { background: #fff4c2; color: #7a5a00; border-color: #f1d26b; }
.paguro-admin .paguro-badge--orange { background: #ffe1c2; color: #8a3d00; border-color: #ffc28a; }
.paguro-admin .paguro-badge--red { background: #ffd6d6; color: #7a0000; border-color: #f0a0a0; }
.paguro-admin .paguro-badge--gray { background: #eef1f4; color: #505a64; border-color: #d6dde5; }
.paguro-admin .paguro-badge--blue { background: #dbeafe; color: #1e3a8a; border-color: #bfdbfe; }
.paguro-finance-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    margin: 12px 0 18px;
}
.paguro-finance-card {
    border: 1px solid #e2e8f0;
    background: #fff;
    border-radius: 8px;
    padding: 12px 14px;
}
.paguro-finance-card .label {
    display: block;
    font-size: 12px;
    color: #667085;
    margin-bottom: 6px;
}
.paguro-finance-card .value {
    font-size: 16px;
    color: #111827;
}
.paguro-finance-table td,
.paguro-finance-table th {
    vertical-align: top;
}
.paguro-admin .paguro-ai-rule {
    margin: 16px 0;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fafafa;
}
.paguro-admin .paguro-ai-rule label {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}
.paguro-admin .paguro-ai-rule .paguro-ai-remove {
    margin-top: 10px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.querySelector('.paguro-toggle-manual');
    var body = document.getElementById('paguro-manual-body');
    if (!toggleBtn || !body) return;

    toggleBtn.addEventListener('click', function () {
        var isCollapsed = body.classList.toggle('is-collapsed');
        toggleBtn.setAttribute('aria-expanded', (!isCollapsed).toString());
        toggleBtn.textContent = isCollapsed ? 'Apri' : 'Chiudi';
    });

    document.querySelectorAll('.paguro-group-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var group = btn.getAttribute('data-group');
            if (!group) return;
            document.querySelectorAll('.paguro-group-child[data-group="' + group + '"]').forEach(function(row) {
                row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
            });
        });
    });
});
</script>
