<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt   = $wpdb->prefix . 'paguro_apartments';

$base_url = admin_url('admin.php?page=paguro-booking');
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';

// --- GESTIONE AZIONI (POST) ---
if (isset($_POST['paguro_action'])) {
    if (!check_admin_referer('paguro_admin_action', 'paguro_nonce')) wp_die('Sicurezza violata.');

    // 1. SALVA IMPOSTAZIONI
    if ($_POST['paguro_action'] === 'save_settings') {
        $is_active = isset($_POST['paguro_global_active']) ? 1 : 0;
        update_option('paguro_global_active', $is_active);
        echo '<div class="notice notice-success is-dismissible"><p>Impostazioni salvate.</p></div>';
    }

    // 2. TEST BACKEND
    if ($_POST['paguro_action'] === 'test_backend') {
        $api_url = defined('PAGURO_API_URL') ? PAGURO_API_URL : 'URL NON DEFINITO';
        $response = wp_remote_post($api_url, [
            'body' => json_encode(['message' => 'ping_test_admin', 'session_id' => 'admin_test']),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5
        ]);
        if (is_wp_error($response)) {
            echo '<div class="notice notice-error"><p>❌ Errore: ' . $response->get_error_message() . '</p></div>';
        } else {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            echo '<div class="notice notice-success"><p>✅ Test OK. Risposta: <em>' . esc_html($data['reply'] ?? 'Nessuna') . '</em></p></div>';
        }
    }

    // 3. ESTENDI SCADENZA (Nuovo)
    if ($_POST['paguro_action'] === 'extend_expiry') {
        $req_id = intval($_POST['request_id']);
        $new_date = sanitize_text_field($_POST['new_expiry']); // Format: Y-m-d\TH:i
        if ($req_id && $new_date) {
            $wpdb->update($table_avail, ['lock_expires' => $new_date], ['id' => $req_id]);
            echo '<div class="notice notice-success"><p>Scadenza aggiornata con successo.</p></div>';
        }
    }

    // 4. REINVIA MAIL (Nuovo)
    if ($_POST['paguro_action'] === 'resend_email') {
        $req_id = intval($_POST['request_id']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id = %d", $req_id));
        if ($row && $row->guest_email) {
            $link = site_url("/riepilogo-prenotazione/?token=" . $row->lock_token);
            $subject = "Riepilogo Prenotazione Villa Celi";
            $message = "Ciao {$row->guest_name},\n\nEcco il link per gestire la tua prenotazione e caricare la distinta:\n{$link}\n\nA presto,\nVilla Celi";
            $headers = ['Content-Type: text/plain; charset=UTF-8'];
            
            wp_mail($row->guest_email, $subject, $message, $headers);
            echo '<div class="notice notice-success"><p>Email reinviata a ' . esc_html($row->guest_email) . '.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Impossibile inviare: email mancante.</p></div>';
        }
    }

    // 5. CONFERMA/ELIMINA (Esistenti)
    if ($_POST['paguro_action'] === 'confirm_booking') {
        $req_id = intval($_POST['request_id']);
        $winner = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id = %d", $req_id));
        if ($winner) {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);
            // Elimina competitori
            $losers = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM $table_avail WHERE apartment_id = %d AND id != %d AND status != 1 AND (date_start < %s AND date_end > %s)",
                $winner->apartment_id, $req_id, $winner->date_end, $winner->date_start
            ));
            foreach ($losers as $loser) $wpdb->delete($table_avail, ['id' => $loser->id]);
            echo '<div class="notice notice-success"><p>Prenotazione Confermata!</p></div>';
        }
    }
    if ($_POST['paguro_action'] === 'delete_row') {
        $wpdb->delete($table_avail, ['id' => intval($_POST['request_id'])]);
        echo '<div class="notice notice-success"><p>Eliminata.</p></div>';
    }
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Gestione Paguro 🦀</h1>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo $base_url . '&tab=bookings'; ?>" class="nav-tab <?php echo ($current_tab == 'bookings') ? 'nav-tab-active' : ''; ?>">📅 Prenotazioni</a>
        <a href="<?php echo $base_url . '&tab=settings'; ?>" class="nav-tab <?php echo ($current_tab == 'settings') ? 'nav-tab-active' : ''; ?>">⚙️ Impostazioni</a>
    </nav>
    <br>

    <?php if ($current_tab == 'settings'): ?>
        <form method="post">
            <?php wp_nonce_field('paguro_admin_action', 'paguro_nonce'); ?>
            <input type="hidden" name="paguro_action" value="save_settings">
            <label><input type="checkbox" name="paguro_global_active" value="1" <?php checked(get_option('paguro_global_active', 1), 1); ?>> Attiva Chatbot Globalmente</label>
            <p><button type="submit" class="button button-primary">Salva</button></p>
        </form>
    
    <?php else: ?>
        
        <?php 
            // Recupero Dati
            $rows = $wpdb->get_results("
                SELECT av.*, apt.name as apt_name 
                FROM $table_avail av 
                JOIN $table_apt apt ON av.apartment_id = apt.id 
                ORDER BY av.created_at DESC
            ");
        ?>
        
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th style="width: 80px;">Stato</th>
                    <th style="width: 100px;">Ricevuta</th> <th>Ospite</th>
                    <th>Appartamento / Date</th>
                    <th style="width: 180px;">Scadenza Token</th> <th style="width: 160px;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?><tr><td colspan="6">Nessuna prenotazione.</td></tr><?php else: foreach ($rows as $row): 
                    $start = date('d/m', strtotime($row->date_start));
                    $end = date('d/m', strtotime($row->date_end));
                    
                    // STATO
                    $status_html = ($row->status == 1) ? '<span style="color:green; font-weight:bold;">✔ OK</span>' : '<span style="color:orange;">⏳ Pend.</span>';
                    
                    // RICEVUTA (Giallo/Verde)
                    if ($row->receipt_url) {
                        $receipt_html = '<a href="'.esc_url($row->receipt_url).'" target="_blank" class="button button-small" style="border-color:green; color:green;">🟢 Vedi</a>';
                    } else {
                        $receipt_html = '<span style="color:#f0b849; font-size:20px;">🟡</span>';
                    }

                    // CALCOLO SCADENZA
                    // Usa lock_expires se esiste, altrimenti created_at + 48h
                    $expire_ts = $row->lock_expires ? strtotime($row->lock_expires) : (strtotime($row->created_at) + (48*3600));
                    $is_expired = ($row->status == 2 && time() > $expire_ts);
                    $expire_val = date('Y-m-d\TH:i', $expire_ts);
                    
                    $timer_html = "";
                    if ($row->status == 2) {
                        $diff = $expire_ts - time();
                        if ($diff > 0) {
                            $h = floor($diff/3600); $m = floor(($diff%3600)/60);
                            $timer_html = "<small style='color:green'>Scade tra {$h}h {$m}m</small>";
                        } else {
                            $timer_html = "<small style='color:red; font-weight:bold;'>SCADUTA</small>";
                        }
                    }
                ?>
                <tr>
                    <td><?php echo $status_html; ?></td>
                    <td style="text-align:center;"><?php echo $receipt_html; ?></td>
                    <td>
                        <strong><?php echo esc_html($row->guest_name); ?></strong><br>
                        <a href="mailto:<?php echo esc_attr($row->guest_email); ?>"><?php echo esc_html($row->guest_email); ?></a>
                    </td>
                    <td>
                        <?php echo ucfirst($row->apt_name); ?><br>
                        <small><?php echo "$start ➝ $end"; ?></small>
                    </td>
                    <td>
                        <?php if($row->status == 2): ?>
                            <form method="post" style="display:flex; flex-direction:column; gap:5px;">
                                <?php wp_nonce_field('paguro_admin_action', 'paguro_nonce'); ?>
                                <input type="hidden" name="paguro_action" value="extend_expiry">
                                <input type="hidden" name="request_id" value="<?php echo $row->id; ?>">
                                <input type="datetime-local" name="new_expiry" value="<?php echo $expire_val; ?>" style="font-size:11px; width:100%;">
                                <button type="submit" class="button button-small">Aggiorna 📅</button>
                            </form>
                            <?php echo $timer_html; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:flex; flex-wrap:wrap; gap:4px;">
                            <?php wp_nonce_field('paguro_admin_action', 'paguro_nonce'); ?>
                            <input type="hidden" name="request_id" value="<?php echo $row->id; ?>">
                            
                            <?php if ($row->status == 2): ?>
                                <button type="submit" name="paguro_action" value="confirm_booking" class="button button-primary button-small" title="Conferma">💰 OK</button>
                                <button type="submit" name="paguro_action" value="resend_email" class="button button-small" title="Reinvia Mail">✉️ Mail</button>
                            <?php endif; ?>
                            
                            <button type="submit" name="paguro_action" value="delete_row" class="button button-small" onclick="return confirm('Eliminare?');" title="Elimina">❌</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>