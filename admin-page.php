<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apts  = $wpdb->prefix . 'paguro_apartments';
$table_prices= $wpdb->prefix . 'paguro_prices';
$active_tab  = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';

// --- LOGICA GESTIONE DATI (POST) ---

// 1. APPARTAMENTI: Salva
if (isset($_POST['paguro_add_apt']) && check_admin_referer('paguro_manage_apt')) {
    $wpdb->insert($table_apts, [
        'name' => sanitize_text_field($_POST['apt_name']), 
        'base_price' => floatval($_POST['apt_price'])
    ]);
    echo '<div class="notice notice-success"><p>Appartamento aggiunto!</p></div>';
}
// APPARTAMENTI: Elimina
if (isset($_GET['action']) && $_GET['action'] == 'delete_apt' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $wpdb->delete($table_avail, ['apartment_id' => $id]); 
    $wpdb->delete($table_prices, ['apartment_id' => $id]); 
    $wpdb->delete($table_apts, ['id' => $id]);
    echo '<div class="notice notice-success"><p>Appartamento eliminato.</p></div>';
}

// 2. PREZZI: Salva Regola Singola
if (isset($_POST['paguro_add_price']) && check_admin_referer('paguro_add_price')) {
    $wpdb->insert($table_prices, [
        'apartment_id' => intval($_POST['apartment_id']),
        'date_start'   => sanitize_text_field($_POST['start_date']),
        'date_end'     => sanitize_text_field($_POST['end_date']),
        'weekly_price' => floatval($_POST['weekly_price'])
    ]);
    echo '<div class="notice notice-success"><p>Prezzo stagionale impostato!</p></div>';
}

// 2.1 PREZZI: Copia Calendario (NUOVO)
if (isset($_POST['paguro_copy_prices']) && check_admin_referer('paguro_copy_prices')) {
    $source_id = intval($_POST['source_apt_id']);
    $target_ids = isset($_POST['target_apt_ids']) ? $_POST['target_apt_ids'] : [];

    if ($source_id && !empty($target_ids)) {
        // Prendo tutte le regole del sorgente
        $source_rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_prices WHERE apartment_id = %d", $source_id));
        
        $count = 0;
        foreach ($target_ids as $target_id) {
            foreach ($source_rules as $rule) {
                $wpdb->insert($table_prices, [
                    'apartment_id' => intval($target_id),
                    'date_start'   => $rule->date_start,
                    'date_end'     => $rule->date_end,
                    'weekly_price' => $rule->weekly_price
                ]);
                $count++;
            }
        }
        echo '<div class="notice notice-success"><p>Copiator ' . $count . ' regole di prezzo su ' . count($target_ids) . ' appartamenti.</p></div>';
    }
}

// PREZZI: Elimina
if (isset($_GET['action']) && $_GET['action'] == 'delete_price' && isset($_GET['id'])) {
    $wpdb->delete($table_prices, ['id' => intval($_GET['id'])]);
    echo '<div class="notice notice-success"><p>Regola rimossa.</p></div>';
}

// 3. PRENOTAZIONI: Salva (Insert o Update)
if (isset($_POST['paguro_save_booking']) && check_admin_referer('paguro_save_booking')) {
    $data = [
        'apartment_id' => intval($_POST['apartment_id']),
        'date_start'   => sanitize_text_field($_POST['start_date']),
        'date_end'     => sanitize_text_field($_POST['end_date']),
        'status'       => 2, 
        'guest_name'   => sanitize_text_field($_POST['guest_name']),
        'guest_email'  => sanitize_email($_POST['guest_email']),
        'guest_phone'  => sanitize_text_field($_POST['guest_phone']),
    ];

    if (!empty($_POST['booking_id'])) {
        // UPDATE
        $wpdb->update($table_avail, $data, ['id' => intval($_POST['booking_id'])]);
        echo '<div class="notice notice-success"><p>Prenotazione aggiornata con successo.</p></div>';
        // Rimuovo action query string per pulire l'URL dopo il save
        $_GET['action'] = ''; 
    } else {
        // INSERT
        $wpdb->insert($table_avail, $data);
        echo '<div class="notice notice-success"><p>Nuova prenotazione salvata.</p></div>';
    }
}
// PRENOTAZIONI: Elimina
if (isset($_GET['action']) && $_GET['action'] == 'delete_booking' && isset($_GET['id'])) {
    $wpdb->delete($table_avail, ['id' => intval($_GET['id'])]);
    echo '<div class="notice notice-success"><p>Prenotazione cancellata.</p></div>';
}

// --- RECUPERO DATI PER VISUALIZZAZIONE ---
$apartments = $wpdb->get_results("SELECT * FROM $table_apts");

// Gestione Edit Mode Prenotazione
$edit_booking = null;
if ($active_tab == 'bookings' && isset($_GET['action']) && $_GET['action'] == 'edit_booking' && isset($_GET['id'])) {
    $edit_booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_avail WHERE id = %d", intval($_GET['id'])));
}

$bookings = $wpdb->get_results("
    SELECT v.*, a.name 
    FROM $table_avail v 
    JOIN $table_apts a ON v.apartment_id = a.id 
    ORDER BY v.date_start ASC
");
$prices = $wpdb->get_results("
    SELECT p.*, a.name 
    FROM $table_prices p 
    JOIN $table_apts a ON p.apartment_id = a.id 
    ORDER BY p.date_start ASC, a.name ASC
");
?>

<div class="wrap">
    <h1>🦀 Gestione Paguro v1.2</h1>
    
    <h2 class="nav-tab-wrapper">
        <a href="?page=paguro-booking&tab=bookings" class="nav-tab <?php echo $active_tab == 'bookings' ? 'nav-tab-active' : ''; ?>">📅 Calendario & Ospiti</a>
        <a href="?page=paguro-booking&tab=prices" class="nav-tab <?php echo $active_tab == 'prices' ? 'nav-tab-active' : ''; ?>">💰 Listino Prezzi</a>
        <a href="?page=paguro-booking&tab=apartments" class="nav-tab <?php echo $active_tab == 'apartments' ? 'nav-tab-active' : ''; ?>">🏠 Appartamenti</a>
    </h2>

    <?php if ($active_tab == 'bookings'): ?>
        <div style="margin-top: 20px;">
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h3><?php echo $edit_booking ? 'Modifica Prenotazione' : 'Nuova Prenotazione Manuale'; ?></h3>
                
                <form method="post">
                    <?php wp_nonce_field('paguro_save_booking'); ?>
                    <?php if ($edit_booking): ?>
                        <input type="hidden" name="booking_id" value="<?php echo $edit_booking->id; ?>">
                    <?php endif; ?>
                    
                    <div style="display:flex; gap:20px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:300px;">
                            <h4>Dati Soggiorno</h4>
                            <p><label>Appartamento:</label><br>
                            <select name="apartment_id" required style="width:100%">
                                <?php foreach ($apartments as $apt): ?>
                                    <option value="<?php echo $apt->id; ?>" <?php selected($edit_booking ? $edit_booking->apartment_id : '', $apt->id); ?>>
                                        <?php echo esc_html($apt->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select></p>
                            
                            <div style="display:flex; gap:10px;">
                                <p style="flex:1"><label>Check-In:</label><br>
                                <input type="date" name="start_date" required style="width:100%" value="<?php echo $edit_booking ? $edit_booking->date_start : ''; ?>"></p>
                                <p style="flex:1"><label>Check-Out:</label><br>
                                <input type="date" name="end_date" required style="width:100%" value="<?php echo $edit_booking ? $edit_booking->date_end : ''; ?>"></p>
                            </div>
                        </div>

                        <div style="flex:1; min-width:300px; border-left:1px solid #eee; padding-left:20px;">
                            <h4>Dati Ospite</h4>
                            <p><label>Nome e Cognome:</label><br>
                            <input type="text" name="guest_name" placeholder="Mario Rossi" style="width:100%" value="<?php echo $edit_booking ? esc_attr($edit_booking->guest_name) : ''; ?>"></p>
                            
                            <div style="display:flex; gap:10px;">
                                <p style="flex:1"><label>Email:</label><br>
                                <input type="email" name="guest_email" placeholder="email@esempio.com" style="width:100%" value="<?php echo $edit_booking ? esc_attr($edit_booking->guest_email) : ''; ?>"></p>
                                <p style="flex:1"><label>Cellulare:</label><br>
                                <input type="text" name="guest_phone" placeholder="+39 333..." style="width:100%" value="<?php echo $edit_booking ? esc_attr($edit_booking->guest_phone) : ''; ?>"></p>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <input type="submit" name="paguro_save_booking" class="button button-primary" value="<?php echo $edit_booking ? 'Aggiorna Prenotazione' : 'Registra Prenotazione'; ?>">
                    <?php if ($edit_booking): ?>
                        <a href="?page=paguro-booking&tab=bookings" class="button">Annulla</a>
                    <?php endif; ?>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Appartamento</th>
                        <th>Periodo</th>
                        <th>Ospite</th>
                        <th>Contatti</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr><td colspan="5">Nessuna prenotazione futura.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $row): ?>
                            <tr>
                                <td><strong><?php echo esc_html($row->name); ?></strong></td>
                                <td>
                                    IN: <?php echo date('d/m/Y', strtotime($row->date_start)); ?><br>
                                    OUT: <?php echo date('d/m/Y', strtotime($row->date_end)); ?>
                                </td>
                                <td><?php echo esc_html($row->guest_name); ?></td>
                                <td>
                                    <?php echo esc_html($row->guest_email); ?><br>
                                    <?php echo esc_html($row->guest_phone); ?>
                                </td>
                                <td>
                                    <a href="?page=paguro-booking&tab=bookings&action=edit_booking&id=<?php echo $row->id; ?>" class="button button-small">Modifica</a>
                                    <a href="?page=paguro-booking&tab=bookings&action=delete_booking&id=<?php echo $row->id; ?>" style="color:red; margin-left:10px;" onclick="return confirm('Vuoi cancellare questa prenotazione?');">Cancella</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($active_tab == 'prices'): ?>
        <div style="margin-top: 20px;">
            <div style="display:flex; gap: 20px; align-items: flex-start;">
                
                <div style="flex:2; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                    <h3>➕ Aggiungi Periodo</h3>
                    <form method="post">
                        <?php wp_nonce_field('paguro_add_price'); ?>
                        <p>
                            <label>Appartamento:</label><br>
                            <select name="apartment_id" required style="width:100%">
                                <?php foreach ($apartments as $apt): ?>
                                    <option value="<?php echo $apt->id; ?>"><?php echo esc_html($apt->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <div style="display:flex; gap:10px;">
                            <p style="flex:1"><label>Da:</label><br><input type="date" name="start_date" required style="width:100%"></p>
                            <p style="flex:1"><label>A:</label><br><input type="date" name="end_date" required style="width:100%"></p>
                        </div>
                        <p><label>Prezzo/Settimana (€):</label><br><input type="number" step="0.01" name="weekly_price" required style="width:100%"></p>
                        <input type="submit" name="paguro_add_price" class="button button-primary" value="Salva">
                    </form>
                </div>

                <div style="flex:1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1;">
                    <h3>📋 Clona Listino</h3>
                    <p>Copia tutte le regole da un appartamento agli altri.</p>
                    <form method="post">
                        <?php wp_nonce_field('paguro_copy_prices'); ?>
                        <p>
                            <label><b>DA</b> (Sorgente):</label><br>
                            <select name="source_apt_id" required style="width:100%">
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($apartments as $apt): ?>
                                    <option value="<?php echo $apt->id; ?>"><?php echo esc_html($apt->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label><b>A</b> (Destinazioni):</label><br>
                            <div style="max-height: 100px; overflow-y:auto; border:1px solid #ddd; padding:5px;">
                                <?php foreach ($apartments as $apt): ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="target_apt_ids[]" value="<?php echo $apt->id; ?>"> <?php echo esc_html($apt->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </p>
                        <input type="submit" name="paguro_copy_prices" class="button" value="Copia Regole">
                    </form>
                </div>

            </div>

            <br>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Appartamento</th><th>Periodo Validità</th><th>Prezzo Settimanale</th><th>Azioni</th></tr></thead>
                <tbody>
                    <?php if (empty($prices)): ?>
                        <tr><td colspan="4">Nessun prezzo speciale impostato.</td></tr>
                    <?php else: ?>
                        <?php foreach ($prices as $p): ?>
                            <tr>
                                <td><strong><?php echo esc_html($p->name); ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($p->date_start)) . ' ➔ ' . date('d/m/Y', strtotime($p->date_end)); ?></td>
                                <td>€ <?php echo number_format($p->weekly_price, 2); ?></td>
                                <td><a href="?page=paguro-booking&tab=prices&action=delete_price&id=<?php echo $p->id; ?>" style="color:red;" onclick="return confirm('Rimuovere?');">Rimuovi</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($active_tab == 'apartments'): ?>
        <div style="margin-top: 20px;">
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px; max-width: 500px;">
                <h3>Configurazione Appartamenti</h3>
                <form method="post">
                    <?php wp_nonce_field('paguro_manage_apt'); ?>
                    <p><label>Nome Appartamento:</label><br><input type="text" name="apt_name" required class="regular-text" style="width:100%"></p>
                    <p><label>Prezzo Base (Bassa stagione):</label><br><input type="number" step="0.01" name="apt_price" required style="width:100%"></p>
                    <input type="submit" name="paguro_add_apt" class="button button-primary" value="Salva Appartamento">
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>ID</th><th>Nome</th><th>Prezzo Base</th><th>Azioni</th></tr></thead>
                <tbody>
                    <?php foreach ($apartments as $apt): ?>
                        <tr>
                            <td><?php echo $apt->id; ?></td>
                            <td><strong><?php echo esc_html($apt->name); ?></strong></td>
                            <td>€ <?php echo number_format($apt->base_price, 2); ?></td>
                            <td><a href="?page=paguro-booking&tab=apartments&action=delete_apt&id=<?php echo $apt->id; ?>" style="color:red;" onclick="return confirm('ATTENZIONE: Eliminando l\'appartamento cancellerai anche tutte le prenotazioni future associate. Procedere?');">Elimina</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>