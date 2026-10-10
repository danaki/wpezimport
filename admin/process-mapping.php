<?php
/**
 * Handle mapping form submission and processing
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Handle AJAX mapping form submission
 */
function wpezimport_handle_mapping_submit() {
    // Check nonce
    if (!isset($_POST['wpezimport_mapping_nonce']) || !wp_verify_nonce($_POST['wpezimport_mapping_nonce'], 'wpezimport_save_mapping')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
    }

    // Get form data
    $field_mapping = isset($_POST['field_mapping']) ? $_POST['field_mapping'] : array();
    $field_mapping_units = isset($_POST['field_mapping_units']) ? $_POST['field_mapping_units'] : array();
    $import_options = isset($_POST['import_options']) ? $_POST['import_options'] : array();
    $update_flags = isset($_POST['update_flags']) ? $_POST['update_flags'] : array();

    // Validate field patterns
    $field_mapping = array_filter($field_mapping, function($value) {
        if (is_array($value)) {
            return !empty($value);
        }
        return is_string($value) && trim($value) !== '';
    }); // Remove empty patterns

    if (empty($field_mapping)) {
        wp_send_json_error(array('message' => 'Please map at least one field.'));
    }

    // Sanitize all patterns
    $sanitized_field_mapping = array();
    foreach ($field_mapping as $field_id => $pattern) {
        $sanitized_field_mapping[sanitize_key($field_id)] = sanitize_text_field($pattern);
    }

    // Sanitize import options
    $sanitized_import_options = array();
    if (!empty($import_options)) {
        foreach ($import_options as $option_key => $option_value) {
            $sanitized_import_options[sanitize_key($option_key)] = sanitize_text_field($option_value);
        }
    }

    // Set default options if not provided
    if (!isset($sanitized_import_options['default_post_type'])) {
        $sanitized_import_options['default_post_type'] = 'post';
    }
    if (!isset($sanitized_import_options['default_post_status'])) {
        $sanitized_import_options['default_post_status'] = 'draft';
    }
    if (!isset($sanitized_import_options['default_author'])) {
        $sanitized_import_options['default_author'] = get_current_user_id();
    }

    // Get draft_id from POST to read state
    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    $draft = ($draft_id > 0) ? wpezimport_get_draft($draft_id) : false;

    // Include the selected XML item path from draft if available
    if ($draft && isset($draft->state['xml_item_path']) && !empty($draft->state['xml_item_path'])) {
        $sanitized_import_options['selected_xpath'] = $draft->state['xml_item_path'];
    }

    // Store file format type (xml vs csv/xls) for validation on future imports
    $sanitized_import_options['is_xml'] = $draft ? (bool)(isset($draft->state['is_xml']) ? $draft->state['is_xml'] : false) : false;

    // Extract unique_identifier_source from field_mapping if exists and move it to top level
    if (isset($sanitized_field_mapping['unique_identifier_source']) && !empty($sanitized_field_mapping['unique_identifier_source'])) {
        $sanitized_import_options['unique_identifier_source'] = $sanitized_field_mapping['unique_identifier_source'];
        // Remove it from mappings to avoid duplication
        unset($sanitized_field_mapping['unique_identifier_source']);
    }

    // Sanitize and save field mapping units (for weight and dimension conversions, etc.)
    $sanitized_units = array();
    if (!empty($field_mapping_units)) {
        foreach ($field_mapping_units as $field_id => $unit) {
            $sanitized_units[sanitize_key($field_id)] = sanitize_text_field($unit);
        }

        // If dimensions unit is set, also store it for individual dimension fields for easier lookup
        if (isset($sanitized_units['_dimensions'])) {
            $sanitized_units['_length'] = $sanitized_units['_dimensions'];
            $sanitized_units['_width'] = $sanitized_units['_dimensions'];
            $sanitized_units['_height'] = $sanitized_units['_dimensions'];
        }
    }

    // Sanitize update flags
    $sanitized_update_flags = array();
    if (!empty($update_flags)) {
        foreach ($update_flags as $field_id => $flag_value) {
            $sanitized_update_flags[sanitize_key($field_id)] = $flag_value === '1' ? '1' : '0';
        }
    }

    // Add mappings, units, and update flags to the import options
    $sanitized_import_options['mappings'] = $sanitized_field_mapping;
    $sanitized_import_options['mapping_units'] = $sanitized_units;
    $sanitized_import_options['update_flags'] = $sanitized_update_flags;

    // Save the fields to the database instead of proceeding to import
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpezimport_saved_imports';

    // Store the options using PHP's serialize() function as requested
    $serialized_options = serialize($sanitized_import_options);

    // Check if we're editing an existing import (from draft state)
    $editing_import = $draft && isset($draft->state['editing_import']) && $draft->state['editing_import'];
    $edit_import_id = $draft && isset($draft->state['edit_import_id']) ? intval($draft->state['edit_import_id']) : 0;

    // Get import name - either from POST or generate a default one with date and time
    $import_name = isset($_POST['import_name']) && !empty(trim($_POST['import_name']))
        ? sanitize_text_field($_POST['import_name'])
        : sprintf(
            /* translators: %s: date and time of the import. */
            __('Import %s', 'ezimport'),
            current_time('Y-m-d H:i:s')
        );

    // Get import type and file info from draft
    $import_type = $draft ? (isset($draft->state['import_type']) ? $draft->state['import_type'] : $draft->type) : 'file';
    $file_path   = $draft ? (isset($draft->state['uploaded_file']) ? $draft->state['uploaded_file'] : $draft->file) : '';
    $import_url  = $draft ? (isset($draft->state['import_url']) ? $draft->state['import_url'] : $draft->url) : '';
    $post_type   = isset($sanitized_import_options['default_post_type']) ? $sanitized_import_options['default_post_type'] : 'post';
    $is_xml      = !empty($sanitized_import_options['is_xml']) ? 1 : 0;

    $result = false;

    if ($editing_import && $edit_import_id > 0) {
        // Update existing import
        $result = $wpdb->update(
            $table_name,
            array(
                'name' => $import_name,
                'type' => $import_type,
                'post_type' => $post_type,
                'file_path' => $file_path,
                'url' => $import_url,
                'is_xml' => $is_xml,
                'options' => $serialized_options
            ),
            array('id' => $edit_import_id),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s'),
            array('%d')
        );

        // For wpdb->update, true means success, false means failure
        $result = ($result !== false);
    } else {
        // Insert new import
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $import_name,
                'type' => $import_type,
                'post_type' => $post_type,
                'file_path' => $file_path,
                'url' => $import_url,
                'is_xml' => $is_xml,
                'options' => $serialized_options,
                'created_at' => current_time('mysql')
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'
            )
        );
    }

    wpezimport_debug_log('WPEZIMPORT SAVE - result: ' . var_export($result, true));
    wpezimport_debug_log('WPEZIMPORT SAVE - last_error: ' . $wpdb->last_error);

    if ($result) {
        // Get the ID of the newly inserted record
        $import_id = $editing_import ? $edit_import_id : $wpdb->insert_id;

        // Clean up the draft now that we've saved to the permanent table
        if ($draft_id > 0) {
            wpezimport_delete_draft($draft_id);
        }

        // Redirect to the manage imports page with success message
        wp_send_json_success(array(
            'message' => __('Import configuration saved successfully.', 'ezimport'),
            'redirect' => admin_url('admin.php?page=wpezimport-manage')
        ));
    } else {
        wpezimport_debug_log('WPEZIMPORT SAVE - FAILED');
        // If database insert failed, show an error
        wp_send_json_error(array(
            'message' => __('Failed to save import configuration: ', 'ezimport') . $wpdb->last_error
        ));
    }
}
add_action('wp_ajax_wpezimport_save_mapping', 'wpezimport_handle_mapping_submit');

// Note: The wpezimport_extract_file_headers function was moved to process-upload.php
// to avoid redeclaration errors

/**
 * Parse a pattern string and replace field tokens with actual data
 *
 * @param string $pattern Pattern with field tokens like "{field_name}"
 * @param array $row_data Data row from the Excel file
 * @return string Parsed string with tokens replaced by actual values
 */
function wpezimport_parse_pattern($pattern, $row_data) {
    // Match all tokens in the pattern {token_name}
    preg_match_all('/{([^}]+)}/', $pattern, $matches);

    if (empty($matches[1])) {
        return $pattern; // No tokens found
    }

    $result = $pattern;

    foreach ($matches[1] as $field_name) {
        if (isset($row_data[$field_name])) {
            $result = str_replace('{' . $field_name . '}', $row_data[$field_name], $result);
        } else {
            // If field not found, replace with empty string
            $result = str_replace('{' . $field_name . '}', '', $result);
        }
    }

    return $result;
}
