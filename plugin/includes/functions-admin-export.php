<?php
/**
 * Admin CSV export handlers
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('paguro_csv_safe_cell')) {
    /**
     * Neutralizza la CSV/Formula injection (OWASP): i valori che iniziano con
     * = + - @ TAB CR vengono interpretati come formule da Excel/LibreOffice.
     * Si antepone un apostrofo, che il foglio di calcolo non mostra.
     * Interi e float passano invariati (sono numeri, non testo arbitrario).
     */
    function paguro_csv_safe_cell($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $value = (string) $value;
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }
        return $value;
    }
}

if (!function_exists('paguro_send_csv_download')) {
    function paguro_send_csv_download($filename, $headers, $rows)
    {
        if (headers_sent()) {
            wp_die(esc_html__('Impossibile inviare il CSV: output già iniziato.', 'paguro'));
        }

        nocache_headers();
        send_nosniff_header();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('Impossibile generare il file CSV.', 'paguro'));
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_map('paguro_csv_safe_cell', $headers), ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, array_map('paguro_csv_safe_cell', (array) $row), ';', '"', '\\');
        }
        fclose($output);
        exit;
    }
}

if (!function_exists('paguro_admin_csv_money_format')) {
    function paguro_admin_csv_money_format($value)
    {
        return number_format((float) $value, 2, ',', '');
    }
}

// Helper condivisi con admin/admin-dashboard.php (definiti qui perché questo file
// è sempre caricato, mentre la dashboard solo nella pagina admin).
if (!function_exists('paguro_get_booking_status_label_for_export')) {
    function paguro_get_booking_status_label_for_export($status, $receipt_url = '')
    {
        $status = intval($status);
        if ($status === 2 && !empty($receipt_url)) {
            return 'Distinta (in validazione)';
        }

        $labels = [
            1 => 'Confermata',
            2 => 'Preventivo',
            3 => 'Cancellata',
            4 => 'Waitlist',
            5 => 'Richiesta cancellazione',
        ];

        return $labels[$status] ?? 'Sconosciuto';
    }
}

if (!function_exists('paguro_get_booking_summary_url')) {
    function paguro_get_booking_summary_url($booking)
    {
        if (!$booking || empty($booking->lock_token)) return '';

        $query_args = ['token' => $booking->lock_token];
        if (intval($booking->status) === 4) {
            $query_args['waitlist'] = 1;
        }

        return add_query_arg(
            $query_args,
            site_url('/' . get_option('paguro_page_slug', 'riepilogo-prenotazione') . '/')
        );
    }
}

add_action('admin_post_paguro_export_csv', 'paguro_admin_handle_export_csv');
function paguro_admin_handle_export_csv()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Non autorizzato.', 'paguro'), '', ['response' => 403]);
    }

    $export_type = sanitize_key(wp_unslash($_GET['export'] ?? ''));
    if (!in_array($export_type, ['active_bookings', 'finance'], true)) {
        wp_die(esc_html__('Tipo export non valido.', 'paguro'), '', ['response' => 400]);
    }

    check_admin_referer('paguro_export_' . $export_type, 'paguro_nonce');

    global $wpdb;
    $table_apt = $wpdb->prefix . 'paguro_apartments';

    $apt_rows_export = $wpdb->get_results("SELECT id, name, apt_url FROM {$table_apt} ORDER BY name ASC");
    $apt_map_export = [];
    foreach ((array) $apt_rows_export as $apt_row_export) {
        $apt_map_export[intval($apt_row_export->id)] = [
            'name' => (string) ($apt_row_export->name ?? ''),
            'url' => (string) ($apt_row_export->apt_url ?? ''),
        ];
    }

    if ($export_type === 'active_bookings') {
        // Nota: la colonna "Token" (lock_token in chiaro) è stata rimossa: è una
        // credenziale di accesso alla prenotazione. Resta il link riepilogo, utile
        // all'amministratore: trattare il file come dato riservato.
        $bookings_export = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}paguro_availability WHERE status <> 3 ORDER BY created_at DESC, id DESC"
        );
        $rows = [];
        foreach ((array) $bookings_export as $booking_export) {
            $apt_export = $apt_map_export[intval($booking_export->apartment_id)] ?? ['name' => '', 'url' => ''];
            $rows[] = [
                intval($booking_export->id),
                (string) ($booking_export->group_id ?? ''),
                isset($booking_export->group_seq) ? intval($booking_export->group_seq) : '',
                (string) ($booking_export->guest_name ?? ''),
                (string) ($booking_export->guest_email ?? ''),
                (string) ($booking_export->guest_phone ?? ''),
                (string) ($apt_export['name'] ?? ''),
                (string) paguro_get_apartment_public_url($booking_export->apartment_id ?? 0, $apt_export['url'] ?? ''),
                (string) ($booking_export->date_start ?? ''),
                (string) ($booking_export->date_end ?? ''),
                paguro_get_booking_status_label_for_export($booking_export->status ?? 0, $booking_export->receipt_url ?? ''),
                !empty($booking_export->receipt_url) ? 'Si' : 'No',
                (string) ($booking_export->receipt_url ?? ''),
                isset($booking_export->manual_price) ? paguro_admin_csv_money_format($booking_export->manual_price) : '',
                isset($booking_export->manual_discount) ? paguro_admin_csv_money_format($booking_export->manual_discount) : '',
                (string) ($booking_export->created_at ?? ''),
                (string) ($booking_export->lock_expires ?? ''),
                paguro_get_booking_summary_url($booking_export),
            ];
        }

        paguro_send_csv_download(
            'paguro-prenotazioni-attive-' . date('Y-m-d') . '.csv',
            ['ID', 'Gruppo', 'Sequenza gruppo', 'Guest', 'Email', 'Telefono', 'Appartamento', 'Link appartamento', 'Arrivo', 'Partenza', 'Stato', 'Distinta caricata', 'URL distinta', 'Prezzo manuale', 'Sconto manuale', 'Creata il', 'Scadenza blocco', 'Link riepilogo'],
            $rows
        );
    }

    $season_start_fin = get_option('paguro_season_start', '2026-06-01');
    $season_end_fin = get_option('paguro_season_end', '2026-09-30');
    $deposit_percent = intval(get_option('paguro_deposit_percent', 30));
    $finance_deposit_overrides = get_option('paguro_finance_deposit_overrides', []);
    if (!is_array($finance_deposit_overrides)) $finance_deposit_overrides = [];
    $finance_received_overrides = get_option('paguro_finance_received_overrides', []);
    if (!is_array($finance_received_overrides)) $finance_received_overrides = [];

    // SELECT * mantenuto: paguro_get_booking_total_cost() può leggere più colonne.
    $finance_bookings_export = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}paguro_availability
         WHERE status = 1 AND date_end >= %s AND date_start <= %s
         ORDER BY date_start ASC, id ASC",
        $season_start_fin,
        $season_end_fin
    ));

    $rows = [];
    foreach ((array) $finance_bookings_export as $booking_export) {
        $apt_export = $apt_map_export[intval($booking_export->apartment_id)] ?? ['name' => '', 'url' => ''];
        $total_cost = function_exists('paguro_get_booking_total_cost') ? paguro_get_booking_total_cost($booking_export) : 0;
        $total_cost = max(0, round((float) $total_cost, 2));
        $booking_key = (string) intval($booking_export->id ?? 0);
        $deposit = $total_cost > 0 ? ceil($total_cost * ($deposit_percent / 100)) : 0;
        if (array_key_exists($booking_key, $finance_deposit_overrides)) {
            $deposit = max(0, round((float) $finance_deposit_overrides[$booking_key], 2));
        }
        if ($deposit > $total_cost) $deposit = $total_cost;

        $deposit_received = $deposit;
        if (array_key_exists($booking_key, $finance_received_overrides)) {
            $deposit_received = max(0, round((float) $finance_received_overrides[$booking_key], 2));
        }
        if ($deposit_received > $total_cost) $deposit_received = $total_cost;

        $remaining = max(0, $total_cost - $deposit_received);
        $forecast = $remaining;
        $refund = 0;

        $rows[] = [
            intval($booking_export->id),
            (string) ($booking_export->group_id ?? ''),
            isset($booking_export->group_seq) ? intval($booking_export->group_seq) : '',
            (string) ($booking_export->guest_name ?? ''),
            (string) ($booking_export->guest_email ?? ''),
            (string) ($apt_export['name'] ?? ''),
            (string) paguro_get_apartment_public_url($booking_export->apartment_id ?? 0, $apt_export['url'] ?? ''),
            (string) ($booking_export->date_start ?? ''),
            (string) ($booking_export->date_end ?? ''),
            paguro_admin_csv_money_format($total_cost),
            paguro_admin_csv_money_format($deposit),
            paguro_admin_csv_money_format($deposit_received),
            paguro_admin_csv_money_format($remaining),
            paguro_admin_csv_money_format($forecast),
            paguro_admin_csv_money_format($refund),
            $season_start_fin,
            $season_end_fin,
            paguro_get_booking_summary_url($booking_export),
        ];
    }

    paguro_send_csv_download(
        'paguro-situazione-economica-' . date('Y-m-d') . '.csv',
        ['ID', 'Gruppo', 'Sequenza gruppo', 'Guest', 'Email', 'Appartamento', 'Link appartamento', 'Arrivo', 'Partenza', 'Totale', 'Acconto', 'Acconto ricevuto', 'Saldo previsto', 'Incasso previsto', 'Rimborso', 'Report da', 'Report a', 'Link riepilogo'],
        $rows
    );
}
