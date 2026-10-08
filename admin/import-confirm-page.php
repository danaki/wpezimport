<?php
/**
 * Import Confirmation Page
 *
 * Displays import details and allows user to confirm before running the import.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get import ID from URL parameter
$import_id = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;

// Retrieve import details
global $wpdb;
$table_name = $wpdb->prefix . 'wpezimport_saved_imports';
$import = null;

if ($import_id > 0) {
    $import = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $import_id
    ));
}

// Handle the actual import process if confirmed
if (isset($_POST['confirm_import']) && isset($_POST['import_id'])) {
    // Verify nonce
    if (!isset($_POST['wpezimport_confirm_nonce']) || !wp_verify_nonce($_POST['wpezimport_confirm_nonce'], 'wpezimport_confirm_import')) {
        wp_die(__('Security check failed.', 'wpezimport'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action.', 'wpezimport'));
    }

    $run_import_id = intval($_POST['import_id']);

    if ($run_import_id > 0) {
        $run_import = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $run_import_id
        ));

        if ($run_import) {
            if ($run_import->type === 'url') {
                // URL imports: just redirect — the process page triggers via AJAX
                wp_redirect(admin_url('admin.php?page=wpezimport-process&import_id=' . $run_import_id));
                exit;

            } else if ($run_import->type === 'file') {
                // File imports: upload the file, store path, then redirect
                if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                    wp_die(__('Please upload a file to proceed with this import.', 'wpezimport'));
                }

                $file = $_FILES['import_file'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                $options = !empty($run_import->options) ? maybe_unserialize($run_import->options) : array();
                $expected_xml = isset($options['is_xml']) ? (bool) $options['is_xml'] : false;
                $is_xml_file = ($file_ext === 'xml');

                if ($expected_xml && !$is_xml_file) {
                    wp_die(__('This import was configured for XML files. Please upload an XML file.', 'wpezimport'));
                }
                if (!$expected_xml && $is_xml_file) {
                    wp_die(__('This import was configured for CSV/XLS files. Please upload a CSV, XLS, or XLSX file.', 'wpezimport'));
                }

                $allowed_extensions = $expected_xml ? array('xml') : array('csv', 'xls', 'xlsx');
                if (!in_array($file_ext, $allowed_extensions)) {
                    wp_die(sprintf(__('Invalid file type. Allowed: %s', 'wpezimport'), implode(', ', $allowed_extensions)));
                }

                // Move uploaded file
                $upload_dir = wp_upload_dir();
                $wpezimport_dir = $upload_dir['basedir'] . '/wpezimport';
                if (!file_exists($wpezimport_dir)) {
                    wp_mkdir_p($wpezimport_dir);
                }

                $upload_file = $wpezimport_dir . '/' . sanitize_file_name($file['name']);
                if (!move_uploaded_file($file['tmp_name'], $upload_file)) {
                    wp_die(__('Failed to upload file.', 'wpezimport'));
                }

                // Store file path in the saved import record so the batch processor can find it
                $wpdb->update(
                    $table_name,
                    array('file_path' => $upload_file),
                    array('id' => $run_import_id),
                    array('%s'),
                    array('%d')
                );

                wp_redirect(admin_url('admin.php?page=wpezimport-process&import_id=' . $run_import_id));
                exit;
            }
        }
    }
}

// Display the confirmation page UI
?>
<div class="wrap wpezimport-admin-wrap">
    <h1 class="wp-heading-inline"><?php _e('Confirm Import', 'wpezimport'); ?></h1>

    <?php if (!$import): ?>
        <div class="notice notice-error">
            <p><?php _e('Import not found.', 'wpezimport'); ?></p>
        </div>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php _e('Back to Manage Imports', 'wpezimport'); ?></a>
        </p>
    <?php else: ?>
        <div class="wpezimport-confirm-box">
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('wpezimport_confirm_import', 'wpezimport_confirm_nonce'); ?>
                <input type="hidden" name="import_id" value="<?php echo esc_attr($import_id); ?>">

            <h2><?php _e('Import Details', 'wpezimport'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Import Name', 'wpezimport'); ?></th>
                    <td><?php echo esc_html($import->name); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Import Type', 'wpezimport'); ?></th>
                    <td><?php echo esc_html(ucfirst($import->type)); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Source', 'wpezimport'); ?></th>
                    <td>
                        <?php if ($import->type === 'url' && !empty($import->url)): ?>
                            <a href="<?php echo esc_url($import->url); ?>" target="_blank"><?php echo esc_html($import->url); ?></a>
                        <?php else:
                            $import_options = maybe_unserialize($import->options);
                            $expected_xml = isset($import_options['is_xml']) ? $import_options['is_xml'] : false;
                            $expected_format = $expected_xml ? __('XML', 'wpezimport') : __('CSV/XLS/XLSX', 'wpezimport');
                            $accepted_extensions = $expected_xml ? '.xml' : '.csv,.xls,.xlsx';
                            $forbidden_format = $expected_xml ? __('CSV/Excel', 'wpezimport') : __('XML', 'wpezimport');
                        ?>
                            <input type="file" name="import_file" id="import_file" accept="<?php echo esc_attr($accepted_extensions); ?>" required>
                            <input type="hidden" name="expected_xml" value="<?php echo $expected_xml ? '1' : '0'; ?>">
                            <p class="description">
                                <?php printf(__('Upload a %s file.', 'wpezimport'), '<strong>' . $expected_format . '</strong>'); ?>
                                <em><?php printf(__('%s files are not compatible.', 'wpezimport'), $forbidden_format); ?></em>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <!--tr>
                    <th scope="row"><?php _e('Post Type', 'wpezimport'); ?></th>
                    <td>
                        <?php
                        // Handle possible undefined post_type property
                        if (isset($import->post_type) && !empty($import->post_type)) {
                            echo esc_html($import->post_type);
                        } else {
                            // Try to get from options
                            $options = maybe_unserialize($import->options);
                            if (isset($options['default_post_type'])) {
                                echo esc_html($options['default_post_type']);
                            } else {
                                echo esc_html('post'); // Default fallback
                            }
                        }
                        ?>
                    </td>
                </tr-->
            </table>

                <div class="form-field">
                    <h3><?php _e('Unique Identifier Status', 'wpezimport'); ?></h3>

                    <?php
                    // Show information about unique identifier
                    $options = maybe_unserialize($import->options);
                    $unique_identifier_source = isset($options['unique_identifier_source']) ? $options['unique_identifier_source'] : '';

                    if (empty($unique_identifier_source)) :
                    ?>
                        <div class="notice notice-warning inline">
                            <p>
                                <strong><?php _e('Warning:', 'wpezimport'); ?></strong>
                                <?php _e('No unique identifier field is defined for this import. All items will be created as new records.', 'wpezimport'); ?>
                            </p>
                            <p>
                                <?php _e('To enable updating of existing records, go back to the Edit Import page and select a unique identifier field.', 'wpezimport'); ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new&step=2&import_id=' . esc_attr($import_id))); ?>" class="button">
                                    <?php _e('Edit Import Settings', 'wpezimport'); ?>
                                </a>
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-info inline">
                            <p>
                                <strong><?php _e('Unique Identifier Field:', 'wpezimport'); ?></strong>
                                <code><?php echo esc_html($unique_identifier_source); ?></code>
                            </p>
                            <p>
                                <?php _e('This field will be used to detect existing records for updating instead of creating duplicates.', 'wpezimport'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="submit">
                    <input type="submit" name="confirm_import" value="<?php _e('Run Import Now', 'wpezimport'); ?>" class="button button-primary">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php _e('Cancel', 'wpezimport'); ?></a>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

