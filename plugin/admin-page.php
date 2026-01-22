<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$base_url = admin_url('admin.php?page=paguro-booking');
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt   = $wpdb->prefix . 'paguro_apartments';
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';

// --- SAVE SETTINGS (Invariato) ---
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

// --- APT ACTIONS (Invariato) ---
if (isset($_POST['paguro_apt_action'])) {
    if (!check_admin_referer('paguro_apt_nonce', 'paguro_apt_nonce')) wp_die('Security.');
    if ($_POST['paguro_apt_action'] === 'add_apt') { $name = sanitize_text_field($_POST['apt_name']); if ($name) $wpdb->insert($table_apt, ['name' => $name, 'base_price' => 500]); }
    if ($_POST['paguro_apt_action'] === 'delete_apt') { $id = intval($_POST['apt_id']); $wpdb->delete($table_apt, ['id' => $id]); }
    if ($_POST['paguro_apt_action'] === 'save_pricing') { $id = intval($_POST['apt_id']); $prices = $_POST['price'] ?? []; $json = json_encode($prices); $wpdb->update($table_apt, ['pricing_json' => $json], ['id' => $id]); echo '<div class="notice notice-success"><p>Listino Aggiornato!</p></div>'; }
}

// --- BOOKING ACTIONS (Updated) ---
if (isset($_POST['paguro_action'])) {
    if (!check_admin_referer('paguro_admin_action', 'paguro_nonce')) wp_die('Sicurezza.');

    $req_id = intval($_POST['request_id']);
    $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id=%d", $req_id));
    
    // Helper Vars
    $apt_row = $wpdb->get_row($wpdb->prepare("SELECT name, pricing_json, base_price FROM $table_apt WHERE id=%d", $w->apartment_id));
    $ph = ['guest_name'=>$w->guest_name, 'date_start'=>date('d/m/Y',strtotime($w->date_start)), 'date_end'=>date('d/m/Y',strtotime($w->date_end)), 'apt_name'=>ucfirst($apt_row->name ?? ''), 'link_riepilogo'=>site_url("/riepilogo-prenotazione/?token={$w->lock_token}")];
    
    // 1. Confirm
    if ($_POST['paguro_action'] === 'confirm_booking') {
        if ($w) {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);
            // Clean conflicts
            $losers = $wpdb->get_results($wpdb->prepare("SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status!=1 AND (date_start<%s AND date_end>%s)", $w->apartment_id, $req_id, $w->date_end, $w->date_start));
            foreach ($losers as $l) $wpdb->delete($table_avail, ['id' => $l->id]);
            
            // Mail
            $tot = 0; $cur = new DateTime($w->date_start); $end = new DateTime($w->date_end); $prices = json_decode($apt_row->pricing_json, true) ?: [];
            while($cur < $end) { $k = $cur->format('Y-m-d'); $tot += ($prices[$k] ?? $apt_row->base_price); $cur->add(new DateInterval('P1W')); }
            $dep = ceil($tot * 0.3);
            
            $ph['total_cost'] = $tot; $ph['deposit_cost'] = $dep;
            $subj = paguro_parse_template(get_option('paguro_txt_email_confirm_subj'), $ph);
            $body = paguro_parse_template(get_option('paguro_txt_email_confirm_body'), $ph);
            
            if ($w->guest_email) paguro_send_html_email($w->guest_email, $subj, $body);
            echo '<div class="notice notice-success"><p>Confermata e Mail inviata.</p></div>';
        }
    }
    
    // 2. Extend
    if ($_POST['paguro_action'] === 'extend_expiry') {
        $new = sanitize_text_field($_POST['new_expiry']);
        if($req_id && $new) $wpdb->update($table_avail, ['lock_expires' => $new], ['id' => $req_id]);
        echo '<div class="notice notice-success"><p>Scadenza aggiornata.</p></div>';
    }
    
    // 3. Resend Request Mail
    if ($_POST['paguro_action'] === 'resend_email') {
        if ($w->guest_email) {
            $subj = paguro_parse_template(get_option('paguro_txt_email_request_subj'), $ph);
            $body = paguro_parse_template(get_option('paguro_txt_email_request_body'), $ph);
            paguro_send_html_email($w->guest_email, $subj, $body);
            echo '<div class="notice notice-success"><p>Mail Richiesta reinviata.</p></div>';
        }
    }

    // 4. Resend Receipt Ack
    if ($_POST['paguro_action'] === 'resend_receipt_ack') {
        if ($w->guest_email) {
            $subj = paguro_parse_template(get_option('paguro_txt_email_receipt_subj'), $ph);
            $body = paguro_parse_template(get_option('paguro_txt_email_receipt_body'), $ph);
            paguro_send_html_email($w->guest_email, $subj, $body);
            echo '<div class="notice notice-success"><p>Mail Ricezione Distinta reinviata.</p></div>';
        }
    }

    // 5. Resend Confirmation
    if ($_POST['paguro_action'] === 'resend_confirmation') {
        if ($w->guest_email && $w->status == 1) {
            // Recalc for safety
            $tot = 0; $cur = new DateTime($w->date_start); $end = new DateTime($w->date_end); $prices = json_decode($apt_row->pricing_json, true) ?: [];
            while($cur < $end) { $k = $cur->format('Y-m-d'); $tot += ($prices[$k] ?? $apt_row->base_price); $cur->add(new DateInterval('P1W')); }
            $dep = ceil($tot * 0.3); $ph['total_cost'] = $tot; $ph['deposit_cost'] = $dep;

            $subj = paguro_parse_template(get_option('paguro_txt_email_confirm_subj'), $ph);
            $body = paguro_parse_template(get_option('paguro_txt_email_confirm_body'), $ph);
            paguro_send_html_email($w->guest_email, $subj, $body);
            echo '<div class="notice notice-success"><p>Mail Conferma reinviata.</p></div>';
        }
    }

    // 6. Delete
    if ($_POST['paguro_action'] === 'delete_row') {
        $wpdb->delete($table_avail, ['id' => $req_id]);
        echo '<div class="notice notice-success"><p>Eliminata.</p></div>';
    }
}

// --- HELPER TIMELINE ---
function paguro_render_timeline() {
    global $wpdb;
    $s_start = new DateTime('2026-06-01'); $s_end = new DateTime('2026-09-30');
    $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
    $bookings = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE status IN (1,2)");
    
    echo '<div style="overflow-x:auto; background:#fff; padding:10px; border:1px solid #ccc; margin-bottom:20px;">';
    echo '<table style="border-collapse:collapse; width:100%; min-width:1200px; font-size:10px;">';
    
    // Header
    echo '<tr><td style="width:100px;"><strong>Appartamento</strong></td>';
    $p = new DatePeriod($s_start, new DateInterval('P1D'), $s_end);
    foreach($p as $dt) {
        $bg = ($dt->format('N')>=6) ? '#eee' : '#fff';
        echo "<td style='border:1px solid #ddd; text-align:center; background:$bg; width:20px;'>".$dt->format('d')."<br>".substr($dt->format('D'),0,1)."</td>";
    }
    echo '</tr>';

    // Rows
    foreach($apts as $apt) {
        echo "<tr><td style='border:1px solid #ddd; padding:5px;'><strong>{$apt->name}</strong></td>";
        foreach($p as $dt) {
            $ymd = $dt->format('Y-m-d');
            $class = ''; $title = '';
            foreach($bookings as $b) {
                if ($b->apartment_id == $apt->id && $ymd >= $b->date_start && $ymd < $b->date_end) {
                    if ($b->status == 1) { $class = 'bg-red'; $title="Confermato: ".$b->guest_name; }
                    elseif ($b->status == 2) { $class = 'bg-yellow'; $title="In attesa: ".$b->guest_name; }
                }
            }
            $style = "";
            if($class=='bg-red') $style="background:#dc3545;";
            if($class=='bg-yellow') $style="background:#ffc107;";
            
            echo "<td style='border:1px solid #ddd; $style' title='$title'></td>";
        }
        echo "</tr>";
    }
    echo '</table></div>';
}
?>

<div class="wrap">
    <h1>Gestione Paguro 2.4</h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo $base_url.'&tab=bookings'; ?>" class="nav-tab <?php echo $current_tab=='bookings'?'nav-tab-active':''; ?>">📅 Prenotazioni</a>
        <a href="<?php echo $base_url.'&tab=apartments'; ?>" class="nav-tab <?php echo $current_tab=='apartments'?'nav-tab-active':''; ?>">🏠 Appartamenti & Listino</a>
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
            $start = new DateTime('2026-06-13'); $end = new DateTime('2026-10-03'); $period = new DatePeriod($start, new DateInterval('P1W'), $end);
            ?>
            <h3>Listino Prezzi: <?php echo ucfirst($apt->name); ?></h3>
            <a href="<?php echo $base_url.'&tab=apartments'; ?>" class="button">« Torna alla lista</a>
            <form method="post" style="margin-top:15px;">
                <?php wp_nonce_field('paguro_apt_nonce', 'paguro_apt_nonce'); ?>
                <input type="hidden" name="paguro_apt_action" value="save_pricing">
                <input type="hidden" name="apt_id" value="<?php echo $edit_id; ?>">
                <table class="wp-list-table widefat fixed striped"><thead><tr><th>Settimana</th><th>Prezzo (€)</th><th>Azioni</th></tr></thead><tbody>
                <?php $current_month = ''; foreach ($period as $dt) { $ws = $dt->format('Y-m-d'); $wl = $dt->format('d/m/Y'); $val = isset($saved_prices[$ws]) ? $saved_prices[$ws] : $apt->base_price; $mc = $dt->format('m');
                    if ($mc != $current_month) { $current_month = $mc; echo "<tr style='background:#e5e5e5;'><td colspan='3'><strong>Mese: $current_month</strong> <button type='button' class='button button-small copy-btn' data-month='$current_month'>Copia</button></td></tr>"; }
                    echo "<tr><td>$wl</td><td><input type='number' name='price[$ws]' value='$val' class='price-input month-$current_month' style='width:100px;'></td><td>-</td></tr>";
                } ?></tbody></table>
                <p><button type="submit" class="button button-primary button-large">Salva Listino</button></p>
            </form>
            <script>jQuery(document).ready(function($){ $('.copy-btn').click(function(){ var m = $(this).data('month'); var inputs = $('.month-'+m); inputs.val(inputs.first().val()); }); });</script>
            <?php
        } else {
            ?>
            <table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Nome</th><th>Prezzo Base</th><th>Azioni</th></tr></thead><tbody>
                <?php foreach($apts as $a): ?><tr><td><?php echo $a->id; ?></td><td><strong><?php echo ucfirst($a->name); ?></strong></td><td>€<?php echo $a->base_price; ?></td><td><a href="<?php echo $base_url.'&tab=apartments&edit_prices='.$a->id; ?>" class="button button-small">Modifica Listino</a> <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare?');"><?php wp_nonce_field('paguro_apt_nonce', 'paguro_apt_nonce'); ?><input type="hidden" name="paguro_apt_action" value="delete_apt"><input type="hidden" name="apt_id" value="<?php echo $a->id; ?>"><button type="submit" class="button button-small" style="color:red;border-color:red;">Elimina</button></form></td></tr><?php endforeach; ?>
            </tbody></table>
            <?php
        }
        ?>

    <?php elseif ($current_tab == 'settings'): ?>
        <form method="post">
            <?php wp_nonce_field('paguro_admin_opts'); ?>
            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                <div style="flex:1; min-width:300px; background:#fff; padding:20px; border:1px solid #ccc;"><h3>🔐 Sicurezza</h3><p><label>Site Key</label><br><input type="text" name="recaptcha_site" value="<?php echo esc_attr(get_option('paguro_recaptcha_site')); ?>" style="width:100%;"></p><p><label>Secret Key</label><br><input type="password" name="recaptcha_secret" value="<?php echo esc_attr(get_option('paguro_recaptcha_secret')); ?>" style="width:100%;"></p></div>
                <div style="flex:2; min-width:400px; background:#fff; padding:20px; border:1px solid #ccc;"><h3>✉️ Email</h3><p>Placeholder: {guest_name}, {apt_name}, {date_start}, {date_end}, {link_riepilogo}, {total_cost}, {deposit_cost}</p><strong>1. Richiesta</strong><input type="text" name="email_req_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_request_subj')); ?>" style="width:100%;"><textarea name="email_req_body" style="width:100%;height:80px;"><?php echo esc_textarea(get_option('paguro_txt_email_request_body')); ?></textarea><hr><strong>2. Ricevuta</strong><input type="text" name="email_rec_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_receipt_subj')); ?>" style="width:100%;"><textarea name="email_rec_body" style="width:100%;height:80px;"><?php echo esc_textarea(get_option('paguro_txt_email_receipt_body')); ?></textarea><hr><strong>3. Conferma</strong><input type="text" name="email_conf_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_confirm_subj')); ?>" style="width:100%;"><textarea name="email_conf_body" style="width:100%;height:80px;"><?php echo esc_textarea(get_option('paguro_txt_email_confirm_body')); ?></textarea></div>
            </div>
            <p><button type="submit" name="paguro_save_opts" class="button button-primary button-large">Salva</button></p>
        </form>

    <?php else: ?>
        
        <?php paguro_render_timeline(); ?>

        <?php $rows = $wpdb->get_results("SELECT av.*, apt.name as apt_name FROM $table_avail av JOIN $table_apt apt ON av.apartment_id = apt.id ORDER BY av.created_at DESC"); ?>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th style="width:70px;">Stato</th>
                    <th>Date (Richiesta / Distinta)</th>
                    <th>Ospite / Note</th>
                    <th>Dettagli Soggiorno</th>
                    <th>Azioni Rapide</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($rows)):?><tr><td colspan="5">Nessuna prenotazione.</td></tr><?php else: foreach($rows as $r): 
                    $st = ($r->status==1)?'<span style="color:green;font-weight:bold">OK</span>':(($r->status==3)?'<span style="color:red;font-weight:bold">CANC</span>':'<span style="color:orange">Pend</span>');
                    $rc_icon = ($r->receipt_url)?'📄':'';
                    
                    $dt_req = date('d/m H:i', strtotime($r->created_at));
                    $dt_rec = ($r->receipt_uploaded_at) ? date('d/m H:i', strtotime($r->receipt_uploaded_at)) : '-';
                    
                    $exp_ts = $r->lock_expires ? strtotime($r->lock_expires) : (strtotime($r->created_at)+48*3600);
                    $timer = ($r->status==2 && time()<$exp_ts) ? "<small style='color:green'>Scade: ".date('d/m H:i',$exp_ts)."</small>" : "";
                ?>
                <tr>
                    <td><?php echo $st.' '.$rc_icon; ?></td>
                    <td style="font-size:12px;">
                        Rich: <?php echo $dt_req; ?><br>
                        Dist: <?php echo ($r->receipt_url) ? "<a href='".esc_url($r->receipt_url)."' target='_blank'>$dt_rec</a>" : 'In attesa'; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($r->guest_name); ?></strong><br>
                        <small><?php echo esc_html($r->guest_email); ?> | <?php echo esc_html($r->guest_phone); ?></small>
                        <div style="cursor:pointer; color:#0073aa; font-size:12px;" onclick="jQuery('#hist_<?php echo $r->id; ?>').slideToggle()">📜 Mostra Storia/Note</div>
                        <div id="hist_<?php echo $r->id; ?>" style="display:none; background:#f9f9f9; padding:5px; border:1px solid #eee; margin-top:5px;">
                            <?php echo $r->guest_notes ? "Note: <em>".esc_html($r->guest_notes)."</em><br>" : "Nessuna nota.<br>"; ?>
                            Log: Richiesta il <?php echo $dt_req; ?>.
                        </div>
                    </td>
                    <td>
                        <?php echo ucfirst($r->apt_name); ?><br>
                        <small><?php echo date('d/m',strtotime($r->date_start)).' -> '.date('d/m',strtotime($r->date_end)); ?></small><br>
                        <?php echo $timer; ?>
                    </td>
                    <td>
                        <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;">
                            <?php wp_nonce_field('paguro_admin_action','paguro_nonce'); ?>
                            <input type="hidden" name="request_id" value="<?php echo $r->id; ?>">
                            
                            <?php if($r->status==2): ?>
                                <button type="submit" name="paguro_action" value="confirm_booking" class="button button-primary button-small">✅ OK</button>
                                <?php if($r->receipt_url): ?>
                                    <button type="submit" name="paguro_action" value="resend_receipt_ack" class="button button-small" title="Reinvia Ricezione Distinta">📧 Ack</button>
                                <?php else: ?>
                                    <button type="submit" name="paguro_action" value="resend_email" class="button button-small" title="Reinvia Richiesta">📧 Req</button>
                                <?php endif; ?>
                            <?php elseif($r->status==1): ?>
                                <button type="submit" name="paguro_action" value="resend_confirmation" class="button button-small" title="Reinvia Conferma">📧 Conf</button>
                            <?php endif; ?>
                            
                            <button type="submit" name="paguro_action" value="delete_row" class="button button-small" style="color:#a00;" onclick="return confirm('Eliminare?')">❌</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>