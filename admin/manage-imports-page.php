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
    wp_die(__('You do not have permission to access this page.', 'wpezimport'));
}

// Get all saved imports
global $wpdb;
$table_name = $wpdb->prefix . 'wpezimport_saved_imports';
$imports = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

// Display action result notices
if (isset($_GET['deleted'])) {
    if ($_GET['deleted'] === '1') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Import successfully deleted.', 'wpezimport') . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to delete import.', 'wpezimport') . '</p></div>';
    }
}
?>

<div class="wrap wpezimport-admin-wrap">
    <h1 class="wp-heading-inline"><?php _e('Manage Imports', 'wpezimport'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new')); ?>" class="page-title-action"><?php _e('Add New', 'wpezimport'); ?></a>
    <hr class="wp-header-end">

    <?php if (empty($imports)) : ?>
        <div class="wpezimport-notice-box">
            <p><?php _e('No saved imports found. Create a new import to get started.', 'wpezimport'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new')); ?>" class="button button-primary"><?php _e('Create New Import', 'wpezimport'); ?></a>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php _e('Name', 'wpezimport'); ?></th>
                    <th scope="col"><?php _e('Type', 'wpezimport'); ?></th>
                    <th scope="col"><?php _e('URL of Import', 'wpezimport'); ?></th>
                    <th scope="col"><?php _e('Created', 'wpezimport'); ?></th>
                    <th scope="col"><?php _e('Last Run', 'wpezimport'); ?></th>
                    <th scope="col"><?php _e('Actions', 'wpezimport'); ?></th>
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
                                _e('N/A', 'wpezimport');
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->created_at))); ?></td>
                        <td>
                            <?php
                            if ($import->last_run) {
                                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($import->last_run)));
                            } else {
                                _e('Never', 'wpezimport');
                            }
                            ?>
                        </td>
                        <td class="wpezimport-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-confirm&import_id=' . $import->id)); ?>" class="button"><?php _e('Run', 'wpezimport'); ?></a>
                            <?php if ($import->last_run): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-statistics&import_id=' . $import->id)); ?>" class="button button-secondary"><?php _e('Statistics', 'wpezimport'); ?></a>
                            <?php endif; ?>
                            <?php if ($import->type === 'url'): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-schedule&import_id=' . $import->id)); ?>" class="button button-secondary"><?php _e('Schedule', 'wpezimport'); ?></a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new&step=2&edit_import=' . $import->id)); ?>" class="button"><?php _e('Edit', 'wpezimport'); ?></a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wpezimport-manage&action=delete&import_id=' . $import->id), 'wpezimport_delete_import_' . $import->id)); ?>" class="button delete" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this import?', 'wpezimport')); ?>');"><?php _e('Delete', 'wpezimport'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

