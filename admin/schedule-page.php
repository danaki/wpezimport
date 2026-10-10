<?php
/**
 * Scheduling Options Page
 *
 * Displays trigger, processing, and cancel URLs for a specific import,
 * along with current cron status and instructions.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get import ID from URL parameter
$import_id = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;

if (empty($import_id)) {
    echo '<div class="wrap"><h1>' . esc_html__('Scheduling Options', 'ezimport') . '</h1>';
    echo '<div class="notice notice-error"><p>' . esc_html__('No import ID specified.', 'ezimport') . '</p></div></div>';
    return;
}

// Load import
global $wpdb;
$table_name = $wpdb->prefix . 'wpezimport_saved_imports';
$import = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $import_id));

if (!$import) {
    echo '<div class="wrap"><h1>' . esc_html__('Scheduling Options', 'ezimport') . '</h1>';
    echo '<div class="notice notice-error"><p>' . esc_html__('Import not found.', 'ezimport') . '</p></div></div>';
    return;
}

if ($import->type !== 'url') {
    echo '<div class="wrap"><h1>' . esc_html__('Scheduling Options', 'ezimport') . '</h1>';
    echo '<div class="notice notice-error"><p>' . esc_html__('Cron scheduling is only available for URL-based imports.', 'ezimport') . '</p></div></div>';
    return;
}

// Handle regenerate key action
if (isset($_POST['wpezimport_regenerate_key']) && check_admin_referer('wpezimport_schedule_' . $import_id)) {
    $new_key = wp_generate_password(48, false, false);
    $wpdb->update($table_name, array('import_key' => $new_key), array('id' => $import_id), array('%s'), array('%d'));
    $import->import_key = $new_key;
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Import key regenerated. Update your crontab with the new URLs below.', 'ezimport') . '</p></div>';
}

// Handle reset progress action
if (isset($_POST['wpezimport_reset_progress']) && check_admin_referer('wpezimport_schedule_' . $import_id)) {
    $wpdb->update($table_name, array(
        'cron_status'      => 'idle',
        'cron_phase'       => 'posts',
        'cron_last_item'   => 0,
        'cron_total_items' => 0,
        'cron_started_at'  => null,
        'cron_created'     => 0,
        'cron_updated'     => 0,
        'cron_errors'      => 0,
    ), array('id' => $import_id));
    $import->cron_status = 'idle';
    $import->cron_phase = 'posts';
    $import->cron_last_item = 0;
    $import->cron_total_items = 0;
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Import progress has been reset.', 'ezimport') . '</p></div>';
}

// Ensure the import has a key
if (empty($import->import_key)) {
    require_once plugin_dir_path(__FILE__) . 'cron-handler.php';
    $import->import_key = wpezimport_ensure_import_key($import_id);
}

// Build URLs
$base_url = home_url('/');
$trigger_url  = add_query_arg(array('wpezimport_action' => 'trigger',  'import_id' => $import_id, 'import_key' => $import->import_key), $base_url);
$process_url  = add_query_arg(array('wpezimport_action' => 'process',  'import_id' => $import_id, 'import_key' => $import->import_key), $base_url);
$cancel_url   = add_query_arg(array('wpezimport_action' => 'cancel',   'import_id' => $import_id, 'import_key' => $import->import_key), $base_url);

// Cron status info
$status_labels = array(
    'idle'       => __('Idle', 'ezimport'),
    'triggered'  => __('Triggered (waiting for processing)', 'ezimport'),
    'processing' => __('Processing', 'ezimport'),
);
$phase_labels = array(
    'posts'    => __('Posts (creating/updating)', 'ezimport'),
    'images'   => __('Images (downloading)', 'ezimport'),
    'complete' => __('Complete', 'ezimport'),
);

$cron_status = isset($status_labels[$import->cron_status]) ? $status_labels[$import->cron_status] : $import->cron_status;
$cron_phase  = isset($phase_labels[$import->cron_phase])   ? $phase_labels[$import->cron_phase]   : $import->cron_phase;
$cron_progress = (int) $import->cron_last_item;
$cron_total    = (int) $import->cron_total_items;

// Parse cron log
$cron_log = !empty($import->cron_log) ? maybe_unserialize($import->cron_log) : array();
if (!is_array($cron_log)) {
    $cron_log = array();
}
$cron_log_recent = array_slice($cron_log, -20);
?>

<div class="wrap wpezimport-admin-wrap">
    <h1><?php /* translators: %s: name of the import. */ echo esc_html(sprintf(__('Scheduling Options: %s', 'ezimport'), $import->name)); ?></h1>

    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button">&larr; <?php esc_html_e('Back to Manage Imports', 'ezimport'); ?></a>
    </p>

    <!-- Current Status -->
    <div class="wpezimport-schedule-section">
        <h2><?php esc_html_e('Current Status', 'ezimport'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Status', 'ezimport'); ?></th>
                <td>
                    <span class="wpezimport-cron-status wpezimport-cron-status-<?php echo esc_attr($import->cron_status); ?>">
                        <?php echo esc_html($cron_status); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Phase', 'ezimport'); ?></th>
                <td><?php echo esc_html($cron_phase); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Progress', 'ezimport'); ?></th>
                <td>
                    <?php if ($cron_total > 0): ?>
                        <?php echo esc_html($cron_progress); ?> / <?php echo esc_html($cron_total); ?>
                        (<?php echo esc_html(round(($cron_progress / $cron_total) * 100, 1)); ?>%)
                        <div class="wpezimport-progress-bar">
                            <div class="wpezimport-progress-fill" style="width: <?php echo esc_attr(round(($cron_progress / $cron_total) * 100, 1)); ?>%"></div>
                        </div>
                    <?php else: ?>
                        <?php esc_html_e('Not started', 'ezimport'); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Last Run', 'ezimport'); ?></th>
                <td>
                    <?php
                    if (!empty($import->last_run)) {
                        echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->last_run)));
                    } else {
                        esc_html_e('Never', 'ezimport');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Stats (this run)', 'ezimport'); ?></th>
                <td>
                    <?php
                    $cc = isset($import->cron_created) ? (int) $import->cron_created : 0;
                    $cu = isset($import->cron_updated) ? (int) $import->cron_updated : 0;
                    $ce = isset($import->cron_errors)  ? (int) $import->cron_errors  : 0;
                    if ($cc || $cu || $ce) {
                        printf(
                            /* translators: 1: created count, 2: updated count, 3: error count. */
                            esc_html__('Created: %1$d | Updated: %2$d | Errors: %3$d', 'ezimport'),
                            absint($cc), absint($cu), absint($ce)
                        );
                    } else {
                        esc_html_e('No data yet', 'ezimport');
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Source URL', 'ezimport'); ?></th>
                <td><code><?php echo esc_html($import->url); ?></code></td>
            </tr>
        </table>

        <form method="post" style="display:inline;">
            <?php wp_nonce_field('wpezimport_schedule_' . $import_id); ?>
            <button type="submit" name="wpezimport_reset_progress" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reset progress? The import will start from the beginning on the next processing run.', 'ezimport'); ?>');">
                <?php esc_html_e('Reset Progress', 'ezimport'); ?>
            </button>
        </form>
    </div>

    <!-- Cron URLs -->
    <div class="wpezimport-schedule-section">
        <h2><?php esc_html_e('Cron URLs', 'ezimport'); ?></h2>
        <p class="description"><?php esc_html_e('Use these URLs with wget or curl in your server\'s crontab to automate this import.', 'ezimport'); ?></p>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Trigger URL', 'ezimport'); ?></th>
                <td>
                    <input type="text" readonly class="large-text code wpezimport-copy-field" value="<?php echo esc_attr($trigger_url); ?>" onclick="this.select();" />
                    <p class="description"><?php esc_html_e('Starts the import. Checks if the source file has changed (using ETag/Last-Modified headers). If content changed, the import restarts from the beginning. Run this once per day (or as needed).', 'ezimport'); ?></p>
                    <p class="description"><strong><?php esc_html_e('Crontab example (once daily at 2:00 AM):', 'ezimport'); ?></strong></p>
                    <code class="wpezimport-crontab-example">0 2 * * * /usr/bin/wget -q -O /dev/null "<?php echo esc_html($trigger_url); ?>"</code>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Processing URL', 'ezimport'); ?></th>
                <td>
                    <input type="text" readonly class="large-text code wpezimport-copy-field" value="<?php echo esc_attr($process_url); ?>" onclick="this.select();" />
                    <p class="description"><?php esc_html_e('Continues the import where it left off. Each run processes items for up to ~2 minutes (or the server\'s max execution time, whichever is shorter). When the import is complete, this URL has no effect until the next trigger.', 'ezimport'); ?></p>
                    <p class="description"><strong><?php esc_html_e('Crontab example (every 2 minutes):', 'ezimport'); ?></strong></p>
                    <code class="wpezimport-crontab-example">*/2 * * * * /usr/bin/wget -q -O /dev/null "<?php echo esc_html($process_url); ?>"</code>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Cancel URL', 'ezimport'); ?></th>
                <td>
                    <input type="text" readonly class="large-text code wpezimport-copy-field" value="<?php echo esc_attr($cancel_url); ?>" onclick="this.select();" />
                    <p class="description"><?php esc_html_e('Cancels the currently running import. Progress is preserved — the import can be re-triggered later without starting over (unless the content has changed).', 'ezimport'); ?></p>
                </td>
            </tr>
        </table>

        <form method="post" style="display:inline;">
            <?php wp_nonce_field('wpezimport_schedule_' . $import_id); ?>
            <button type="submit" name="wpezimport_regenerate_key" class="button" onclick="return confirm('<?php esc_attr_e('Regenerating the key will invalidate all existing cron URLs. You will need to update your crontab. Continue?', 'ezimport'); ?>');">
                <?php esc_html_e('Regenerate Secret Key', 'ezimport'); ?>
            </button>
        </form>
    </div>

    <!-- How It Works -->
    <div class="wpezimport-schedule-section">
        <h2><?php esc_html_e('How It Works', 'ezimport'); ?></h2>
        <ol>
            <li><?php esc_html_e('The trigger URL is called by cron (e.g., once a day). It downloads the source XML/CSV and checks if the content has changed since the last import.', 'ezimport'); ?></li>
            <li><?php esc_html_e('If the content has changed (or this is the first run), the import state is reset and the import is marked as "triggered".', 'ezimport'); ?></li>
            <li><?php esc_html_e('The processing URL is called every 2 minutes by cron. Each time it runs, it processes as many items as it can within the time limit (~2 minutes).', 'ezimport'); ?></li>
            <li><?php esc_html_e('The import has two phases: first all posts are created/updated, then all images are downloaded and attached.', 'ezimport'); ?></li>
            <li><?php esc_html_e('When both phases are complete, the import is "untriggered" — the processing URL will have no effect until the trigger URL is called again.', 'ezimport'); ?></li>
            <li><?php esc_html_e('The cancel URL can be called at any time to stop the import. Progress is preserved.', 'ezimport'); ?></li>
        </ol>
    </div>

    <!-- Recent Log -->
    <?php if (!empty($cron_log_recent)): ?>
    <div class="wpezimport-schedule-section">
        <h2><?php esc_html_e('Recent Log', 'ezimport'); ?></h2>
        <div class="wpezimport-cron-log">
            <?php foreach (array_reverse($cron_log_recent) as $entry): ?>
                <div class="wpezimport-cron-log-entry"><code><?php echo esc_html($entry); ?></code></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

