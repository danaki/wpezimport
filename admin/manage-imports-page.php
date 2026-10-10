<?php
/**
 * Manage Imports Page
 *
 * Displays a list of saved imports and allows users to run, edit, or delete them.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Delete/run actions are handled on admin_init by wpezimport_handle_manage_actions()
// (see wpezimport.php) so that redirects happen before any output.

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have permission to access this page.', 'ezimport'));
}

// Get all saved imports
global $wpdb;
$table_name = $wpdb->prefix . 'wpezimport_saved_imports';
$imports = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

// Display action result notices
if (isset($_GET['deleted'])) {
    if ($_GET['deleted'] === '1') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Import successfully deleted.', 'ezimport') . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to delete import.', 'ezimport') . '</p></div>';
    }
}
?>

<div class="wrap wpezimport-admin-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Manage Imports', 'ezimport'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new')); ?>" class="page-title-action"><?php esc_html_e('Add New', 'ezimport'); ?></a>
    <hr class="wp-header-end">

    <?php if (empty($imports)) : ?>
        <div class="wpezimport-notice-box">
            <p><?php esc_html_e('No saved imports found. Create a new import to get started.', 'ezimport'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new')); ?>" class="button button-primary"><?php esc_html_e('Create New Import', 'ezimport'); ?></a>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Name', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Type', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('URL of Import', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Created', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Last Run', 'ezimport'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'ezimport'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($imports as $import) : ?>
                    <tr>
                        <td><?php echo esc_html($import->name); ?></td>
                        <td><?php echo esc_html(ucfirst($import->type)); ?></td>
                        <td>
                            <?php
                            if ($import->type === 'url' && !empty($import->url)) {
                                echo '<a href="' . esc_url($import->url) . '" target="_blank">' . esc_html($import->url) . '</a>';
                            } else {
                                esc_html_e('N/A', 'ezimport');
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->created_at))); ?></td>
                        <td>
                            <?php
                            if ($import->last_run) {
                                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->last_run)));
                            } else {
                                esc_html_e('Never', 'ezimport');
                            }
                            ?>
                        </td>
                        <td class="wpezimport-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-confirm&import_id=' . $import->id)); ?>" class="button"><?php esc_html_e('Run', 'ezimport'); ?></a>
                            <?php if ($import->last_run): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-statistics&import_id=' . $import->id)); ?>" class="button button-secondary"><?php esc_html_e('Statistics', 'ezimport'); ?></a>
                            <?php endif; ?>
                            <?php if ($import->type === 'url'): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-schedule&import_id=' . $import->id)); ?>" class="button button-secondary"><?php esc_html_e('Schedule', 'ezimport'); ?></a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new&step=2&edit_import=' . $import->id)); ?>" class="button"><?php esc_html_e('Edit', 'ezimport'); ?></a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wpezimport-manage&action=delete&import_id=' . $import->id), 'wpezimport_delete_import_' . $import->id)); ?>" class="button delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this import?', 'ezimport')); ?>');"><?php esc_html_e('Delete', 'ezimport'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

