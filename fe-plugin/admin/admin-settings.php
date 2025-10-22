<div class="wrap">
    <h1>PaguroChatBot - Impostazioni API e Connessione</h1>
    
    <?php 
    // NECESSARIO per mostrare gli avvisi "Impostazioni salvate."
    settings_errors(); 
    ?>
    
    <h2>Configurazione API e Display Globale</h2>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'paguro_settings_group' );
        do_settings_sections( 'paguro-main' );
        submit_button( 'Salva Impostazioni' );
        ?>
    </form>
    
    <hr>
    
    <h2>Test di Connettività</h2>
    <p>Verifica che il plugin possa comunicare correttamente con il Backend <code>api.viamerano24.it</code> e che il Token API sia valido.</p>
    <button id="paguro-test-btn" class="button button-primary">Esegui Test di Connessione</button>
    <div id="paguro-test-result" style="margin-top: 15px;"></div>
</div>