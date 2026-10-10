<?php
/**
 * Handle file uploads and processing
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Recursively builds a tree structure from a SimpleXMLElement for UI selection.
 *
 * @param SimpleXMLElement $xml The XML element to process.
 * @param string $current_xpath The current base XPath.
 * @return array The tree structure.
 */
/**
 * Multibyte-safe truncation with fallback when mbstring is unavailable.
 */
function wpezimport_truncate_text($text, $length) {
    if (function_exists('mb_substr')) {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '...' : $text;
    }
    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

function wpezimport_build_xml_tree_recursive(SimpleXMLElement $xml, $current_xpath = '') {
    $node_name = $xml->getName();
    $node_xpath = $current_xpath . '/' . $node_name;
    if ($current_xpath === '') { // Root element special case
        $node_xpath = '/' . $node_name;
    }

    $tree_node = [
        'name' => $node_name,
        'xpath' => $node_xpath,
        'attributes' => [],
        'children' => [],
        'text_preview' => trim((string)$xml) ? wpezimport_truncate_text(trim((string)$xml), 50) : '',
        'child_count' => count($xml->children()),
        'attribute_count' => count($xml->attributes()),
    ];

    foreach ($xml->attributes() as $attr_name => $attr_value) {
        $tree_node['attributes'][(string)$attr_name] = (string)$attr_value;
    }

    $child_elements = [];
    foreach ($xml->children() as $child) {
        $child_elements[] = wpezimport_build_xml_tree_recursive($child, $node_xpath);
    }
    $tree_node['children'] = $child_elements;

    return $tree_node;
}

/**
 * Loads an XML file and generates a tree structure for element selection.
 *
 * @param string $file_path Path to the XML file.
 * @return array|WP_Error The XML tree structure or WP_Error on failure.
 */
function wpezimport_get_xml_tree_for_selection($file_path) {
    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', __('XML file not found for processing.', 'ezimport'));
    }

    libxml_use_internal_errors(true);
    $xml_content = file_get_contents($file_path);
    if (false === $xml_content) {
        return new WP_Error('file_read_error', __('Could not read XML file content.', 'ezimport'));
    }

    // Attempt to remove UTF-8 BOM if present, as it can cause issues with simplexml_load_string
    if (substr($xml_content, 0, 3) === "\xEF\xBB\xBF") {
        $xml_content = substr($xml_content, 3);
    }

    $xml = simplexml_load_string($xml_content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);

    if (false === $xml) {
        $errors = libxml_get_errors();
        $error_message = __('Failed to parse XML.', 'ezimport');
        if (!empty($errors)) {
            $error_detail = [];
            foreach ($errors as $error) {
                $error_detail[] = trim($error->message) . ' (Line: ' . $error->line . ')';
            }
            $error_message .= ' Details: ' . implode('; ', $error_detail);
        }
        libxml_clear_errors();
        return new WP_Error('xml_parse_error', $error_message);
    }

    return wpezimport_build_xml_tree_recursive($xml);
}

/**
 * Store an uploaded import file using WordPress file validation.
 */
function wpezimport_store_uploaded_file($file, $allow_xml = true) {
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $mimes = array(
        'csv'  => 'text/csv',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
    $xml_mimes_filter = null;
    if ($allow_xml) {
        $mimes['xml'] = 'text/xml';
        $xml_mimes_filter = static function ($allowed_mimes) {
            $allowed_mimes['xml'] = 'text/xml';
            return $allowed_mimes;
        };
        add_filter('upload_mimes', $xml_mimes_filter);
    }

    try {
        return wp_handle_upload($file, array('test_form' => false, 'mimes' => $mimes));
    } finally {
        if ($xml_mimes_filter) {
            remove_filter('upload_mimes', $xml_mimes_filter);
        }
    }
}

/** Handle AJAX file upload. */
function wpezimport_handle_file_upload() {
    // Check nonce
    if (!isset($_POST['wpezimport_nonce']) || !wp_verify_nonce($_POST['wpezimport_nonce'], 'wpezimport_file_upload')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to upload files.'));
    }

    // Check if file was uploaded
    if (!isset($_FILES['wpezimport_file']) || $_FILES['wpezimport_file']['error'] !== UPLOAD_ERR_OK) {
        $error = isset($_FILES['wpezimport_file']) ? wpezimport_get_upload_error_message($_FILES['wpezimport_file']['error']) : 'No file was uploaded.';
        wp_send_json_error(array('message' => $error));
    }

    $file = $_FILES['wpezimport_file'];

    // Check file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, array('csv', 'xls', 'xlsx', 'xml'))) {
        wp_send_json_error(array('message' => 'Invalid file type. Please upload a CSV, XLS, XLSX, or XML file.'));
    }

    $handled = wpezimport_store_uploaded_file($file);
    if (isset($handled['error'])) {
        wp_send_json_error(array('message' => $handled['error']));
    }
    $upload_file = $handled['file'];
    $filename = basename($upload_file);

    // Accept optional draft_id (for re-uploads into an existing draft)
    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    // Accept optional saved_import_id (for re-running a saved import that needs a file)
    $saved_import_id = isset($_POST['saved_import_id']) ? intval($_POST['saved_import_id']) : 0;

    // Process the file based on its type
    if ($file_ext === 'xml') {
        // Generate XML tree data for selection UI
        $xml_tree_data = wpezimport_get_xml_tree_for_selection($upload_file);

        if (is_wp_error($xml_tree_data)) {
            wp_delete_file($upload_file);
            wp_send_json_error(array('message' => $xml_tree_data->get_error_message()));
        }

        // Build draft state
        $state = array(
            'uploaded_file'      => $upload_file,
            'uploaded_file_name' => $file['name'],
            'is_xml'             => true,
            'import_type'        => 'file',
        );

        if ($draft_id > 0) {
            wpezimport_update_draft($draft_id, $state);
        } else {
            $draft_id = wpezimport_create_draft(array(
                'type'  => 'file',
                'file'  => $upload_file,
                'state' => $state,
            ));
        }

        wp_send_json_success(array(
            'xml_tree_data' => $xml_tree_data,
            'draft_id'      => $draft_id,
        ));
        return;

    } else {
        // Process CSV/XLS/XLSX file
        $preview = wpezimport_generate_file_preview($upload_file, $file_ext);

        if (is_wp_error($preview)) {
            wp_delete_file($upload_file);
            wp_send_json_error(array('message' => $preview->get_error_message()));
        }

        // Extract headers from the file
        $file_headers = wpezimport_extract_file_headers($upload_file, $file_ext);

        // Build draft state
        $state = array(
            'uploaded_file'      => $upload_file,
            'uploaded_file_name' => $file['name'],
            'is_xml'             => false,
            'import_type'        => 'file',
            'file_headers'       => $file_headers,
        );

        // CSV-specific options
        if ($file_ext === 'csv') {
            $current_delimiter = isset($_POST['csv_delimiter']) ? sanitize_text_field($_POST['csv_delimiter']) : ',';
            $current_enclosure = isset($_POST['csv_enclosure']) ? html_entity_decode(sanitize_text_field($_POST['csv_enclosure'])) : '"';
            if (strlen($current_enclosure) > 1) {
                $current_enclosure = substr($current_enclosure, 0, 1);
            }
            $state['csv_delimiter'] = $current_delimiter;
            $state['csv_enclosure'] = $current_enclosure;
        }

        // If re-running a saved import, load its configuration into the draft
        $saved_import = null;
        if ($saved_import_id > 0) {
            $saved_import = wpezimport_get_import($saved_import_id);
        }

        if ($saved_import) {
            $import_options = maybe_unserialize($saved_import->options);
            if (isset($import_options['mappings'])) {
                $state['field_mapping'] = $import_options['mappings'];
            }
            $state['import_options']    = $import_options;
            $state['selected_post_type'] = $saved_import->post_type;
            $state['saved_import_id']   = $saved_import_id;

            wpezimport_update_last_run($saved_import_id);
        }

        // Create or update draft
        if ($draft_id > 0) {
            wpezimport_update_draft($draft_id, $state);
        } else {
            $draft_id = wpezimport_create_draft(array(
                'type'  => 'file',
                'file'  => $upload_file,
                'state' => $state,
            ));
        }

        // If saved import with preloaded config, redirect straight to mapping
        if ($saved_import) {
            wp_send_json_success(array(
                'message'  => __('File uploaded successfully. Loading saved import configuration...', 'ezimport'),
                'redirect' => admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id),
                'draft_id' => $draft_id,
            ));
            return;
        }

        // Generate the preview table
        $preview_table_html = wpezimport_generate_preview_html($file_headers, $preview);

        // Assemble the form with the preview table inside
        $html = '<form method="post" action="' . esc_url(admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id)) . '" id="wpezimport-preview-form">';

        // Add CSV options if it's a CSV file
        if ($file_ext === 'csv') {
            $html .= '<div class="wpezimport-csv-options">';
            $html .= '<div class="wpezimport-csv-option">';
            $html .= '<label for="csv_delimiter">' . __('Delimiter', 'ezimport') . '</label>';
            $html .= '<select name="csv_delimiter" id="csv_delimiter">';
            $html .= '<option value="," ' . selected($current_delimiter, ',', false) . '>' . __('Comma (,)', 'ezimport') . '</option>';
            $html .= '<option value=";" ' . selected($current_delimiter, ';', false) . '>' . __('Semicolon (;)', 'ezimport') . '</option>';
            $html .= '<option value="tab" ' . selected($current_delimiter, 'tab', false) . '>' . __('Tab', 'ezimport') . '</option>';
            $html .= '<option value="|" ' . selected($current_delimiter, '|', false) . '>' . __('Pipe (|)', 'ezimport') . '</option>';
            $html .= '</select>';
            $html .= '</div>';
            $html .= '<div class="wpezimport-csv-option">';
            $html .= '<label for="csv_enclosure">' . __('Enclosure', 'ezimport') . '</label>';
            $html .= '<select name="csv_enclosure" id="csv_enclosure">';
            $html .= '<option value="&quot;" ' . selected($current_enclosure, '"', false) . '>' . __('Double Quote (")', 'ezimport') . '</option>';
            $html .= '<option value="&#39;" ' . selected($current_enclosure, "'", false) . '>' . __("Single Quote (')", 'ezimport') . '</option>';
            $html .= '</select>';
            $html .= '</div>';
            $html .= '<button type="button" class="button" id="wpezimport-refresh-preview">' . __('Refresh Preview', 'ezimport') . '</button>';
            $html .= '</div>';
        }

        $html .= $preview_table_html;
        // Post type hardcoded to 'product' (WooCommerce)
        $html .= '<input type="hidden" name="wpezimport_preview_post_type" value="product">';
        $html .= '<div class="wpezimport-preview-actions">';
        $html .= '<button type="submit" class="button button-primary">' . __('Continue to Mapping', 'ezimport') . '</button>';
        $html .= '</div>';
        $html .= '</form>';

        wp_send_json_success(array(
            'message'   => 'File uploaded successfully.',
            'preview'   => $html,
            'file_name' => $file['name'],
            'next_step' => admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id),
            'draft_id'  => $draft_id,
        ));
    }
}
add_action('wp_ajax_wpezimport_upload_file', 'wpezimport_handle_file_upload');

/**
 * Fetch XML from a URL, parse it with the given XPath, and return samples + headers.
 *
 * @param string $url        The URL to fetch XML from.
 * @param string $xpath_path The XPath expression to select items.
 * @return array|WP_Error    Array with 'file_path', 'headers', 'xml_samples' on success, WP_Error on failure.
 */
function wpezimport_fetch_xml_from_url($url, $xpath_path) {
    $response = wp_remote_get($url, array(
        'timeout'   => 60,
        'sslverify' => apply_filters('wpezimport_sslverify', true),
    ));

    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', sprintf(
            /* translators: %s: error returned while downloading the XML file. */
            __('Failed to download XML from URL: %s', 'ezimport'),
            $response->get_error_message()
        ));
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        return new WP_Error('http_error', sprintf(
            /* translators: %d: HTTP response status code. */
            __('URL returned HTTP %d instead of 200.', 'ezimport'),
            $http_code
        ));
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return new WP_Error('empty_body', __('Downloaded file is empty.', 'ezimport'));
    }

    // Save to a temp file
    $upload_dir = wp_upload_dir();
    $wpezimport_dir = $upload_dir['basedir'] . '/wpezimport';
    if (!file_exists($wpezimport_dir)) {
        wp_mkdir_p($wpezimport_dir);
    }
    $filename = 'url_edit_' . md5($url) . '_' . time() . '.xml';
    $file_path = $wpezimport_dir . '/' . $filename;
    if (file_put_contents($file_path, $body) === false) {
        return new WP_Error('save_failed', __('Failed to save downloaded XML file.', 'ezimport'));
    }

    // Free the in-memory body; parse from disk to keep memory usage low on large feeds
    unset($body, $response);

    // Parse XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file_path, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $msg = !empty($errors) ? $errors[0]->message : __('Unknown XML parse error', 'ezimport');
        wp_delete_file($file_path);
        return new WP_Error('xml_parse', sprintf(
            /* translators: %s: XML parser error message. */
            __('Failed to parse XML: %s', 'ezimport'),
            trim($msg)
        ));
    }
    libxml_clear_errors();

    $selected_nodes = $xml->xpath($xpath_path);
    if ($selected_nodes === false || empty($selected_nodes)) {
        wp_delete_file($file_path);
        return new WP_Error('xpath_empty', sprintf(
            /* translators: %s: XPath expression entered for the XML feed. */
            __('XPath "%s" did not match any elements in the fetched XML.', 'ezimport'),
            $xpath_path
        ));
    }

    // Extract headers from the first node
    $headers = array();
    if (isset($selected_nodes[0])) {
        wpezimport_extract_xml_node_structure_recursive($selected_nodes[0], '', $headers);
    }

    // Extract samples (max 5)
    $xml_samples = array();
    $count = 0;
    foreach ($selected_nodes as $node) {
        if ($count >= 5) break;
        $xml_samples[] = $node->asXML();
        $count++;
    }

    return array(
        'file_path'   => $file_path,
        'headers'     => $headers,
        'xml_samples' => $xml_samples,
    );
}

/**
 * AJAX handler: refetch XML from URL for editing an import.
 */
function wpezimport_refetch_xml_from_url_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpezimport_ajax_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'ezimport')));
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ezimport')));
    }

    $url   = isset($_POST['url'])   ? esc_url_raw(wp_unslash($_POST['url']))   : '';
    $xpath = isset($_POST['xpath']) ? sanitize_text_field(wp_unslash($_POST['xpath'])) : '';

    if (empty($url) || empty($xpath)) {
        wp_send_json_error(array('message' => __('URL and XPath are required.', 'ezimport')));
    }

    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;

    $result = wpezimport_fetch_xml_from_url($url, $xpath);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    // Update draft state in DB
    if ($draft_id > 0) {
        wpezimport_update_draft($draft_id, array(
            'file'                 => $result['file_path'],
            'uploaded_file'        => $result['file_path'],
            'is_xml'               => true,
            'xml_item_path'        => $xpath,
            'file_headers'         => $result['headers'],
            'xml_samples'          => $result['xml_samples'],
            'current_sample_index' => 0,
        ));
    }

    // Build the XML structure HTML for the first sample
    $xml_html = '';
    if (!empty($result['xml_samples'])) {
        libxml_use_internal_errors(true);
        $sample = simplexml_load_string($result['xml_samples'][0], 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        libxml_clear_errors();
        if ($sample !== false && function_exists('wpezimport_process_xml_node')) {
            $xml_html = wpezimport_process_xml_node($sample, '', true, 0);
        }
    }

    wp_send_json_success(array(
        'message'       => __('XML structure refreshed successfully.', 'ezimport'),
        'xml_html'      => $xml_html,
        'total_samples' => count($result['xml_samples']),
        'draft_id'      => $draft_id,
    ));
}
add_action('wp_ajax_wpezimport_refetch_xml', 'wpezimport_refetch_xml_from_url_callback');

/**
 * Handle URL import
 */
function wpezimport_handle_url_import() {
    // Check nonce
    if (!isset($_POST['wpezimport_url_nonce']) || !wp_verify_nonce($_POST['wpezimport_url_nonce'], 'wpezimport_url_import')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to import files.'));
    }

    // Check if URL was provided
    if (!isset($_POST['wpezimport_file_url']) || empty($_POST['wpezimport_file_url'])) {
        wp_send_json_error(array('message' => 'No URL provided.'));
    }

    $file_url = esc_url_raw($_POST['wpezimport_file_url']);

    // Validate URL
    if (!filter_var($file_url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array('message' => 'Invalid URL format.'));
    }

    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;

    // Create uploads directory if it doesn't exist
    $upload_dir = wp_upload_dir();
    $wpezimport_upload_dir = $upload_dir['basedir'] . '/wpezimport';

    if (!file_exists($wpezimport_upload_dir)) {
        wp_mkdir_p($wpezimport_upload_dir);
    }

    // Download the file
    $response = wp_remote_get($file_url, array(
        'timeout' => 60,
        'sslverify' => apply_filters('wpezimport_sslverify', true)
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Failed to download file: ' . $response->get_error_message()));
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        wp_send_json_error(array('message' => 'Failed to download file. Server returned: ' . wp_remote_retrieve_response_code($response)));
    }

    $file_content = wp_remote_retrieve_body($response);
    if (empty($file_content)) {
        wp_send_json_error(array('message' => 'Downloaded file is empty.'));
    }

    // Choose the extension before writing the file so no filesystem rename is needed.
    $filename = basename((string) wp_parse_url($file_url, PHP_URL_PATH));
    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($file_ext === '') {
        $is_xml = strpos(ltrim($file_content), '<') === 0;
        $file_ext = $is_xml ? 'xml' : 'csv';
        $filename = 'import_' . gmdate('YmdHis') . '.' . $file_ext;
    }
    if (!in_array($file_ext, array('csv', 'xls', 'xlsx', 'xml'), true)) {
        wp_send_json_error(array('message' => __('Unsupported file type.', 'ezimport')));
    }
    $filename = wp_unique_filename($wpezimport_upload_dir, sanitize_file_name($filename));
    $upload_file = $wpezimport_upload_dir . '/' . $filename;

    // Save the file
    if (file_put_contents($upload_file, $file_content) === false) {
        wp_send_json_error(array('message' => __('Failed to save downloaded file.', 'ezimport')));
    }

    // Process the file based on its type
    if ($file_ext === 'xml') {
        $xml_tree_data = wpezimport_get_xml_tree_for_selection($upload_file);

        if (is_wp_error($xml_tree_data)) {
            wp_delete_file($upload_file);
            wp_send_json_error(array('message' => $xml_tree_data->get_error_message()));
        }

        // Build draft state
        $state = array(
            'uploaded_file'      => $upload_file,
            'uploaded_file_name' => $filename,
            'is_xml'             => true,
            'import_type'        => 'url',
            'import_url'         => $file_url,
        );

        if ($draft_id > 0) {
            wpezimport_update_draft($draft_id, array_merge($state, array('type' => 'url', 'url' => $file_url, 'file' => $upload_file)));
        } else {
            $draft_id = wpezimport_create_draft(array(
                'type'  => 'url',
                'file'  => $upload_file,
                'url'   => $file_url,
                'state' => $state,
            ));
        }

        wp_send_json_success(array(
            'xml_tree_data' => $xml_tree_data,
            'draft_id'      => $draft_id,
        ));
        return;

    } else {
        // Process CSV/XLS/XLSX from URL
        $file_headers = wpezimport_extract_file_headers($upload_file, $file_ext);
        $preview_table_html = wpezimport_generate_preview_html($file_headers, wpezimport_generate_file_preview($upload_file, $file_ext));

        // Build draft state
        $state = array(
            'uploaded_file'      => $upload_file,
            'uploaded_file_name' => $filename,
            'is_xml'             => false,
            'import_type'        => 'url',
            'import_url'         => $file_url,
            'file_headers'       => $file_headers,
        );

        if ($draft_id > 0) {
            wpezimport_update_draft($draft_id, array_merge($state, array('type' => 'url', 'url' => $file_url, 'file' => $upload_file)));
        } else {
            $draft_id = wpezimport_create_draft(array(
                'type'  => 'url',
                'file'  => $upload_file,
                'url'   => $file_url,
                'state' => $state,
            ));
        }

        $html = '<form method="post" action="' . esc_url(admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id)) . '" id="wpezimport-preview-form">';
        $html .= $preview_table_html;
        $html .= '<input type="hidden" name="wpezimport_preview_post_type" value="product">';
        $html .= '<div class="wpezimport-preview-actions">';
        $html .= '<button type="submit" class="button button-primary">' . __('Continue to Mapping', 'ezimport') . '</button>';
        $html .= '</div>';
        $html .= '</form>';

        wp_send_json_success(array(
            'message'   => __('File imported successfully.', 'ezimport'),
            'preview'   => $html,
            'file_name' => $filename,
            'next_step' => admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id),
            'draft_id'  => $draft_id,
        ));
    }
}
add_action('wp_ajax_wpezimport_url_import', 'wpezimport_handle_url_import');

/**
 * AJAX handler to finalize the XML item selection from Step 1.
 * Saves selected XPath, post type, extracts headers and samples to draft.
 */
function wpezimport_finalize_step1_selection_callback() {
    if (!isset($_POST['finalize_nonce']) || !wp_verify_nonce($_POST['finalize_nonce'], 'wpezimport_finalize_step1_selection_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed for finalizing selection.', 'ezimport')));
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ezimport')));
        return;
    }

    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    $selected_xpath = isset($_POST['selected_xpath']) ? sanitize_text_field(wp_unslash($_POST['selected_xpath'])) : null;
    $selected_post_type = isset($_POST['selected_post_type']) ? sanitize_text_field(wp_unslash($_POST['selected_post_type'])) : null;

    if (empty($selected_xpath) || empty($selected_post_type)) {
        wp_send_json_error(array('message' => __('Missing selected XML item path or post type.', 'ezimport')));
        return;
    }

    if ($draft_id <= 0) {
        wp_send_json_error(array('message' => __('Missing draft ID.', 'ezimport')));
        return;
    }

    $draft = wpezimport_get_draft($draft_id);
    if (!$draft) {
        wp_send_json_error(array('message' => __('Draft not found.', 'ezimport')));
        return;
    }

    $uploaded_file_path = isset($draft->state['uploaded_file']) ? $draft->state['uploaded_file'] : '';
    if (empty($uploaded_file_path) || !file_exists($uploaded_file_path)) {
        wp_send_json_error(array('message' => __('Uploaded XML file not found or has been removed.', 'ezimport')));
        return;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($uploaded_file_path, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
    if (false === $xml) {
        wp_send_json_error(array('message' => __('Failed to re-parse XML file for finalization.', 'ezimport')));
        return;
    }

    $selected_nodes = $xml->xpath($selected_xpath);
    if (false === $selected_nodes || empty($selected_nodes)) {
        wp_send_json_error(array('message' => __('The selected XML path did not return any elements. Path: ', 'ezimport') . esc_html($selected_xpath)));
        return;
    }

    // Extract headers from the first selected node
    $headers = [];
    if (isset($selected_nodes[0])) {
        wpezimport_extract_xml_node_structure_recursive($selected_nodes[0], '', $headers);
    }

    // Extract samples (raw XML strings of selected items, max 5)
    $xml_samples = [];
    $sample_count = 0;
    foreach ($selected_nodes as $node) {
        if ($sample_count >= 5) break;
        $xml_samples[] = $node->asXML();
        $sample_count++;
    }

    // Update draft with finalized selection
    wpezimport_update_draft($draft_id, array(
        'xml_item_path'        => $selected_xpath,
        'selected_post_type'   => $selected_post_type,
        'file_headers'         => $headers,
        'xml_samples'          => $xml_samples,
        'current_sample_index' => 0,
    ));

    wp_send_json_success(array(
        'message'      => __('XML item selection finalized. Proceeding to mapping.', 'ezimport'),
        'redirect_url' => admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id),
        'draft_id'     => $draft_id,
    ));
}
add_action('wp_ajax_wpezimport_finalize_step1_selection', 'wpezimport_finalize_step1_selection_callback');

/**
 * AJAX handler to refresh CSV preview with new delimiter/enclosure options
 */
function wpezimport_refresh_csv_preview_callback() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpezimport_file_upload')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'ezimport')));
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ezimport')));
    }

    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    if ($draft_id <= 0) {
        wp_send_json_error(array('message' => __('Missing draft ID.', 'ezimport')));
    }

    $draft = wpezimport_get_draft($draft_id);
    if (!$draft) {
        wp_send_json_error(array('message' => __('Draft not found.', 'ezimport')));
    }

    $upload_file = isset($draft->state['uploaded_file']) ? $draft->state['uploaded_file'] : '';
    if (!$upload_file || !file_exists($upload_file)) {
        wp_send_json_error(array('message' => __('No file found. Please re-upload.', 'ezimport')));
    }

    // Get delimiter and enclosure from POST
    $delimiter = isset($_POST['delimiter']) ? sanitize_text_field($_POST['delimiter']) : ',';
    $enclosure = isset($_POST['enclosure']) ? html_entity_decode(sanitize_text_field($_POST['enclosure'])) : '"';

    // Ensure enclosure is a single character
    if (strlen($enclosure) > 1) {
        $enclosure = substr($enclosure, 0, 1);
    }

    // Get file extension
    $file_ext = strtolower(pathinfo($upload_file, PATHINFO_EXTENSION));

    if ($file_ext !== 'csv') {
        wp_send_json_error(array('message' => __('Not a CSV file.', 'ezimport')));
    }

    // Extract headers and generate preview with new options
    $file_headers = wpezimport_extract_file_headers($upload_file, $file_ext, $delimiter, $enclosure);
    $preview = wpezimport_generate_file_preview($upload_file, $file_ext, $delimiter, $enclosure);

    // Update draft with new CSV options and headers
    wpezimport_update_draft($draft_id, array(
        'csv_delimiter' => $delimiter,
        'csv_enclosure' => $enclosure,
        'file_headers'  => $file_headers,
    ));

    // Generate preview HTML
    $preview_table_html = wpezimport_generate_preview_html($file_headers, $preview);

    // Build the form HTML
    $html = '<form method="post" action="' . esc_url(admin_url('admin.php?page=wpezimport-new&step=2&draft_id=' . $draft_id)) . '" id="wpezimport-preview-form">';

    // Add CSV options
    $html .= '<div class="wpezimport-csv-options">';
    $html .= '<div class="wpezimport-csv-option">';
    $html .= '<label for="csv_delimiter">' . __('Delimiter', 'ezimport') . '</label>';
    $html .= '<select name="csv_delimiter" id="csv_delimiter">';
    $html .= '<option value="," ' . selected($delimiter, ',', false) . '>' . __('Comma (,)', 'ezimport') . '</option>';
    $html .= '<option value=";" ' . selected($delimiter, ';', false) . '>' . __('Semicolon (;)', 'ezimport') . '</option>';
    $html .= '<option value="tab" ' . selected($delimiter, 'tab', false) . '>' . __('Tab', 'ezimport') . '</option>';
    $html .= '<option value="|" ' . selected($delimiter, '|', false) . '>' . __('Pipe (|)', 'ezimport') . '</option>';
    $html .= '</select>';
    $html .= '</div>';
    $html .= '<div class="wpezimport-csv-option">';
    $html .= '<label for="csv_enclosure">' . __('Enclosure', 'ezimport') . '</label>';
    $html .= '<select name="csv_enclosure" id="csv_enclosure">';
    $html .= '<option value="&quot;" ' . selected($enclosure, '"', false) . '>' . __('Double Quote (")', 'ezimport') . '</option>';
    $html .= '<option value="&#39;" ' . selected($enclosure, "'", false) . '>' . __("Single Quote (')", 'ezimport') . '</option>';
    $html .= '</select>';
    $html .= '</div>';
    $html .= '<button type="button" class="button" id="wpezimport-refresh-preview">' . __('Refresh Preview', 'ezimport') . '</button>';
    $html .= '</div>';

    $html .= $preview_table_html;
    $html .= '<input type="hidden" name="wpezimport_preview_post_type" value="product">';
    $html .= '<div class="wpezimport-preview-actions">';
    $html .= '<button type="submit" class="button button-primary">' . __('Continue to Mapping', 'ezimport') . '</button>';
    $html .= '</div>';
    $html .= '</form>';

    wp_send_json_success(array(
        'preview'  => $html,
        'draft_id' => $draft_id,
    ));
}
add_action('wp_ajax_wpezimport_refresh_csv_preview', 'wpezimport_refresh_csv_preview_callback');

/**
 * Recursively extracts the structure (child elements and attributes as relative XPaths)
 * from a SimpleXMLElement to be used as headers/mapping keys.
 *
 * @param SimpleXMLElement $element The XML element to process.
 * @param string $current_relative_path The current relative path from the item root.
 * @param array &$structure Array to populate with relative paths/keys.
 */
function wpezimport_extract_xml_node_structure_recursive(SimpleXMLElement $element, $current_relative_path, array &$structure) {
    // Add attributes of the current element
    foreach ($element->attributes() as $attr_name => $attr_value) {
        $attr_path = ($current_relative_path ? $current_relative_path . '/' : '') . '@' . $attr_name;
        if (!in_array($attr_path, $structure)) {
            $structure[] = $attr_path;
        }
    }

    // If the current element has no children but has text content, represent it
    if (count($element->children()) == 0 && trim((string)$element) !== '') {
        $self_path = $current_relative_path ?: $element->getName(); // Use element name if at root of item
         // If current_relative_path is empty, it means $element is the root of the selected item.
         // We don't want to add the root item itself as a mappable field unless it also has direct text content and no children.
         // Typically, the children of the root item are the mappable fields.
         // However, if the selected item is simple like <tag>value</tag>, then 'tag' itself is the key.
         // Let's use current_relative_path. If it's empty, this is the root selected item. Usually, we map its children/attributes.
         // If the path is not empty OR if it is empty AND the element itself has text and no children, add it.
        if ($current_relative_path !== '' && !in_array($current_relative_path, $structure)) {
             $structure[] = $current_relative_path; // Path to the text content of this element
        }
    }

    // Add child elements
    foreach ($element->children() as $child_name => $child) {
        $child_node_path = ($current_relative_path ? $current_relative_path . '/' : '') . $child_name;
        // Check for uniqueness before recursing to avoid issues with same-named siblings at the same level if desired (though typically not for headers)
        wpezimport_extract_xml_node_structure_recursive($child, $child_node_path, $structure);
    }
}

/**
 * Extract headers from the uploaded file
 *
 * @param string $file_path Path to the file
 * @param string $file_type File extension (csv, xls, xlsx)
 * @param string $delimiter CSV delimiter character (default: ,)
 * @param string $enclosure CSV enclosure character (default: ")
 */
function wpezimport_extract_file_headers($file_path, $file_type, $delimiter = ',', $enclosure = '"') {
    $headers = array();

    // Handle tab delimiter
    if ($delimiter === 'tab') {
        $delimiter = "\t";
    }

    if ($file_type === 'csv') {
        // Extract headers from CSV file
        try {
            $csv = new \SplFileObject($file_path, 'r');
            $headers = $csv->fgetcsv($delimiter, $enclosure);
        } catch (\RuntimeException $e) {
            return array();
        }
    } else {
        // Extract headers from XLS/XLSX using PhpSpreadsheet
        try {
            // Determine the reader type based on file extension
            if ($file_type === 'xlsx') {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            } else {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
            }

            // Configure the reader to only read data (not formatting)
            $reader->setReadDataOnly(true);

            // Load the spreadsheet
            $spreadsheet = $reader->load($file_path);

            // Get the active sheet
            $sheet = $spreadsheet->getActiveSheet();

            // Get the highest column
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Extract headers (first row)
            $headers = array();
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                // Get the column letter
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col); // 1-based: A = 1
                // Get the cell value using updated API (PhpSpreadsheet 4.x syntax)
                $cellCoordinate = $columnLetter . '1';
                $cellValue = $sheet->getCell($cellCoordinate)->getValue();
                $headers[] = $cellValue;
            }

            // Free up memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

        } catch (\Exception $e) {
            wpezimport_debug_log('Failed to extract headers: ' . $e->getMessage());
            return array();
        }
    }

    // Clean up headers (remove empty values, etc.)
    $headers = array_map('trim', $headers);
    $headers = array_filter($headers, 'strlen');

    // If no headers found, create generic ones (Column 1, Column 2, etc.)
    if (empty($headers)) {
        // Try to determine number of columns
        $num_columns = 0;

        if ($file_type === 'csv') {
            try {
                $csv = new \SplFileObject($file_path, 'r');
                if (($data = $csv->fgetcsv($delimiter, $enclosure)) !== false) {
                    $num_columns = count($data);
                }
            } catch (\RuntimeException $e) {
                $num_columns = 0;
            }
        } else {
            // For XLS/XLSX, we need to determine from the first row data
            try {
                // Determine the reader type based on file extension
                if ($file_type === 'xlsx') {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                } else {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                }

                // Configure the reader to only read data (not formatting)
                $reader->setReadDataOnly(true);

                // Load the spreadsheet
                $spreadsheet = $reader->load($file_path);

                // Get the active sheet
                $sheet = $spreadsheet->getActiveSheet();

                // Get the highest column from the first row
                $highestColumn = $sheet->getHighestColumn(1);
                $num_columns = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

                // Free up memory
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

            } catch (\Exception $e) {
                $num_columns = 10; // Default to 10 columns
            }
        }

        for ($i = 1; $i <= $num_columns; $i++) {
            $headers[] = 'Column ' . $i;
        }
    }

    return array_values($headers); // Re-index the array
}

/**
 * Generate HTML preview table for the uploaded file
 *
 * @param array $headers Array of column headers
 * @param array $data Array of data rows
 * @param bool $is_xml Whether the data is from XML
 * @return string HTML preview table
 */
function wpezimport_generate_preview_html($headers, $data, $is_xml = false) {
    // Debug information
    wpezimport_debug_log('Generating preview HTML with headers: ' . print_r($headers, true));
    wpezimport_debug_log('Data rows count: ' . (is_array($data) ? count($data) : 'not an array'));
    wpezimport_debug_log('Is XML: ' . ($is_xml ? 'true' : 'false'));

    $html = '<div class="wpezimport-table-wrapper">';
    $html .= '<table class="wp-list-table widefat striped">';

    // Table header
    $html .= '<thead><tr>';
    foreach ($headers as $index => $header) {
        $display_header = $header;
        if ($is_xml) {
            // For XML, show a simplified version of the XPath
            $parts = explode('/', $header);
            $last_part = end($parts);

            // Handle attributes
            if (strpos((string)$last_part, '@') === 0) { // Cast to string inline to avoid null issues
                $display_header = '@' . substr($last_part, 1); // Show attribute name with @
            } else {
                $display_header = $last_part; // Show just the element name
            }

            // Add tooltip with full XPath
            $html .= '<th title="' . esc_attr($header) . '" data-xpath="' . esc_attr($header) . '">';
            $html .= esc_html($display_header);
            $html .= '</th>';
        } else {
            $html .= '<th>' . esc_html($header) . '</th>';
        }
    }
    $html .= '</tr></thead>';

    // Table body
    $html .= '<tbody>';
    $max_cell_length = 250; // Maximum characters to display per cell
    if (!empty($data) && is_array($data)) {
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue; // Skip non-array rows
            }
            $html .= '<tr>';
            foreach ($row as $cell) {
                $cell_text = (string) $cell;
                $truncated = (strlen($cell_text) > $max_cell_length);
                $display_text = $truncated ? substr($cell_text, 0, $max_cell_length) . '...' : $cell_text;
                $html .= '<td title="' . esc_attr($cell_text) . '">' . esc_html($display_text) . '</td>';
            }
            $html .= '</tr>';
        }
    }

    // Show message if no data rows were rendered
    if (empty($data) || !is_array($data)) {
        $html .= '<tr><td colspan="' . count($headers) . '">' . __('No data found in the file.', 'ezimport') . '</td></tr>';
    }
    $html .= '</tbody>';

    $html .= '</table>';
    $html .= '</div>';

    // Add XML info if applicable
    if ($is_xml) {
        $html .= '<div class="wpezimport-xml-info">';
        $html .= '<p><strong>' . __('XML Import Mode:', 'ezimport') . '</strong> ';
        $html .= __('Drag XPath expressions to map fields. Full XPath will be shown in curly braces {}.', 'ezimport');
        $html .= '</p>';
        $html .= '</div>';
    }

    return $html;
}

/**
 * Generate a preview of the uploaded file
 *
 * @param string $file_path Path to the file
 * @param string $file_type File extension (csv, xls, xlsx)
 * @param string $delimiter CSV delimiter character (default: ,)
 * @param string $enclosure CSV enclosure character (default: ")
 */
function wpezimport_generate_file_preview($file_path, $file_type, $delimiter = ',', $enclosure = '"') {
    $preview_data = array();
    $max_data_rows = 3; // Number of data rows to preview (excluding header)

    // Handle tab delimiter
    if ($delimiter === 'tab') {
        $delimiter = "\t";
    }

    wpezimport_debug_log("wpezimport_generate_file_preview: file=$file_path, type=$file_type, delimiter='$delimiter', enclosure='$enclosure'");

    if ($file_type === 'csv') {
        // Process CSV file
        try {
            $csv = new \SplFileObject($file_path, 'r');
            // Skip the header row
            $header = $csv->fgetcsv($delimiter, $enclosure);
            wpezimport_debug_log("wpezimport: Skipped header row: " . print_r($header, true));

            $row = 0;
            while (!$csv->eof() && $row < $max_data_rows) {
                $data = $csv->fgetcsv($delimiter, $enclosure);
                if ($data === false || $data === array(null)) {
                    break;
                }
                wpezimport_debug_log("wpezimport: Read data row $row: " . print_r($data, true));
                $preview_data[] = $data;
                $row++;
            }
            wpezimport_debug_log("wpezimport: Total data rows read: " . count($preview_data));
        } catch (\RuntimeException $e) {
            wpezimport_debug_log("wpezimport: Failed to open file: $file_path");
        }
    } else {
        // For XLS/XLSX files, use PhpSpreadsheet library
        try {
            // Determine the reader type based on file extension
            if ($file_type === 'xlsx') {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            } else {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
            }

            // Configure the reader to only read data (not formatting)
            $reader->setReadDataOnly(true);

            // Load the spreadsheet
            $spreadsheet = $reader->load($file_path);

            // Get the active sheet
            $sheet = $spreadsheet->getActiveSheet();

            // Get the highest row and column
            $highestRow = min($sheet->getHighestRow(), $max_data_rows + 1); // +1 for header (row 1)
            $highestColumn = $sheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Loop through each row, starting from row 2 (skip header)
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = array();

                // Loop through each column
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    // Get the column letter
                    $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col); // 1-based: A = 1
                    // Get the cell value using updated API (PhpSpreadsheet 4.x syntax)
                    $cellCoordinate = $columnLetter . $row;
                    $cellValue = $sheet->getCell($cellCoordinate)->getValue();
                    $rowData[] = $cellValue;
                }

                $preview_data[] = $rowData;
            }

            // Free up memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

        } catch (\Exception $e) {
            // Handle any exceptions
            $preview_data = array(
                array('Error'),
                array('Failed to read Excel file: ' . $e->getMessage())
            );
        }
    }

    return $preview_data;
}

/**
 * Get upload error message
 */
/**
 * AJAX handler: re-upload a file on the mapping page (editing a saved file import).
 *
 * Accepts a CSV/XLS/XLSX file, extracts headers, updates the draft state with
 * the new file and headers, and returns the draggable column list HTML.
 */
function wpezimport_reupload_file_callback() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpezimport_file_upload')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'ezimport')));
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ezimport')));
    }

    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    if ($draft_id <= 0) {
        wp_send_json_error(array('message' => __('Missing draft ID.', 'ezimport')));
    }

    if (!isset($_FILES['wpezimport_reupload_file']) || $_FILES['wpezimport_reupload_file']['error'] !== UPLOAD_ERR_OK) {
        $error = isset($_FILES['wpezimport_reupload_file'])
            ? wpezimport_get_upload_error_message($_FILES['wpezimport_reupload_file']['error'])
            : __('No file was uploaded.', 'ezimport');
        wp_send_json_error(array('message' => $error));
    }

    $file = $_FILES['wpezimport_reupload_file'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, array('csv', 'xls', 'xlsx'), true)) {
        wp_send_json_error(array('message' => __('Invalid file type. Please upload a CSV, XLS, or XLSX file.', 'ezimport')));
    }

    $handled = wpezimport_store_uploaded_file($file, false);
    if (isset($handled['error'])) {
        wp_send_json_error(array('message' => $handled['error']));
    }
    $upload_file = $handled['file'];

    // Extract headers
    $draft = wpezimport_get_draft($draft_id);
    $ds = $draft ? $draft->state : array();
    $delimiter = isset($ds['csv_delimiter']) ? $ds['csv_delimiter'] : ',';
    $enclosure = isset($ds['csv_enclosure']) ? $ds['csv_enclosure'] : '"';

    $file_headers = wpezimport_extract_file_headers($upload_file, $file_ext, $delimiter, $enclosure);

    if (empty($file_headers)) {
        wp_delete_file($upload_file);
        wp_send_json_error(array('message' => __('Could not extract columns from the file. Please check the file format.', 'ezimport')));
    }

    // Update draft state with new file and headers
    wpezimport_update_draft($draft_id, array(
        'uploaded_file'      => $upload_file,
        'uploaded_file_name' => $file['name'],
        'file_headers'       => $file_headers,
    ));

    // Build draggable column list HTML
    $html = '';
    foreach ($file_headers as $header) {
        $html .= '<div class="wpezimport-column-item" draggable="true" data-column="' . esc_attr($header) . '">';
        $html .= '<span class="dashicons dashicons-move"></span>';
        $html .= '<span class="wpezimport-column-name">' . esc_html($header) . '</span>';
        $html .= '</div>';
    }

    wp_send_json_success(array(
        /* translators: %d: number of detected columns in the uploaded file. */
        'message'  => sprintf(__('File uploaded successfully. %d columns found.', 'ezimport'), count($file_headers)),
        'columns_html' => $html,
        'headers'  => $file_headers,
    ));
}
add_action('wp_ajax_wpezimport_reupload_file', 'wpezimport_reupload_file_callback');

function wpezimport_get_upload_error_message($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.';
        case UPLOAD_ERR_PARTIAL:
            return 'The uploaded file was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing a temporary folder.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the file upload.';
        default:
            return 'Unknown upload error.';
    }
}
