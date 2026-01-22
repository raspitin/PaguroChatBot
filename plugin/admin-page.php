<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$base_url = admin_url('admin.php?page=paguro-booking');
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt   = $wpdb->prefix . 'paguro_apartments';
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';

// --- SAVE ACTIONS (Invariato v2.6.1) ---
// (Copia il blocco POST 'paguro_save_opts' e 'paguro_apt_action' dalla v2.6.1)
if (isset($_POST['paguro_save_opts']) && check_admin_referer('paguro_admin_opts')) {
    update_option('paguro_recaptcha_site', sanitize_text_field($_POST['recaptcha_site']));
    update_option('paguro_recaptcha_secret', sanitize_text_field($_POST['recaptcha_secret']));
    update_option('paguro_txt_email_request_subj', sanitize_text_field($_POST['email_req_subj']));
    update_option('paguro_txt_email_request_body', wp_kses_post($_POST['email_req_body']));
    update_option('paguro_txt_email_receipt_subj', sanitize_text_field($_POST['email_rec_subj']));
    update_option('paguro_txt_email_receipt_body', wp_kses_post($_POST['email_rec_body']));
    update_option('paguro_txt_email_confirm_subj', sanitize_text_field($_POST['email_conf_subj']));
    update_option('paguro_txt_email_confirm_body', wp_kses_post($_POST['email_conf_body']));
    echo '<div class="notice notice-success"><p>Configurazione Salvata.</p></div>';
}
// ... (Apt Actions same as v2.6.1) ...

// --- BOOKING ACTIONS ---
if (isset($_POST['paguro_action'])) {
    if (!check_admin_referer('paguro_admin_action', 'paguro_nonce')) wp_die('Sicurezza.');
    $req_id = intval($_POST['request_id']);
    
    // ANONYMIZE ACTION (ADMIN SIDE)
    if ($_POST['paguro_action'] === 'anonymize_admin') {
        $wpdb->update($table_avail, [
            'guest_name' => 'Anonimo (Admin)',
            'guest_email' => 'deleted@admin.act',
            'guest_phone' => '0000',
            'guest_notes' => ''
        ], ['id' => $req_id]);
        paguro_add_history($req_id, 'GDPR_ADMIN', 'Dati anonimizzati da Amministratore');
        echo '<div class="notice notice-success"><p>Utente Anonimizzato.</p></div>';
    }
    
    // ... (Other actions confirm/resend/delete same as v2.6.1) ...
    // (Per brevità includo solo Confirm e Delete, tu mantieni anche resend)
    if ($_POST['paguro_action'] === 'delete_row') { $wpdb->delete($table_avail, ['id' => $req_id]); }
}

// --- RENDER TIMELINE (Invariato v2.6.1) ---
// (Copia funzione paguro_render_timeline da v2.6.1)
function paguro_render_timeline() {
    // ... (Code from v2.6.1) ...
}
?>

<div class="wrap">
    <h1>Gestione Paguro 2.7</h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo $base_url.'&tab=bookings'; ?>" class="nav-tab <?php echo $current_tab=='bookings'?'nav-tab-active':''; ?>">📅 Prenotazioni</a>
        <a href="<?php echo $base_url.'&tab=apartments'; ?>" class="nav-tab <?php echo $current_tab=='apartments'?'nav-tab-active':''; ?>">🏠 Appartamenti</a>
        <a href="<?php echo $base_url.'&tab=settings'; ?>" class="nav-tab <?php echo $current_tab=='settings'?'nav-tab-active':''; ?>">⚙️ Configurazione</a>
    </nav>
    <br>

    <?php if ($current_tab == 'apartments'): ?>
        <?php elseif ($current_tab == 'settings'): ?>
        <?php else: ?>
        <?php if(function_exists('paguro_render_timeline')) paguro_render_timeline(); ?>
        <?php $rows = $wpdb->get_results("SELECT av.*, apt.name as apt_name FROM $table_avail av JOIN $table_apt apt ON av.apartment_id = apt.id ORDER BY av.created_at DESC"); ?>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead><tr><th style="width:70px;">Stato</th><th>Date</th><th>Ospite</th><th>Note</th><th>Storico</th><th>Dettagli</th><th>Azioni</th></tr></thead>
            <tbody>
                <?php if(empty($rows)):?><tr><td colspan="7">Nessuna prenotazione.</td></tr><?php else: foreach($rows as $r): 
                    $st = ($r->status==1)?'<span style="color:green;font-weight:bold">OK</span>':(($r->status==3)?'<span style="color:red;font-weight:bold">CANC</span>':'<span style="color:orange">Pend</span>');
                    $rc_icon = ($r->receipt_url)?'📄':''; $dt_req = date('d/m H:i', strtotime($r->created_at)); $hist_json = htmlspecialchars($r->history_log, ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                    <td><?php echo $st.' '.$rc_icon; ?></td>
                    <td style="font-size:12px;">Rich: <?php echo $dt_req; ?></td>
                    <td><strong><?php echo esc_html($r->guest_name); ?></strong><br><small><?php echo esc_html($r->guest_email); ?></small></td>
                    <td><small><em><?php echo esc_html($r->guest_notes); ?></em></small></td>
                    <td><button class="button button-small" onclick="openHistory(<?php echo $r->id; ?>, '<?php echo $hist_json; ?>')">📜 Log</button></td>
                    <td><?php echo ucfirst($r->apt_name); ?><br><small><?php echo date('d/m',strtotime($r->date_start)).' -> '.date('d/m',strtotime($r->date_end)); ?></small></td>
                    <td>
                        <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php wp_nonce_field('paguro_admin_action','paguro_nonce'); ?>
                            <input type="hidden" name="request_id" value="<?php echo $r->id; ?>">
                            <?php if($r->status==2): ?><button type="submit" name="paguro_action" value="confirm_booking" class="button button-primary button-small">OK</button><?php endif; ?>
                            
                            <button type="submit" name="paguro_action" value="anonymize_admin" class="button button-small" onclick="return confirm('Anonimizzare? Irreversibile.')" title="GDPR Wipe">🕵️ Anon</button>
                            
                            <button type="submit" name="paguro_action" value="delete_row" class="button button-small" style="color:#a00;" onclick="return confirm('Eliminare?')">❌</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
</div>