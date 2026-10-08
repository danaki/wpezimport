<?php
// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Check if we're running a saved import
$run_saved_import = isset($_GET['run_saved_import']) ? intval($_GET['run_saved_import']) : 0;
$saved_import = null;

if ($run_saved_import > 0) {
    // Get the saved import configuration
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    $saved_import = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $run_saved_import
    ));

    if ($saved_import) {
        // For URL imports, redirect straight to mapping page (edit_import will create a draft there)
        if ($saved_import->type === 'url' && !empty($saved_import->url)) {
            wp_redirect(admin_url('admin.php?page=wpezimport-new&step=2&edit_import=' . $run_saved_import));
            exit;
        }
        // For file imports, the user needs to upload a file first.
        // We'll pass the saved_import_id so JS can include it in the AJAX upload call.
    }
}
?>
<div class="wrap wpezimport-admin-container">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="wpezimport-intro">
        <p><?php _e('Welcome to wpezimport! This tool allows you to import posts from Excel files (CSV, XLS, XLSX) or XML files into your WordPress site.', 'wpezimport'); ?></p>
    </div>

    <!-- File Upload Form -->
    <div class="wpezimport-card">
        <h2><?php _e('Upload File', 'wpezimport'); ?></h2>
        <p><?php _e('Select an Excel or XML file from your computer to begin the import process.', 'wpezimport'); ?></p>

        <form method="post" enctype="multipart/form-data" id="wpezimport-upload-form">
            <?php wp_nonce_field('wpezimport_file_upload', 'wpezimport_nonce'); ?>
            <?php if ($saved_import): ?>
            <input type="hidden" name="saved_import_id" value="<?php echo esc_attr($saved_import->id); ?>">
            <?php endif; ?>

            <div class="wpezimport-form-group">
                <div class="wpezimport-file-upload">
                    <div class="wpezimport-file-select">
                        <div class="wpezimport-file-select-button" id="wpezimport-file-select-button"><?php _e('Choose File', 'wpezimport'); ?></div>
                        <div class="wpezimport-file-select-name" id="wpezimport-file-select-name"><?php _e('No file chosen', 'wpezimport'); ?></div>
                        <input type="file" name="wpezimport_file" id="wpezimport-file-input" accept=".csv,.xls,.xlsx,.xml">
                    </div>
                </div>
            </div>

            <p class="wpezimport-supported-formats">
                <?php _e('Supported formats: CSV, XLS, XLSX, XML', 'wpezimport'); ?>
            </p>

            <div class="wpezimport-form-footer">
                <button type="submit" name="wpezimport_upload_submit" class="button button-primary" id="wpezimport-upload-button">
                    <?php _e('Upload & Continue', 'wpezimport'); ?>
                </button>
                <div class="wpezimport-loader" id="wpezimport-loader">
                    <span class="spinner"></span>
                    <?php _e('Uploading file...', 'wpezimport'); ?>
                </div>
            </div>
        </form>
    </div>

    <!-- URL Import Form -->
    <div class="wpezimport-card">
        <h2><?php _e('Import from URL', 'wpezimport'); ?></h2>
        <p><?php _e('Enter a URL to an Excel or XML file to import.', 'wpezimport'); ?></p>

        <form method="post" id="wpezimport-url-form">
            <?php wp_nonce_field('wpezimport_url_import', 'wpezimport_url_nonce'); ?>

            <div class="wpezimport-form-group">
                <label for="wpezimport-file-url"><?php _e('File URL', 'wpezimport'); ?></label>
                <input type="url" name="wpezimport_file_url" id="wpezimport-file-url" class="regular-text" placeholder="https://example.com/file.xlsx" required>
                <p class="description"><?php _e('Enter the full URL to an Excel or XML file (CSV, XLS, XLSX, XML).', 'wpezimport'); ?></p>
            </div>

            <div class="wpezimport-form-footer">
                <button type="submit" name="wpezimport_url_submit" class="button button-primary" id="wpezimport-url-button">
                    <?php _e('Import & Continue', 'wpezimport'); ?>
                </button>
                <div class="wpezimport-loader" id="wpezimport-url-loader">
                    <span class="spinner"></span>
                    <?php _e('Downloading file...', 'wpezimport'); ?>
                </div>
            </div>
        </form>
    </div>

    <div class="wpezimport-card wpezimport-preview-card" id="wpezimport-preview-container" style="display: none;">
        <h2><?php _e('File Preview', 'wpezimport'); ?></h2>
        <div id="wpezimport-preview-content"></div>
    </div>
</div>
