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
        wp_die(esc_html__('Security check failed.', 'ezimport'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'ezimport'));
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
                    wp_die(esc_html__('Please upload a file to proceed with this import.', 'ezimport'));
                }

                $file = $_FILES['import_file'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                $options = !empty($run_import->options) ? maybe_unserialize($run_import->options) : array();
                $expected_xml = isset($options['is_xml']) ? (bool) $options['is_xml'] : false;
                $is_xml_file = ($file_ext === 'xml');

                if ($expected_xml && !$is_xml_file) {
                    wp_die(esc_html__('This import was configured for XML files. Please upload an XML file.', 'ezimport'));
                }
                if (!$expected_xml && $is_xml_file) {
                    wp_die(esc_html__('This import was configured for CSV/XLS files. Please upload a CSV, XLS, or XLSX file.', 'ezimport'));
                }

                $allowed_extensions = $expected_xml ? array('xml') : array('csv', 'xls', 'xlsx');
                if (!in_array($file_ext, $allowed_extensions)) {
                    /* translators: %s: comma-separated list of allowed file extensions. */
                    wp_die(sprintf(esc_html__('Invalid file type. Allowed: %s', 'ezimport'), esc_html(implode(', ', $allowed_extensions))));
                }

                $handled = wpezimport_store_uploaded_file($file, $expected_xml);
                if (isset($handled['error'])) {
                    wp_die(esc_html($handled['error']));
                }
                $upload_file = $handled['file'];

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
    <h1 class="wp-heading-inline"><?php esc_html_e('Confirm Import', 'ezimport'); ?></h1>

    <?php if (!$import): ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Import not found.', 'ezimport'); ?></p>
        </div>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php esc_html_e('Back to Manage Imports', 'ezimport'); ?></a>
        </p>
    <?php else: ?>
        <div class="wpezimport-confirm-box">
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field('wpezimport_confirm_import', 'wpezimport_confirm_nonce'); ?>
                <input type="hidden" name="import_id" value="<?php echo esc_attr($import_id); ?>">

            <h2><?php esc_html_e('Import Details', 'ezimport'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Import Name', 'ezimport'); ?></th>
                    <td><?php echo esc_html($import->name); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Import Type', 'ezimport'); ?></th>
                    <td><?php echo esc_html(ucfirst($import->type)); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Source', 'ezimport'); ?></th>
                    <td>
                        <?php if ($import->type === 'url' && !empty($import->url)): ?>
                            <a href="<?php echo esc_url($import->url); ?>" target="_blank"><?php echo esc_html($import->url); ?></a>
                        <?php else:
                            $import_options = maybe_unserialize($import->options);
                            $expected_xml = isset($import_options['is_xml']) ? $import_options['is_xml'] : false;
                            $expected_format = $expected_xml ? __('XML', 'ezimport') : __('CSV/XLS/XLSX', 'ezimport');
                            $accepted_extensions = $expected_xml ? '.xml' : '.csv,.xls,.xlsx';
                            $forbidden_format = $expected_xml ? __('CSV/Excel', 'ezimport') : __('XML', 'ezimport');
                        ?>
                            <input type="file" name="import_file" id="import_file" accept="<?php echo esc_attr($accepted_extensions); ?>" required>
                            <input type="hidden" name="expected_xml" value="<?php echo $expected_xml ? '1' : '0'; ?>">
                            <p class="description">
                                <?php /* translators: %s: required file format. */ ?>
                                <?php printf(esc_html__('Upload a %s file.', 'ezimport'), esc_html($expected_format)); ?>
                                <em><?php /* translators: %s: incompatible file format. */ printf(esc_html__('%s files are not compatible.', 'ezimport'), esc_html($forbidden_format)); ?></em>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <!--tr>
                    <th scope="row"><?php esc_html_e('Post Type', 'ezimport'); ?></th>
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
                    <h3><?php esc_html_e('Unique Identifier Status', 'ezimport'); ?></h3>

                    <?php
                    // Show information about unique identifier
                    $options = maybe_unserialize($import->options);
                    $unique_identifier_source = isset($options['unique_identifier_source']) ? $options['unique_identifier_source'] : '';

                    if (empty($unique_identifier_source)) :
                    ?>
                        <div class="notice notice-warning inline">
                            <p>
                                <strong><?php esc_html_e('Warning:', 'ezimport'); ?></strong>
                                <?php esc_html_e('No unique identifier field is defined for this import. All items will be created as new records.', 'ezimport'); ?>
                            </p>
                            <p>
                                <?php esc_html_e('To enable updating of existing records, go back to the Edit Import page and select a unique identifier field.', 'ezimport'); ?>
                            </p>
                            <p>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-new&step=2&import_id=' . esc_attr($import_id))); ?>" class="button">
                                    <?php esc_html_e('Edit Import Settings', 'ezimport'); ?>
                                </a>
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-info inline">
                            <p>
                                <strong><?php esc_html_e('Unique Identifier Field:', 'ezimport'); ?></strong>
                                <code><?php echo esc_html($unique_identifier_source); ?></code>
                            </p>
                            <p>
                                <?php esc_html_e('This field will be used to detect existing records for updating instead of creating duplicates.', 'ezimport'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="submit">
                    <input type="submit" name="confirm_import" value="<?php esc_html_e('Run Import Now', 'ezimport'); ?>" class="button button-primary">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wpezimport-manage')); ?>" class="button"><?php esc_html_e('Cancel', 'ezimport'); ?></a>
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

