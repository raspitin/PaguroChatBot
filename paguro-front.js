jQuery(document).ready(function($) {
    


    // 1. INIEZIONE HTML
    $('body').append(`
        <div id="paguro-bubble">${paguroIcon}</div>
        <div id="paguro-chat-window">
            <div class="paguro-header">
                <span>Assistente Paguro</span>
                <span id="paguro-close" style="cursor:pointer;">&times;</span>
            </div>
            <div id="paguro-messages"></div>
            <div class="paguro-input-area">
                <input type="text" id="paguro-input" placeholder="Scrivi qui..." />
                <button id="paguro-send">Invia</button>
            </div>
        </div>
    `);

    // GESTIONE SESSIONE E STORICO
    let sessionId = localStorage.getItem('paguro_session_id');
    if (!sessionId) {
        sessionId = 'sess_' + Math.random().toString(36).substr(2, 9);
        localStorage.setItem('paguro_session_id', sessionId);
    }

    // Carica lo storico al refresh della pagina
    loadChatHistory();

    // Funzione: Salva messaggio nel LocalStorage
    function saveMessageToHistory(role, htmlContent) {
        let history = JSON.parse(localStorage.getItem('paguro_history_' + sessionId) || '[]');
        history.push({ role: role, content: htmlContent });
        // Teniamo solo gli ultimi 50 messaggi per non intasare la memoria
        if (history.length > 50) history.shift();
        localStorage.setItem('paguro_history_' + sessionId, JSON.stringify(history));
    }

    // Funzione: Carica messaggi salvati
    function loadChatHistory() {
        let history = JSON.parse(localStorage.getItem('paguro_history_' + sessionId) || '[]');
        
        if (history.length === 0) {
            // Messaggio di benvenuto default se non c'è storico
            appendMessage('bot', 'Ciao! Sono l\'Assistente Paguro 🐚<br>Cerchi disponibilità o informazioni?', false);
        } else {
            history.forEach(msg => {
                appendMessage(msg.role, msg.content, false); // false = non risalvare
            });
        }
        scrollToBottom();
    }

    // Funzione helper per aggiungere messaggi a schermo
    function appendMessage(role, html, save = true) {
        const className = role === 'user' ? 'user' : 'bot';
        $('#paguro-messages').append(`<div class="paguro-msg ${className}">${html}</div>`);
        if (save) saveMessageToHistory(role, html);
    }

    // TOGGLE CHAT
    $('#paguro-bubble, #paguro-close').click(function() {
        var chatWindow = $('#paguro-chat-window');
        chatWindow.fadeToggle(200, function() {
            if($(this).is(':visible')) {
                $(this).addClass('open').css('display', 'flex'); 
                scrollToBottom();
                $('#paguro-input').focus();
            } else {
                $(this).removeClass('open');
            }
        });
    });

    // INVIO MESSAGGIO
    function sendMessage() {
        var msg = $('#paguro-input').val().trim();
        if(msg === '') return;

        // 1. Mostra e salva messaggio utente
        appendMessage('user', msg, true);
        $('#paguro-input').val('');
        scrollToBottom();

        // 2. Chiamata AJAX
        doAjaxRequest({
            message: msg,
            session_id: sessionId
        });
    }

    // PAGINAZIONE ("...altre date")
    $(document).on('click', '.paguro-load-more', function(e) {
        e.preventDefault();
        var btn = $(this);
        var offset = btn.data('offset');
        var aptId = btn.data('apt');
        var month = btn.data('month');

        btn.text('Caricamento...').css('color', '#999').css('pointer-events', 'none');

        doAjaxRequest({
            message: 'LOAD_MORE', 
            session_id: sessionId,
            offset: offset,
            apt_id: aptId,
            filter_month: month
        }, true);
    });

    // PRENOTAZIONE (Ninja Forms link)
    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault();
        var aptValue = $(this).data('apt');
        var dateIn   = $(this).data('in'); 
        var dateOut  = $(this).data('out');
        
        var targetUrl = paguroData.booking_url + 
                        '?nf_apt=' + encodeURIComponent(aptValue) + 
                        '&nf_in=' + encodeURIComponent(dateIn) + 
                        '&nf_out=' + encodeURIComponent(dateOut);
        
        window.open(targetUrl, '_blank');
    });

    // LOGICA AJAX CENTRALE
    function doAjaxRequest(dataParams, isSystemRequest = false) {
        var loadingId = 'loading-' + Date.now();
        
        // Mostra loader solo se è l'utente a scrivere (non salviamo il loader nella history)
        if(!isSystemRequest) {
            $('#paguro-messages').append('<div class="paguro-msg bot" id="' + loadingId + '">...</div>');
            scrollToBottom();
        }

        $.ajax({
            url: paguroData.ajax_url,
            type: 'POST',
            data: Object.assign({
                action: 'paguro_chat_request',
                nonce: paguroData.nonce
            }, dataParams),
            success: function(res) {
                $('#' + loadingId).remove();
                if(res.success) {
                    let reply = res.data.reply;
                    
                    if(res.data.type === 'ACTION' && !reply) {
                        reply = "⚙️ Sto verificando..."; 
                    }
                    
                    if(reply) {
                        // Mostra e SALVA la risposta del bot
                        appendMessage('bot', reply, true);
                    }
                } else {
                    appendMessage('bot', 'Errore di connessione.', false);
                }
                scrollToBottom();
            },
            error: function() {
                $('#' + loadingId).remove();
                appendMessage('bot', 'Errore server.', false);
            }
        });
    }

    $('#paguro-send').click(sendMessage);
    $('#paguro-input').keypress(function(e) {
        if(e.which == 13) sendMessage();
    });

    function scrollToBottom() {
        var div = $('#paguro-messages');
        div.scrollTop(div.prop("scrollHeight"));
    }

    // ---------------------------------------------------------
    // AUTO-COMPILAZIONE FORM (Invariato)
    // ---------------------------------------------------------
    function tryPopulateForm() {
        const params = new URLSearchParams(window.location.search);
        const pApt = params.get('nf_apt');
        const pIn  = params.get('nf_in');
        const pOut = params.get('nf_out');

        if(pApt) {
            let aptSelect = $('.nf-custom-apt').find('select');
            if(aptSelect.length && aptSelect.val() !== pApt) {
                aptSelect.val(pApt).trigger('change');
            }
            let summaryApt = $('.nf-summary-apt');
            if(summaryApt.length) {
                let formattedApt = pApt.charAt(0).toUpperCase() + pApt.slice(1);
                summaryApt.text(formattedApt).css('color', '#000');
            }
        }
        if(pIn) {
            let dateInInput = $('.nf-custom-in').find('input.nf-element');
            if(dateInInput.length && dateInInput.val() !== pIn) {
                dateInInput.val(pIn).trigger('change');
                if (dateInInput[0]._flatpickr) dateInInput[0]._flatpickr.setDate(pIn, true);
            }
            let summaryIn = $('.nf-summary-in');
            if(summaryIn.length) summaryIn.text(pIn).css('color', '#000');
        }
        if(pOut) {
            let dateOutInput = $('.nf-custom-out').find('input.nf-element');
            if(dateOutInput.length && dateOutInput.val() !== pOut) {
                dateOutInput.val(pOut).trigger('change');
                if (dateOutInput[0]._flatpickr) dateOutInput[0]._flatpickr.setDate(pOut, true);
            }
            let summaryOut = $('.nf-summary-out');
            if(summaryOut.length) summaryOut.text(pOut).css('color', '#000');
        }
    }

    $(document).on('nfFormReady', function() {
        tryPopulateForm();
        let attempts = 0;
        let interval = setInterval(function() {
            tryPopulateForm();
            attempts++;
            if(attempts > 6) clearInterval(interval);
        }, 500);
    });
});