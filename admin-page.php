<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
$table_avail = $wpdb->prefix . 'paguro_availability';
$table_apts  = $wpdb->prefix . 'paguro_apartments';
$table_prices= $wpdb->prefix . 'paguro_prices';
$active_tab  = isset($_GET['tab']) ? $_GET['tab'] : 'bookings';

// --- AZIONE: SALVA APPARTAMENTO ---
if (isset($_POST['paguro_add_apt']) && check_admin_referer('paguro_manage_apt')) {
    $wpdb->insert($table_apts, [
        'name' => sanitize_text_field($_POST['apt_name']), 
        'base_price' => floatval($_POST['apt_price'])
    ]);
    echo '<div class="notice notice-success"><p>Appartamento aggiunto!</p></div>';
}
// --- AZIONE: ELIMINA APPARTAMENTO ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_apt' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $wpdb->delete($table_avail, ['apartment_id' => $id]); // Pulisce prenotazioni
    $wpdb->delete($table_prices, ['apartment_id' => $id]); // Pulisce prezzi
    $wpdb->delete($table_apts, ['id' => $id]);
    echo '<div class="notice notice-success"><p>Appartamento eliminato.</p></div>';
}

// --- AZIONE: SALVA PREZZO STAGIONALE ---
if (isset($_POST['paguro_add_price']) && check_admin_referer('paguro_add_price')) {
    $wpdb->insert($table_prices, [
        'apartment_id' => intval($_POST['apartment_id']),
        'date_start'   => sanitize_text_field($_POST['start_date']),
        'date_end'     => sanitize_text_field($_POST['end_date']),
        'weekly_price' => floatval($_POST['weekly_price'])
    ]);
    echo '<div class="notice notice-success"><p>Prezzo stagionale impostato!</p></div>';
}
// --- AZIONE: ELIMINA PREZZO ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_price' && isset($_GET['id'])) {
    $wpdb->delete($table_prices, ['id' => intval($_GET['id'])]);
    echo '<div class="notice notice-success"><p>Regola di prezzo rimossa.</p></div>';
}

// --- AZIONE: SALVA PRENOTAZIONE (Manuale) ---
if (isset($_POST['paguro_add_booking']) && check_admin_referer('paguro_add_booking')) {
    $result = $wpdb->insert($table_avail, [
        'apartment_id' => intval($_POST['apartment_id']),
        'date_start'   => sanitize_text_field($_POST['start_date']),
        'date_end'     => sanitize_text_field($_POST['end_date']),
        'status'       => 2, // 2 = Prenotato manuale
        'guest_name'   => sanitize_text_field($_POST['guest_name']),
        'guest_email'  => sanitize_email($_POST['guest_email']),
        'guest_phone'  => sanitize_text_field($_POST['guest_phone']),
    ]);

    if ($result === false) {
        // QUI VEDRAI IL VERO PROBLEMA
        echo '<div class="notice notice-error"><p>ERRORE DATABASE: ' . $wpdb->last_error . '</p></div>';
    } else {
        echo '<div class="notice notice-success"><p>Prenotazione salvata (Calendario bloccato).</p></div>';
    }
}
// --- AZIONE: ELIMINA PRENOTAZIONE ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_booking' && isset($_GET['id'])) {
    $wpdb->delete($table_avail, ['id' => intval($_GET['id'])]);
    echo '<div class="notice notice-success"><p>Prenotazione cancellata. Date liberate.</p></div>';
}

// RECUPERO DATI DAL DB
$apartments = $wpdb->get_results("SELECT * FROM $table_apts");
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
    ORDER BY p.date_start ASC
");
?>

<div class="wrap">
    <h1>🦀 Gestione Paguro v1.1</h1>
    
    <h2 class="nav-tab-wrapper">
        <a href="?page=paguro-booking&tab=bookings" class="nav-tab <?php echo $active_tab == 'bookings' ? 'nav-tab-active' : ''; ?>">📅 Calendario & Ospiti</a>
        <a href="?page=paguro-booking&tab=prices" class="nav-tab <?php echo $active_tab == 'prices' ? 'nav-tab-active' : ''; ?>">💰 Listino Prezzi</a>
        <a href="?page=paguro-booking&tab=apartments" class="nav-tab <?php echo $active_tab == 'apartments' ? 'nav-tab-active' : ''; ?>">🏠 Appartamenti</a>
    </h2>

    <?php if ($active_tab == 'bookings'): ?>
        <div style="margin-top: 20px;">
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h3>Nuova Prenotazione Manuale</h3>
                <form method="post">
                    <?php wp_nonce_field('paguro_add_booking'); ?>
                    
                    <div style="display:flex; gap:20px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:300px;">
                            <h4>Dati Soggiorno</h4>
                            <p><label>Appartamento:</label><br>
                            <select name="apartment_id" required style="width:100%">
                                <?php foreach ($apartments as $apt): ?>
                                    <option value="<?php echo $apt->id; ?>"><?php echo esc_html($apt->name); ?></option>
                                <?php endforeach; ?>
                            </select></p>
                            
                            <div style="display:flex; gap:10px;">
                                <p style="flex:1"><label>Check-In (Sabato):</label><br>
                                <input type="date" name="start_date" required style="width:100%"></p>
                                <p style="flex:1"><label>Check-Out (Sabato):</label><br>
                                <input type="date" name="end_date" required style="width:100%"></p>
                            </div>
                        </div>

                        <div style="flex:1; min-width:300px; border-left:1px solid #eee; padding-left:20px;">
                            <h4>Dati Ospite</h4>
                            <p><label>Nome e Cognome:</label><br>
                            <input type="text" name="guest_name" placeholder="Mario Rossi" style="width:100%"></p>
                            
                            <div style="display:flex; gap:10px;">
                                <p style="flex:1"><label>Email:</label><br>
                                <input type="email" name="guest_email" placeholder="email@esempio.com" style="width:100%"></p>
                                <p style="flex:1"><label>Cellulare:</label><br>
                                <input type="text" name="guest_phone" placeholder="+39 333..." style="width:100%"></p>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <input type="submit" name="paguro_add_booking" class="button button-primary" value="Registra Prenotazione">
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
                        <tr><td colspan="5">Nessuna prenotazione futura. Il calendario è tutto LIBERO.</td></tr>
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
                                <td><a href="?page=paguro-booking&tab=bookings&action=delete_booking&id=<?php echo $row->id; ?>" style="color:red;" onclick="return confirm('Vuoi cancellare questa prenotazione?');">Cancella</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($active_tab == 'prices'): ?>
        <div style="margin-top: 20px;">
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px; max-width: 700px;">
                <h3>Imposta Prezzo Stagionale</h3>
                <p>Usa questo modulo per definire prezzi diversi (es. Alta Stagione).<br>
                <i>Nota: Se una data non rientra in questi periodi, verrà usato il prezzo base dell'appartamento.</i></p>
                
                <form method="post">
                    <?php wp_nonce_field('paguro_add_price'); ?>
                    <div style="display:flex; gap:10px; align-items:flex-end;">
                        <div style="flex:1">
                            <label>Appartamento:</label><br>
                            <select name="apartment_id" required style="width:100%">
                                <?php foreach ($apartments as $apt): ?>
                                    <option value="<?php echo $apt->id; ?>"><?php echo esc_html($apt->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex:1">
                            <label>Da:</label><br><input type="date" name="start_date" required style="width:100%">
                        </div>
                        <div style="flex:1">
                            <label>A:</label><br><input type="date" name="end_date" required style="width:100%">
                        </div>
                        <div style="flex:1">
                            <label>€/Settimana:</label><br><input type="number" step="0.01" name="weekly_price" required style="width:100%">
                        </div>
                    </div>
                    <div style="margin-top:10px;">
                        <input type="submit" name="paguro_add_price" class="button button-primary" value="Salva Regola Prezzo">
                    </div>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Appartamento</th><th>Periodo Validità</th><th>Prezzo Settimanale</th><th>Azioni</th></tr></thead>
                <tbody>
                    <?php if (empty($prices)): ?>
                        <tr><td colspan="4">Nessun prezzo speciale impostato. Verranno usati sempre i prezzi base.</td></tr>
                    <?php else: ?>
                        <?php foreach ($prices as $p): ?>
                            <tr>
                                <td><strong><?php echo esc_html($p->name); ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($p->date_start)) . ' ➔ ' . date('d/m/Y', strtotime($p->date_end)); ?></td>
                                <td>€ <?php echo number_format($p->weekly_price, 2); ?></td>
                                <td><a href="?page=paguro-booking&tab=prices&action=delete_price&id=<?php echo $p->id; ?>" style="color:red;" onclick="return confirm('Rimuovere questo prezzo?');">Rimuovi</a></td>
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