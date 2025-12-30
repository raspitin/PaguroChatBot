jQuery(document).ready(function($) {
    
    // ICONA PAGURO (Immagine PNG)
    const paguroIcon = `<img src="${paguroData.icon_url}" alt="Assistente Paguro" class="paguro-icon-img" />`;

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

    loadChatHistory();

    function saveMessageToHistory(role, htmlContent) {
        let history = JSON.parse(localStorage.getItem('paguro_history_' + sessionId) || '[]');
        history.push({ role: role, content: htmlContent });
        if (history.length > 50) history.shift();
        localStorage.setItem('paguro_history_' + sessionId, JSON.stringify(history));
    }

    function loadChatHistory() {
        let history = JSON.parse(localStorage.getItem('paguro_history_' + sessionId) || '[]');
        if (history.length === 0) {
            appendMessage('bot', 'Ciao! Sono l\'Assistente Paguro 🐚<br>Cerchi disponibilità o informazioni?', false);
        } else {
            history.forEach(msg => appendMessage(msg.role, msg.content, false));
        }
        scrollToBottom();
    }

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

        appendMessage('user', msg, true);
        $('#paguro-input').val('');
        scrollToBottom();

        doAjaxRequest({
            message: msg,
            session_id: sessionId
        });
    }

    // CLICK "ALTRE DATE"
    $(document).on('click', '.paguro-load-more', function(e) {
        e.preventDefault();
        var btn = $(this);
        btn.text('Caricamento...').css('color', '#999').css('pointer-events', 'none');

        doAjaxRequest({
            message: 'LOAD_MORE', 
            session_id: sessionId,
            offset: btn.data('offset'),
            apt_id: btn.data('apt'),
            filter_month: btn.data('month')
        }, true);
    });

    // CLICK "PRENOTA"
    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault();
        var targetUrl = paguroData.booking_url + 
                        '?nf_apt=' + encodeURIComponent($(this).data('apt')) + 
                        '&nf_in=' + encodeURIComponent($(this).data('in')) + 
                        '&nf_out=' + encodeURIComponent($(this).data('out'));
        window.open(targetUrl, '_blank');
    });

    function doAjaxRequest(dataParams, isSystemRequest = false) {
        var loadingId = 'loading-' + Date.now();
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
                    if(res.data.type === 'ACTION' && !reply) reply = "⚙️ Sto verificando..."; 
                    if(reply) appendMessage('bot', reply, true);
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
    $('#paguro-input').keypress(function(e) { if(e.which == 13) sendMessage(); });
    function scrollToBottom() { var div = $('#paguro-messages'); div.scrollTop(div.prop("scrollHeight")); }

    // AUTO-COMPILAZIONE FORM
    function tryPopulateForm() {
        const params = new URLSearchParams(window.location.search);
        const pApt = params.get('nf_apt');
        const pIn  = params.get('nf_in');
        const pOut = params.get('nf_out');

        if(pApt) {
            let aptSelect = $('.nf-custom-apt').find('select');
            if(aptSelect.length && aptSelect.val() !== pApt) aptSelect.val(pApt).trigger('change');
            let summaryApt = $('.nf-summary-apt');
            if(summaryApt.length) summaryApt.text(pApt.charAt(0).toUpperCase() + pApt.slice(1)).css('color', '#000');
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
        let interval = setInterval(function() { tryPopulateForm(); attempts++; if(attempts > 6) clearInterval(interval); }, 500);
    });
});