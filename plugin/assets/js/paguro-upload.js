/**
 * PAGURO UPLOAD MODULE
 * Gestisce il caricamento delle ricevute di bonifico
 */

jQuery(document).ready(function($) {
    // Safe fallback if localized data is missing
    const data = window.paguroData || {
        ajax_url: window.ajaxurl || '/wp-admin/admin-ajax.php',
        nonce: '',
        booking_url: '/',
        icon_url: '',
        recaptcha_site: '',
        msgs: {}
    };

    function refreshNonce() {
        return $.post(data.ajax_url, { action: 'paguro_refresh_nonce' }).then(function(res) {
            if (res && res.success && res.data && res.data.nonce) {
                data.nonce = res.data.nonce;
                return data.nonce;
            }
            throw new Error('Nonce refresh failed');
        });
    }

    // =========================================================
    // UPLOAD DRAG & DROP
    // =========================================================

    $(document).on('dragover', '#paguro-upload-area', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('paguro-upload-dragover');
    });

    $(document).on('dragleave', '#paguro-upload-area', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('paguro-upload-dragover');
    });

    $(document).on('drop', '#paguro-upload-area', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('paguro-upload-dragover');

        var files = e.originalEvent.dataTransfer.files;
        if (files && files.length > 0) {
            handleFileUpload(files[0]);
        }
    });

    // =========================================================
    // CLICK TO SELECT FILE
    // =========================================================

    $(document).on('click', '#paguro-upload-area', function() {
        $('#paguro-file-input').click();
    });

    // =========================================================
    // SHOW SECURE AREA (Summary)
    // =========================================================

    $(document).on('click', '#paguro-show-secure-area', function(e) {
        e.preventDefault();
        var targetId = $(this).data('target') || 'paguro-secure-area';
        $('#' + targetId).show();
        $(this).hide();
    });

    $(document).on('change', '#paguro-file-input', function() {
        if (this.files && this.files.length > 0) {
            handleFileUpload(this.files[0]);
        }
    });

    // =========================================================
    // FILE UPLOAD HANDLER
    // =========================================================

    function handleFileUpload(file) {
        var token = $('#paguro-token').val();
        if (!token) {
            alert('Token mancante');
            return;
        }

        // Validazione file
        var allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
        var maxSize = 5 * 1024 * 1024; // 5MB

        if (!allowedTypes.includes(file.type)) {
            showUploadError('Formato file non supportato. Usa PDF, JPG, PNG o GIF.');
            return;
        }

        if (file.size > maxSize) {
            showUploadError('File troppo grande. Massimo 5MB.');
            return;
        }

        function sendUpload() {
            // Prepara upload
            var formData = new FormData();
            formData.append('action', 'paguro_upload_receipt');
            formData.append('nonce', data.nonce);
            formData.append('token', token);
            formData.append('file', file);

            // Mostra loader
            showUploadLoading();

            // Invia file
            $.ajax({
                url: data.ajax_url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(res) {
                    if (res.success) {
                        showUploadSuccess(res.data.url);
                    } else {
                        showUploadError(res.data.msg || 'Errore server');
                    }
                },
                error: function() {
                    showUploadError('Errore di connessione');
                }
            });
        }

        if (!data.nonce) {
            refreshNonce().then(sendUpload).catch(sendUpload);
        } else {
            sendUpload();
        }
    }

    // =========================================================
    // UI HELPERS
    // =========================================================

    function showUploadLoading() {
        $('#paguro-upload-status').html(
            '<div class="upload-spinner">⏳ Caricamento in corso...</div>'
        ).css('color', 'blue');
    }

    function showUploadSuccess(fileUrl) {
        $('#paguro-upload-area').slideUp(300);
        $('#paguro-upload-status').html('');

        var successHtml = '<h4>✅ Distinta Ricevuta!</h4>' +
            '<p>Le date sono ora bloccate in attesa di validazione.</p>' +
            '<a href="' + fileUrl + '" target="_blank" class="paguro-btn paguro-btn-secondary">📄 Visualizza File</a>';

        if ($('#paguro-upload-success-container').length === 0) {
            $('<div id="paguro-upload-success-container" class="paguro-alert paguro-alert-success"></div>')
                .insertAfter('#paguro-upload-area');
        }

        $('#paguro-upload-success-container')
            .html(successHtml)
            .slideDown(300);

        // Disabilita input file
        $('#paguro-file-input').prop('disabled', true);
    }

    function showUploadError(msg) {
        $('#paguro-upload-status').html(
            '<div class="upload-error">❌ ' + msg + '</div>'
        ).css('color', 'red');
    }

});
