<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$base_url = admin_url('admin.php?page=paguro-booking');
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt   = $wpdb->prefix . 'paguro_apartments';
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'bookings';

if (isset($_POST['paguro_save_opts']) && check_admin_referer('paguro_admin_opts')) {
    update_option('paguro_season_start', sanitize_text_field(stripslashes($_POST['season_start'])));
    update_option('paguro_season_end', sanitize_text_field(stripslashes($_POST['season_end'])));
    update_option('paguro_deposit_percent', intval($_POST['deposit_percent']));
    update_option('paguro_bank_iban', sanitize_text_field(stripslashes($_POST['bank_iban'])));
    update_option('paguro_bank_owner', sanitize_text_field(stripslashes($_POST['bank_owner'])));
    update_option('paguro_page_slug', sanitize_text_field(stripslashes($_POST['page_slug'])));

    update_option('paguro_recaptcha_site', sanitize_text_field(stripslashes($_POST['recaptcha_site'])));
    update_option('paguro_recaptcha_secret', sanitize_text_field(stripslashes($_POST['recaptcha_secret'])));
    update_option('paguro_api_url', esc_url_raw(stripslashes($_POST['paguro_api_url'])));
    
    update_option('paguro_txt_email_request_subj', sanitize_text_field(stripslashes($_POST['email_req_subj'])));
    update_option('paguro_txt_email_request_body', wp_kses_post(stripslashes($_POST['email_req_body'])));
    update_option('paguro_txt_email_receipt_subj', sanitize_text_field(stripslashes($_POST['email_rec_subj'])));
    update_option('paguro_txt_email_receipt_body', wp_kses_post(stripslashes($_POST['email_rec_body'])));
    update_option('paguro_txt_email_confirm_subj', sanitize_text_field(stripslashes($_POST['email_conf_subj'])));
    update_option('paguro_txt_email_confirm_body', wp_kses_post(stripslashes($_POST['email_conf_body'])));
    update_option('paguro_txt_email_race_lost_subj', sanitize_text_field(stripslashes($_POST['email_lost_subj'])));
    update_option('paguro_txt_email_race_lost_body', wp_kses_post(stripslashes($_POST['email_lost_body'])));
    update_option('paguro_txt_email_refund_ok_subj', sanitize_text_field(stripslashes($_POST['email_refund_ok_subj'])));
    update_option('paguro_txt_email_refund_ok_body', wp_kses_post(stripslashes($_POST['email_refund_ok_body'])));
    update_option('paguro_msg_email_cancel_subj', sanitize_text_field(stripslashes($_POST['email_cancel_subj'])));
    update_option('paguro_msg_email_cancel_body', wp_kses_post(stripslashes($_POST['email_cancel_body'])));

    update_option('paguro_msg_email_adm_new_req_subj', sanitize_text_field(stripslashes($_POST['adm_new_req_subj'])));
    update_option('paguro_msg_email_adm_new_req_body', wp_kses_post(stripslashes($_POST['adm_new_req_body'])));
    update_option('paguro_msg_email_adm_receipt_subj', sanitize_text_field(stripslashes($_POST['adm_receipt_subj'])));
    update_option('paguro_msg_email_adm_receipt_body', wp_kses_post(stripslashes($_POST['adm_receipt_body'])));
    update_option('paguro_msg_email_adm_refund_subj', sanitize_text_field(stripslashes($_POST['adm_refund_subj'])));
    update_option('paguro_msg_email_adm_refund_body', wp_kses_post(stripslashes($_POST['adm_refund_body'])));
    update_option('paguro_msg_email_adm_wait_subj', sanitize_text_field(stripslashes($_POST['adm_wait_subj'])));
    update_option('paguro_msg_email_adm_wait_body', wp_kses_post(stripslashes($_POST['adm_wait_body'])));
    update_option('paguro_msg_email_adm_cancel_subj', sanitize_text_field(stripslashes($_POST['adm_cancel_subj'])));
    update_option('paguro_msg_email_adm_cancel_body', wp_kses_post(stripslashes($_POST['adm_cancel_body'])));

    update_option('paguro_msg_ui_summary_page', wp_kses_post(stripslashes($_POST['ui_summary_page'])));
    update_option('paguro_msg_ui_login_page', wp_kses_post(stripslashes($_POST['ui_login_page'])));
    update_option('paguro_msg_ui_privacy_notice', wp_kses_post(stripslashes($_POST['ui_privacy'])));
    update_option('paguro_msg_ui_refund_sent', sanitize_text_field(stripslashes($_POST['ui_refund_sent'])));
    update_option('paguro_msg_ui_wait_list', sanitize_text_field(stripslashes($_POST['ui_wait_list'])));
    update_option('paguro_msg_ui_race_warning', wp_kses_post(stripslashes($_POST['ui_race_warning'])));
    update_option('paguro_msg_ui_social_pressure', wp_kses_post(stripslashes($_POST['ui_social_pressure'])));
    update_option('paguro_msg_ui_upload_instruction', sanitize_text_field(stripslashes($_POST['ui_upload_instr'])));
    update_option('paguro_msg_ui_upload_btn', sanitize_text_field(stripslashes($_POST['ui_upload_btn'])));
    update_option('paguro_msg_ui_checkout_title', sanitize_text_field(stripslashes($_POST['ui_checkout_title'])));

    update_option('paguro_js_upload_loading', sanitize_text_field(stripslashes($_POST['js_upload_loading'])));
    update_option('paguro_js_upload_success', sanitize_text_field(stripslashes($_POST['js_upload_success'])));
    update_option('paguro_js_upload_error', sanitize_text_field(stripslashes($_POST['js_upload_error'])));
    update_option('paguro_js_form_success', sanitize_text_field(stripslashes($_POST['js_form_success'])));
    update_option('paguro_js_btn_book', sanitize_text_field(stripslashes($_POST['js_btn_book'])));

    echo '<div class="notice notice-success"><p>Configurazione Salvata.</p></div>';
}

if (isset($_POST['paguro_apt_action'])) {
    if (!check_admin_referer('paguro_apt_nonce', 'paguro_apt_nonce')) wp_die('Sicurezza.');
    if ($_POST['paguro_apt_action'] === 'add_apt') { $name = sanitize_text_field($_POST['apt_name']); if ($name) $wpdb->insert($table_apt, ['name' => $name, 'base_price' => 500]); }
    if ($_POST['paguro_apt_action'] === 'delete_apt') { $id = intval($_POST['apt_id']); $wpdb->delete($table_apt, ['id' => $id]); }
    if ($_POST['paguro_apt_action'] === 'save_pricing') { 
        $id = intval($_POST['apt_id']); $prices = $_POST['price'] ?? []; 
        $clean_prices = []; foreach($prices as $k => $v) { $clean_prices[sanitize_text_field($k)] = floatval($v); }
        $json = json_encode($clean_prices);
        $res = $wpdb->update($table_apt, ['pricing_json' => $json], ['id' => $id]);
        if ($res === false) echo '<div class="notice notice-error"><p>Errore SQL.</p></div>'; else echo '<div class="notice notice-success"><p>Listino Aggiornato!</p></div>'; 
    }
}

if (isset($_POST['paguro_action'])) {
    if (!check_admin_referer('paguro_admin_action', 'paguro_nonce')) wp_die('Sicurezza.');
    $req_id = intval($_POST['request_id']);
    $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id=%d", $req_id));
    
    if ($_POST['paguro_action'] === 'anonymize_admin') {
        $cleaned_log = json_encode([['time'=>current_time('mysql'), 'action'=>'GDPR_WIPE', 'details'=>'Data wiped by Admin']]);
        $wpdb->update($table_avail, ['guest_name'=>'Anonimo (Admin)','guest_email'=>'deleted@admin.act','guest_phone'=>'0000','guest_notes'=>'', 'history_log'=>$cleaned_log], ['id'=>$req_id]);
        echo '<div class="notice notice-success"><p>Anonimizzato.</p></div>';
    }

    if ($w) {
        $apt_row = $wpdb->get_row($wpdb->prepare("SELECT name, pricing_json, base_price FROM $table_apt WHERE id=%d", $w->apartment_id));
        $tot = paguro_calculate_quote($w->apartment_id, $w->date_start, $w->date_end);
        $dep_percent = intval(get_option('paguro_deposit_percent', 30)) / 100;
        $dep = ceil($tot * $dep_percent);
        $competitors = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_avail WHERE apartment_id=%d AND status=2 AND receipt_url IS NULL AND id!=%d AND (date_start < %s AND date_end > %s)", $w->apartment_id, $w->id, $w->date_end, $w->date_start));

        $ph = [
            'guest_name' => $w->guest_name, 'date_start' => date('d/m/Y',strtotime($w->date_start)), 'date_end' => date('d/m/Y',strtotime($w->date_end)), 
            'apt_name' => ucfirst($apt_row->name ?? ''), 'link_riepilogo' => site_url("/".get_option('paguro_page_slug')."/?token={$w->lock_token}"),
            'total_cost' => $tot, 'deposit_cost' => $dep, 'count' => $competitors, 'receipt_url' => $w->receipt_url ?? '#',
            'id' => $w->id, 'guest_phone' => $w->guest_phone
        ];
        
        if ($_POST['paguro_action'] === 'confirm_booking') {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);
            $losers = $wpdb->get_results($wpdb->prepare("SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status!=1 AND (date_start<%s AND date_end>%s)", $w->apartment_id, $req_id, $w->date_end, $w->date_start));
            foreach ($losers as $l) $wpdb->delete($table_avail, ['id' => $l->id]);
            $subj = paguro_parse_template(get_option('paguro_txt_email_confirm_subj'), $ph); 
            $body = paguro_parse_template(get_option('paguro_txt_email_confirm_body'), $ph);
            if ($w->guest_email) paguro_send_html_email($w->guest_email, $subj, $body);
            paguro_add_history($req_id, 'ADMIN_CONFIRM', 'Confermata da Admin');
            echo '<div class="notice notice-success"><p>Confermata.</p></div>';
        }
        if ($_POST['paguro_action'] === 'resend_email') {
            if ($w->guest_email) {
                $subj = paguro_parse_template(get_option('paguro_txt_email_request_subj'), $ph); $body = paguro_parse_template(get_option('paguro_txt_email_request_body'), $ph);
                paguro_send_html_email($w->guest_email, $subj, $body); paguro_add_history($req_id, 'ADMIN_RESEND_REQ', 'Reinviata mail richiesta'); echo '<div class="notice notice-success"><p>Mail reinviata.</p></div>';
            }
        }
        if ($_POST['paguro_action'] === 'resend_receipt_ack') {
            if ($w->guest_email) {
                $subj = paguro_parse_template(get_option('paguro_txt_email_receipt_subj'), $ph); $body = paguro_parse_template(get_option('paguro_txt_email_receipt_body'), $ph);
                paguro_send_html_email($w->guest_email, $subj, $body); paguro_add_history($req_id, 'ADMIN_RESEND_ACK', 'Reinviata mail distinta'); echo '<div class="notice notice-success"><p>Mail reinviata.</p></div>';
            }
        }
        if ($_POST['paguro_action'] === 'notify_refund_user') {
            if ($w->guest_email) {
                $subj = paguro_parse_template(get_option('paguro_txt_email_refund_ok_subj'), $ph); $body = paguro_parse_template(get_option('paguro_txt_email_refund_ok_body'), $ph);
                paguro_send_html_email($w->guest_email, $subj, $body); paguro_add_history($req_id, 'ADMIN_NOTIFY_REFUND', 'Notificato Rimborso al cliente'); echo '<div class="notice notice-success"><p>Notifica Rimborso Inviata!</p></div>';
            }
        }
        if ($_POST['paguro_action'] === 'delete_row') { $wpdb->delete($table_avail, ['id' => $req_id]); echo '<div class="notice notice-success"><p>Eliminata.</p></div>'; }
    }
}

function paguro_render_timeline() {
    global $wpdb; 
    $s_start = new DateTime(get_option('paguro_season_start', '2026-06-01')); 
    $s_end = new DateTime(get_option('paguro_season_end', '2026-09-30'));
    $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
    $bookings = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE status IN (1,2)");
    $occupancy = []; foreach($bookings as $b) { $curr = new DateTime($b->date_start); $end = new DateTime($b->date_end); while($curr < $end) { $occupancy[$b->apartment_id][$curr->format('Y-m-d')] = ['status' => $b->status, 'name' => $b->guest_name]; $curr->modify('+1 day'); } }
    $day_map = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Gio','Fri'=>'Ven','Sat'=>'Sab','Sun'=>'Dom']; $month_map = ['06'=>'Giugno','07'=>'Luglio','08'=>'Agosto','09'=>'Settembre', '10'=>'Ottobre', '05'=>'Maggio'];
    echo '<div style="overflow-x:auto; background:#fff; padding:10px; border:1px solid #ccc; margin-bottom:20px;">';
    echo '<table style="border-collapse:collapse; width:100%; min-width:1200px; font-size:10px;">';
    echo '<tr><td style="width:100px; border:none;"></td>';
    $temp = clone $s_start;
    while($temp <= $s_end) { 
        $m = $temp->format('m'); $days_in_month = (int)$temp->format('t');
        if ($temp->format('d') != '01') { $days_in_month = (int)$days_in_month - (int)$temp->format('d') + 1; }
        $month_end = clone $temp; $month_end->modify('last day of this month');
        if ($month_end > $s_end) { $diff = $temp->diff($s_end); $days_in_month = $diff->days + 1; }
        echo "<td colspan='{$days_in_month}' style='border:1px solid #999; text-align:center; background:#eee; font-weight:bold; font-size:12px;'>".($month_map[$m]??$m)."</td>"; $temp->modify('first day of next month'); 
    }
    echo '</tr><tr><td style="width:100px;"><strong>Appartamento</strong></td>';
    $p = new DatePeriod($s_start, new DateInterval('P1D'), $s_end->modify('+1 day'));
    foreach($p as $dt) { $d=$day_map[$dt->format('D')]; $n=$dt->format('d'); $bg=($dt->format('N')>=6)?'#ddd':'#fff'; echo "<td style='border:1px solid #eee; border-bottom:1px solid #999; text-align:center; background:$bg; width:20px; padding:2px;'>$d<br>$n</td>"; }
    echo '</tr>';
    foreach($apts as $apt) {
        echo "<tr><td style='border:1px solid #ddd; padding:5px; border-right:2px solid #999;'><strong>".esc_html($apt->name)."</strong></td>";
        foreach($p as $dt) {
            $ymd = $dt->format('Y-m-d'); $cell_data = isset($occupancy[$apt->id][$ymd]) ? $occupancy[$apt->id][$ymd] : null; $style = ''; $title = '';
            if ($cell_data) { if ($cell_data['status'] == 1) { $style = "background:#dc3545;"; $title="Occ: ".$cell_data['name']; } elseif ($cell_data['status'] == 2) { $style = "background:#ffc107;"; $title="Pend: ".$cell_data['name']; } }
            echo "<td style='border:1px solid #eee; border-right:1px solid #ddd; $style' title='".esc_attr($title)."'></td>";
        }
        echo "</tr>";
    }
    echo '</table></div>';
}
?>

<div class="wrap">
    <h1>Gestione Paguro v3.0.4</h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo $base_url.'&tab=bookings'; ?>" class="nav-tab <?php echo $current_tab=='bookings'?'nav-tab-active':''; ?>">📅 Prenotazioni</a>
        <a href="<?php echo $base_url.'&tab=apartments'; ?>" class="nav-tab <?php echo $current_tab=='apartments'?'nav-tab-active':''; ?>">🏠 Appartamenti</a>
        <a href="<?php echo $base_url.'&tab=settings'; ?>" class="nav-tab <?php echo $current_tab=='settings'?'nav-tab-active':''; ?>">⚙️ Configurazione</a>
    </nav>
    <br>

    <?php if ($current_tab == 'apartments'): ?>
        <div style="background:#fff; padding:15px; border:1px solid #ccc; margin-bottom:20px; max-width: 600px;">
            <h3>Aggiungi Appartamento</h3>
            <form method="post" style="display:flex; gap:10px;">
                <?php wp_nonce_field('paguro_apt_nonce', 'paguro_apt_nonce'); ?>
                <input type="hidden" name="paguro_apt_action" value="add_apt">
                <input type="text" name="apt_name" placeholder="Nome (es. Delfino)" required>
                <button type="submit" class="button button-primary">Aggiungi</button>
            </form>
        </div>
        <?php 
        $apts = $wpdb->get_results("SELECT * FROM $table_apt");
        $edit_id = isset($_GET['edit_prices']) ? intval($_GET['edit_prices']) : 0;
        if ($edit_id > 0) {
            $apt = $wpdb->get_row("SELECT * FROM $table_apt WHERE id = $edit_id");
            $saved_prices = ($apt && $apt->pricing_json) ? json_decode($apt->pricing_json, true) : [];
            echo '<div style="background:#e7f9e7;padding:10px;font-size:11px;border:1px solid green; margin-bottom:10px;">✅ MODIFICA LISTINO: '.ucfirst($apt->name).'</div>';
            $s_start = new DateTime(get_option('paguro_season_start', '2026-06-01')); $s_end = new DateTime(get_option('paguro_season_end', '2026-09-30'));
            if ($s_start->format('N') != 6) $s_start->modify('next saturday');
            $period = new DatePeriod($s_start, new DateInterval('P1W'), $s_end);
            ?>
            <a href="<?php echo $base_url.'&tab=apartments'; ?>" class="button">« Torna</a>
            <form method="post" style="margin-top:15px;">
                <?php wp_nonce_field('paguro_apt_nonce', 'paguro_apt_nonce'); ?>
                <input type="hidden" name="paguro_apt_action" value="save_pricing"><input type="hidden" name="apt_id" value="<?php echo $edit_id; ?>">
                <table class="wp-list-table widefat fixed striped"><thead><tr><th>Settimana</th><th>Prezzo (€)</th><th>Azioni</th></tr></thead><tbody>
                <?php $current_month = ''; foreach ($period as $dt) { 
                    $ws = $dt->format('Y-m-d'); $wl = $dt->format('d/m/Y'); $val = isset($saved_prices[$ws]) ? $saved_prices[$ws] : $apt->base_price; $mc = $dt->format('m');
                    if ($mc != $current_month) { $current_month = $mc; echo "<tr style='background:#e5e5e5;'><td colspan='3'><strong>Mese: $current_month</strong> <button type='button' class='button button-small copy-btn' data-month='$current_month'>Copia</button></td></tr>"; }
                    echo "<tr><td>$wl</td><td><input type='number' name='price[$ws]' value='$val' class='price-input month-$current_month' style='width:100px;'></td><td>-</td></tr>";
                } ?></tbody></table>
                <p><button type="submit" class="button button-primary button-large">Salva Listino</button></p>
            </form>
            <script>jQuery(document).ready(function($){ $('.copy-btn').click(function(){ var m = $(this).data('month'); var inputs = $('.month-'+m); inputs.val(inputs.first().val()); }); });</script>
            <?php
        } else { ?>
            <table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Nome</th><th>Prezzo Base</th><th>Azioni</th></tr></thead><tbody>
                <?php foreach($apts as $a): ?><tr><td><?php echo $a->id; ?></td><td><strong><?php echo ucfirst($a->name); ?></strong></td><td>€<?php echo $a->base_price; ?></td><td><a href="<?php echo $base_url.'&tab=apartments&edit_prices='.$a->id; ?>" class="button button-small">Modifica Listino</a> <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare?');"><?php wp_nonce_field('paguro_apt_nonce', 'paguro_apt_nonce'); ?><input type="hidden" name="paguro_apt_action" value="delete_apt"><input type="hidden" name="apt_id" value="<?php echo $a->id; ?>"><button type="submit" class="button button-small" style="color:red;border-color:red;">Elimina</button></form></td></tr><?php endforeach; ?>
            </tbody></table>
        <?php } ?>

    <?php elseif ($current_tab == 'settings'): ?>
        <style>
            .paguro-main-layout { display:flex; gap:20px; align-items: flex-start; }
            .paguro-content-area { flex:3; min-width:0; }
            .paguro-sidebar { flex:1; min-width:280px; position:sticky; top:40px; }
            .paguro-sect { background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,0.04); } 
            .paguro-sect h3 { margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; } 
            .paguro-field-group { margin-bottom:15px; padding-bottom:15px; border-bottom:1px dashed #eee; }
            .paguro-field-group:last-child { border-bottom:none; }
            label { display:block; margin-bottom:5px; font-weight:600; font-size:12px; } 
            textarea { width:100%; height:80px; font-size:12px; }
            .paguro-help-box { background:#f0f6fc; border:1px solid #cce5ff; padding:15px; font-size:12px; }
            .paguro-help-box h4 { margin:0 0 10px 0; color:#005b9f; }
            .paguro-help-list code { display:inline-block; background:rgba(255,255,255,0.5); padding:2px 4px; margin-bottom:4px; cursor:pointer; }
            .paguro-preview-btn { float:right; margin-top:-25px; font-size:11px!important; }
        </style>

        <form method="post" class="paguro-main-layout">
            <?php wp_nonce_field('paguro_admin_opts'); ?>
            
            <div class="paguro-content-area">
                <div class="paguro-sect">
                    <h3>🤖 Generale & API</h3>
                    <p><label>API URL (FQDN)</label><input type="text" id="paguro_api_url" name="paguro_api_url" value="<?php echo esc_attr(get_option('paguro_api_url')); ?>" style="width:100%;"></p>
                    <p><button type="button" id="paguro-test-btn" class="button">⚡ Test Connessione</button><span id="paguro-test-res" style="margin-left:10px;"></span></p>
                    
                    <div style="display:flex; gap:10px; margin-top:10px;">
                        <div style="flex:1"><label>Stagione Inizio</label><input type="date" name="season_start" value="<?php echo esc_attr(get_option('paguro_season_start')); ?>" style="width:100%;"></div>
                        <div style="flex:1"><label>Stagione Fine</label><input type="date" name="season_end" value="<?php echo esc_attr(get_option('paguro_season_end')); ?>" style="width:100%;"></div>
                        <div style="flex:1"><label>Acconto %</label><input type="number" name="deposit_percent" value="<?php echo esc_attr(get_option('paguro_deposit_percent')); ?>" style="width:100%;"></div>
                    </div>
                    
                    <div style="display:flex; gap:10px; margin-top:10px;">
                        <div style="flex:1"><label>IBAN (per template)</label><input type="text" name="bank_iban" value="<?php echo esc_attr(get_option('paguro_bank_iban')); ?>" style="width:100%;"></div>
                        <div style="flex:1"><label>Intestatario</label><input type="text" name="bank_owner" value="<?php echo esc_attr(get_option('paguro_bank_owner')); ?>" style="width:100%;"></div>
                    </div>
                    <div style="margin-top:10px;">
                        <label>Slug Pagina Riepilogo (default: riepilogo-prenotazione)</label>
                        <input type="text" name="page_slug" value="<?php echo esc_attr(get_option('paguro_page_slug', 'riepilogo-prenotazione')); ?>" style="width:100%;">
                    </div>

                    <div style="display:flex; gap:10px; margin-top:10px;">
                        <div style="flex:1"><label>ReCaptcha Site Key</label><input type="text" name="recaptcha_site" value="<?php echo esc_attr(get_option('paguro_recaptcha_site')); ?>" style="width:100%;"></div>
                        <div style="flex:1"><label>ReCaptcha Secret</label><input type="password" name="recaptcha_secret" value="<?php echo esc_attr(get_option('paguro_recaptcha_secret')); ?>" style="width:100%;"></div>
                    </div>
                </div>

                <div class="paguro-sect">
                    <h3>✉️ Email Utente</h3>
                    <?php 
                    $user_fields = [
                        ['lbl'=>'1. Richiesta Inviata',       'db_base'=>'paguro_txt_email_request',   'form_base'=>'email_req'],
                        ['lbl'=>'2. Ricevuta Distinta',       'db_base'=>'paguro_txt_email_receipt',   'form_base'=>'email_rec'],
                        ['lbl'=>'3. Conferma Prenotazione',   'db_base'=>'paguro_txt_email_confirm',   'form_base'=>'email_conf'],
                        ['lbl'=>'4. Priorità Persa (Race)',   'db_base'=>'paguro_txt_email_race_lost', 'form_base'=>'email_lost'],
                        ['lbl'=>'5. Conferma Rimborso',       'db_base'=>'paguro_txt_email_refund_ok', 'form_base'=>'email_refund_ok'],
                        ['lbl'=>'6. Cancellazione (GDPR)',    'db_base'=>'paguro_msg_email_cancel',    'form_base'=>'email_cancel']
                    ];
                    foreach($user_fields as $f): 
                        $subj_val = esc_attr(stripslashes(get_option($f['db_base'].'_subj')));
                        $body_val = esc_textarea(stripslashes(get_option($f['db_base'].'_body')));
                    ?>
                    <div class="paguro-field-group">
                        <label><?php echo $f['lbl']; ?></label>
                        <input type="text" id="<?php echo $f['form_base']; ?>_subj" name="<?php echo $f['form_base']; ?>_subj" value="<?php echo $subj_val; ?>" style="width:100%; margin-bottom:5px;" placeholder="Oggetto">
                        <textarea id="<?php echo $f['form_base']; ?>_body" name="<?php echo $f['form_base']; ?>_body"><?php echo $body_val; ?></textarea>
                        <button type="button" class="button paguro-preview-btn" data-s="<?php echo $f['form_base']; ?>_subj" data-b="<?php echo $f['form_base']; ?>_body">👁️ Anteprima</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="paguro-sect">
                    <h3>🕵️ Notifiche Admin</h3>
                    <?php 
                    $adm_fields = [
                        ['lbl'=>'1. Nuova Richiesta',    'db_base'=>'paguro_msg_email_adm_new_req', 'form_base'=>'adm_new_req'],
                        ['lbl'=>'2. Caricamento Distinta', 'db_base'=>'paguro_msg_email_adm_receipt', 'form_base'=>'adm_receipt'],
                        ['lbl'=>'3. Richiesta Rimborso',   'db_base'=>'paguro_msg_email_adm_refund',  'form_base'=>'adm_refund'],
                        ['lbl'=>'4. Utente in Attesa',     'db_base'=>'paguro_msg_email_adm_wait',    'form_base'=>'adm_wait'],
                        ['lbl'=>'5. Cancellazione',        'db_base'=>'paguro_msg_email_adm_cancel',  'form_base'=>'adm_cancel']
                    ];
                    foreach($adm_fields as $f): 
                        $subj_val = esc_attr(stripslashes(get_option($f['db_base'].'_subj')));
                        $body_val = esc_textarea(stripslashes(get_option($f['db_base'].'_body')));
                    ?>
                    <div class="paguro-field-group">
                        <label><?php echo $f['lbl']; ?></label>
                        <input type="text" id="<?php echo $f['form_base']; ?>_subj" name="<?php echo $f['form_base']; ?>_subj" value="<?php echo $subj_val; ?>" style="width:100%; margin-bottom:5px;" placeholder="Oggetto">
                        <textarea id="<?php echo $f['form_base']; ?>_body" name="<?php echo $f['form_base']; ?>_body"><?php echo $body_val; ?></textarea>
                        <button type="button" class="button paguro-preview-btn" data-s="<?php echo $f['form_base']; ?>_subj" data-b="<?php echo $f['form_base']; ?>_body">👁️ Anteprima</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="paguro-sect">
                    <h3>🖥️ Interfaccia Web & Messaggi JS</h3>
                    
                    <div class="paguro-field-group">
                        <label style="color:#0073aa; font-size:14px;">📄 Contenuto Pagina Riepilogo (HTML)</label>
                        <p class="description">Questo testo sostituisce il corpo principale della pagina utente. I box gialli/rossi e l'upload appaiono automaticamente.</p>
                        <textarea id="ui_summary_page" name="ui_summary_page" style="height:300px; width:100%; font-family:monospace;"><?php echo esc_textarea(stripslashes(get_option('paguro_msg_ui_summary_page'))); ?></textarea>
                        <button type="button" class="button paguro-preview-btn" data-b="ui_summary_page" style="margin-top:-28px; float:right;">👁️ Anteprima</button>
                    </div>

                    <div class="paguro-field-group">
                        <label style="color:#0073aa; font-size:14px;">🔐 Contenuto Pagina Login (HTML)</label>
                        <textarea id="ui_login_page" name="ui_login_page" style="height:150px; width:100%; font-family:monospace;"><?php echo esc_textarea(stripslashes(get_option('paguro_msg_ui_login_page'))); ?></textarea>
                        <button type="button" class="button paguro-preview-btn" data-b="ui_login_page" style="margin-top:-28px; float:right;">👁️ Anteprima</button>
                    </div>

                    <div class="paguro-field-group">
                        <label>Titolo Checkout</label><input type="text" id="ui_checkout_title" name="ui_checkout_title" value="<?php echo esc_attr(stripslashes(get_option('paguro_msg_ui_checkout_title'))); ?>" style="width:100%;">
                        <button type="button" class="button paguro-preview-btn" data-b="ui_checkout_title">👁️</button>
                    </div>
                    <div class="paguro-field-group">
                        <label>Testo Privacy (HTML allowed)</label><textarea id="ui_privacy" name="ui_privacy" style="height:100px;"><?php echo esc_textarea(stripslashes(get_option('paguro_msg_ui_privacy_notice'))); ?></textarea>
                        <button type="button" class="button paguro-preview-btn" data-b="ui_privacy">👁️</button>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div>
                            <label>Istruzioni Upload</label><input type="text" name="ui_upload_instr" value="<?php echo esc_attr(stripslashes(get_option('paguro_msg_ui_upload_instruction'))); ?>" style="width:100%;">
                            <label>Bottone Upload</label><input type="text" name="ui_upload_btn" value="<?php echo esc_attr(stripslashes(get_option('paguro_msg_ui_upload_btn'))); ?>" style="width:100%;">
                            <label>Alert Race Condition</label>
                            <textarea id="ui_race_warning" name="ui_race_warning" style="height:80px; width:100%;"><?php echo esc_textarea(stripslashes(get_option('paguro_msg_ui_race_warning'))); ?></textarea>
                            <button type="button" class="button paguro-preview-btn" data-b="ui_race_warning" style="margin-top:-28px; float:right;">👁️</button>
                        </div>
                        <div>
                            <label>Msg: Rimborso Inviato</label><input type="text" name="ui_refund_sent" value="<?php echo esc_attr(stripslashes(get_option('paguro_msg_ui_refund_sent'))); ?>" style="width:100%;">
                            <label>Msg: Lista d'Attesa</label><input type="text" name="ui_wait_list" value="<?php echo esc_attr(stripslashes(get_option('paguro_msg_ui_wait_list'))); ?>" style="width:100%;">
                            <label style="color:#d63638;font-weight:bold;margin-top:8px;">Msg: Pressione Sociale (Alert Giallo)</label>
                            <input type="text" id="ui_social_pressure" name="ui_social_pressure" value="<?php echo esc_attr(stripslashes(get_option('paguro_msg_ui_social_pressure'))); ?>" style="width:100%;">
                            <button type="button" class="button paguro-preview-btn" data-b="ui_social_pressure" style="margin-top:-28px;">👁️</button>
                        </div>
                    </div>
                    <hr>
                    <h4>Javascript Frontend</h4>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <input type="text" name="js_upload_loading" value="<?php echo esc_attr(stripslashes(get_option('paguro_js_upload_loading'))); ?>" placeholder="Loading...">
                        <input type="text" name="js_upload_success" value="<?php echo esc_attr(stripslashes(get_option('paguro_js_upload_success'))); ?>" placeholder="Success...">
                        <input type="text" name="js_upload_error" value="<?php echo esc_attr(stripslashes(get_option('paguro_js_upload_error'))); ?>" placeholder="Error...">
                        <input type="text" name="js_form_success" value="<?php echo esc_attr(stripslashes(get_option('paguro_js_form_success'))); ?>" placeholder="Form OK...">
                        <input type="text" name="js_btn_book" value="<?php echo esc_attr(stripslashes(get_option('paguro_js_btn_book'))); ?>" placeholder="[Prenota]">
                    </div>
                </div>

                <p><button type="submit" name="paguro_save_opts" class="button button-primary button-large">Salva Tutto</button></p>
            </div>

            <div class="paguro-sidebar">
                <div class="paguro-help-box">
                    <h4>ℹ️ Legenda Shortcode</h4>
                    <p>Usa questi codici nei testi. Verranno sostituiti con i dati reali.</p>
                    <div class="paguro-help-list">
                        <strong>Dati Prenotazione:</strong><br>
                        <code>{guest_name}</code> Nome Ospite<br>
                        <code>{guest_email}</code> Email Ospite<br>
                        <code>{guest_phone}</code> Telefono Ospite<br>
                        <code>{apt_name}</code> Nome Appartamento<br>
                        <code>{date_start}</code> Data Arrivo<br>
                        <code>{date_end}</code> Data Partenza<br>
                        <code>{link_riepilogo}</code> Link Pagina Utente<br>
                        <br>
                        <strong>Amministrazione & Soldi:</strong><br>
                        <code>{id}</code> ID Prenotazione<br>
                        <code>{total_cost}</code> Costo Totale (€)<br>
                        <code>{deposit_cost}</code> Acconto (€)<br>
                        <code>{iban}</code> IBAN Admin<br>
                        <code>{intestatario}</code> Intestatario IBAN<br>
                        <code>{receipt_url}</code> Link Distinta<br>
                        <code>{note}</code> Note Utente<br>
                        <code>{expiry}</code> Scadenza Blocco<br>
                        <code>{refund_type}</code> Tipo Rimborso<br>
                        <br>
                        <strong>Speciale (Msg Pressione):</strong><br>
                        <code>{count}</code> Numero Utenti Competitors<br>
                    </div>
                </div>
            </div>
        </form>

        <div id="paguro-preview-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:999999; justify-content:center; align-items:center;">
            <div style="background:#fff; width:600px; max-width:95%; max-height:90vh; overflow-y:auto; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.5);">
                <div style="padding:15px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; background:#f9f9f9;">
                    <h3 style="margin:0;">Anteprima Messaggio</h3>
                    <button type="button" onclick="jQuery('#paguro-preview-modal').hide()" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
                </div>
                <div id="paguro-preview-content" style="padding:20px; line-height:1.6;"></div>
                <div style="padding:15px; background:#f0f0f1; border-top:1px solid #eee; font-size:11px; color:#666; text-align:center;">
                    Dati simulati a scopo dimostrativo.
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            const dummy = {
                guest_name: "Mario Rossi",
                guest_email: "mario@email.test",
                guest_phone: "+39 333 1234567",
                apt_name: "Corallo",
                date_start: "13/06/2026",
                date_end: "20/06/2026",
                link_riepilogo: "#",
                receipt_url: "#",
                total_cost: "500.00",
                deposit_cost: "150.00",
                iban: "IT00X...",
                intestatario: "Nome Cognome",
                note: "Ho effettuato il bonifico ieri.",
                expiry: "20/06/2026 12:00",
                refund_type: "RIMBORSO TOTALE (Entro 15gg)",
                id: "1042",
                count: "3"
            };

            $('.paguro-preview-btn').click(function(){
                var subjId = $(this).data('s');
                var bodyId = $(this).data('b');
                
                var content = "";
                if(subjId) {
                    var s = $('#'+subjId).val();
                    content += "<div style='font-weight:bold; margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:10px;'>Oggetto: " + parseTpl(s) + "</div>";
                }
                var b = $('#'+bodyId).val();
                content += "<div>" + parseTpl(b) + "</div>";

                $('#paguro-preview-content').html(content);
                $('#paguro-preview-modal').css('display', 'flex');
            });

            function parseTpl(str) {
                if(!str) return "";
                for (const [key, value] of Object.entries(dummy)) {
                    str = str.replace(new RegExp('{'+key+'}', 'g'), value);
                }
                return str;
            }

            $('#paguro-test-btn').click(function(e){
                e.preventDefault(); var btn = $(this); var resSpan = $('#paguro-test-res');
                btn.prop('disabled', true).text('Test...'); resSpan.text('').css('color','black');
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'paguro_chat_request', nonce: '<?php echo wp_create_nonce("paguro_chat_nonce"); ?>', message: 'ciao', session_id: 'test_admin_' + Math.random() },
                    success: function(r) { btn.prop('disabled', false).text('⚡ Test Connessione'); if(r.success) resSpan.text('✅ OK: ' + r.data.reply.substring(0,30)).css('color','green'); else resSpan.text('❌ Err: ' + r.data.reply).css('color','red'); },
                    error: function(x) { btn.prop('disabled', false).text('⚡ Test Connessione'); resSpan.text('❌ HTTP Error ' + x.status).css('color','red'); }
                });
            });
        });
        </script>

    <?php else: ?>
        <?php paguro_render_timeline(); ?>
        <?php $rows = $wpdb->get_results("SELECT av.*, apt.name as apt_name FROM $table_avail av JOIN $table_apt apt ON av.apartment_id = apt.id ORDER BY av.created_at DESC"); ?>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead><tr><th style="width:100px;">Stato</th><th>Date</th><th>Ospite</th><th>Note</th><th>Storico</th><th>Azione</th></tr></thead>
            <tbody>
                <?php if(empty($rows)):?><tr><td colspan="6">Nessuna prenotazione.</td></tr><?php else: foreach($rows as $r): 
                    $st_lbl = ($r->status==1) ? 'OK' : (($r->status==3)?'CANC':'Pend');
                    $st_col = ($r->status==1) ? 'green' : (($r->status==3)?'red':'orange');
                    $st = '<span style="color:'.$st_col.';font-weight:bold">'.$st_lbl.'</span>';
                    
                    if (strpos($r->history_log, 'USER_REQ_REFUND') !== false) $st .= '<br><span style="background:#dc3545;color:white;padding:2px 4px;font-size:10px;border-radius:3px;">REQ: RIMBORSO</span>';
                    if (strpos($r->history_log, 'USER_REQ_WAIT') !== false) $st .= '<br><span style="background:#0073aa;color:white;padding:2px 4px;font-size:10px;border-radius:3px;">REQ: ATTESA</span>';
                    
                    $rc_icon = ($r->receipt_url)?' <a href="'.esc_url($r->receipt_url).'" target="_blank" title="Vedi Distinta">📄</a>':''; 
                    $dt_req = date('d/m H:i', strtotime($r->created_at)); $hist_json = htmlspecialchars($r->history_log, ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                    <td><?php echo $st.$rc_icon; ?></td>
                    <td style="font-size:12px;"><?php echo $dt_req; ?><br><small><?php echo date('d/m',strtotime($r->date_start)).' -> '.date('d/m',strtotime($r->date_end)); ?></small></td>
                    <td><strong><?php echo esc_html($r->guest_name); ?></strong><br><small><?php echo esc_html($r->guest_email); ?></small></td>
                    <td style="max-width:200px; font-size:11px;"><em><?php echo nl2br(esc_html($r->guest_notes)); ?></em></td>
                    <td><button class="button button-small" onclick="openHistory(<?php echo $r->id; ?>, '<?php echo $hist_json; ?>')">📜</button></td>
                    <td>
                        <form method="post" style="display:flex;gap:2px;flex-wrap:wrap;">
                            <?php wp_nonce_field('paguro_admin_action','paguro_nonce'); ?>
                            <input type="hidden" name="request_id" value="<?php echo $r->id; ?>">
                            <?php if($r->status==2): ?>
                                <button type="submit" name="paguro_action" value="confirm_booking" class="button button-primary button-small">✅ OK</button>
                                <?php if($r->receipt_url): ?><button type="submit" name="paguro_action" value="resend_receipt_ack" class="button button-small" title="Reinvia Ricezione">📧 Ack</button><?php else: ?><button type="submit" name="paguro_action" value="resend_email" class="button button-small" title="Reinvia Richiesta">📧 Req</button><?php endif; ?>
                            <?php elseif($r->status==1): ?>
                                <button type="submit" name="paguro_action" value="resend_confirmation" class="button button-small" title="Reinvia Conferma">📧 Conf</button>
                            <?php endif; ?>
                            
                            <?php if (strpos($r->history_log, 'USER_REQ_REFUND') !== false || $r->status == 3): ?>
                                <button type="submit" name="paguro_action" value="notify_refund_user" class="button button-small" style="background:#6c757d;color:#fff;" title="Notifica Rimborso Effettuato">💸 Notify</button>
                            <?php endif; ?>

                            <button type="submit" name="paguro_action" value="delete_row" class="button button-small" style="color:#a00;" onclick="return confirm('Eliminare?')">❌</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        
        <div id="paguro-history-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999;">
            <div style="background:#fff; width:500px; max-width:90%; margin:100px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
                <div style="display:flex; justify-content:space-between; margin-bottom:15px;"><h3 style="margin:0;">Storico #<span id="hist-id"></span></h3><button onclick="document.getElementById('paguro-history-modal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer;">&times;</button></div>
                <div id="hist-content" style="max-height:400px; overflow-y:auto; font-size:12px; line-height:1.5;"></div>
            </div>
        </div>
        <script>
        function openHistory(id, jsonStr) {
            document.getElementById('hist-id').innerText = id;
            var container = document.getElementById('hist-content');
            container.innerHTML = '';
            try {
                var logs = JSON.parse(jsonStr);
                if(!logs || logs.length === 0) { container.innerHTML = 'Nessun evento.'; return; }
                var html = '<table style="width:100%; border-collapse:collapse;">';
                logs.forEach(function(l){ html += '<tr style="border-bottom:1px solid #eee;"><td style="padding:5px; color:#666;">'+l.time+'</td><td style="padding:5px;"><strong>'+l.action+'</strong></td><td style="padding:5px;">'+l.details+'</td></tr>'; });
                html += '</table>';
                container.innerHTML = html;
            } catch(e) { container.innerHTML = 'Errore dati.'; }
            document.getElementById('paguro-history-modal').style.display = 'block';
        }
        </script>
    <?php endif; ?>
</div>