<?php
/**
 * Statistics Page
 *
 * Displays import statistics for a specific saved import.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include import processor if not already included
if (!function_exists('wpezimport_get_statistics')) {
    require_once plugin_dir_path(__FILE__) . 'import-processor.php';
}

// Get the import ID from the URL
$import_id = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;

if ($import_id <= 0) {
    ?>
    <div class="wrap wpezimport-admin-wrap">
        <h1><?php esc_html_e('Import Statistics', 'ezimport'); ?></h1>
        <div class="notice notice-error">
            <p><?php esc_html_e('Invalid import ID.', 'ezimport'); ?></p>
        </div>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php esc_html_e('Back to Manage Imports', 'ezimport'); ?></a></p>
    </div>
    <?php
    return;
}

// Get the import details
$import = wpezimport_get_import($import_id);

if (!$import) {
    ?>
    <div class="wrap wpezimport-admin-wrap">
        <h1><?php esc_html_e('Import Statistics', 'ezimport'); ?></h1>
        <div class="notice notice-error">
            <p><?php esc_html_e('Import not found.', 'ezimport'); ?></p>
        </div>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php esc_html_e('Back to Manage Imports', 'ezimport'); ?></a></p>
    </div>
    <?php
    return;
}

// Get all statistics for this import
$statistics = wpezimport_get_statistics($import_id, true);
?>

<div class="wrap wpezimport-admin-wrap">
    <h1 class="wp-heading-inline"><?php /* translators: %s: name of the import. */ printf(esc_html__('Statistics for: %s', 'ezimport'), esc_html($import->name)); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="page-title-action"><?php esc_html_e('Back to Manage Imports', 'ezimport'); ?></a>
    <hr class="wp-header-end">

    <div class="wpezimport-import-info">
        <table class="widefat fixed" style="max-width: 600px;">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Import Name', 'ezimport'); ?></th>
                    <td><?php echo esc_html($import->name); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Type', 'ezimport'); ?></th>
                    <td><?php echo esc_html(ucfirst($import->type)); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Post Type', 'ezimport'); ?></th>
                    <td><?php echo esc_html($import->post_type); ?></td>
                </tr>
                <?php if ($import->type === 'url' && !empty($import->url)): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('URL', 'ezimport'); ?></th>
                    <td><a href="<?php echo esc_url($import->url); ?>" target="_blank"><?php echo esc_html($import->url); ?></a></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Created', 'ezimport'); ?></th>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->created_at))); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last Run', 'ezimport'); ?></th>
                    <td>
                        <?php
                        if ($import->last_run) {
                            echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->last_run)));
                        } else {
                            esc_html_e('Never', 'ezimport');
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <h2><?php esc_html_e('Import Run History', 'ezimport'); ?></h2>

    <?php if (empty($statistics)): ?>
        <div class="wpezimport-notice-box">
            <p><?php esc_html_e('No statistics available for this import yet. Run the import to generate statistics.', 'ezimport'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-confirm&import_id=' . $import_id)); ?>" class="button button-primary"><?php esc_html_e('Run Import', 'ezimport'); ?></a>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Run Date', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Created', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Updated', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Errors', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Total Processed', 'ezimport'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statistics as $stat): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stat->run_date))); ?></td>
                        <td><span class="wpezimport-stat-created"><?php echo intval($stat->created); ?></span></td>
                        <td><span class="wpezimport-stat-updated"><?php echo intval($stat->updated); ?></span></td>
                        <td>
                            <?php if (intval($stat->errors) > 0): ?>
                                <span class="wpezimport-stat-errors"><?php echo intval($stat->errors); ?></span>
                            <?php else: ?>
                                <span class="wpezimport-stat-no-errors">0</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($stat->created) + intval($stat->updated) + intval($stat->errors); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row"><?php esc_html_e('Totals', 'ezimport'); ?></th>
                    <td><strong><?php echo esc_html(array_sum(array_column($statistics, 'created'))); ?></strong></td>
                    <td><strong><?php echo esc_html(array_sum(array_column($statistics, 'updated'))); ?></strong></td>
                    <td><strong><?php echo esc_html(array_sum(array_column($statistics, 'errors'))); ?></strong></td>
                    <td><strong><?php
                        $total = array_sum(array_column($statistics, 'created')) +
                                 array_sum(array_column($statistics, 'updated')) +
                                 array_sum(array_column($statistics, 'errors'));
                        echo esc_html($total);
                    ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <p class="wpezimport-stats-summary">
            <?php printf(
                /* translators: %d: number of completed import runs. */
                esc_html__('This import has been run %d time(s).', 'ezimport'),
                absint(count($statistics))
            ); ?>
        </p>
    <?php endif; ?>

    <p class="wpezimport-actions-row" style="margin-top: 20px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-confirm&import_id=' . $import_id)); ?>" class="button button-primary"><?php esc_html_e('Run Import Again', 'ezimport'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new&step=2&edit_import=' . $import_id)); ?>" class="button"><?php esc_html_e('Edit Import', 'ezimport'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php esc_html_e('Back to Manage Imports', 'ezimport'); ?></a>
    </p>
</div>

