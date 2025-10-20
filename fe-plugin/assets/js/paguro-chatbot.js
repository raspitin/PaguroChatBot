jQuery(document).ready(function($) {
    var $container = $('#paguro-chatbot-container');
    var $toggle = $('#paguro-chatbot-toggle');
    var $window = $('#paguro-chatbot-window');
    var $messages = $('#paguro-chatbot-messages');
    var $input = $('#paguro-chatbot-input');
    var $send = $('#paguro-chatbot-send');

    // Funzione per mostrare il messaggio iniziale
    function showWelcomeMessage() {
        var welcomeMessage = PaguroConfig.welcome_message;
        addMessage(welcomeMessage, 'bot');
    }

    // Aggiunge un messaggio alla finestra del chatbot
    function addMessage(text, sender) {
        var className = sender === 'user' ? 'paguro-user-msg' : 'paguro-bot-msg';
        $messages.append('<div class="' + className + '">' + text + '</div>');
        $messages.scrollTop($messages[0].scrollHeight); // Scrolla in fondo
    }

    // Toggle apertura/chiusura
    $toggle.on('click', function() {
        $window.toggle();
        if ($window.is(':visible') && $messages.is(':empty')) {
            showWelcomeMessage();
        }
    });
    
    // Gestisce l'invio del messaggio
    function sendMessage() {
        var query = $input.val().trim();
        if (query === '') return;

        addMessage(query, 'user');
        $input.val('');
        $send.prop('disabled', true);

        // Chiamata AJAX al gestore PHP del plugin
        $.ajax({
            url: PaguroConfig.ajaxurl,
            type: 'POST',
            data: {
                action: 'paguro_chatbot_query',
                security: PaguroConfig.nonce,
                query: query
            },
            success: function(response) {
                if (response.success && response.data) {
                    var responseData = response.data;
                    
                    if (responseData.type === 'ollama') {
                        // Risposta di Fallback Ollama
                        addMessage(responseData.text, 'bot');
                    } else if (responseData.type === 'availability') {
                        // Risposta di Disponibilità
                        var availabilityText = responseData.available ? 
                            '✅ Ottima notizia! L\'appartamento è disponibile.' : 
                            '❌ Ci dispiace, non è disponibile per quel periodo. ' + responseData.message;
                        addMessage(availabilityText, 'bot');
                    }
                    
                    // Se la disponibilità è confermata, mostra il link/form di prenotazione
                    if (responseData.type === 'availability' && responseData.available) {
                         addMessage('👉 Vai al modulo di prenotazione sicuro (con reCAPTCHA): <a href="/prenota" target="_blank">Prenota Ora</a>', 'bot');
                    }

                } else {
                    addMessage('⚠️ Errore: ' + (response.data.message || 'La logica del plugin non ha risposto.'), 'bot');
                }
            },
            error: function() {
                addMessage('🔴 Errore di rete: Impossibile contattare il server.', 'bot');
            },
            complete: function() {
                $send.prop('disabled', false);
            }
        });
    }

    $send.on('click', sendMessage);
    $input.on('keypress', function(e) {
        if (e.which === 13) {
            sendMessage();
        }
    });

    // Funzione per inizializzare il chatbot (chiamata all'apertura)
    // showWelcomeMessage(); 
});
