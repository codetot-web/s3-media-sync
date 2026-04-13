(function($){
    $(function(){
        // Test connection handler (settings page)
        $('#s3-media-sync-test').on('click', function(e){
            e.preventDefault();
            var $result = $('#s3-media-sync-test-result');
            $result.css('color','').text('Testing...');

            var opts = {};
            var keys = ['enabled','access_key','secret_key','region','bucket','endpoint','public_url','disable_ssl_verify'];
            keys.forEach(function(k){
                var el = $('input[name="s3_media_sync_options['+k+']"]');
                if (el.length) {
                    if (el.is(':checkbox')) opts[k] = el.is(':checked') ? 1 : 0;
                    else opts[k] = el.val();
                }
            });

            $.post(S3MediaSync.ajax_url, {
                action: 's3_media_sync_test_connection',
                nonce: S3MediaSync.nonce_test,
                opts: opts
            }, function(resp){
                if (resp.success) {
                    $result.css('color','green').text(resp.data);
                } else {
                    $result.css('color','red').text(resp.data || resp.message || 'Error');
                }
            }).fail(function(){
                $result.css('color','red').text('AJAX error');
            });
        });

        // ── Manual Sync ──────────────────────────────────────────────────────
        var syncing       = false;
        var stopRequested = false;

        function updateProgress(percent, status) {
            $('#s3-media-sync-progress-bar').css('width', percent + '%');
            $('#s3-media-sync-status').text(status || (percent + '%'));
            if (syncing) {
                var orig = $('#s3-media-sync-sync').data('orig-label');
                if (!orig) $('#s3-media-sync-sync').data('orig-label', $('#s3-media-sync-sync').text());
                $('#s3-media-sync-sync').text('Syncing — ' + percent + '%');
            } else {
                var orig = $('#s3-media-sync-sync').data('orig-label');
                if (orig) $('#s3-media-sync-sync').text(orig);
            }
        }

        function updateDeleteProgress(percent, status) {
            $('#s3-media-sync-delete-progress-bar').css('width', percent + '%');
            $('#s3-media-sync-delete-status').text(status || (percent + '%'));
            if (deleting) {
                var orig = $('#s3-media-sync-delete-local').data('orig-label');
                if (!orig) $('#s3-media-sync-delete-local').data('orig-label', $('#s3-media-sync-delete-local').text());
                $('#s3-media-sync-delete-local').text('Deleting — ' + percent + '%');
            } else {
                var orig = $('#s3-media-sync-delete-local').data('orig-label');
                if (orig) $('#s3-media-sync-delete-local').text(orig);
            }
        }

        // Restore saved last_id from previous session
        var savedLastId = parseInt( localStorage.getItem('s3_sync_last_id') || '0', 10 );
        if ( savedLastId > 0 ) {
            updateProgress(0, 'Có tiến trình chưa hoàn thành (last ID: ' + savedLastId + '). Bấm Start để tiếp tục.');
        }

        $('#s3-media-sync-sync').on('click', function(e){
            e.preventDefault();
            if (syncing) return;
            syncing       = true;
            stopRequested = false;
            $(this).prop('disabled', true);
            $('#s3-media-sync-stop').prop('disabled', false);

            var lastId        = parseInt( localStorage.getItem('s3_sync_last_id') || '0', 10 );
            var batch         = 5;
            var totalUploaded = 0;
            var totalSkipped  = 0;
            var totalMissing  = 0;
            var totalErrors   = 0;
            var grandTotal    = 0;
            var retries       = 0;
            var maxRetries    = 3;

            updateProgress(0, 'Starting' + (lastId > 0 ? ' (resume từ ID ' + lastId + ')' : '') + '...');

            function resetButtons() {
                syncing = false;
                $('#s3-media-sync-sync').prop('disabled', false);
                $('#s3-media-sync-stop').prop('disabled', true);
            }

            function percent() {
                return grandTotal > 0 ? Math.round( (totalUploaded + totalSkipped + totalMissing) / grandTotal * 100 ) : 0;
            }

            function statusText() {
                return 'Uploaded: ' + totalUploaded + ' / ' + grandTotal
                    + ' | Skipped: ' + totalSkipped
                    + (totalMissing ? ' | Missing: ' + totalMissing : '')
                    + (totalErrors  ? ' | Errors: '  + totalErrors  : '');
            }

            function runBatch() {
                if (stopRequested) {
                    resetButtons();
                    localStorage.setItem('s3_sync_last_id', lastId);
                    updateProgress(percent(), 'Stopped. ' + statusText() + '. Bấm Start để tiếp tục.');
                    return;
                }

                $.ajax({
                    url:     S3MediaSync.ajax_url,
                    type:    'POST',
                    timeout: 300000,
                    data: {
                        action:     's3_media_sync_sync_batch',
                        nonce:      S3MediaSync.nonce_sync,
                        last_id:    lastId,
                        total:      grandTotal,
                        batch_size: batch
                    }
                }).done(function(resp){
                    retries = 0;

                    if (!resp || !resp.success) {
                        lastId += batch;
                        localStorage.setItem('s3_sync_last_id', lastId);
                        setTimeout(runBatch, 500);
                        return;
                    }

                    var data = resp.data;
                    if ( data.total ) grandTotal = data.total;
                    lastId        = data.last_id || lastId;
                    totalUploaded += data.succeeded || 0;
                    totalSkipped  += data.skipped   || 0;
                    totalMissing  += data.missing   || 0;
                    totalErrors   += data.errors ? data.errors.length : 0;

                    localStorage.setItem('s3_sync_last_id', lastId);
                    updateProgress(percent(), statusText());

                    if ( data.done ) {
                        resetButtons();
                        localStorage.removeItem('s3_sync_last_id');
                        updateProgress(100, 'Sync complete! Uploaded: ' + totalUploaded
                            + ' | Skipped: ' + totalSkipped
                            + (totalErrors ? ' | Errors: ' + totalErrors : ''));
                    } else {
                        setTimeout(runBatch, 300);
                    }
                }).fail(function(jqXHR){
                    retries++;
                    if (retries <= maxRetries) {
                        var delay = retries * 3000;
                        updateProgress(percent(),
                            'Server error (' + (jqXHR.status || 'timeout') + ')'
                            + ' — Auto-retry ' + retries + '/' + maxRetries + ' in ' + (delay/1000) + 's...'
                        );
                        setTimeout(runBatch, delay);
                    } else {
                        retries = 0;
                        lastId += batch;
                        localStorage.setItem('s3_sync_last_id', lastId);
                        totalErrors += batch;
                        updateProgress(percent(), 'Batch lỗi — đã bỏ qua, tiếp tục...');
                        setTimeout(runBatch, 1000);
                    }
                });
            }

            runBatch();
        });

        $('#s3-media-sync-stop').on('click', function(e){
            e.preventDefault();
            if (!syncing) return;
            stopRequested = true;
            $(this).prop('disabled', true);
        });

        $('#s3-media-sync-reset-offset').on('click', function(e){
            e.preventDefault();
            if (syncing) return;
            localStorage.removeItem('s3_sync_last_id');
            updateProgress(0, 'Đã reset. Bấm Start để sync từ đầu.');
        });

        // ── Delete Local Media ────────────────────────────────────────────────
        var deleting            = false;
        var stopDeleteRequested = false;

        $('#s3-media-sync-delete-local').on('click', function(e){
            e.preventDefault();
            if (deleting) return;
            if (!window.confirm('Xoá file local của media đã sync lên S3? Hành động này không thể hoàn tác.')) return;

            deleting            = true;
            stopDeleteRequested = false;
            $(this).prop('disabled', true);
            $('#s3-media-sync-stop-delete').show().prop('disabled', false);
            updateDeleteProgress(0, 'Starting deletion...');

            var offset     = 0;
            var batch      = 10;
            var totalDel   = 0;
            var delRetries = 0;

            function resetDeleteButtons() {
                deleting = false;
                $('#s3-media-sync-delete-local').prop('disabled', false);
                $('#s3-media-sync-stop-delete').hide();
            }

            function runDeleteBatch() {
                if (stopDeleteRequested) {
                    resetDeleteButtons();
                    updateDeleteProgress(0, 'Deletion stopped. Removed: ' + totalDel);
                    return;
                }

                $.post(S3MediaSync.ajax_url, {
                    action:     's3_media_sync_delete_local',
                    nonce:      S3MediaSync.nonce_delete,
                    offset:     offset,
                    batch_size: batch
                }, function(resp){
                    delRetries = 0;
                    if (!resp || !resp.success) {
                        resetDeleteButtons();
                        updateDeleteProgress(0, 'Error: ' + (resp && (resp.data || resp.message) ? (resp.data || resp.message) : 'Unknown'));
                        return;
                    }
                    var data = resp.data;
                    offset   = data.offset;
                    totalDel += data.succeeded || 0;
                    updateDeleteProgress(data.percent, 'Processed ' + offset + ' / ' + data.total + ' | Deleted: ' + totalDel);
                    if (offset < data.total) {
                        setTimeout(runDeleteBatch, 300);
                    } else {
                        resetDeleteButtons();
                        updateDeleteProgress(100, 'Done! Deleted local files: ' + totalDel + (data.errors && data.errors.length ? ' | Errors: ' + data.errors.length : ''));
                    }
                }).fail(function(){
                    delRetries++;
                    if (delRetries <= 3) {
                        updateDeleteProgress(0, 'Error — Auto-retry ' + delRetries + '/3...');
                        setTimeout(runDeleteBatch, delRetries * 3000);
                    } else {
                        resetDeleteButtons();
                        updateDeleteProgress(0, 'Failed. Deleted so far: ' + totalDel + '. Refresh và bấm lại để tiếp tục.');
                    }
                });
            }

            runDeleteBatch();
        });

        $('#s3-media-sync-stop-delete').on('click', function(e){
            e.preventDefault();
            if (!deleting) return;
            stopDeleteRequested = true;
            $(this).prop('disabled', true);
        });

        // ── Reset Sync Status ─────────────────────────────────────────────────
        $('#s3-media-sync-reset').on('click', function(e){
            e.preventDefault();
            if (!window.confirm('Xoá toàn bộ sync status? Tất cả file sẽ được coi là chưa sync.')) return;
            var $btn    = $(this);
            var $result = $('#s3-media-sync-reset-result');
            $btn.prop('disabled', true);
            $result.css('color','').text('Đang xoá...');
            $.post(S3MediaSync.ajax_url, {
                action: 's3_media_sync_reset_status',
                nonce:  S3MediaSync.nonce_reset
            }, function(resp){
                $btn.prop('disabled', false);
                if (resp.success) {
                    $result.css('color','green').text(resp.data);
                } else {
                    $result.css('color','red').text(resp.data || 'Error');
                }
            }).fail(function(){
                $btn.prop('disabled', false);
                $result.css('color','red').text('AJAX error');
            });
        });
    });
})(jQuery);
