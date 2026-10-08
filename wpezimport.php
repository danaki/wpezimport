<?php
/**
 * Plugin Name: wpezimport
 * Plugin URI: https://wpezimport.com
 * Description: Import posts from Excel files (CSV, XLS, XLSX) or XML files into WordPress. Supports both file upload and URL import.
 * Version: 1.0.0
 * Author: Yuri Pimenov
 * Author URI: https://example.com
 * Text Domain: wpezimport
 * License: GPL-2.0+
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

require __DIR__ . '/vendor/autoload.php';

// Define constants
define('WPEZIMPORT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WPEZIMPORT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPEZIMPORT_PLUGIN_VERSION', '1.0.0');

/**
 * Check if WooCommerce is active
 */
function wpezimport_is_woocommerce_active() {
    return class_exists('WooCommerce');
}

/**
 * Display admin notice if WooCommerce is not active
 */
function wpezimport_woocommerce_dependency_notice() {
    if (!wpezimport_is_woocommerce_active()) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong><?php _e('wpezimport', 'wpezimport'); ?>:</strong>
                <?php _e('This plugin requires WooCommerce to be installed and activated. Please install and activate WooCommerce to use wpezimport.', 'wpezimport'); ?>
            </p>
            <?php if (current_user_can('install_plugins')): ?>
                <p>
                    <a href="<?php echo esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')); ?>" class="button button-primary">
                        <?php _e('Install WooCommerce', 'wpezimport'); ?>
                    </a>
                    <?php if (file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')): ?>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('plugins.php?action=activate&plugin=woocommerce/woocommerce.php'), 'activate-plugin_woocommerce/woocommerce.php')); ?>" class="button">
                            <?php _e('Activate WooCommerce', 'wpezimport'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
add_action('admin_notices', 'wpezimport_woocommerce_dependency_notice');

/**
 * Add menu item to the WordPress admin dashboard
 */
function wpezimport_add_admin_menu() {
    // Don't add menu if WooCommerce is not active
    if (!wpezimport_is_woocommerce_active()) {
        return;
    }

    // Add main menu item with Manage Imports as default
    add_menu_page(
        __('wpezimport', 'wpezimport'),
        __('EzImport', 'wpezimport'),
        'manage_options',
        'wpezimport-manage',
        'wpezimport_display_manage_imports_page', // Default to Manage Imports
        'dashicons-upload',
        25
    );

    // Add submenu items
    add_submenu_page(
        'wpezimport-manage',                 // Parent slug
        __('Manage Imports', 'wpezimport'), // Page title
        __('Manage Imports', 'wpezimport'), // Menu title
        'manage_options',                    // Capability
        'wpezimport-manage',                 // Menu slug (same as parent for first item)
        'wpezimport_display_manage_imports_page'    // Function
    );

    add_submenu_page(
        'wpezimport-manage',                 // Parent slug
        __('New Import', 'wpezimport'),     // Page title
        __('New Import', 'wpezimport'),     // Menu title
        'manage_options',                    // Capability
        'wpezimport-new',                    // Menu slug (unique for new page)
        'wpezimport_display_admin_page'             // Function
    );

    // Hide these pages from the menu but register them for access.
    // We capture the hook suffix and set the global $title on the load-{hook}
    // action so that admin-header.php receives a string (not null) for
    // strip_tags(), avoiding a PHP 8.1+ deprecation notice.
    $hidden_pages = array(
        'wpezimport-confirm'    => array(__('Confirm Import', 'wpezimport'),    'wpezimport_display_confirm_page'),
        'wpezimport-process'    => array(__('Process Import', 'wpezimport'),    'wpezimport_display_process_page'),
        'wpezimport-statistics' => array(__('Import Statistics', 'wpezimport'), 'wpezimport_display_statistics_page'),
        'wpezimport-schedule'   => array(__('Scheduling Options', 'wpezimport'), 'wpezimport_display_schedule_page'),
    );

    // Hidden pages use null parent so they don't appear in menu but are still accessible
    foreach ($hidden_pages as $slug => $page) {
        $hook = add_submenu_page('', $page[0], $page[0], 'manage_options', $slug, $page[1]);
        if ($hook) {
            $page_title = $page[0];
            add_action("load-$hook", function () use ($page_title) {
                global $title;
                $title = $page_title;
            });
        }
    }
}
add_action('admin_menu', 'wpezimport_add_admin_menu');

/**
 * Enqueue admin scripts and styles
 */
function wpezimport_enqueue_admin_scripts($hook) {
    // Only load on plugin pages
    if (strpos((string)$hook, 'wpezimport') === false) { // Cast to string inline to avoid null issues
        return;
    }

    // Enqueue styles
    wp_enqueue_style('wpezimport-admin-style', WPEZIMPORT_PLUGIN_URL . 'assets/css/admin-style.css', array(), WPEZIMPORT_PLUGIN_VERSION);

    // Enqueue scripts
    wp_enqueue_script('wpezimport-admin-script', WPEZIMPORT_PLUGIN_URL . 'assets/js/admin-script.js', array('jquery'), WPEZIMPORT_PLUGIN_VERSION, true);

    // Prepare data for localization
    $post_types_objs = get_post_types(array('public' => true), 'objects');
    $post_type_options = array();
    foreach ($post_types_objs as $post_type) {
        // Changed to associative array for JS: { slug: label }
        $post_type_options[$post_type->name] = $post_type->label;
    }

    // Localize script with AJAX URL and nonces
    wp_localize_script('wpezimport-admin-script', 'wpezimport_localized_data', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wpezimport_ajax_nonce'), // General AJAX nonce
        'upload_nonce' => wp_create_nonce('wpezimport_file_upload'), // For file uploads
        'url_nonce' => wp_create_nonce('wpezimport_url_import'),     // For URL imports
        'finalize_nonce' => wp_create_nonce('wpezimport_finalize_step1_selection_nonce'), // For finalizing XML item selection
        'mapping_nonce' => wp_create_nonce('wpezimport_save_mapping'), // For mapping page operations like sample navigation (same action as the form nonce)
        'save_mapping_nonce' => wp_create_nonce('wpezimport_save_mapping'), // For saving the mapping
        'run_import_nonce' => wp_create_nonce('wpezimport_import'), // For running the import (verified in wpezimport_handle_import)
        'batch_nonce' => wp_create_nonce('wpezimport_batch_nonce'), // For batch import processing
        'plugin_url' => WPEZIMPORT_PLUGIN_URL,
        'post_types' => $post_type_options, // Format: { slug: label, ... }
        'strings' => [ // Renamed from 'text' to 'strings'
            'confirm_delete_mapping' => __('Are you sure you want to delete this mapping configuration?', 'wpezimport'),
            'config_saved' => __('Mapping configuration saved successfully!', 'wpezimport'), // Renamed from mapping_saved_success
            'error_saving_config' => __('Error saving mapping configuration.', 'wpezimport'), // Renamed from mapping_saved_error
            'field_required' => __('This field is required.', 'wpezimport'),
            'unique_id_required' => __('A unique ID field must be selected for updates.', 'wpezimport'),
            'confirm_leave_page' => __('You have unsaved changes. Are you sure you want to leave this page?', 'wpezimport'),
            'run_import_confirm' => __('Are you sure you want to run this import? This action cannot be undone.', 'wpezimport'),
            'running_import' => __('Running import, please wait...', 'wpezimport'),
            'import_complete' => __('Import complete!', 'wpezimport'),
            'import_error' => __('An error occurred during the import.', 'wpezimport'),
            'view_log_details' => __('View log details', 'wpezimport'),
            'sample_navigation_error' => __('Error navigating XML samples.', 'wpezimport'),
            // New strings for XML Selection UI
            'select_post_type' => __('Select Post Type:', 'wpezimport'),
            'choose_post_type' => __('-- Choose Post Type --', 'wpezimport'),
            'confirm_selection' => __('Confirm Selection and Proceed to Mapping', 'wpezimport'),
            'alert_select_xml_element' => __('Please select an XML element from the tree.', 'wpezimport'),
            'alert_select_post_type' => __('Please select a post type.', 'wpezimport'),
            'processing' => __('Processing...', 'wpezimport'),
            'saving' => __('Saving...', 'wpezimport'), // Added for save button text
            'success_proceed_manually' => __('Selection successful. Proceed to mapping (URL missing).', 'wpezimport'),
            'error_finalizing' => __('Error finalizing selection: ', 'wpezimport'),
            'ajax_error' => __('AJAX error: ', 'wpezimport'), // Renamed from error_ajax
            'xpath_evaluation_error' => __('Error evaluating XPath expression.', 'wpezimport'),
            'evaluate_xpath_button' => __('Evaluate XPath', 'wpezimport'),
            'clear_value_button' => __('Clear Value', 'wpezimport'),
            'refreshing' => __('Refreshing...', 'wpezimport'),
            'refresh_preview' => __('Refresh Preview', 'wpezimport')
        ],
        'default_tab' => isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'file-upload',
        'version' => WPEZIMPORT_PLUGIN_VERSION,
        'debug_mode' => defined('WP_DEBUG') && WP_DEBUG // Pass debug mode status
    ));
}
add_action('admin_enqueue_scripts', 'wpezimport_enqueue_admin_scripts');

/**
 * Enqueue WooCommerce admin stylesheet
 */
add_action('admin_enqueue_scripts', function($hook) {
    // Only load on your plugin's admin page
    if (isset($_GET['page']) && strpos($_GET['page'], 'wpezimport') === 0) {
        // Check if WooCommerce is active and the stylesheet exists
        if (class_exists('WooCommerce')) {
            // Path to the admin CSS file
            $wc_admin_css = WC()->plugin_url() . '/assets/css/admin.css';
            wp_enqueue_style('woocommerce-admin', $wc_admin_css, array(), WC_VERSION);
        }
    }
});

/**
 * Display the admin page based on the current step
 */
function wpezimport_display_admin_page() {
    $step = isset($_GET['step']) ? intval($_GET['step']) : 1;

    switch ($step) {
        case 2:
            // Field mapping step
            require_once WPEZIMPORT_PLUGIN_PATH . 'admin/mapping-page.php';
            break;
        case 3:
            // Import process step
            require_once WPEZIMPORT_PLUGIN_PATH . 'admin/import-page.php';
            break;
        default:
            // File upload step (default)
            require_once WPEZIMPORT_PLUGIN_PATH . 'admin/admin-page.php';
            break;
    }
}

/**
 * Display the manage imports page
 */
function wpezimport_display_manage_imports_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/manage-imports-page.php';
}

/**
 * Handle delete/run actions on the Manage Imports page.
 * Runs on admin_init so redirects happen before any output.
 */
function wpezimport_handle_manage_actions() {
    if (!isset($_GET['page'], $_GET['action'], $_GET['import_id']) || $_GET['page'] !== 'wpezimport-manage') {
        return;
    }

    $action    = sanitize_key($_GET['action']);
    $import_id = intval($_GET['import_id']);
    if ($import_id <= 0 || !in_array($action, array('delete', 'run'), true)) {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to perform this action.', 'wpezimport'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    if ($action === 'delete') {
        check_admin_referer('wpezimport_delete_import_' . $import_id);
        $result = $wpdb->delete($table_name, array('id' => $import_id), array('%d'));
        wp_safe_redirect(add_query_arg(array('page' => 'wpezimport-manage', 'deleted' => $result ? '1' : '0'), admin_url('admin.php')));
        exit;
    }

    // run
    $import = $wpdb->get_row($wpdb->prepare("SELECT id, type FROM $table_name WHERE id = %d", $import_id));
    if ($import && $import->type === 'file') {
        wp_safe_redirect(admin_url('admin.php?page=wpezimport-new&run_saved_import=' . $import_id));
    } elseif ($import && $import->type === 'url') {
        wp_safe_redirect(admin_url('admin.php?page=wpezimport-new&step=2&edit_import=' . $import_id));
    } else {
        wp_safe_redirect(admin_url('admin.php?page=wpezimport-manage'));
    }
    exit;
}
add_action('admin_init', 'wpezimport_handle_manage_actions');

/**
 * Display the import confirmation page
 */
function wpezimport_display_confirm_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/import-confirm-page.php';
}

/**
 * Display the import processing page
 */
function wpezimport_display_process_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/import-process-page.php';
}

/**
 * Display the import statistics page
 */
function wpezimport_display_statistics_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/statistics-page.php';
}

/**
 * Display the scheduling options page
 */
function wpezimport_display_schedule_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/schedule-page.php';
}

/**
 * Include processors
 */
// Include process handlers - these don't depend on WordPress admin functions
require_once plugin_dir_path(__FILE__) . 'admin/process-upload.php';
require_once plugin_dir_path(__FILE__) . 'admin/process-mapping.php';
require_once plugin_dir_path(__FILE__) . 'admin/process-import.php';
require_once plugin_dir_path(__FILE__) . 'admin/import-processor.php';

// Include unified batch import processor (used by web UI and cron)
require_once plugin_dir_path(__FILE__) . 'admin/import-batch.php';

// Include cron handler for external trigger/process/cancel endpoints
require_once plugin_dir_path(__FILE__) . 'admin/cron-handler.php';

// Admin pages will be included via admin_menu hook

// Always include the mapping helpers (logic only, not the page template)
require_once plugin_dir_path(__FILE__) . 'admin/mapping-helpers.php';
require_once plugin_dir_path(__FILE__) . 'admin/mapping-ajax.php';
add_action('wp_ajax_wpezimport_navigate_xml_sample', 'wpezimport_navigate_xml_sample');

// Session start removed - using database-backed draft state instead.

/**
 * Activation hook
 */
function wpezimport_create_db_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Create imports table
    $table_name = $wpdb->prefix . 'wpezimport_imports';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        type varchar(50) NOT NULL,
        file varchar(500) DEFAULT '',
        url varchar(500) DEFAULT '',
        options longtext,
        created datetime DEFAULT CURRENT_TIMESTAMP,
        modified datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Create saved imports table
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        type varchar(50) NOT NULL,
        post_type varchar(50) DEFAULT 'product',
        file_path varchar(500) DEFAULT '',
        url varchar(500) DEFAULT '',
        is_xml tinyint(1) DEFAULT 0,
        options longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        last_run datetime DEFAULT NULL,
        import_key varchar(64) DEFAULT '',
        cron_status varchar(20) DEFAULT 'idle',
        cron_phase varchar(20) DEFAULT 'posts',
        cron_last_item int(11) DEFAULT 0,
        cron_total_items int(11) DEFAULT 0,
        cron_etag varchar(255) DEFAULT '',
        cron_last_modified varchar(255) DEFAULT '',
        cron_content_hash varchar(64) DEFAULT '',
        cron_started_at datetime DEFAULT NULL,
        cron_log longtext,
        cron_created int(11) DEFAULT 0,
        cron_updated int(11) DEFAULT 0,
        cron_errors int(11) DEFAULT 0,
        PRIMARY KEY (id),
        KEY type (type),
        KEY post_type (post_type)
    ) $charset_collate;";

    dbDelta($sql);

    // Create downloaded images tracking table
    $table_name = $wpdb->prefix . 'wpezimport_images';
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        attachment_id bigint(20) NOT NULL,
        url varchar(2000) NOT NULL,
        filename varchar(255) NOT NULL,
        created datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY attachment_id (attachment_id),
        KEY url (url(191))
    ) $charset_collate;";

    dbDelta($sql);

    // Create import statistics table
    wpezimport_create_statistics_table();
}
register_activation_hook(__FILE__, 'wpezimport_create_db_tables');

/**
 * Database version — bump when the schema changes so existing installs migrate.
 */
define('WPEZIMPORT_DB_VERSION', '1');

/**
 * Run dbDelta when the stored DB version is older than the code's.
 *
 * register_activation_hook only fires on activate — existing installs that
 * upgrade the plugin in place never get new tables/columns. This check runs
 * on plugins_loaded and upgrades the schema once.
 */
function wpezimport_maybe_upgrade_db() {
    if (get_option('wpezimport_db_version') === WPEZIMPORT_DB_VERSION) {
        return;
    }
    wpezimport_create_db_tables();
    update_option('wpezimport_db_version', WPEZIMPORT_DB_VERSION);
}
add_action('plugins_loaded', 'wpezimport_maybe_upgrade_db');

/**
 * Deactivation hook
 */
function wpezimport_deactivate() {
    // Deactivation code if needed
}
register_deactivation_hook(__FILE__, 'wpezimport_deactivate');

/**
 * Clear WordPress script caches
 * This helps ensure the latest JS and CSS files are loaded
 */
function wpezimport_clear_script_cache() {
    // Force WordPress to regenerate script version numbers
    update_option('wpezimport_script_version', time());
}
register_activation_hook(__FILE__, 'wpezimport_clear_script_cache');

/**
 * AJAX handler for evaluating XPath expressions
 */
function wpezimport_ajax_evaluate_xpath() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpezimport_save_mapping')) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }

    // Get the XPath expression (only trim/unslash: sanitize_text_field would mangle valid XPath)
    $xpath_expression = isset($_POST['xpath_expression']) ? trim(wp_unslash((string) $_POST['xpath_expression'])) : '';

    if (empty($xpath_expression)) {
        wp_send_json_error(['message' => 'No XPath expression provided']);
    }

    // Get the XML sample from draft state
    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    $draft = ($draft_id > 0) ? wpezimport_get_draft($draft_id) : false;
    $ds = $draft ? $draft->state : array();

    $xml_sample = '';
    if (isset($ds['xml_samples']) && !empty($ds['xml_samples'])) {
        $idx = isset($ds['current_sample_index']) ? intval($ds['current_sample_index']) : 0;
        $xml_sample = isset($ds['xml_samples'][$idx]) ? $ds['xml_samples'][$idx] : '';
    }
    $item_xpath = isset($ds['xml_item_path']) ? $ds['xml_item_path'] : '';

    if (empty($xml_sample)) {
        wp_send_json_error(['message' => 'No XML sample available']);
    }

    // Log the XML sample for debugging
    error_log('XML Sample: ' . substr($xml_sample, 0, 200) . '...');
    error_log('XPath Expression: ' . $xpath_expression);

    // Evaluate the XPath expression
    try {
        // Load the XML
        $dom = new DOMDocument();
        $prev_libxml = libxml_use_internal_errors(true);
        $success = $dom->loadXML($xml_sample);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev_libxml);

        if (!$success || !empty($errors)) {
            wp_send_json_error(['message' => 'Error parsing XML sample: ' . (isset($errors[0]) ? $errors[0]->message : 'Unknown error')]);
            return;
        }

        // Create XPath object
        $xpath = new DOMXPath($dom);

        // Extract XPath expressions from curly braces
        $result = $xpath_expression;
        preg_match_all('/{([^}]+)}/', $xpath_expression, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $match) {
                // Execute the XPath query
                $query_result = '';

                // Try with and without the item_xpath prefix
                $xpath_queries = [
                    $match,                    // Try the exact expression
                    ltrim($match, '/'),       // Try without leading slash
                    '//' . ltrim($match, '/') // Try with // prefix for anywhere in document
                ];

                $node_found = false;

                foreach ($xpath_queries as $query) {
                    error_log('Trying XPath query: ' . $query);
                    $nodes = @$xpath->query($query);

                    if ($nodes !== false && $nodes->length > 0) {
                        // Get the value of the first node
                        $node = $nodes->item(0);
                        $query_result = $node->nodeValue;
                        $node_found = true;
                        error_log('Found match with query: ' . $query . ', value: ' . $query_result);
                        break;
                    }
                }

                if (!$node_found) {
                    $query_result = '[No matches]';
                    error_log('No matches found for any XPath query variations');
                }

                // Replace the XPath expression with its value
                $result = str_replace('{' . $match . '}', $query_result, $result);
            }
        }

        wp_send_json_success(['result' => $result]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Error evaluating XPath: ' . $e->getMessage()]);
    }
}
add_action('wp_ajax_wpezimport_evaluate_xpath', 'wpezimport_ajax_evaluate_xpath');