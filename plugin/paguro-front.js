jQuery(document).ready(function($) {
    const data = paguroData; // Shortcut per i dati passati da PHP

    // ==========================================
    // 1. GESTIONE INTERFACCIA CHAT
    // ==========================================
    
    // Apri/Chiudi Chat
    $('.paguro-chat-launcher, .close-btn').on('click', function() {
        $('.paguro-chat-window').fadeToggle();
    });

    // Helper: Scroll automatico in basso
    function scrollToBottom() {
        var body = $('#paguro-chat-body');
        body.scrollTop(body[0].scrollHeight);
    }

    // Helper: Aggiungi messaggio utente
    function appendUserMsg(text) {
        $('#paguro-chat-body').append('<div class="paguro-msg paguro-msg-user"><div class="paguro-msg-content">' + text + '</div></div>');
        scrollToBottom();
    }

    // Helper: Aggiungi messaggio bot
    function appendBotMsg(html) {
        $('#paguro-chat-body').append('<div class="paguro-msg paguro-msg-bot"><img src="' + data.icon_url + '" class="paguro-bot-avatar"><div class="paguro-msg-content">' + html + '</div></div>');
        scrollToBottom();
    }

    // Funzione invio messaggio al server
    function sendMsg(msg, offset=0, month='') {
        if (!msg) return;
        if(offset===0) appendUserMsg(msg);
        
        // Feedback visivo (opzionale)
        // appendBotMsg('...'); 
        
        $.post(data.ajax_url, {
            action: 'paguro_chat_request',
            nonce: data.nonce,
            message: msg,
            session_id: 'sess_' + Date.now(), // ID sessione semplice
            offset: offset,
            filter_month: month
        }, function(res) {
            // Rimuovi eventuali loader se messi
            if (res.success) {
                appendBotMsg(res.data.reply);
            } else {
                appendBotMsg("⚠️ " + (res.data.reply || "Errore di connessione"));
            }
        }).fail(function() {
            appendBotMsg("⚠️ Errore di rete.");
        });
    }

    // Click bottone Invio
    $('#paguro-send-btn').click(function() {
        var input = $('#paguro-input');
        var txt = input.val().trim();
        if (txt) {
            sendMsg(txt);
            input.val('');
        }
    });

    // Invio con tasto Enter
    $('#paguro-input').keypress(function(e) {
        if (e.which == 13) $('#paguro-send-btn').click();
    });

    // Click sui Bottoni Rapidi (Mesi)
    $(document).on('click', '.paguro-quick-btn', function() {
        var msg = $(this).data('msg');
        sendMsg(msg);
    });

    // Click su "Altre..." (Paginazione risultati)
    $(document).on('click', '.paguro-load-more', function(e) {
        e.preventDefault();
        var off = $(this).data('offset');
        var m = $(this).data('month');
        
        // Nascondi il link "altre" appena cliccato
        $(this).parent().hide();
        
        // Richiedi i prossimi risultati
        sendMsg("Availability", off, m); 
    });

    // ==========================================
    // 2. LOGICA PRENOTAZIONE (LOCK DATE)
    // ==========================================
    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var oldTxt = btn.text();
        
        // Feedback visivo sul bottone
        btn.text(data.msgs.btn_locking).prop('disabled', true);

        $.post(data.ajax_url, {
            action: 'paguro_lock_dates',
            nonce: data.nonce,
            apt_name: btn.data('apt'),
            date_in: btn.data('in'),
            date_out: btn.data('out')
        }, function(res) {
            if (res.success) {
                // REDIRECT ALLA FORM DI CHECKOUT (Pagina 1)
                // Usa l'URL configurato nel backend + i parametri token
                window.location.href = data.booking_url + res.data.redirect_params;
            } else {
                alert("❌ " + res.data.msg); // Es. "Occupato"
                btn.text(oldTxt).prop('disabled', false);
            }
        }).fail(function() {
            alert("Errore di connessione.");
            btn.text(oldTxt).prop('disabled', false);
        });
    });

    // ==========================================
    // 3. INVIO FORM DATI OSPITE (CHECKOUT)
    // ==========================================
    $('#paguro-native-form').on('submit', function(e) {
        e.preventDefault();
        var btn = $('#paguro-submit-btn');
        btn.prop('disabled', true);
        $('#paguro-form-msg').html('');

        var formData = $(this).serialize();
        formData += '&action=paguro_submit_booking&nonce=' + data.nonce;

        $.post(data.ajax_url, formData, function(res) {
            if (res.success) {
                $('#paguro-form-msg').html('<span style="color:green">' + data.msgs.form_success + '</span>');
                // Redirect alla pagina di Riepilogo (Pagina 2)
                setTimeout(function() {
                    window.location.href = res.data.redirect;
                }, 1000);
            } else {
                $('#paguro-form-msg').html('<span style="color:red">' + res.data.msg + '</span>');
                btn.prop('disabled', false);
            }
        }).fail(function() {
            $('#paguro-form-msg').html('<span style="color:red">' + data.msgs.form_conn_error + '</span>');
            btn.prop('disabled', false);
        });
    });

    // ==========================================
    // 4. UPLOAD DISTINTA (FIX A05 UI)
    // ==========================================
    $('#paguro-file-input').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        // Validazione Client-Side base
        if (file.size > 5 * 1024 * 1024) {
            alert("File troppo grande (Max 5MB)");
            return;
        }

        var formData = new FormData();
        formData.append('action', 'paguro_upload_receipt');
        formData.append('nonce', data.nonce);
        formData.append('token', $('#paguro-token').val());
        formData.append('file', file);

        $('#paguro-upload-status').html(data.msgs.upload_loading).css('color', 'blue');

        $.ajax({
            url: data.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(res) {
                if (res.success) {
                    // --- QUI IL FIX UI RICHIESTO ---
                    
                    // 1. Nascondi il box di upload (quello tratteggiato)
                    $('#paguro-upload-area').slideUp();
                    
                    // 2. Assicurati che il contenitore di successo esista (creato dal PHP v3.0.7) o mostralo
                    // Se per caso il PHP non lo ha renderizzato (cache vecchia), lo creiamo al volo per sicurezza
                    if ($('#paguro-upload-success-container').length === 0) {
                         $('<div id="paguro-upload-success-container" style="display:none; margin-top:20px; padding:20px; background:#e7f9e7; border:1px solid green; text-align:center;"><h3>✅ Distinta Ricevuta!</h3><p>Il file è stato caricato correttamente.</p><div id="paguro-view-link"></div></div>').insertAfter('#paguro-upload-area');
                    }
                    
                    // 3. Mostra il box di successo
                    $('#paguro-upload-success-container').slideDown();
                    
                    // 4. Inserisci il bottone "Visualizza" con il link restituito dal server
                    var btnHtml = '<a href="' + res.data.url + '" target="_blank" class="button" style="background:#0073aa; color:#fff; padding:10px 20px; text-decoration:none; border-radius:4px; display:inline-block; margin-top:10px;">📄 Visualizza Distinta</a>';
                    $('#paguro-view-link').html(btnHtml);
                    
                    // Pulisci status vecchi
                    $('#paguro-upload-status').html(''); 
                } else {
                    $('#paguro-upload-status').html('❌ ' + res.data.msg).css('color', 'red');
                }
            },
            error: function() {
                $('#paguro-upload-status').html(data.msgs.upload_error).css('color', 'red');
            }
        });
    });

});