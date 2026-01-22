(function($){
    $(function(){
        // Test connection handler (settings page)
        $('#s3-media-sync-test').on('click', function(e){
            e.preventDefault();
            var $result = $('#s3-media-sync-test-result');
            $result.css('color','').text('Testing...');

            var opts = {};
            var keys = ['enabled','access_key','secret_key','bucket','endpoint'];
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
            }).fail(function(xhr){
                var text = 'AJAX error';
                if (xhr && xhr.responseText) text = xhr.responseText;
                $result.css('color','red').text(text);
            });
        });

        // Manual sync handler (tools page)
        var syncing = false;
        var stopRequested = false;

        function updateProgress(percent, status) {
            $('#s3-media-sync-progress-bar').css('width', percent + '%');
            $('#s3-media-sync-status').text(status || (percent + '%'));
            // Update button labels while syncing/deleting
            if (syncing) {
                var orig = $('#s3-media-sync-sync').data('orig-label');
                if (!orig) {
                    $('#s3-media-sync-sync').data('orig-label', $('#s3-media-sync-sync').text());
                }
                $('#s3-media-sync-sync').text('Syncing — ' + percent + '%');
            } else {
                var orig = $('#s3-media-sync-sync').data('orig-label');
                if (orig) $('#s3-media-sync-sync').text(orig);
            }
            if (deleting) {
                var origd = $('#s3-media-sync-delete-local').data('orig-label');
                if (!origd) {
                    $('#s3-media-sync-delete-local').data('orig-label', $('#s3-media-sync-delete-local').text());
                }
                $('#s3-media-sync-delete-local').text('Deleting — ' + percent + '%');
            } else {
                var origd = $('#s3-media-sync-delete-local').data('orig-label');
                if (origd) $('#s3-media-sync-delete-local').text(origd);
            }
        }

        $('#s3-media-sync-sync').on('click', function(e){
            e.preventDefault();
            if (syncing) return;
            syncing = true;
            stopRequested = false;
            $(this).prop('disabled', true);
            $('#s3-media-sync-stop').prop('disabled', false);
            updateProgress(0, 'Starting...');

            var offset = 0;
            var batch = 10;

            function runBatch() {
                if (stopRequested) {
                    syncing = false;
                    $('#s3-media-sync-sync').prop('disabled', false);
                    $('#s3-media-sync-stop').prop('disabled', true);
                    updateProgress(0, 'Stopped by user');
                    return;
                }

                $.post(S3MediaSync.ajax_url, {
                    action: 's3_media_sync_sync_batch',
                    nonce: S3MediaSync.nonce_sync,
                    offset: offset,
                    batch_size: batch
                }, function(resp){
                    if (!resp) {
                        syncing = false;
                        updateProgress(0, 'No response');
                        return;
                    }
                    if (!resp.success) {
                        syncing = false;
                        updateProgress(0, resp.data || resp.message || 'Error');
                        $('#s3-media-sync-sync').prop('disabled', false);
                        $('#s3-media-sync-stop').prop('disabled', true);
                        return;
                    }

                    var data = resp.data;
                    offset = data.offset;
                    updateProgress( data.percent, 'Processed ' + offset + ' / ' + data.total + ' (succeeded: ' + data.succeeded + ')' );

                    if ( offset < data.total ) {
                        // Continue with next batch
                        setTimeout( runBatch, 250 );
                    } else {
                        syncing = false;
                        $('#s3-media-sync-sync').prop('disabled', false);
                        $('#s3-media-sync-stop').prop('disabled', true);
                        updateProgress(100, 'Sync complete. Succeeded: ' + data.succeeded + (data.errors && data.errors.length ? ', Errors: ' + data.errors.length : '') );
                    }
                }).fail(function(xhr){
                    syncing = false;
                    $('#s3-media-sync-sync').prop('disabled', false);
                    $('#s3-media-sync-stop').prop('disabled', true);
                    updateProgress(0, 'AJAX error');
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

        // Delete local media flow
        var deleting = false;
        var stopDeleteRequested = false;

        $('#s3-media-sync-delete-local').on('click', function(e){
            e.preventDefault();
            if (deleting) return;
            var ok = window.confirm('Are you sure you want to permanently delete local copies of media that have already been synced to S3? This cannot be undone. Continue?');
            if ( ! ok ) {
                return;
            }
            deleting = true;
            stopDeleteRequested = false;
            $(this).prop('disabled', true);
            $('#s3-media-sync-stop-delete').show().prop('disabled', false);
            updateProgress(0, 'Starting deletion...');

            var offset = 0;
            var batch = 10;

            function runDeleteBatch() {
                if (stopDeleteRequested) {
                    deleting = false;
                    $('#s3-media-sync-delete-local').prop('disabled', false);
                    $('#s3-media-sync-stop-delete').hide();
                    updateProgress(0, 'Deletion stopped by user');
                    return;
                }

                $.post(S3MediaSync.ajax_url, {
                    action: 's3_media_sync_delete_local',
                    nonce: S3MediaSync.nonce_delete,
                    offset: offset,
                    batch_size: batch
                }, function(resp){
                    if (!resp) {
                        deleting = false;
                        updateProgress(0, 'No response');
                        return;
                    }
                    if (!resp.success) {
                        deleting = false;
                        updateProgress(0, resp.data || resp.message || 'Error');
                        $('#s3-media-sync-delete-local').prop('disabled', false);
                        $('#s3-media-sync-stop-delete').hide();
                        return;
                    }

                    var data = resp.data;
                    offset = data.offset;
                    updateProgress( data.percent, 'Deleted local for ' + data.succeeded + ' attachments (processed: ' + data.processed + ')' );

                    if ( offset < data.total ) {
                        setTimeout( runDeleteBatch, 250 );
                    } else {
                        deleting = false;
                        $('#s3-media-sync-delete-local').prop('disabled', false);
                        $('#s3-media-sync-stop-delete').hide();
                        updateProgress(100, 'Local deletion complete. Removed: ' + data.succeeded + (data.errors && data.errors.length ? ', Errors: ' + data.errors.length : '') );
                    }
                }).fail(function(xhr){
                    deleting = false;
                    $('#s3-media-sync-delete-local').prop('disabled', false);
                    $('#s3-media-sync-stop-delete').hide();
                    updateProgress(0, 'AJAX error');
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
    });
})(jQuery);
