<?php
/**
 * Import Processing Page
 *
 * AJAX-based progress UI that uses the shared batch processor
 * (import-batch.php). Works identically for web UI and cron-triggered imports.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get import ID from URL parameter
$import_id = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;

// Load saved import from DB
$import = ($import_id > 0) ? wpezimport_get_import($import_id) : null;
?>
<div class="wrap wpezimport-admin-wrap">
    <h1 class="wp-heading-inline"><?php _e('Import Processing', 'wpezimport'); ?></h1>

    <?php if (!$import): ?>
        <div class="notice notice-error">
            <p><?php _e('Import not found. Please start again from the Manage Imports page.', 'wpezimport'); ?></p>
        </div>
    <?php else: ?>
        <div class="wpezimport-results-box">
            <h2><?php echo esc_html($import->name); ?></h2>

            <!-- Progress section — visible during processing -->
            <div id="wpezimport-progress-section">
                <div class="wpezimport-progress-bar-wrap" style="background:#e0e0e0;border-radius:4px;height:28px;margin:16px 0;position:relative;overflow:hidden;">
                    <div id="wpezimport-progress-bar" style="background:#0073aa;height:100%;width:0%;transition:width .3s;border-radius:4px;"></div>
                    <span id="wpezimport-progress-text" style="position:absolute;top:0;left:0;right:0;text-align:center;line-height:28px;color:#fff;font-weight:600;text-shadow:0 1px 1px rgba(0,0,0,.3);"></span>
                </div>
                <div id="wpezimport-progress-status" style="margin:12px 0;">
                    <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                    <span id="wpezimport-status-text"><?php _e('Starting import...', 'wpezimport'); ?></span>
                </div>
                <div class="wpezimport-stats-grid" id="wpezimport-live-stats" style="margin-top:16px;">
                    <div class="wpezimport-stat-box created">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <div class="stat-number" id="stat-created">0</div>
                        <div class="stat-label"><?php _e('Created', 'wpezimport'); ?></div>
                    </div>
                    <div class="wpezimport-stat-box stat-updated">
                        <span class="dashicons dashicons-update"></span>
                        <div class="stat-number" id="stat-updated">0</div>
                        <div class="stat-label"><?php _e('Updated', 'wpezimport'); ?></div>
                    </div>
                    <div class="wpezimport-stat-box errors">
                        <span class="dashicons dashicons-warning"></span>
                        <div class="stat-number" id="stat-errors">0</div>
                        <div class="stat-label"><?php _e('Errors', 'wpezimport'); ?></div>
                    </div>
                    <div class="wpezimport-stat-box no_change">
                        <span class="dashicons dashicons-yes"></span>
                        <div class="stat-number" id="stat-nochange">0</div>
                        <div class="stat-label"><?php _e('No Change', 'wpezimport'); ?></div>
                    </div>
                    <div class="wpezimport-stat-box skipped">
                        <span class="dashicons dashicons-dismiss"></span>
                        <div class="stat-number" id="stat-skipped">0</div>
                        <div class="stat-label"><?php _e('Skipped', 'wpezimport'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Results section — populated after completion -->
            <div id="wpezimport-results-section" style="display:none;">
                <div class="notice notice-success" id="wpezimport-complete-notice" style="display:none;">
                    <p><strong><?php _e('Import complete!', 'wpezimport'); ?></strong> <span id="wpezimport-complete-summary"></span></p>
                </div>
                <div class="notice notice-error" id="wpezimport-error-notice" style="display:none;">
                    <p id="wpezimport-error-text"></p>
                </div>
                <div id="wpezimport-resolved-items" class="wpezimport-resolved-items"></div>
            </div>

            <p class="submit" id="wpezimport-actions" style="display:none;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button button-primary"><?php _e('Back to Manage Imports', 'wpezimport'); ?></a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-confirm&import_id=' . $import_id)); ?>" class="button"><?php _e('Run Again', 'wpezimport'); ?></a>
            </p>
        </div>

        <script type="text/javascript">
        (function($) {
            'use strict';

            var importId    = <?php echo intval($import_id); ?>;
            var ajaxUrl     = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var batchNonce  = '<?php echo wp_create_nonce('wpezimport_batch_nonce'); ?>';
            var allResolved = [];

            // Accumulated counters (across batches)
            var totals = { created: 0, updated: 0, errors: 0, no_change: 0, skipped: 0 };

            function updateStats(data) {
                if (data.created   !== undefined) totals.created   = data.created;
                if (data.updated   !== undefined) totals.updated   = data.updated;
                if (data.errors    !== undefined) totals.errors    = data.errors;
                if (data.no_change !== undefined) totals.no_change = data.no_change;
                if (data.skipped   !== undefined) totals.skipped   = data.skipped;

                $('#stat-created').text(totals.created);
                $('#stat-updated').text(totals.updated);
                $('#stat-errors').text(totals.errors);
                $('#stat-nochange').text(totals.no_change);
                $('#stat-skipped').text(totals.skipped);
            }

            function updateProgress(current, total) {
                if (total <= 0) return;
                var pct = Math.min(100, Math.round((current / total) * 100));
                $('#wpezimport-progress-bar').css('width', pct + '%');
                $('#wpezimport-progress-text').text(current + ' / ' + total + ' (' + pct + '%)');
            }

            function setStatus(text) {
                $('#wpezimport-status-text').text(text);
            }

            function showComplete(data) {
                $('#wpezimport-progress-section .spinner').removeClass('is-active');
                setStatus('<?php echo esc_js(__('Complete', 'wpezimport')); ?>');

                var summary = '<?php echo esc_js(__('Created', 'wpezimport')); ?>: ' + totals.created +
                    ', <?php echo esc_js(__('Updated', 'wpezimport')); ?>: ' + totals.updated +
                    ', <?php echo esc_js(__('Errors', 'wpezimport')); ?>: ' + totals.errors;
                if (totals.no_change > 0) summary += ', <?php echo esc_js(__('No Change', 'wpezimport')); ?>: ' + totals.no_change;
                if (totals.skipped > 0)   summary += ', <?php echo esc_js(__('Skipped', 'wpezimport')); ?>: ' + totals.skipped;

                $('#wpezimport-complete-summary').text(summary);
                $('#wpezimport-complete-notice').show();
                $('#wpezimport-results-section').show();
                $('#wpezimport-actions').show();

                renderResolvedItems();
            }

            function showError(msg) {
                $('#wpezimport-progress-section .spinner').removeClass('is-active');
                setStatus('<?php echo esc_js(__('Error', 'wpezimport')); ?>');
                $('#wpezimport-error-text').text(msg);
                $('#wpezimport-error-notice').show();
                $('#wpezimport-results-section').show();
                $('#wpezimport-actions').show();
            }

            // Render resolved items (accordion)
            function renderResolvedItems() {
                if (!allResolved.length) return;
                var $container = $('#wpezimport-resolved-items');
                $container.append('<h3><?php echo esc_js(__('Item Resolution Details', 'wpezimport')); ?></h3>');
                $container.append('<p class="description"><?php echo esc_js(__('Click on each item to expand and see field details.', 'wpezimport')); ?></p>');

                var statusLabels = {
                    'created':   '<?php echo esc_js(__('Created', 'wpezimport')); ?>',
                    'updated':   '<?php echo esc_js(__('Updated', 'wpezimport')); ?>',
                    'no_change': '<?php echo esc_js(__('No Change', 'wpezimport')); ?>',
                    'skipped':   '<?php echo esc_js(__('Skipped', 'wpezimport')); ?>',
                    'error':     '<?php echo esc_js(__('Error', 'wpezimport')); ?>'
                };

                $.each(allResolved, function(idx, item) {
                    var res    = item.resolution || {};
                    var status = res.status || 'unknown';
                    var title  = res.title || ('Item #' + item.item_index);
                    var label  = statusLabels[status] || status;
                    var fields = item.fields || {};
                    var showOld = (status === 'updated' || status === 'no_change');

                    var html = '<div class="wpezimport-resolved-item">';
                    html += '<div class="wpezimport-resolved-item-header" onclick="this.parentElement.classList.toggle(\'expanded\')">';
                    html += '<div><span class="toggle-icon dashicons dashicons-arrow-right-alt2"></span>';
                    html += '<span class="wpezimport-resolved-item-title">' + $('<span>').text(title).html() + '</span></div>';
                    html += '<span class="wpezimport-resolved-item-status ' + status + '">' + label + '</span>';
                    html += '</div>';
                    html += '<div class="wpezimport-resolved-item-body">';

                    // ID resolution
                    var idRes = item.id_resolution || {};
                    html += '<div class="wpezimport-id-resolution"><h4><?php echo esc_js(__('ID Resolution', 'wpezimport')); ?></h4>';
                    html += '<table class="wpezimport-id-table">';
                    html += '<tr><th><?php echo esc_js(__('XPath Pattern:', 'wpezimport')); ?></th><td><code>' + $('<span>').text(idRes.xpath || '').html() + '</code></td></tr>';
                    html += '<tr><th><?php echo esc_js(__('Extracted Value:', 'wpezimport')); ?></th><td><strong>' + $('<span>').text(idRes.extracted_value || '(empty)').html() + '</strong></td></tr>';
                    html += '</table></div>';

                    // Result info
                    html += '<div class="wpezimport-resolution-info">';
                    if (res.post_id) html += '<strong><?php echo esc_js(__('Post ID:', 'wpezimport')); ?></strong> ' + res.post_id + ' | ';
                    html += '<strong><?php echo esc_js(__('Action:', 'wpezimport')); ?></strong> ' + label;
                    if (res.message) html += '<br><strong><?php echo esc_js(__('Message:', 'wpezimport')); ?></strong> ' + $('<span>').text(res.message).html();
                    html += '</div>';

                    // Fields table
                    if (Object.keys(fields).length > 0) {
                        html += '<table class="wpezimport-field-table ' + (showOld ? 'has-old-value' : '') + '">';
                        html += '<thead><tr><th><?php echo esc_js(__('Field', 'wpezimport')); ?></th>';
                        if (showOld) html += '<th><?php echo esc_js(__('Old Value', 'wpezimport')); ?></th>';
                        html += '<th><?php echo esc_js(__('Value', 'wpezimport')); ?></th></tr></thead><tbody>';
                        $.each(fields, function(fk, fd) {
                            var changed = fd.changed !== undefined ? fd.changed : true;
                            html += '<tr class="' + (changed ? 'changed-row' : 'no-change-row') + '">';
                            html += '<th>' + $('<span>').text(fk).html() + '<span class="wpezimport-field-xpath">' + $('<span>').text(fd.xpath || '').html() + '</span></th>';
                            if (showOld) {
                                html += '<td class="wpezimport-old-value">';
                                if (!changed) html += '<strong class="no-change-indicator">(no change)</strong>';
                                else html += $('<span>').text(fd.old_value || '').html();
                                html += '</td>';
                            }
                            html += '<td class="wpezimport-field-value">' + $('<span>').text(fd.value || '').html() + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    }

                    // Image statuses
                    var imgStatuses = item.image_statuses || [];
                    if (imgStatuses.length > 0) {
                        html += '<div class="wpezimport-image-statuses"><h4><?php echo esc_js(__('Image Processing', 'wpezimport')); ?></h4>';
                        html += '<table class="wpezimport-image-table"><thead><tr>';
                        html += '<th><?php echo esc_js(__('URL', 'wpezimport')); ?></th>';
                        html += '<th><?php echo esc_js(__('Status', 'wpezimport')); ?></th>';
                        html += '<th><?php echo esc_js(__('Details', 'wpezimport')); ?></th></tr></thead><tbody>';
                        $.each(imgStatuses, function(ii, is_) {
                            var imgUrl  = is_.url || '';
                            var imgStat = is_.status || 'unknown';
                            var imgMsg  = is_.message || '';
                            var cls = imgStat === 'fetched' ? 'success' : (imgStat === 'cached' ? 'info' : (imgStat === 'error' ? 'error' : 'warning'));
                            html += '<tr class="wpezimport-image-row ' + cls + '">';
                            html += '<td class="wpezimport-image-url" title="' + $('<span>').text(imgUrl).html() + '">' + $('<span>').text(imgUrl.length > 60 ? imgUrl.substring(0,60) + '...' : imgUrl).html() + '</td>';
                            html += '<td><span class="wpezimport-image-status ' + cls + '">' + imgStat + '</span></td>';
                            html += '<td>' + $('<span>').text(imgMsg).html() + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                    }

                    html += '</div></div>';
                    $container.append(html);
                });
            }

            // ── Step 1: Trigger the import ──
            function triggerImport() {
                setStatus('<?php echo esc_js(__('Triggering import (fetching data)...', 'wpezimport')); ?>');

                $.post(ajaxUrl, {
                    action:    'wpezimport_trigger_import',
                    import_id: importId,
                    nonce:     batchNonce
                }, function(response) {
                    if (response.success) {
                        setStatus('<?php echo esc_js(__('Processing items...', 'wpezimport')); ?>');
                        processBatch();
                    } else {
                        showError(response.data ? response.data.message : '<?php echo esc_js(__('Failed to trigger import.', 'wpezimport')); ?>');
                    }
                }).fail(function(xhr) {
                    showError('<?php echo esc_js(__('AJAX request failed.', 'wpezimport')); ?> (' + xhr.status + ')');
                });
            }

            // ── Step 2: Process batches in a loop ──
            function processBatch() {
                $.post(ajaxUrl, {
                    action:    'wpezimport_process_batch',
                    import_id: importId,
                    nonce:     batchNonce
                }, function(response) {
                    if (!response.success) {
                        showError(response.data ? response.data.message : '<?php echo esc_js(__('Batch processing failed.', 'wpezimport')); ?>');
                        return;
                    }

                    var d = response.data;

                    // Accumulate resolved items
                    if (d.resolved_items && d.resolved_items.length) {
                        allResolved = allResolved.concat(d.resolved_items);
                    }

                    updateStats(d);

                    if (d.total && d.total > 0) {
                        updateProgress(d.last_item || 0, d.total);
                    }

                    if (d.status === 'complete') {
                        updateProgress(d.total, d.total);
                        showComplete(d);
                    } else if (d.status === 'processing') {
                        // More items to do — continue
                        setStatus('<?php echo esc_js(__('Processing items...', 'wpezimport')); ?> ' + (d.last_item || 0) + '/' + (d.total || '?'));
                        processBatch();
                    } else {
                        showError('<?php echo esc_js(__('Unexpected status: ', 'wpezimport')); ?>' + d.status);
                    }
                }).fail(function(xhr) {
                    showError('<?php echo esc_js(__('AJAX request failed.', 'wpezimport')); ?> (' + xhr.status + ')');
                });
            }

            // ── Start ──
            $(document).ready(function() {
                triggerImport();
            });

        })(jQuery);
        </script>

    <?php endif; ?>
</div>
