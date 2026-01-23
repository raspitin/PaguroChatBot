<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$base_url = admin_url('admin.php?page=paguro-booking');
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt   = $wpdb->prefix . 'paguro_apartments';
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'bookings';

// --- SAVE SETTINGS ---
if (isset($_POST['paguro_save_opts']) && check_admin_referer('paguro_admin_opts')) {
    update_option('paguro_recaptcha_site', sanitize_text_field($_POST['recaptcha_site']));
    update_option('paguro_recaptcha_secret', sanitize_text_field($_POST['recaptcha_secret']));
    update_option('paguro_api_url', esc_url_raw($_POST['paguro_api_url']));
    
    update_option('paguro_txt_email_request_subj', sanitize_text_field($_POST['email_req_subj']));
    update_option('paguro_txt_email_request_body', wp_kses_post($_POST['email_req_body']));
    update_option('paguro_txt_email_receipt_subj', sanitize_text_field($_POST['email_rec_subj']));
    update_option('paguro_txt_email_receipt_body', wp_kses_post($_POST['email_rec_body']));
    update_option('paguro_txt_email_confirm_subj', sanitize_text_field($_POST['email_conf_subj']));
    update_option('paguro_txt_email_confirm_body', wp_kses_post($_POST['email_conf_body']));
    
    // Nuove Email Conflitto
    update_option('paguro_txt_email_race_lost_subj', sanitize_text_field($_POST['email_lost_subj']));
    update_option('paguro_txt_email_race_lost_body', wp_kses_post($_POST['email_lost_body']));
    
    echo '<div class="notice notice-success"><p>Configurazione Salvata.</p></div>';
}

// --- APT ACTIONS ---
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

// --- BOOKING ACTIONS ---
if (isset($_POST['paguro_action'])) {
    if (!check_admin_referer('paguro_admin_action', 'paguro_nonce')) wp_die('Sicurezza.');
    $req_id = intval($_POST['request_id']);
    $w = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id=%d", $req_id));
    
    if ($_POST['paguro_action'] === 'anonymize_admin') {
        $wpdb->update($table_avail, ['guest_name'=>'Anonimo (Admin)','guest_email'=>'deleted@admin.act','guest_phone'=>'0000','guest_notes'=>''], ['id'=>$req_id]);
        paguro_add_history($req_id, 'GDPR_ADMIN', 'Dati anonimizzati da Admin');
        echo '<div class="notice notice-success"><p>Anonimizzato.</p></div>';
    }

    if ($w) {
        $apt_row = $wpdb->get_row($wpdb->prepare("SELECT name, pricing_json, base_price FROM $table_apt WHERE id=%d", $w->apartment_id));
        $ph = ['guest_name'=>$w->guest_name, 'date_start'=>date('d/m/Y',strtotime($w->date_start)), 'date_end'=>date('d/m/Y',strtotime($w->date_end)), 'apt_name'=>ucfirst($apt_row->name ?? ''), 'link_riepilogo'=>site_url("/riepilogo-prenotazione/?token={$w->lock_token}")];
        
        if ($_POST['paguro_action'] === 'confirm_booking') {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);
            $losers = $wpdb->get_results($wpdb->prepare("SELECT id FROM $table_avail WHERE apartment_id=%d AND id!=%d AND status!=1 AND (date_start<%s AND date_end>%s)", $w->apartment_id, $req_id, $w->date_end, $w->date_start));
            foreach ($losers as $l) $wpdb->delete($table_avail, ['id' => $l->id]);
            $tot = 0; $cur = new DateTime($w->date_start); $end = new DateTime($w->date_end); 
            $prices = ($apt_row->pricing_json) ? json_decode($apt_row->pricing_json, true) : [];
            while($cur < $end) { $k = $cur->format('Y-m-d'); $tot += (isset($prices[$k]) ? floatval($prices[$k]) : floatval($apt_row->base_price)); $cur->add(new DateInterval('P1W')); }
            $dep = ceil($tot * 0.3); $ph['total_cost'] = $tot; $ph['deposit_cost'] = $dep;
            $subj = paguro_parse_template(get_option('paguro_txt_email_confirm_subj'), $ph); $body = paguro_parse_template(get_option('paguro_txt_email_confirm_body'), $ph);
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
        if ($_POST['paguro_action'] === 'delete_row') { $wpdb->delete($table_avail, ['id' => $req_id]); echo '<div class="notice notice-success"><p>Eliminata.</p></div>'; }
    }
}

// RENDER TIMELINE
function paguro_render_timeline() {
    global $wpdb; $s_start = new DateTime('2026-06-01'); $s_end = new DateTime('2026-09-30');
    $apts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_apartments");
    $bookings = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}paguro_availability WHERE status IN (1,2)");
    $day_map = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Gio','Fri'=>'Ven','Sat'=>'Sab','Sun'=>'Dom'];
    $month_map = ['06'=>'Giugno','07'=>'Luglio','08'=>'Agosto','09'=>'Settembre'];
    echo '<div style="overflow-x:auto; background:#fff; padding:10px; border:1px solid #ccc; margin-bottom:20px;">';
    echo '<table style="border-collapse:collapse; width:100%; min-width:1200px; font-size:10px;">';
    echo '<tr><td style="width:100px; border:none;"></td>';
    $temp = clone $s_start;
    while($temp <= $s_end) { $m = $temp->format('m'); $days = (int)$temp->format('t'); echo "<td colspan='{$days}' style='border:1px solid #999; text-align:center; background:#eee; font-weight:bold; font-size:12px;'>".($month_map[$m])."</td>"; $temp->modify('first day of next month'); }
    echo '</tr><tr><td style="width:100px;"><strong>Appartamento</strong></td>';
    $p = new DatePeriod($s_start, new DateInterval('P1D'), $s_end->modify('+1 day'));
    foreach($p as $dt) { $d=$day_map[$dt->format('D')]; $n=$dt->format('d'); $bg=($dt->format('N')>=6)?'#ddd':'#fff'; echo "<td style='border:1px solid #eee; border-bottom:1px solid #999; text-align:center; background:$bg; width:20px; padding:2px;'>$d<br>$n</td>"; }
    echo '</tr>';
    foreach($apts as $apt) {
        echo "<tr><td style='border:1px solid #ddd; padding:5px; border-right:2px solid #999;'><strong>".esc_html($apt->name)."</strong></td>";
        foreach($p as $dt) {
            $ymd = $dt->format('Y-m-d'); $class = ''; $title = '';
            foreach($bookings as $b) { if ($b->apartment_id == $apt->id && $ymd >= $b->date_start && $ymd < $b->date_end) { if ($b->status == 1) { $class = 'bg-red'; $title="Occ: ".$b->guest_name; } elseif ($b->status == 2) { $class = 'bg-yellow'; $title="Pend: ".$b->guest_name; } } }
            $style = ($class=='bg-red')?"background:#dc3545;":(($class=='bg-yellow')?"background:#ffc107;":"");
            echo "<td style='border:1px solid #eee; border-right:1px solid #ddd; $style' title='".esc_attr($title)."'></td>";
        }
        echo "</tr>";
    }
    echo '</table></div>';
}
?>

<div class="wrap">
    <h1>Gestione Paguro v2.8.2</h1>
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
            $start = new DateTime('2026-06-13'); $end = new DateTime('2026-10-03'); $period = new DatePeriod($start, new DateInterval('P1W'), $end);
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
        <form method="post">
            <?php wp_nonce_field('paguro_admin_opts'); ?>
            <div style="display:flex; gap:20px; flex-wrap:wrap;">
                
                <div style="flex:1; min-width:300px; background:#fff; padding:20px; border:1px solid #ccc;">
                    <h3>🤖 Intelligenza Artificiale</h3>
                    <p><label><strong>API URL</strong> (FQDN)</label><br><input type="text" id="paguro_api_url" name="paguro_api_url" value="<?php echo esc_attr(get_option('paguro_api_url', 'https://api.viamerano24.it/chat')); ?>" style="width:100%;"></p>
                    <p><button type="button" id="paguro-test-btn" class="button">⚡ Test Connessione</button><span id="paguro-test-res" style="margin-left:10px;"></span></p>
                    <hr>
                    <h3>🔐 Sicurezza</h3>
                    <p><label>Site Key</label><br><input type="text" name="recaptcha_site" value="<?php echo esc_attr(get_option('paguro_recaptcha_site')); ?>" style="width:100%;"></p>
                    <p><label>Secret Key</label><br><input type="password" name="recaptcha_secret" value="<?php echo esc_attr(get_option('paguro_recaptcha_secret')); ?>" style="width:100%;"></p>
                </div>
                
                <div style="flex:2; min-width:400px; background:#fff; padding:20px; border:1px solid #ccc;">
                    <h3>✉️ Email Standard</h3>
                    <strong>1. Richiesta</strong><br><input type="text" name="email_req_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_request_subj')); ?>" style="width:100%;"><textarea name="email_req_body" style="width:100%;height:60px;"><?php echo esc_textarea(get_option('paguro_txt_email_request_body')); ?></textarea>
                    <hr><strong>2. Ricevuta</strong><br><input type="text" name="email_rec_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_receipt_subj')); ?>" style="width:100%;"><textarea name="email_rec_body" style="width:100%;height:60px;"><?php echo esc_textarea(get_option('paguro_txt_email_receipt_body')); ?></textarea>
                    <hr><strong>3. Conferma</strong><br><input type="text" name="email_conf_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_confirm_subj')); ?>" style="width:100%;"><textarea name="email_conf_body" style="width:100%;height:60px;"><?php echo esc_textarea(get_option('paguro_txt_email_confirm_body')); ?></textarea>
                    
                    <hr><h3 style="color:#d63638;">⚠️ Email Conflitto (Novità)</h3>
                    <strong>4. Avviso Priorità Persa</strong><br>
                    <input type="text" name="email_lost_subj" value="<?php echo esc_attr(get_option('paguro_txt_email_race_lost_subj')); ?>" style="width:100%;">
                    <textarea name="email_lost_body" style="width:100%;height:60px;"><?php echo esc_textarea(get_option('paguro_txt_email_race_lost_body')); ?></textarea>
                </div>
            </div>
            <p><button type="submit" name="paguro_save_opts" class="button button-primary button-large">Salva Configurazione</button></p>
        </form>
        <script>
        jQuery(document).ready(function($){
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
                    $st = '<span style="color:orange">Pend</span>';
                    if ($r->status==1) $st = '<span style="color:green;font-weight:bold">OK</span>';
                    elseif ($r->status==3) $st = '<span style="color:red;font-weight:bold">CANC</span>';
                    
                    // Badge speciali conflitti
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
