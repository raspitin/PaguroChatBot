jQuery(document).ready(function($) {
    
    // 1. CHAT UI
    $('.paguro-chat-launcher').on('click', function() { $('.paguro-chat-widget').fadeToggle(); });
    $('.paguro-chat-header .close-btn').on('click', function() { $('.paguro-chat-widget').fadeOut(); });
    $('#paguro-send-btn').on('click', function() { sendMessage(); });
    $('#paguro-input').on('keypress', function(e) { if(e.which == 13) sendMessage(); });

    $(document).on('click', '.paguro-quick-btn', function() {
        var txt = $(this).data('msg');
        appendMessage("user", txt); 
        processMessage(txt); 
    });

    function sendMessage() {
        var msg = $('#paguro-input').val().trim(); if(msg === "") return;
        appendMessage("user", msg); $('#paguro-input').val(''); 
        processMessage(msg);
    }

    function processMessage(msg) {
        $('#paguro-chat-body').scrollTop($('#paguro-chat-body')[0].scrollHeight);
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
            success: function(response) { btn.hide(); if(response.success) { appendMessage("bot", response.data.reply); bindLoadMore(); } }
        });
    }
    function appendMessage(s, t) { var id='msg_'+Math.random().toString(36); var cls=(s==="user")?"paguro-msg-user":"paguro-msg-bot"; var ic=(s==='bot'&&paguroData.icon_url)?'<img src="'+paguroData.icon_url+'" class="paguro-bot-avatar">':''; $('#paguro-chat-body').append('<div id="'+id+'" class="paguro-msg '+cls+'">'+ic+'<div class="paguro-msg-content">'+t+'</div></div>'); $('#paguro-chat-body').scrollTop($('#paguro-chat-body')[0].scrollHeight); return id; }
    function removeMessage(id) { $('#' + id).remove(); }

    // 2. LOCK & REDIRECT
    $(document).on('click', '.paguro-book-btn', function(e) {
        e.preventDefault(); var btn = $(this); var originalText = btn.text(); if(btn.hasClass('locking')) return;
        btn.addClass('locking').text('Blocco...');
        $.ajax({
            url: paguroData.ajax_url, type: 'POST',
            data: { action: 'paguro_lock_dates', nonce: paguroData.nonce, apt_name: btn.data('apt'), date_in: btn.data('in'), date_out: btn.data('out') },
            success: function(res) {
                btn.removeClass('locking').text(originalText);
                if(res.success) {
                    window.location.href = paguroData.booking_url + res.data.redirect_params;
                } else { alert("⚠️ " + res.data.msg); }
            }, error: function() { btn.removeClass('locking').text(originalText); alert("Errore Server."); }
        });
    });

    // 3. NATIVE FORM SUBMIT
    $('#paguro-native-form').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        var btn = $('#paguro-submit-btn');
        var msg = $('#paguro-form-msg');
        
        btn.prop('disabled', true).text('Elaborazione...');
        msg.html('');

        function submitData(tokenRecaptcha) {
            var data = form.serializeArray();
            data.push({name: 'action', value: 'paguro_submit_booking'});
            data.push({name: 'nonce', value: paguroData.nonce});
            if(tokenRecaptcha) data.push({name: 'recaptcha_token', value: tokenRecaptcha});

            $.ajax({
                url: paguroData.ajax_url, type: 'POST', data: data,
                success: function(res) {
                    if (res.success) {
                        msg.html('<span style="color:green; font-weight:bold;">Richiesta inviata! Reindirizzamento...</span>');
                        window.location.replace(res.data.redirect);
                    } else {
                        btn.prop('disabled', false).text('Conferma Richiesta');
                        msg.html('<span style="color:red;">' + res.data.msg + '</span>');
                    }
                },
                error: function() { btn.prop('disabled', false).text('Conferma Richiesta'); msg.html('<span style="color:red;">Errore di connessione.</span>'); }
            });
        }

        if (paguroData.recaptcha_site) {
            grecaptcha.ready(function() {
                grecaptcha.execute(paguroData.recaptcha_site, {action: 'submit'}).then(function(token) {
                    submitData(token);
                });
            });
        } else { submitData(''); }
    });

// DRAG & DROP & UPLOAD (FIXED CACHE REFRESH)
    var dropArea = $('#paguro-upload-area');
    if (dropArea.length > 0) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => { dropArea.on(eventName, function(e) { e.preventDefault(); e.stopPropagation(); }); });
        dropArea.on('dragenter dragover', function() { $(this).css('background', '#e7f5fe').css('border-color', '#005b88'); });
        dropArea.on('dragleave drop', function() { $(this).css('background', '#fff').css('border-color', '#0073aa'); });
        dropArea.on('drop', function(e) { handleFiles(e.originalEvent.dataTransfer.files); });
        $('#paguro-file-input').on('change', function() { handleFiles(this.files); });

        function handleFiles(files) { if (files.length === 0) return; uploadFile(files[0]); }
        
        function uploadFile(file) {
            var statusDiv = $('#paguro-upload-status');
            statusDiv.html('<span style="color:#0073aa;">⏳ Caricamento in corso...</span>');
            
            var formData = new FormData();
            formData.append('action', 'paguro_upload_receipt'); 
            formData.append('nonce', paguroData.nonce); 
            formData.append('file', file); 
            formData.append('token', $('#paguro-token').val());
            
            $.ajax({
                url: paguroData.ajax_url, type: 'POST', data: formData, contentType: false, processData: false,
                success: function(response) {
                    if (response.success) { 
                        // IMMEDIATE UI SWAP
                        $('#paguro-upload-area').replaceWith(
                            '<div style="margin-top:20px; padding:20px; background:#e7f9e7; border:1px solid green; border-radius:8px; text-align:center;">' +
                            '<h3 style="color:green; margin:0;">✅ Distinta Caricata!</h3>' +
                            '<p>Attendi un istante...</p></div>'
                        );
                        // HARD RELOAD WITH TIMESTAMP TO BYPASS CACHE
                        setTimeout(function(){ 
                            window.location.href = window.location.href.split('#')[0] + '&t=' + new Date().getTime();
                        }, 1500); 
                    } 
                    else { statusDiv.html('<span style="color:red;">❌ ' + response.data.msg + '</span>'); }
                }, 
                error: function() { statusDiv.html('<span style="color:red;">❌ Errore server.</span>'); }
            });
        }
    }
});