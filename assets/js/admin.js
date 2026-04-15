(function($){
    $(function(){
        var i18n = (S3MediaSync && S3MediaSync.i18n) ? S3MediaSync.i18n : {};

        function t(key, fallback) {
            return i18n[key] || fallback || key;
        }

        // Test connection handler (settings page)
        $('#s3-media-sync-test').on('click', function(e){
            e.preventDefault();
            var $result = $('#s3-media-sync-test-result');
            $result.css('color','').text(t('testing', 'Testing...'));

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
                $result.css('color','red').text(t('ajax_error', 'AJAX error'));
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
                $('#s3-media-sync-sync').text(t('syncing_percent', 'Syncing —') + ' ' + percent + '%');
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
                $('#s3-media-sync-delete-local').text(t('deleting_percent', 'Deleting —') + ' ' + percent + '%');
            } else {
                var orig = $('#s3-media-sync-delete-local').data('orig-label');
                if (orig) $('#s3-media-sync-delete-local').text(orig);
            }
        }

        // Restore saved last_id from previous session
        var savedLastId = parseInt( localStorage.getItem('s3_sync_last_id') || '0', 10 );
        if ( savedLastId > 0 ) {
            updateProgress(0, t('unfinished_progress', 'There is unfinished progress (last ID:') + ' ' + savedLastId + '). ' + t('click_start_to_continue', 'Click Start to continue.'));
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

            var startMsg = t('starting', 'Starting...');
            if (lastId > 0) {
                startMsg = t('starting', 'Starting...').replace(/\.*$/, '') + ' (' + t('resume_from_id', 'resume from ID') + ' ' + lastId + ')...';
            }
            updateProgress(0, startMsg);

            function resetButtons() {
                syncing = false;
                $('#s3-media-sync-sync').prop('disabled', false);
                $('#s3-media-sync-stop').prop('disabled', true);
            }

            function percent() {
                return grandTotal > 0 ? Math.round( (totalUploaded + totalSkipped + totalMissing) / grandTotal * 100 ) : 0;
            }

            function statusText() {
                return t('uploaded', 'Uploaded:') + ' ' + totalUploaded + ' / ' + grandTotal
                    + ' | ' + t('skipped', 'Skipped:') + ' ' + totalSkipped
                    + (totalMissing ? ' | ' + t('missing', 'Missing:') + ' ' + totalMissing : '')
                    + (totalErrors  ? ' | ' + t('errors', 'Errors:')  + ' ' + totalErrors  : '');
            }

            function runBatch() {
                if (stopRequested) {
                    resetButtons();
                    localStorage.setItem('s3_sync_last_id', lastId);
                    updateProgress(percent(), t('stopped_status', 'Stopped.') + ' ' + statusText() + '. ' + t('stopped_click_start', 'Stopped. Click Start to continue.').replace(/^Stopped\.\s*/, ''));
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
                        updateProgress(100, t('sync_complete', 'Sync complete!') + ' ' + t('uploaded', 'Uploaded:') + ' ' + totalUploaded
                            + ' | ' + t('skipped', 'Skipped:') + ' ' + totalSkipped
                            + (totalErrors ? ' | ' + t('errors', 'Errors:') + ' ' + totalErrors : ''));
                    } else {
                        setTimeout(runBatch, 300);
                    }
                }).fail(function(jqXHR){
                    retries++;
                    if (retries <= maxRetries) {
                        var delay = retries * 3000;
                        updateProgress(percent(),
                            t('server_error_auto_retry', 'Server error — Auto-retry')
                            + ' (' + (jqXHR.status || 'timeout') + ')'
                            + ' ' + retries + '/' + maxRetries
                            + ' ' + t('in_seconds', 'in') + ' ' + (delay/1000) + 's...'
                        );
                        setTimeout(runBatch, delay);
                    } else {
                        retries = 0;
                        lastId += batch;
                        localStorage.setItem('s3_sync_last_id', lastId);
                        totalErrors += batch;
                        updateProgress(percent(), t('batch_error_skipped', 'Batch error — skipped, continuing...'));
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
            // Reset manual sync offset (browser)
            localStorage.removeItem('s3_sync_last_id');
            updateProgress(0, t('manual_sync_offset_reset', 'Manual sync offset reset.'));
            // Also reset background sync offset (server DB)
            $.post(S3MediaSync.ajax_url, {
                action: 's3_media_sync_bg_reset_offset',
                nonce:  S3MediaSync.nonce_bg
            }, function() {
                bgUpdateUI({ status: 'stopped', processed: 0, succeeded: 0, skipped: 0, missing: 0, errors: 0, total: 0 });
            });
        });

        // ── Delete Local Media ────────────────────────────────────────────────
        var deleting            = false;
        var stopDeleteRequested = false;

        $('#s3-media-sync-delete-local').on('click', function(e){
            e.preventDefault();
            if (deleting) return;
            if (!window.confirm(t('confirm_delete_local', 'Delete local files of media already synced to S3? This action cannot be undone.'))) return;

            deleting            = true;
            stopDeleteRequested = false;
            $(this).prop('disabled', true);
            $('#s3-media-sync-stop-delete').show().prop('disabled', false);
            updateDeleteProgress(0, t('starting_deletion', 'Starting deletion...'));

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
                    updateDeleteProgress(0, t('deletion_stopped', 'Deletion stopped. Removed:') + ' ' + totalDel);
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
                        updateDeleteProgress(0, 'Error: ' + (resp && (resp.data || resp.message) ? (resp.data || resp.message) : t('error_unknown', 'Unknown')));
                        return;
                    }
                    var data = resp.data;
                    offset   = data.offset;
                    totalDel += data.succeeded || 0;
                    updateDeleteProgress(data.percent, t('processed', 'Processed') + ' ' + offset + ' / ' + data.total + ' | ' + t('deleted', 'Deleted:') + ' ' + totalDel);
                    if (offset < data.total) {
                        setTimeout(runDeleteBatch, 300);
                    } else {
                        resetDeleteButtons();
                        updateDeleteProgress(100, t('done_deleted', 'Done! Deleted local files:') + ' ' + totalDel + (data.errors && data.errors.length ? ' | ' + t('errors', 'Errors:') + ' ' + data.errors.length : ''));
                    }
                }).fail(function(){
                    delRetries++;
                    if (delRetries <= 3) {
                        updateDeleteProgress(0, t('error_auto_retry', 'Error — Auto-retry') + ' ' + delRetries + '/3...');
                        setTimeout(runDeleteBatch, delRetries * 3000);
                    } else {
                        resetDeleteButtons();
                        updateDeleteProgress(0, t('failed_deleted_so_far', 'Failed. Deleted so far:') + ' ' + totalDel + '. ' + t('refresh_and_retry', 'Refresh and click again to continue.'));
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

        // ── Background Sync (WP-Cron) ────────────────────────────────────────
        var bgPollTimer = null;

        function bgStatusText( state ) {
            if ( ! state || state.status === 'idle' ) return t('no_bg_sync_running', 'No background sync running.');
            var msg = '[' + state.status.toUpperCase() + '] '
                + t('uploaded', 'Uploaded:') + ' ' + ( state.succeeded || 0 )
                + ' / ' + ( state.total || '?' )
                + ' | ' + t('skipped', 'Skipped:') + ' ' + ( state.skipped || 0 )
                + ( state.missing ? ' | ' + t('missing', 'Missing:') + ' ' + state.missing : '' )
                + ( state.errors  ? ' | ' + t('errors', 'Errors:')  + ' ' + state.errors  : '' );
            if ( state.status === 'done' )    msg = t('bg_done', 'Done!') + ' ' + msg;
            if ( state.status === 'stopped' ) msg = t('bg_stopped', 'Stopped.') + ' ' + msg;
            if ( state.status === 'error' )   msg = t('bg_error', 'Error:') + ' ' + ( state.last_error || '' );
            return msg + ( state.updated_at ? ' (' + t('last_update', 'last update:') + ' ' + new Date( state.updated_at * 1000 ).toLocaleTimeString() + ')' : '' );
        }

        function bgUpdateUI( state ) {
            var pct = ( state && state.total > 0 )
                ? Math.round( ( state.processed || 0 ) / state.total * 100 )
                : 0;
            $('#s3-bg-progress-bar').css( 'width', pct + '%' );
            $('#s3-bg-status').text( bgStatusText( state ) );

            var running = state && state.status === 'running';
            $('#s3-bg-start').prop( 'disabled', running ).text( running ? t('running', 'Running...') : t('start_background_sync', 'Start Background Sync') );
            $('#s3-bg-stop').toggle( running );
        }

        function bgPoll() {
            $.post( S3MediaSync.ajax_url, {
                action: 's3_media_sync_bg_status',
                nonce:  S3MediaSync.nonce_bg
            }, function( resp ) {
                if ( ! resp || ! resp.success ) return;
                var state = resp.data;
                bgUpdateUI( state );
                if ( state.status === 'running' ) {
                    bgPollTimer = setTimeout( bgPoll, 4000 );
                } else {
                    bgPollTimer = null;
                }
            } );
        }

        // Poll on page load to restore state if sync was already running
        bgPoll();

        $('#s3-bg-start').on( 'click', function(e) {
            e.preventDefault();
            if ( ! window.confirm( t('confirm_start_bg', 'Start background sync? All unsynced attachments will be uploaded to S3 on the server.') ) ) return;
            $(this).prop( 'disabled', true ).text( t('starting_bg', 'Starting...') );
            $.post( S3MediaSync.ajax_url, {
                action: 's3_media_sync_bg_start',
                nonce:  S3MediaSync.nonce_bg
            }, function( resp ) {
                if ( resp && resp.success ) {
                    bgUpdateUI( resp.data );
                    bgPollTimer = setTimeout( bgPoll, 4000 );
                } else {
                    $('#s3-bg-status').css( 'color', 'red' ).text( resp.data || t('error_starting_bg', 'Error starting background sync.') );
                    $('#s3-bg-start').prop( 'disabled', false ).text( t('start_background_sync', 'Start Background Sync') );
                }
            } ).fail( function() {
                $('#s3-bg-status').css( 'color', 'red' ).text( t('ajax_error', 'AJAX error') + '.' );
                $('#s3-bg-start').prop( 'disabled', false ).text( t('start_background_sync', 'Start Background Sync') );
            } );
        } );

        $('#s3-bg-clear').on( 'click', function(e) {
            e.preventDefault();
            if ( ! window.confirm( t('confirm_clear_all', 'Stop all running actions, clear sync status, and reset everything? This cannot be undone.') ) ) return;
            var $btn = $(this).prop( 'disabled', true ).text( t('clearing', 'Clearing...') );
            if ( bgPollTimer ) { clearTimeout( bgPollTimer ); bgPollTimer = null; }
            $.post( S3MediaSync.ajax_url, {
                action: 's3_media_sync_bg_clear_all',
                nonce:  S3MediaSync.nonce_bg
            }, function( resp ) {
                $btn.prop( 'disabled', false ).text( t('stop_clear_all', 'Stop & Clear All (reset)') );
                if ( resp && resp.success ) {
                    bgUpdateUI( { status: 'stopped', processed: 0, succeeded: 0, skipped: 0, missing: 0, errors: 0, total: 0 } );
                    $('#s3-bg-status').css( 'color', 'green' ).text( resp.data.message );
                } else {
                    $('#s3-bg-status').css( 'color', 'red' ).text( resp.data || 'Error.' );
                }
            } ).fail( function() {
                $btn.prop( 'disabled', false ).text( t('stop_clear_all', 'Stop & Clear All (reset)') );
                $('#s3-bg-status').css( 'color', 'red' ).text( t('ajax_error', 'AJAX error') + '.' );
            } );
        } );

        $('#s3-bg-stop').on( 'click', function(e) {
            e.preventDefault();
            if ( bgPollTimer ) { clearTimeout( bgPollTimer ); bgPollTimer = null; }
            $.post( S3MediaSync.ajax_url, {
                action: 's3_media_sync_bg_stop',
                nonce:  S3MediaSync.nonce_bg
            }, function( resp ) {
                if ( resp && resp.success ) bgUpdateUI( resp.data );
            } );
        } );

        // ── Mark All as Synced ────────────────────────────────────────────────
        $('#s3-media-sync-mark-all').on('click', function(e){
            e.preventDefault();
            if (!window.confirm(t('confirm_mark_all', 'Mark all media as synced without uploading to S3? Use this when images were already imported to S3 via another tool.'))) return;
            var $btn    = $(this).prop('disabled', true).text(t('mark_all_processing', 'Processing...'));
            var $result = $('#s3-media-sync-mark-all-result').css('color','').text('');
            $.post(S3MediaSync.ajax_url, {
                action: 's3_media_sync_mark_all_synced',
                nonce:  S3MediaSync.nonce_mark_all
            }, function(resp){
                $btn.prop('disabled', false).text(t('mark_all_synced_btn', 'Mark All as Synced'));
                if (resp.success) {
                    $result.css('color','green').text(resp.data);
                } else {
                    $result.css('color','red').text(resp.data || 'Error');
                }
            }).fail(function(){
                $btn.prop('disabled', false).text(t('mark_all_synced_btn', 'Mark All as Synced'));
                $result.css('color','red').text(t('ajax_error', 'AJAX error'));
            });
        });

        // ── Reset Sync Status ─────────────────────────────────────────────────
        $('#s3-media-sync-reset').on('click', function(e){
            e.preventDefault();
            if (!window.confirm(t('confirm_reset_status', 'Delete all sync status? All files will be treated as not yet synced.'))) return;
            var $btn    = $(this);
            var $result = $('#s3-media-sync-reset-result');
            $btn.prop('disabled', true);
            $result.css('color','').text(t('resetting', 'Deleting...'));
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
                $result.css('color','red').text(t('ajax_error', 'AJAX error'));
            });
        });
    });
})(jQuery);
