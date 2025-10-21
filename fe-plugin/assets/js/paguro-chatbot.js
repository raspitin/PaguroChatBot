jQuery(document).ready(function($) {
    var $container = $('#paguro-chatbot-container');
    var $toggle = $('#paguro-chatbot-toggle');
    var $window = $('#paguro-chatbot-window');
    var $messages = $('#paguro-chatbot-messages');
    var $input = $('#paguro-chatbot-input');
    var $send = $('#paguro-chatbot-send');
    // Toggle apertura/chiusura (per la versione flottante)
    $toggle.on('click', function() {
        $window.toggle();
        // Chiama il benvenuto solo se si apre E non ha messaggi (versione flottante)
        if ($window.is(':visible') && $messages.is(':empty')) {
            showWelcomeMessage();
        }
    });
    
    // NUOVA LOGICA: Inizializza il benvenuto se il chatbot è INCORPORATO (Shortcode)
    if ($container.hasClass('paguro-embedded') && $messages.is(':empty')) {
        showWelcomeMessage();
    }
    // Funzione per mostrare il messaggio iniziale
    function showWelcomeMessage() {
        // Verifica se il messaggio di benvenuto è già stato aggiunto
        if ($messages.find('.paguro-bot-msg').length === 0) {
            var welcomeMessage = PaguroConfig.welcome_message;
            addMessage(welcomeMessage, 'bot');
        }
    }

    // Aggiunge un messaggio alla finestra del chatbot
    function addMessage(text, sender) {
        var className = sender === 'user' ? 'paguro-user-msg' : 'paguro-bot-msg';
        // Aggiunge la classe 'first-welcome' per il messaggio di benvenuto
        if (sender === 'bot' && $messages.is(':empty')) {
            className += ' paguro-welcome-msg';
        }
        $messages.append('<div class="' + className + '">' + text + '</div>');
        $messages.scrollTop($messages[0].scrollHeight); // Scrolla in fondo
    }

    // Toggle apertura/chiusura
    $toggle.on('click', function() {
        $window.toggle();
        // Chiama il benvenuto ogni volta che la finestra viene aperta E non ha messaggi
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
                    var botMessage = ''; 

                    // --- Logica Corretta per Estrarre la Stringa ---
                    
                    if (responseData.type === 'ollama') {
                        // Risposta di Fallback Ollama (contiene response_text e redirect nell'oggetto 'text')
                        if (responseData.text && responseData.text.response_text) {
                            botMessage = responseData.text.response_text;
                            if (responseData.text.redirect) {
                                botMessage += ' ' + responseData.text.redirect;
                            }
                        } else {
                            // Questo codice non dovrebbe più essere eseguito se il BE funziona
                            botMessage = '⚠️ Errore: Risposta Ollama con formato inatteso.';
                        }

                    } else if (responseData.type === 'availability') {
                        // Risposta di Disponibilità
                        if (responseData.available) {
                            botMessage = '✅ Ottima notizia! L\'appartamento è disponibile.';
                        } else {
                            // Se non disponibile, 'message' contiene i dettagli
                            botMessage = '❌ Ci dispiace, non è disponibile per quel periodo. ' + responseData.message;
                        }
                    } else {
                        // Fallback per risposte con struttura non identificata
                        botMessage = 'La logica del plugin non ha risposto in modo chiaro. Riprova.';
                    }

                    // Aggiunge il messaggio estratto correttamente
                    addMessage(botMessage, 'bot');
                    
                    // Se la disponibilità è confermata, mostra il link/form di prenotazione
                    if (responseData.type === 'availability' && responseData.available) {
                         addMessage('👉 Vai al modulo di prenotazione sicuro (con reCAPTCHA): <a href="/prenota" target="_blank">Prenota Ora</a>', 'bot');
                    }

                } else {
                    // Gestisce l'errore se response.success è false (es. messaggio d'errore da PHP)
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
});