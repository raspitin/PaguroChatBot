jQuery(document).ready(function($) {
    
    // 1. GESTIONE CHATBOT (Apertura/Chiusura)
    $('.paguro-chat-launcher').on('click', function() {
        $('.paguro-chat-widget').fadeToggle();
    });

    $('.paguro-chat-header .close-btn').on('click', function() {
        $('.paguro-chat-widget').fadeOut();
    });

    // 2. INVIO MESSAGGI
    $('#paguro-send-btn').on('click', function() {
        sendMessage();
    });

    $('#paguro-input').on('keypress', function(e) {
        if(e.which == 13) sendMessage();
    });

    function sendMessage() {
        var msg = $('#paguro-input').val().trim();
        if(msg === "") return;

        // Aggiungi messaggio utente
        appendMessage("user", msg);
        $('#paguro-input').val('');
        $('#paguro-chat-body').scrollTop($('#paguro-chat-body')[0].scrollHeight);

        // Loading...
        var loadingId = appendMessage("bot", '<span class="paguro-typing">...</span>');

        // Genera Session ID se non c'è
        if (!localStorage.getItem('paguro_session_id')) {
            localStorage.setItem('paguro_session_id', 'sess_' + Math.random().toString(36).substr(2, 9));
        }

        // Chiamata AJAX
        $.ajax({
            url: paguroData.ajax_url,
            type: 'POST',
            data: {
                action: 'paguro_chat_request',
                nonce: paguroData.nonce,
                message: msg,
                session_id: localStorage.getItem('paguro_session_id')
            },
            success: function(response) {
                removeMessage(loadingId);
                if(response.success) {
                    var data = response.data;
                    
                    if(data.type === 'ACTION' && data.action === 'CHECK_AVAILABILITY') {
                        appendMessage("bot", data.reply);
                        // Gestione pulsanti "Load More"
                        $('.paguro-load-more').off('click').on('click', function(e) {
                            e.preventDefault();
                            loadMoreDates($(this));
                        });
                    } else {
                        appendMessage("bot", data.reply);
                    }
                } else {
                    appendMessage("bot", "Errore di connessione. Riprova.");
                }
            },
            error: function() {
                removeMessage(loadingId);
                appendMessage("bot", "Errore tecnico. Contatta l'amministratore.");
            }
        });
    }

    // 3. CARICAMENTO ALTRE DATE (Paginazione)
    function loadMoreDates(btn) {
        var aptId = btn.data('apt');
        var offset = btn.data('offset');
        var month = btn.data('month');

        btn.text('Caricamento...').css('opacity', '0.5');

        $.ajax({
            url: paguroData.ajax_url,
            type: 'POST',
            data: {
                action: 'paguro_chat_request',
                nonce: paguroData.nonce,
                message: "LOAD_MORE", 
                session_id: localStorage.getItem('paguro_session_id'),
                offset: offset,
                apt_id: aptId,
                filter_month: month
            },
            success: function(response) {
                btn.hide(); 
                if(response.success) {
                    appendMessage("bot", response.data.reply);
                     $('.paguro-load-more').off('click').on('click', function(e) {
                        e.preventDefault();
                        loadMoreDates($(this));
                    });
                }
            }
        });
    }

    function appendMessage(sender, text) {
        var id = 'msg_' + Math.random().toString(36).substr(2, 9);
        var cls = sender === "user" ? "paguro-msg-user" : "paguro-msg-bot";
        
        // Se è il bot, aggiungiamo l'icona
        var iconHtml = '';
        if (sender === 'bot' && paguroData.icon_url) {
            iconHtml = '<img src="' + paguroData.icon_url + '" class="paguro-bot-avatar">';
        }

        var html = '<div id="' + id + '" class="paguro-msg ' + cls + '">' + iconHtml + '<div class="paguro-msg-content">' + text + '</div></div>';
        $('#paguro-chat-body').append(html);
        $('#paguro-chat-body').scrollTop($('#paguro-chat-body')[0].scrollHeight);
        return id;
    }

    function removeMessage(id) {
        $('#' + id).remove();
    }

    // 4. CLICK "PRENOTA" (Logica Lock + Redirect)
    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var originalText = btn.text();
        
        if(btn.hasClass('locking')) return;
        btn.addClass('locking').text('Blocco date...');

        var aptValue = btn.data('apt');
        var dateIn   = btn.data('in'); 
        var dateOut  = btn.data('out');

        $.ajax({
            url: paguroData.ajax_url,
            type: 'POST',
            data: {
                action: 'paguro_lock_dates',
                nonce: paguroData.nonce,
                apt_name: aptValue,
                date_in: dateIn,
                date_out: dateOut
            },
            success: function(res) {
                btn.removeClass('locking').text(originalText);
                
                if(res.success) {
                    var token = res.data.token;
                    var queueMsg = res.data.queue_msg; 
                    
                    var targetUrl = paguroData.booking_url + 
                        '?nf_apt=' + encodeURIComponent(aptValue) + 
                        '&nf_in=' + encodeURIComponent(dateIn) + 
                        '&nf_out=' + encodeURIComponent(dateOut) +
                        '&nf_token=' + encodeURIComponent(token) +
                        '&nf_msg=' + encodeURIComponent(queueMsg); 
                    
                    window.open(targetUrl, '_self'); 
                    
                } else {
                    alert("⚠️ " + res.data.msg);
                }
            },
            error: function() {
                btn.removeClass('locking').text(originalText);
                alert("Errore di comunicazione col server.");
            }
        });
    });

    // 5. AUTO-COMPILAZIONE NINJA FORMS (Il Fix Cruciale)
    // Ascoltiamo l'evento speciale di Ninja Forms che dice "Il form è pronto"
    $(document).on('nfFormReady', function() {
        const params = new URLSearchParams(window.location.search);
        
        // A. Gestione Avviso Coda
        const pMsg = params.get('nf_msg');
        if(pMsg) {
             let warningBox = $('.nf-queue-warning');
             if(warningBox.length === 0) {
                 // Inietta un box se non esiste
                 $('.nf-form-content').prepend('<div class="paguro-warning-box" style="background:#fff3cd; color:#856404; padding:15px; border:1px solid #ffeeba; border-radius:5px; margin-bottom:15px;"></div>');
                 warningBox = $('.paguro-warning-box');
             }
             warningBox.html('<strong>' + decodeURIComponent(pMsg) + '</strong>');
        }

        // B. FIX DEL TOKEN (Questo risolve il bug del debug.log)
        // Cerca l'input che ha preso il valore sbagliato "{querystring:nf_token}"
        // e lo sostituisce col token vero preso dall'URL.
        const token = params.get('nf_token');
        if (token) {
            // Cerca input hidden che ha il valore "rotto"
            var brokenInput = $('input[value="{querystring:nf_token}"]');
            if (brokenInput.length > 0) {
                brokenInput.val(token); // Corregge il valore!
                brokenInput.trigger('change'); // Avvisa Ninja Forms del cambio
            } else {
                // Fallback: cerca un input nascosto generico se il valore default era vuoto
                // (Meno preciso ma utile come tentativo)
                $('input[type="hidden"]').each(function() {
                    // Se troviamo un input vuoto, proviamo a metterci il token? 
                    // Meglio di no per sicurezza, ci affidiamo al selettore sopra.
                });
            }
        }
    });
	
	jQuery(document).ready(function($) {
    
    // ... (TUTTO IL CODICE PRECEDENTE RIMANE UGUALE: Chat, Lock, Ninja Forms) ...
    // ... Assicurati di copiare tutto quello che c'era prima ...

    // 1. GESTIONE CHATBOT (Codice esistente...)
    $('.paguro-chat-launcher').on('click', function() { $('.paguro-chat-widget').fadeToggle(); });
    $('.paguro-chat-header .close-btn').on('click', function() { $('.paguro-chat-widget').fadeOut(); });
    $('#paguro-send-btn').on('click', function() { sendMessage(); });
    $('#paguro-input').on('keypress', function(e) { if(e.which == 13) sendMessage(); });

    function sendMessage() {
        var msg = $('#paguro-input').val().trim(); if(msg === "") return;
        appendMessage("user", msg); $('#paguro-input').val(''); $('#paguro-chat-body').scrollTop($('#paguro-chat-body')[0].scrollHeight);
        var loadingId = appendMessage("bot", '<span class="paguro-typing">...</span>');
        if (!localStorage.getItem('paguro_session_id')) localStorage.setItem('paguro_session_id', 'sess_' + Math.random().toString(36).substr(2, 9));
        $.ajax({
            url: paguroData.ajax_url, type: 'POST',
            data: { action: 'paguro_chat_request', nonce: paguroData.nonce, message: msg, session_id: localStorage.getItem('paguro_session_id') },
            success: function(response) {
                removeMessage(loadingId);
                if(response.success) {
                    var data = response.data;
                    if(data.type === 'ACTION' && data.action === 'CHECK_AVAILABILITY') { appendMessage("bot", data.reply); bindLoadMore(); }
                    else { appendMessage("bot", data.reply); }
                } else { appendMessage("bot", response.data ? response.data.reply : "Errore generico."); }
            },
            error: function(xhr) { removeMessage(loadingId); appendMessage("bot", "⚠️ Errore (" + xhr.status + ")."); }
        });
    }

    function bindLoadMore() { $('.paguro-load-more').off('click').on('click', function(e) { e.preventDefault(); loadMoreDates($(this)); }); }

    function loadMoreDates(btn) {
        var aptId = btn.data('apt'); var offset = btn.data('offset'); var month = btn.data('month');
        btn.text('Caricamento...').css('opacity', '0.5');
        $.ajax({
            url: paguroData.ajax_url, type: 'POST',
            data: { action: 'paguro_chat_request', nonce: paguroData.nonce, message: "LOAD_MORE", session_id: localStorage.getItem('paguro_session_id'), offset: offset, apt_id: aptId, filter_month: month },
            success: function(response) { btn.hide(); if(response.success) { appendMessage("bot", response.data.reply); bindLoadMore(); } else { appendMessage("bot", "Errore."); } }
        });
    }

    function appendMessage(sender, text) {
        var id = 'msg_' + Math.random().toString(36).substr(2, 9);
        var cls = sender === "user" ? "paguro-msg-user" : "paguro-msg-bot";
        var iconHtml = (sender === 'bot' && paguroData.icon_url) ? '<img src="' + paguroData.icon_url + '" class="paguro-bot-avatar">' : '';
        var html = '<div id="' + id + '" class="paguro-msg ' + cls + '">' + iconHtml + '<div class="paguro-msg-content">' + text + '</div></div>';
        $('#paguro-chat-body').append(html); $('#paguro-chat-body').scrollTop($('#paguro-chat-body')[0].scrollHeight);
        return id;
    }
    function removeMessage(id) { $('#' + id).remove(); }

    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault(); var btn = $(this); var originalText = btn.text(); if(btn.hasClass('locking')) return;
        btn.addClass('locking').text('Blocco...');
        var aptValue = btn.data('apt'); var dateIn = btn.data('in'); var dateOut = btn.data('out');
        $.ajax({
            url: paguroData.ajax_url, type: 'POST',
            data: { action: 'paguro_lock_dates', nonce: paguroData.nonce, apt_name: aptValue, date_in: dateIn, date_out: dateOut },
            success: function(res) {
                btn.removeClass('locking').text(originalText);
                if(res.success) {
                    var targetUrl = paguroData.booking_url + '?nf_apt=' + encodeURIComponent(aptValue) + '&nf_in=' + encodeURIComponent(dateIn) + '&nf_out=' + encodeURIComponent(dateOut) + '&nf_token=' + encodeURIComponent(res.data.token) + '&nf_msg=' + encodeURIComponent(res.data.queue_msg); 
                    window.open(targetUrl, '_self'); 
                } else { alert("⚠️ " + res.data.msg); }
            }, error: function() { btn.removeClass('locking').text(originalText); alert("Errore Server."); }
        });
    });

    $(document).on('nfFormReady', function() {
        const params = new URLSearchParams(window.location.search);
        const pMsg = params.get('nf_msg');
        if(pMsg) {
             let warningBox = $('.nf-queue-warning');
             if(warningBox.length === 0) { $('.nf-form-content').prepend('<div class="paguro-warning-box" style="background:#fff3cd; color:#856404; padding:15px; border:1px solid #ffeeba; border-radius:5px; margin-bottom:15px;"></div>'); warningBox = $('.paguro-warning-box'); }
             warningBox.html('<strong>' + decodeURIComponent(pMsg) + '</strong>');
        }
        const token = params.get('nf_token');
        if (token) { var brokenInput = $('input[value="{querystring:nf_token}"]'); if (brokenInput.length > 0) brokenInput.val(token).trigger('change'); }
    });
    setTimeout(function() { $(document).trigger('nfFormReady'); }, 1500);

    // ==========================================
    // 6. DRAG & DROP UPLOAD LOGIC (NUOVO)
    // ==========================================
    var dropArea = $('#paguro-upload-area');
    
    if (dropArea.length > 0) {
        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.on(eventName, function(e) { e.preventDefault(); e.stopPropagation(); });
        });

        // Highlight effect
        dropArea.on('dragenter dragover', function() { $(this).css('background', '#e7f5fe').css('border-color', '#005b88'); });
        dropArea.on('dragleave drop', function() { $(this).css('background', '#fff').css('border-color', '#0073aa'); });

        // Handle Drop
        dropArea.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            handleFiles(files);
        });

        // Handle Click Select
        $('#paguro-file-input').on('change', function() {
            handleFiles(this.files);
        });

        function handleFiles(files) {
            if (files.length === 0) return;
            var file = files[0];
            uploadFile(file);
        }

        function uploadFile(file) {
            var statusDiv = $('#paguro-upload-status');
            var token = $('#paguro-token').val();

            statusDiv.html('<span style="color:#0073aa;">⏳ Caricamento in corso...</span>');

            var formData = new FormData();
            formData.append('action', 'paguro_upload_receipt');
            formData.append('nonce', paguroData.nonce);
            formData.append('file', file);
            formData.append('token', token);

            $.ajax({
                url: paguroData.ajax_url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    if (response.success) {
                        statusDiv.html('<span style="color:green;">✅ Caricato! Aggiorno...</span>');
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        statusDiv.html('<span style="color:red;">❌ ' + response.data.msg + '</span>');
                    }
                },
                error: function() {
                    statusDiv.html('<span style="color:red;">❌ Errore server.</span>');
                }
            });
        }
    }
});

    // Fallback classico se nfFormReady non scatta (es. form già caricato)
    setTimeout(function() {
        $(document).trigger('nfFormReady');
    }, 1500);

});