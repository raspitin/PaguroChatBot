jQuery(document).ready(function($) {
    // 1. Inietta HTML della chat nel body
    $('body').append(`
        <div id="paguro-bubble">💬</div>
        <div id="paguro-chat-window">
            <div class="paguro-header">
                <span>Paguro Assistente</span>
                <span id="paguro-close" style="cursor:pointer;">X</span>
            </div>
            <div id="paguro-messages">
                <div class="paguro-msg bot">Ciao! Sono Paguro. Cerchi una casa vacanze o informazioni?</div>
            </div>
            <div class="paguro-input-area">
                <input type="text" id="paguro-input" placeholder="Scrivi qui..." />
                <button id="paguro-send">Invia</button>
            </div>
        </div>
    `);

    // Gestione Sessione (Semplice ID salvato nel browser)
    let sessionId = localStorage.getItem('paguro_session_id');
    if (!sessionId) {
        sessionId = 'sess_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('paguro_session_id', sessionId);
    }

// Toggle Chat
    $('#paguro-bubble, #paguro-close').click(function() {
        var chatWindow = $('#paguro-chat-window');
        
        // Usiamo fadeToggle ma forziamo il display flex per il CSS
        chatWindow.fadeToggle(200, function() {
            if($(this).is(':visible')) {
                $(this).addClass('open').css('display', 'flex'); 
                scrollToBottom(); // Scrolla in basso all'apertura
                $('#paguro-input').focus(); // Mette il focus sulla tastiera
            } else {
                $(this).removeClass('open');
            }
        });
    });

    // Funzione Invio
    function sendMessage() {
        var msg = $('#paguro-input').val().trim();
        if(msg === '') return;

        // Aggiungi messaggio utente
        $('#paguro-messages').append('<div class="paguro-msg user">' + msg + '</div>');
        $('#paguro-input').val('');
        scrollToBottom();

        // Loading
        var loadingId = 'loading-' + Date.now();
        $('#paguro-messages').append('<div class="paguro-msg bot" id="' + loadingId + '">...</div>');
        scrollToBottom();

        // Chiamata AJAX a WordPress
        $.ajax({
            url: paguroData.ajax_url,
            type: 'POST',
            data: {
                action: 'paguro_chat_request',
                nonce: paguroData.nonce,
                message: msg,
                session_id: sessionId
            },
            success: function(res) {
                $('#' + loadingId).remove();
                if(res.success) {
                    // Gestione risposta semplice
                    let reply = res.data.reply;
                    
                    // Se c'è un'azione (es. verifica disponibilità)
                    if(res.data.type === 'ACTION') {
                        // Per ora mostriamo solo il testo, poi integreremo i widget
                        reply = "⚙️ " + reply; 
                    }
                    
                    $('#paguro-messages').append('<div class="paguro-msg bot">' + reply + '</div>');
                } else {
                    $('#paguro-messages').append('<div class="paguro-msg bot">Errore di connessione.</div>');
                }
                scrollToBottom();
            },
            error: function() {
                $('#' + loadingId).remove();
                $('#paguro-messages').append('<div class="paguro-msg bot">Errore server.</div>');
            }
        });
    }

    // Eventi Click e Enter
    $('#paguro-send').click(sendMessage);
    $('#paguro-input').keypress(function(e) {
        if(e.which == 13) sendMessage();
    });

    function scrollToBottom() {
        var div = $('#paguro-messages');
        div.scrollTop(div.prop("scrollHeight"));
    }
});
