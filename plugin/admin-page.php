<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apt   = $wpdb->prefix . 'paguro_apartments';

$base_url = admin_url('admin.php?page=paguro-booking');

// --- 1. GESTIONE SALVATAGGIO DATI (POST) ---
if (isset($_POST['paguro_action'])) {
    if (!check_admin_referer('paguro_admin_action', 'paguro_nonce')) {
        wp_die('Sicurezza violata.');
    }

    // A. SALVA / AGGIORNA PRENOTAZIONE (Manuale o Modifica)
    if ($_POST['paguro_action'] === 'save_booking') {
        $id = intval($_POST['booking_id']);
        
        $data = [
            'apartment_id' => intval($_POST['apartment_id']),
            'date_start'   => sanitize_text_field($_POST['date_start']),
            'date_end'     => sanitize_text_field($_POST['date_end']),
            'guest_name'   => sanitize_text_field($_POST['guest_name']),
            'guest_email'  => sanitize_email($_POST['guest_email']),
            'guest_phone'  => sanitize_text_field($_POST['guest_phone']),
            'status'       => intval($_POST['status']),
        ];

        if ($id > 0) {
            // Update
            $wpdb->update($table_avail, $data, ['id' => $id]);
            echo '<div class="notice notice-success"><p>Prenotazione aggiornata.</p></div>';
        } else {
            // Insert
            $data['created_at'] = current_time('mysql');
            $data['lock_token'] = wp_generate_password(20, false); // Genera token fittizio per coerenza
            $wpdb->insert($table_avail, $data);
            echo '<div class="notice notice-success"><p>Nuova prenotazione inserita.</p></div>';
        }
    }

    // B. CONFERMA (Click Killer logic)
    if ($_POST['paguro_action'] === 'confirm_booking') {
        $req_id = intval($_POST['request_id']);
        $winner = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id = %d", $req_id));
        
        if ($winner) {
            $wpdb->update($table_avail, ['status' => 1], ['id' => $req_id]);
            
            // Elimina competitori sovrapposti
            $losers = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM $table_avail 
                 WHERE apartment_id = %d AND id != %d AND status != 1
                 AND (date_start < %s AND date_end > %s)",
                $winner->apartment_id, $req_id, $winner->date_end, $winner->date_start
            ));

            foreach ($losers as $loser) {
                $wpdb->delete($table_avail, ['id' => $loser->id]);
            }
            echo '<div class="notice notice-success"><p>Confermata! Eliminati ' . count($losers) . ' competitori.</p></div>';
        }
    }

    // C. ELIMINA
    if ($_POST['paguro_action'] === 'delete_row') {
        $wpdb->delete($table_avail, ['id' => intval($_POST['request_id'])]);
        echo '<div class="notice notice-success"><p>Elemento eliminato.</p></div>';
    }

    // D. PULIZIA SCADUTI
    if ($_POST['paguro_action'] === 'cleanup_expired') {
        $wpdb->query("DELETE FROM $table_avail WHERE status = 2 AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
        echo '<div class="notice notice-info"><p>Pulizia effettuata.</p></div>';
    }
}

// --- 2. GESTIONE VISTE (Lista vs Modulo) ---
$view = isset($_GET['view']) ? $_GET['view'] : 'list';
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Recupera lista appartamenti per le select
$apartments = $wpdb->get_results("SELECT * FROM $table_apt");

?>

<div class="wrap">
    <h1 class="wp-heading-inline">Gestione Paguro Booking 🦀</h1>
    
    <?php if ($view == 'list'): ?>
        <a href="<?php echo $base_url . '&view=edit'; ?>" class="page-title-action">Aggiungi Nuova</a>
        <hr class="wp-header-end">

        <form method="post" style="margin-bottom: 20px;">
            <?php wp_nonce_field('paguro_admin_action', 'paguro_nonce'); ?>
            <input type="hidden" name="paguro_action" value="cleanup_expired">
            <button type="submit" class="button">🧹 Pulisci Richieste Scadute (>48h)</button>
        </form>

        <?php
        $rows = $wpdb->get_results("
            SELECT av.*, apt.name as apt_name 
            FROM $table_avail av 
            JOIN $table_apt apt ON av.apartment_id = apt.id 
            ORDER BY av.date_start DESC, av.status ASC, av.created_at ASC
        ");
        ?>

        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th style="width: 120px;">Stato</th>
                    <th style="width: 150px;">Appartamento</th>
                    <th style="width: 180px;">Dal - Al</th>
                    <th>Ospite / Contatti</th>
                    <th>Info Sistema</th>
                    <th style="width: 250px;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6">Nessuna prenotazione trovata.</td></tr>
                <?php else: foreach ($rows as $row): 
                    $start = date('d/m/Y', strtotime($row->date_start));
                    $end   = date('d/m/Y', strtotime($row->date_end));
                    $created = $row->created_at ? date('d/m H:i', strtotime($row->created_at)) : '-';
                    
                    if ($row->status == 1) {
                        $status_html = '<span class="dashicons dashicons-yes" style="color:green"></span> <strong>Confermata</strong>';
                        $bg_style = 'background-color:#e7f7ed;';
                    } elseif ($row->status == 2) {
                        $status_html = '<span class="dashicons dashicons-clock" style="color:orange"></span> <strong>Richiesta</strong>';
                        $bg_style = 'background-color:#fff8e5;';
                    } else {
                        $status_html = '<span style="color:grey">Lock Temp</span>';
                        $bg_style = '';
                    }
                ?>
                    <tr style="<?php echo $bg_style; ?>">
                        <td><?php echo $status_html; ?></td>
                        <td><?php echo esc_html($row->apt_name); ?></td>
                        <td><?php echo "$start - $end"; ?></td>
                        <td>
                            <strong><?php echo $row->guest_name ? esc_html($row->guest_name) : '<em>(In attesa dati)</em>'; ?></strong><br>
                            <?php if($row->guest_email): ?><a href="mailto:<?php echo esc_attr($row->guest_email); ?>"><?php echo esc_html($row->guest_email); ?></a><br><?php endif; ?>
                            <?php echo esc_html($row->guest_phone); ?>
                        </td>
                        <td>
                            <small>Token: <?php echo substr($row->lock_token, 0, 8); ?>...</small><br>
                            <small>Creato: <?php echo $created; ?></small>
                            <?php 
                                if($row->status == 2 && $row->created_at) {
                                    $expire = strtotime($row->created_at) + (48*3600);
                                    $diff = floor(($expire - time()) / 3600);
                                    if($diff > 0) echo "<br><span style='color:red'>Scade in $diff ore</span>";
                                    else echo "<br><span style='color:red;font-weight:bold'>SCADUTA</span>";
                                }
                            ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                <a href="<?php echo $base_url . '&view=edit&id=' . $row->id; ?>" class="button button-small">✏️ Modifica</a>

                                <form method="post">
                                    <?php wp_nonce_field('paguro_admin_action', 'paguro_nonce'); ?>
                                    <input type="hidden" name="request_id" value="<?php echo $row->id; ?>">
                                    
                                    <?php if ($row->status != 1): ?>
                                        <button type="submit" name="paguro_action" value="confirm_booking" class="button button-primary button-small" onclick="return confirm('Confermi e cancelli le sovrapposizioni?');">💰 Incassa</button>
                                    <?php endif; ?>

                                    <button type="submit" name="paguro_action" value="delete_row" class="button button-link-delete button-small" onclick="return confirm('Eliminare?');">❌</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

    <?php elseif ($view == 'edit'): ?>
        <?php
            $entry = null;
            if ($edit_id > 0) {
                $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id = %d", $edit_id));
            }
            // Valori di default
            $e_apt   = $entry ? $entry->apartment_id : 0;
            $e_start = $entry ? $entry->date_start : date('Y-m-d');
            $e_end   = $entry ? $entry->date_end : date('Y-m-d', strtotime('+7 days'));
            $e_name  = $entry ? $entry->guest_name : '';
            $e_email = $entry ? $entry->guest_email : '';
            $e_phone = $entry ? $entry->guest_phone : '';
            $e_status= $entry ? $entry->status : 1; // Default Confermata se inserita a mano
        ?>

        <h2><?php echo ($edit_id > 0) ? 'Modifica Prenotazione' : 'Nuova Prenotazione Manuale'; ?></h2>
        
        <form method="post" action="<?php echo $base_url; ?>" style="max-width: 600px; background:#fff; padding:20px; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04);">
            <?php wp_nonce_field('paguro_admin_action', 'paguro_nonce'); ?>
            <input type="hidden" name="paguro_action" value="save_booking">
            <input type="hidden" name="booking_id" value="<?php echo $edit_id; ?>">

            <table class="form-table">
                <tr>
                    <th><label>Appartamento</label></th>
                    <td>
                        <select name="apartment_id" required>
                            <?php foreach ($apartments as $apt): ?>
                                <option value="<?php echo $apt->id; ?>" <?php selected($e_apt, $apt->id); ?>>
                                    <?php echo esc_html($apt->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Date (Check-In / Check-Out)</label></th>
                    <td>
                        <input type="date" name="date_start" value="<?php echo $e_start; ?>" required>
                        <span style="margin:0 10px;">➔</span>
                        <input type="date" name="date_end" value="<?php echo $e_end; ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label>Dati Ospite</label></th>
                    <td>
                        <input type="text" name="guest_name" value="<?php echo esc_attr($e_name); ?>" placeholder="Nome Cognome" class="regular-text"><br><br>
                        <input type="email" name="guest_email" value="<?php echo esc_attr($e_email); ?>" placeholder="Email" class="regular-text"><br><br>
                        <input type="text" name="guest_phone" value="<?php echo esc_attr($e_phone); ?>" placeholder="Telefono" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label>Stato</label></th>
                    <td>
                        <select name="status">
                            <option value="1" <?php selected($e_status, 1); ?>>✅ Confermata (Blocca date)</option>
                            <option value="2" <?php selected($e_status, 2); ?>>⏳ Richiesta (48h)</option>
                            <option value="0" <?php selected($e_status, 0); ?>>🔒 Temporanea (15 min)</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary button-large">💾 Salva Prenotazione</button>
                <a href="<?php echo $base_url; ?>" class="button button-secondary button-large">Annulla</a>
            </p>
        </form>

    <?php endif; ?>
</div>