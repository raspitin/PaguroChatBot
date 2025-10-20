jQuery(document).ready(function($) {
    var $testButton = $('#paguro-test-btn');
    var $testResult = $('#paguro-test-result');

    $testButton.on('click', function(e) {
        e.preventDefault();
        
        var token = PaguroAdmin.token;
        var url = PaguroAdmin.url;

        if (!url || url.length < 10) {
            $testResult.html('<p style="color:red;">❌ Salva prima un URL Backend valido.</p>');
            return;
        }
        if (!token || token.length < 10) {
            $testResult.html('<p style="color:red;">❌ Salva prima un Token API valido e complesso.</p>');
            return;
        }

        $testResult.html('<p style="color:blue;">⏳ Esecuzione del test di connessione...</p>');
        $testButton.prop('disabled', true);

        $.ajax({
            url: PaguroAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'paguro_test_connection',
                security: PaguroAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $testResult.html('<p style="color:green;">✅ ' + response.data.message + '</p>');
                } else {
                    $testResult.html('<p style="color:red;">❌ ' + response.data.message + '</p>');
                }
            },
            error: function(xhr, status, error) {
                // Questo cattura errori di rete, timeout, 500 generici
                $testResult.html('<p style="color:red;">❌ Errore di comunicazione AJAX/Server. Controlla i log di PHP o la console del browser.</p>');
            },
            complete: function() {
                $testButton.prop('disabled', false);
            }
        });
    });
});
