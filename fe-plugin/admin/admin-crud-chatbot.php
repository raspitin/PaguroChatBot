<div class="wrap">
    <h1>Paguro ChatBot - Configurazione Chatbot</h1>
    <p>Definisci le frasi di cortesia e le parole chiave che attivano la logica di prenotazione automatica.</p>
    
    <?php settings_errors(); ?>

    <?php $config = get_option( 'paguro_chatbot_config', array() ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'paguro_chatbot_group' ); ?>
        
        <h2>Frasi di Cortesia e Presentazione</h2>
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

        <h2>Parole Chiave di Prenotazione</h2>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Lista Parole Chiave</th>
                <td>
                    <input type="text" name="paguro_chatbot_config[keywords]" value="<?php echo esc_attr( $config['keywords'] ?? 'disponibilità, libero, date' ); ?>" class="large-text" style="width: 100%;" />
                    <p class="description">Elenco di parole chiave separate da virgole (es. <code>libero, date, prenotare</code>) che attivano la logica di verifica disponibilità.</p>
                </td>
            </tr>
        </table>
        
        <?php submit_button( 'Salva Configurazioni Chatbot' ); ?>
    </form>
</div>