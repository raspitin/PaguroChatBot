<div class="wrap">
    <h1>Paguro ChatBot - Configurazione Frasi e Parole Chiave</h1>
    <p>Qui puoi gestire le frasi di presentazione e le parole chiave che attivano la logica di prenotazione automatica, differenziandole dai fallback di Ollama.</p>
    
    <h2>Frasi di Cortesia e Presentazione</h2>
    <form method="post" action="options.php">
        <?php settings_fields( 'paguro_chatbot_group' ); // Da registrare in register_settings() di Paguro_Admin ?>
        
        <?php $config = get_option( 'paguro_chatbot_config', array() ); ?>
        
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Messaggio di Benvenuto</th>
                <td>
                    <textarea name="paguro_chatbot_config[welcome_message]" rows="5" cols="50" class="large-text">
                        <?php echo esc_textarea( $config['welcome_message'] ?? 'Ciao! Sono Paguro, l\'assistente di Villa Celi. Posso dirti quali sono le settimane libere.' ); ?>
                    </textarea>
                    <p class="description">Il primo messaggio che appare quando l'utente apre il chatbot.</p>
                </td>
            </tr>
        </table>
        
        <?php submit_button( 'Salva Frasi' ); ?>
    </form>

    <hr>
    <h2>Parole Chiave per la Verifica Disponibilità</h2>
    <p>Inserisci le parole o frasi che attivano il controllo delle date (es. 'libero', 'date', 'disponibilità').</p>
    
    <form method="post" action="options.php">
        <?php // settings_fields( 'paguro_keywords_group' ); ?>
        <?php // do_settings_sections( 'paguro-chatbot-config' ); ?>
        
        <p><em>Implementazione: Si consiglia di utilizzare un Custom Post Type o una tabella DB per una gestione CRUD pulita. Qui solo l'interfaccia placeholder.</em></p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr><th>Parola Chiave</th><th>Azione</th></tr>
            </thead>
            <tbody>
                <tr><td>disponibilità, libero, date</td><td>Attiva la logica di verifica API</td></tr>
                </tbody>
        </table>
        
    </form>
</div>
