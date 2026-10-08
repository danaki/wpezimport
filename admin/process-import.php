<?php
/**
 * Process import
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include pure XML parsing functions
require_once plugin_dir_path(__FILE__) . 'xml-parser.php';

/**
 * Handle AJAX import process
 */
function wpezimport_handle_import() {
    error_log('Import process started');

    // Check nonce
    if (!isset($_POST['wpezimport_import_nonce'])) {
        wp_send_json_error(array('message' => 'Security check failed: Missing nonce.'));
        return;
    }
    if (!wp_verify_nonce($_POST['wpezimport_import_nonce'], 'wpezimport_import')) {
        wp_send_json_error(array('message' => 'Security check failed: Invalid nonce.'));
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
        return;
    }

    // Load import data from draft
    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    if ($draft_id <= 0) {
        wp_send_json_error(array('message' => 'Missing draft ID. Please start the import process again.'));
        return;
    }

    $draft = wpezimport_get_draft($draft_id);
    if (!$draft) {
        wp_send_json_error(array('message' => 'Draft not found. Please start the import process again.'));
        return;
    }

    $ds = $draft->state;
    if (!isset($ds['field_mapping']) || !isset($ds['uploaded_file'])) {
        wp_send_json_error(array('message' => 'Import data missing. Please start the import process again.'));
        return;
    }

    $field_mapping   = $ds['field_mapping'];
    $import_options  = isset($ds['import_options']) && is_array($ds['import_options']) ? $ds['import_options'] : array();
    $file_path       = $ds['uploaded_file'];
    $is_xml          = !empty($ds['is_xml']);
    $item_xpath      = isset($ds['xml_item_path']) ? $ds['xml_item_path'] : '';

    if (!file_exists($file_path)) {
        wp_send_json_error(array('message' => 'The uploaded file no longer exists. Please upload the file again.'));
        return;
    }

    // Unique identifier source (field_mapping key or saved option)
    $unique_id_source = '';
    if (!empty($field_mapping['unique_identifier_source'])) {
        $unique_id_source = $field_mapping['unique_identifier_source'];
    } elseif (!empty($import_options['unique_identifier_source'])) {
        $unique_id_source = $import_options['unique_identifier_source'];
    }

    $warnings = array();
    if (empty($unique_id_source)) {
        $warnings[] = 'WARNING: No unique identifier field configured. Products will be skipped to prevent duplicates. Please configure a unique identifier field in the mapping.';
    }

    // ── Build batch options (same layout wpezimport_handle_mapping_submit saves) ──
    $options = $import_options;
    $options['mappings'] = $field_mapping;
    unset($options['mappings']['unique_identifier_source']);
    // Legacy mapping key post_category -> batch key post_categories
    if (isset($options['mappings']['post_category']) && !isset($options['mappings']['post_categories'])) {
        $options['mappings']['post_categories'] = $options['mappings']['post_category'];
        unset($options['mappings']['post_category']);
    }
    $options['unique_identifier_source'] = $unique_id_source;
    $options['is_xml'] = $is_xml;
    if (!empty($item_xpath)) {
        $options['selected_xpath'] = $item_xpath;
    }
    if (isset($ds['csv_delimiter'])) $options['csv_delimiter'] = $ds['csv_delimiter'];
    if (isset($ds['csv_enclosure'])) $options['csv_enclosure'] = $ds['csv_enclosure'];
    if (isset($ds['update_flags']))  $options['update_flags']  = $ds['update_flags'];

    $post_type = isset($options['default_post_type']) ? $options['default_post_type'] : 'post';

    // ── Create or reuse a wpezimport_saved_imports row for the batch processor ──
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';

    $saved_import_id = 0;
    if (isset($ds['edit_import_id'])) {
        $saved_import_id = intval($ds['edit_import_id']);
    } elseif (isset($ds['saved_import_id'])) {
        $saved_import_id = intval($ds['saved_import_id']);
    }
    if ($saved_import_id > 0 && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $saved_import_id))) {
        $saved_import_id = 0;
    }

    $is_temp_row = false;
    if ($saved_import_id > 0) {
        $wpdb->update($table, array(
            'type'      => 'file',
            'post_type' => $post_type,
            'file_path' => $file_path,
            'is_xml'    => $is_xml ? 1 : 0,
            'options'   => serialize($options),
        ), array('id' => $saved_import_id), array('%s', '%s', '%s', '%d', '%s'), array('%d'));
    } else {
        $inserted = $wpdb->insert($table, array(
            'name'       => sprintf(__('Manual import %s', 'wpezimport'), current_time('Y-m-d H:i:s')),
            'type'       => 'file',
            'post_type'  => $post_type,
            'file_path'  => $file_path,
            'is_xml'     => $is_xml ? 1 : 0,
            'options'    => serialize($options),
            'created_at' => current_time('mysql'),
        ), array('%s', '%s', '%s', '%s', '%d', '%s', '%s'));
        if (!$inserted) {
            wp_send_json_error(array('message' => __('Failed to initialize import.', 'wpezimport')));
            return;
        }
        $saved_import_id = (int) $wpdb->insert_id;
        $is_temp_row = true;
    }

    // ── Legacy rule: without a unique identifier nothing is imported ──
    if (empty($unique_id_source)) {
        $items = wpezimport_batch_load_items($file_path, $is_xml, $options);
        $resolved_items = array();
        if (!is_wp_error($items)) {
            foreach ($items as $i => $item) {
                $title = $is_xml ? (string) wpezimport_batch_extract_value($item, isset($field_mapping['post_title']) ? $field_mapping['post_title'] : '') : '';
                $resolved_items[] = array(
                    'item_index' => $i + 1,
                    'fields'     => array(),
                    'resolution' => array(
                        'status'  => 'skipped',
                        'message' => __('No unique identifier field configured', 'wpezimport'),
                        'title'   => $title !== '' ? $title : 'Item #' . ($i + 1),
                    ),
                    'id_resolution' => array('status' => 'not_configured', 'duplicate_found' => false, 'existing_post_id' => null),
                );
            }
        }
        $total = is_wp_error($items) ? 0 : count($items);

        if ($is_temp_row) {
            $wpdb->delete($wpdb->prefix . 'wpezimport_statistics', array('import_id' => $saved_import_id), array('%d'));
            $wpdb->delete($table, array('id' => $saved_import_id), array('%d'));
        }

        wp_send_json_success(array(
            'total_rows'          => $total,
            'imported_count'      => 0,
            'created_count'       => 0,
            'updated_count'       => 0,
            'skipped_count'       => $total,
            'errors'              => array(),
            'warnings'            => $warnings,
            'imported_ids'        => array(),
            'progress_percentage' => 100,
            'xpath_debug'         => isset($ds['xpath_debug']) ? $ds['xpath_debug'] : array(),
            'resolved_items'      => $resolved_items,
        ));
        return;
    }

    // ── Prime run state, then run the shared batch processor to completion ──
    set_transient('wpezimport_run_file_' . $saved_import_id, $file_path, 7200);
    wpezimport_update_import_state($saved_import_id, array(
        'cron_status'      => 'triggered',
        'cron_phase'       => 'items',
        'cron_last_item'   => 0,
        'cron_total_items' => 0,
        'cron_started_at'  => current_time('mysql'),
        'cron_log'         => serialize(array()),
        'cron_created'     => 0,
        'cron_updated'     => 0,
        'cron_errors'      => 0,
    ));

    $result = wpezimport_process_import_batch($saved_import_id, 0);

    $resolved_items = isset($result['resolved_items']) ? $result['resolved_items'] : array();
    $errors        = array();
    $imported_ids  = array();
    foreach ($resolved_items as $ri) {
        if (isset($ri['resolution']['status']) && $ri['resolution']['status'] === 'error') {
            $errors[] = sprintf('Error importing item %d: %s', $ri['item_index'], $ri['resolution']['message']);
        }
        if (!empty($ri['resolution']['post_id'])) {
            $imported_ids[] = $ri['resolution']['post_id'];
        }
    }
    if (isset($result['status'], $result['message']) && $result['status'] === 'error') {
        $errors[] = $result['message'];
    }

    $created   = isset($result['created'])   ? intval($result['created'])   : 0;
    $updated   = isset($result['updated'])   ? intval($result['updated'])   : 0;
    $skipped   = isset($result['skipped'])   ? intval($result['skipped'])   : 0;
    $no_change = isset($result['no_change']) ? intval($result['no_change']) : 0;
    $total     = isset($result['total'])     ? intval($result['total'])     : 0;

    $response = array(
        'total_rows'          => $total,
        'imported_count'      => $created + $updated,
        'created_count'       => $created,
        'updated_count'       => $updated,
        'skipped_count'       => $skipped + $no_change,
        'errors'              => $errors,
        'warnings'            => $warnings,
        'imported_ids'        => $imported_ids,
        'progress_percentage' => 100,
        'xpath_debug'         => isset($ds['xpath_debug']) ? $ds['xpath_debug'] : array(),
        'resolved_items'      => $resolved_items,
    );

    // Statistics: batch saves them against the import row — keep for saved
    // imports, clean up for the temporary row (legacy never persisted those).
    if ($is_temp_row) {
        $wpdb->delete($wpdb->prefix . 'wpezimport_statistics', array('import_id' => $saved_import_id), array('%d'));
        $wpdb->delete($table, array('id' => $saved_import_id), array('%d'));
    } else {
        $response['statistics_url'] = admin_url('admin.php?page=wpezimport-statistics&import_id=' . $saved_import_id);
    }

    // Clear XPath debug from draft
    if ($draft_id > 0) {
        wpezimport_update_draft($draft_id, array('xpath_debug' => array()));
    }

    error_log('Import process completed: ' . ($created + $updated) . ' of ' . $total . ' items imported');
    wp_send_json_success($response);
}

// Register the AJAX action
add_action('wp_ajax_wpezimport_handle_import', 'wpezimport_handle_import');


/**
 * Process taxonomy terms and assign them to a post
 *
 * @param int $post_id The post ID to assign terms to
 * @param string $terms_string Space or newline separated list of terms
 * @param string $taxonomy The taxonomy name (category, post_tag, etc.)
 * @param bool $match_existing_only If true, only match existing terms, don't create new ones
 * @return array Array of term IDs and errors
 */
function wpezimport_process_taxonomy_terms($post_id, $terms_string, $taxonomy = 'category', $match_existing_only = false) {
    if (empty($terms_string) || empty($post_id)) {
        return array('terms' => array(), 'errors' => array('No terms or invalid post ID'));
    }

    if (!taxonomy_exists($taxonomy)) {
        error_log('Taxonomy does not exist, skipping terms: ' . $taxonomy);
        return array('terms' => array(), 'errors' => array('Taxonomy "' . $taxonomy . '" is not registered'));
    }

    // Split the terms (by spaces or newlines)
    $terms = preg_split('/[,\n]+/', trim($terms_string));
    $term_ids = array();
    $errors = array();

    // Process each term
    foreach ($terms as $term) {
        // Skip empty terms
        if (empty($term)) {
            continue;
        }

        // Clean up the term (remove brackets, whitespace, etc.)
        $term = trim($term, " {}\t\n\r\0\x0B");

        // Skip if term is still empty after cleanup
        if (empty($term)) {
            continue;
        }

        error_log('Processing ' . $taxonomy . ' term: ' . $term);

        // Look for existing term
        $existing_term = term_exists($term, $taxonomy);

        if ($existing_term !== 0 && $existing_term !== null) {
            // Term exists, use it
            $term_id = (int) $existing_term['term_id'];
            error_log('Found existing ' . $taxonomy . ' with ID: ' . $term_id);
        } else {
            // Term doesn't exist
            if ($match_existing_only) {
                // Skip creating new term when match_existing_only is enabled
                error_log('Skipping non-existent ' . $taxonomy . ': ' . $term . ' (match existing only mode)');
                continue;
            }

            // Create the term
            $new_term = wp_insert_term($term, $taxonomy);

            if (is_wp_error($new_term)) {
                $errors[] = 'Failed to create ' . $taxonomy . ': ' . $term . ' - ' . $new_term->get_error_message();
                error_log('Failed to create ' . $taxonomy . ': ' . $term . ' - ' . $new_term->get_error_message());
                continue;
            }

            $term_id = $new_term['term_id'];
            error_log('Created new ' . $taxonomy . ' with ID: ' . $term_id . ' for: ' . $term);
        }

        // Add to term IDs array
        $term_ids[] = $term_id;
    }

    // Assign terms to post if we have any
    if (!empty($term_ids)) {
        $result = wp_set_object_terms($post_id, $term_ids, $taxonomy, true); // Append to existing terms

        if (is_wp_error($result)) {
            $errors[] = 'Failed to assign ' . $taxonomy . ' terms to post: ' . $result->get_error_message();
            error_log('Failed to assign ' . $taxonomy . ' terms to post: ' . $result->get_error_message());
        } else {
            error_log('Successfully assigned ' . count($term_ids) . ' ' . $taxonomy . ' terms to post ' . $post_id);
        }
    }

    return array(
        'terms' => $term_ids,
        'errors' => $errors
    );
}

/**
 * Extract image URLs from XPath expressions evaluated against an XML item
 *
 * @param object $xml_item The SimpleXMLElement item to evaluate XPath expressions against
 * @param string $xpath_string Space or newline separated list of XPath expressions
 * @return array Array containing found URLs and any errors
 */
function wpezimport_extract_image_urls_from_xpath($xml_item, $xpath_string) {
    $image_urls = array();
    $errors = array();
    $debug_log = array(); // Store debug messages for frontend display

    if (empty($xml_item) || empty($xpath_string)) {
        return array(
            'urls' => $image_urls,
            'errors' => array('No XML item or XPath expressions provided'),
            'debug' => $debug_log
        );
    }

    // Extract all XPath expressions within curly braces
    preg_match_all('/{([^}]+)}/', $xpath_string, $xpath_matches);

    // If we found XPath expressions, process them
    if (!empty($xpath_matches[1])) {
        $debug_log[] = '=== XPath Evaluation Started ===';
        $debug_log[] = 'Found ' . count($xpath_matches[1]) . ' XPath expression(s) to evaluate';
        error_log('=== XPath Evaluation Started ===');
        error_log('Found ' . count($xpath_matches[1]) . ' XPath expression(s) to evaluate');

        foreach ($xpath_matches[1] as $index => $xpath) {
            if (empty(trim($xpath))) continue;

            $debug_log[] = 'XPath #' . ($index + 1) . ': {' . $xpath . '}';
            error_log('XPath #' . ($index + 1) . ': {' . $xpath . '}');

            try {
                // Evaluate the XPath expression against the XML item
                $result = @$xml_item->xpath($xpath);

                if ($result !== false && !empty($result)) {
                    $debug_log[] = '  ✓ Found ' . count($result) . ' result(s)';
                    error_log('  ✓ Found ' . count($result) . ' result(s)');
                    foreach ($result as $node_index => $node) {
                        $raw_url = trim((string)$node);
                        if (!empty($raw_url)) {
                            // Encode URL to handle spaces and special characters
                            $encoded_url = wpezimport_encode_url($raw_url);
                            $image_urls[] = $encoded_url;
                            $debug_log[] = '  Result #' . ($node_index + 1) . ': ' . $raw_url;
                            if ($encoded_url !== $raw_url) {
                                $debug_log[] = '    → Encoded: ' . $encoded_url;
                                error_log('  Result #' . ($node_index + 1) . ': ' . $raw_url . ' → ' . $encoded_url);
                            } else {
                                error_log('  Result #' . ($node_index + 1) . ': ' . $raw_url);
                            }
                        } else {
                            $debug_log[] = '  Result #' . ($node_index + 1) . ': (empty)';
                            error_log('  Result #' . ($node_index + 1) . ': (empty)');
                        }
                    }
                } else {
                    $debug_log[] = '  ✗ No results found';
                    error_log('  ✗ No results found');
                }
            } catch (Exception $e) {
                $error_msg = 'Failed to evaluate XPath: ' . $xpath . ' - ' . $e->getMessage();
                $errors[] = $error_msg;
                $debug_log[] = '  ✗ ERROR: ' . $e->getMessage();
                error_log('  ✗ ERROR: ' . $e->getMessage());
            }
        }

        $debug_log[] = '=== XPath Evaluation Complete: ' . count($image_urls) . ' URL(s) extracted ===';
        error_log('=== XPath Evaluation Complete: ' . count($image_urls) . ' URL(s) extracted ===');
    } else {
        // If no XPath expressions in curly braces, split by newlines/commas (not spaces - URLs may contain them) and assume direct URLs
        $debug_log[] = 'No XPath expressions found (no {} braces). Treating as direct URLs.';
        error_log('No XPath expressions found (no {} braces). Treating as direct URLs.');
        $url_list = preg_split('/[\n\r,]+/', $xpath_string);
        foreach ($url_list as $raw_url) {
            $raw_url = trim($raw_url);
            if (!empty($raw_url)) {
                // Encode URL to handle spaces and special characters
                $encoded_url = wpezimport_encode_url($raw_url);
                $image_urls[] = $encoded_url;
                if ($encoded_url !== $raw_url) {
                    $debug_log[] = 'URL: ' . $raw_url . ' → ' . $encoded_url;
                }
            }
        }
    }

    return array(
        'urls' => $image_urls,
        'errors' => $errors,
        'debug' => $debug_log
    );
}


/**
 * Resolve an XPath image pattern to actual URLs for the current item
 *
 * Extracts the XML-item/XPath resolution and draft-debug bookkeeping out of
 * wpezimport_process_images(). Returns a newline-separated URL string (or ''
 * when nothing could be resolved).
 *
 * @param string                      $image_urls Pattern or URL list
 * @param SimpleXMLElement|string|null $xml_item  Current item or its XML string
 * @param int                         $draft_id   Draft for loading/storing state
 * @return string Newline-separated resolved URLs
 */
function wpezimport_resolve_image_patterns($image_urls, $xml_item, $draft_id) {
    if (strpos($image_urls, '{') === false) {
        return $image_urls;
    }

    error_log('XPath expressions detected in image URLs. Attempting to evaluate.');

    // Use provided XML item, or load from draft
    $current_xml_item = null;
    if ($xml_item instanceof SimpleXMLElement) {
        $current_xml_item = $xml_item;
    } elseif ($xml_item && is_string($xml_item)) {
        try {
            $current_xml_item = new SimpleXMLElement($xml_item);
        } catch (Exception $e) {
            error_log('Failed to parse provided XML item: ' . $e->getMessage());
        }
    } else {
        $current_draft = ($draft_id > 0) ? wpezimport_get_draft($draft_id) : false;
        $current_ds = $current_draft ? $current_draft->state : array();
        if (isset($current_ds['current_item']) && !empty($current_ds['current_item'])) {
            try {
                $current_xml_item = new SimpleXMLElement($current_ds['current_item']);
                error_log('Successfully loaded XML item from draft for XPath evaluation');
            } catch (Exception $e) {
                error_log('Failed to parse XML item from draft: ' . $e->getMessage());
            }
        }
    }

    if (!$current_xml_item) {
        error_log('No XML item available for XPath evaluation. Clearing pattern.');
        return '';
    }

    $extracted_results = wpezimport_extract_image_urls_from_xpath($current_xml_item, $image_urls);

    // Store debug logs in draft for display on results page
    if ($draft_id > 0 && !empty($extracted_results['debug'])) {
        $debug_draft = wpezimport_get_draft($draft_id);
        $existing_debug = ($debug_draft && isset($debug_draft->state['xpath_debug'])) ? $debug_draft->state['xpath_debug'] : array();
        wpezimport_update_draft($draft_id, array(
            'xpath_debug' => array_merge($existing_debug, $extracted_results['debug'])
        ));
    }

    // Log any XPath evaluation errors
    foreach ($extracted_results['errors'] as $error) {
        error_log('Image XPath error: ' . $error);
    }

    if (!empty($extracted_results['urls'])) {
        $resolved = implode(' ', $extracted_results['urls']);
        error_log('Successfully extracted ' . count($extracted_results['urls']) . ' image URLs from XPath expressions: ' . $resolved);
        return $resolved;
    }

    error_log('No image URLs were extracted from XPath expressions. Clearing pattern.');
    return '';
}

/**
 * Normalize an image URL: decode HTML entities then percent-encode it.
 *
 * @param string $image_url Raw URL as found in the source data
 * @return array [original_url (decoded, for display), encoded_url (for fetching)]
 */
function wpezimport_normalize_image_url($image_url) {
    $original_url = trim(html_entity_decode($image_url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $encoded = wpezimport_encode_url($original_url);
    return array($original_url, $encoded);
}

/**
 * Download an image: HEAD preflight (MIME/size) then GET with size re-check.
 *
 * @param string $image_url           Encoded URL to fetch
 * @param array  $allowed_image_mimes Allowed Content-Types for the HEAD check
 * @param int    $max_image_size      Max bytes
 * @return array|WP_Error array('data'=>bytes,'response'=>..., 'code'=>HTTP) or WP_Error
 */
function wpezimport_download_image($image_url, $allowed_image_mimes, $max_image_size) {
    // ── HEAD preflight: check Content-Type and Content-Length before downloading ──
    $head_response = wp_remote_head($image_url, array(
        'timeout'   => 15,
        'sslverify' => apply_filters('wpezimport_sslverify', true),
    ));

    if (!is_wp_error($head_response)) {
        $head_content_type = wp_remote_retrieve_header($head_response, 'content-type');
        if (!empty($head_content_type)) {
            $head_mime = strtolower(trim(explode(';', $head_content_type)[0]));
            if (!in_array($head_mime, $allowed_image_mimes)) {
                error_log('Image HEAD rejected (MIME): ' . $head_mime . ' - ' . $image_url);
                return new WP_Error('bad_mime', sprintf(__('Rejected: server reports non-image type (%s)', 'wpezimport'), $head_mime));
            }
        }

        $head_content_length = wp_remote_retrieve_header($head_response, 'content-length');
        if (!empty($head_content_length) && intval($head_content_length) > $max_image_size) {
            $size_mb = round(intval($head_content_length) / (1024 * 1024), 1);
            $limit_mb = round($max_image_size / (1024 * 1024), 1);
            error_log('Image HEAD rejected (size): ' . $head_content_length . ' bytes - ' . $image_url);
            return new WP_Error('too_large', sprintf(__('Rejected: file too large (%s MB, limit %s MB)', 'wpezimport'), $size_mb, $limit_mb));
        }
    }
    // If HEAD fails, proceed to GET anyway (some servers don't support HEAD)

    // ── Download the image ──
    $response = wp_remote_get($image_url, array(
        'timeout' => 30,
        'sslverify' => apply_filters('wpezimport_sslverify', true)
    ));

    if (is_wp_error($response)) {
        error_log('Failed to download image: ' . $image_url . ' - ' . $response->get_error_message());
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $image_data = wp_remote_retrieve_body($response);
    if (empty($image_data)) {
        error_log('Empty image data from URL: ' . $image_url);
        return new WP_Error('empty_body', 'Empty response (HTTP ' . $response_code . ')');
    }

    // ── Post-download size check (in case HEAD didn't report Content-Length) ──
    if (strlen($image_data) > $max_image_size) {
        $size_mb = round(strlen($image_data) / (1024 * 1024), 1);
        $limit_mb = round($max_image_size / (1024 * 1024), 1);
        error_log('Image rejected (download size): ' . strlen($image_data) . ' bytes - ' . $image_url);
        return new WP_Error('too_large', sprintf(__('Rejected: downloaded file too large (%s MB, limit %s MB)', 'wpezimport'), $size_mb, $limit_mb));
    }

    return array('data' => $image_data, 'response' => $response, 'code' => $response_code);
}

/**
 * Detect a usable file extension for downloaded image data.
 *
 * Order: Content-Type header → URL path → finfo magic bytes.
 *
 * @param array|null $response   HTTP response (may be null for cached copies)
 * @param string     $image_url  Encoded URL
 * @param string     $image_data Raw bytes
 * @return array 'extension' (empty when undetectable) and 'detected_mime'
 */
function wpezimport_detect_image_extension($response, $image_url, $image_data) {
    $mime_to_ext = array(
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    );
    $valid_image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');

    $extension = '';

    // 1. Content-Type header from GET response
    if ($response && !is_wp_error($response)) {
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!empty($content_type)) {
            $content_type = strtolower(trim(explode(';', $content_type)[0]));
            if (isset($mime_to_ext[$content_type])) {
                $extension = $mime_to_ext[$content_type];
            }
        }
    }

    // 2. URL path extension
    if (empty($extension)) {
        $url_extension = strtolower(pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($url_extension, $valid_image_extensions)) {
            $extension = $url_extension;
        }
    }

    // 3. Magic bytes (authoritative check)
    $detected_mime = '';
    if (!empty($image_data)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->buffer($image_data);
        if (empty($extension) && isset($mime_to_ext[$detected_mime])) {
            $extension = $mime_to_ext[$detected_mime];
        }
    }

    return array('extension' => $extension, 'detected_mime' => $detected_mime);
}

/**
 * Build a filename for an imported image.
 *
 * @param string $image_url     Encoded URL
 * @param string $extension     Detected extension (no dot)
 * @param string $base_filename Slug derived from the post title, may be ''
 * @param int    $index         Zero-based image index in this item
 * @return string
 */
function wpezimport_generate_image_filename($image_url, $extension, $base_filename, $index) {
    if (!empty($base_filename)) {
        return $base_filename . '-' . ($index + 1) . '.' . $extension;
    }

    $url_filename = basename(parse_url($image_url, PHP_URL_PATH));
    $url_filename = pathinfo($url_filename, PATHINFO_FILENAME);

    if (empty($url_filename) || $url_filename === 'index') {
        return 'image-' . time() . '-' . mt_rand(1000, 9999) . '.' . $extension;
    }

    return $url_filename . '.' . $extension;
}

/**
 * Look up the tracking table for a previously downloaded image.
 *
 * @param string $image_url Encoded URL
 * @param int    $post_id   Post the image is being attached to
 * @param array  &$existing_attachment_ids Map of the post's attachments pending cleanup;
 *                                          the matched ID is removed on a "same post" hit
 * @return array One of:
 *               array('mode' => 'attached', 'attachment_id' => int)
 *               array('mode' => 'copy', 'data' => string)
 *               array('mode' => 'download')
 */
function wpezimport_find_cached_image($image_url, $post_id, &$existing_attachment_ids) {
    global $wpdb;

    $existing_image = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpezimport_images WHERE url = %s",
        $image_url
    ));

    if (!$existing_image) {
        return array('mode' => 'download');
    }

    $existing_attach_id = $existing_image->attachment_id;
    $attachment_exists = get_post($existing_attach_id);

    if (!$attachment_exists) {
        // Attachment no longer exists, delete the record and download again
        $wpdb->delete(
            $wpdb->prefix . 'wpezimport_images',
            array('id' => $existing_image->id),
            array('%d')
        );
        return array('mode' => 'download');
    }

    if (intval($attachment_exists->post_parent) === intval($post_id)) {
        // Already attached to this post
        if (isset($existing_attachment_ids[$existing_attach_id])) {
            unset($existing_attachment_ids[$existing_attach_id]);
        }
        return array('mode' => 'attached', 'attachment_id' => $existing_attach_id);
    }

    // Attached to a different post — reuse the file bytes if still on disk
    $attachment_path = get_attached_file($existing_attach_id);
    if ($attachment_path && file_exists($attachment_path)) {
        return array('mode' => 'copy', 'data' => file_get_contents($attachment_path), 'attachment_id' => $existing_attach_id);
    }

    return array('mode' => 'download');
}

function wpezimport_process_images($post_id, $image_urls, $post_title = '', $options = array(), $draft_id = 0, $xml_item = null) {
    // Default options
    $set_featured = isset($options['set_featured']) ? $options['set_featured'] : true;
    global $wpdb;

    $image_urls = wpezimport_resolve_image_patterns($image_urls, $xml_item, $draft_id);

    if (empty($post_id)) {
        return array(
            'attachments' => array(),
            'errors' => array('Invalid post ID')
        );
    }

    // Allowed image MIME types and max file size for download validation
    $allowed_image_mimes = array(
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
    );
    $max_image_size = apply_filters('wpezimport_max_image_size', 20 * 1024 * 1024); // 20 MB default

    // Get upload directory information
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['path'];
    // Make sure the directory exists
    if (!file_exists($import_dir)) {
        wp_mkdir_p($import_dir);
    }

    $attachments = array();
    $errors = array();
    $processed_urls = array();
    $image_statuses = array(); // Track status for each image URL

    // Get existing attachments for this post to track deletions
    $existing_attachments = get_posts(array(
        'post_parent' => $post_id,
        'post_type'   => 'attachment',
        'numberposts' => -1,
        'fields'      => 'ids'
    ));

    $existing_attachment_ids = array();
    foreach ($existing_attachments as $existing_id) {
        $existing_attachment_ids[$existing_id] = $existing_id;
    }

    // Check if we have any URLs to process
    if (empty($image_urls)) {
        return array(
            'attachments' => array(),
            'errors' => array('No image URLs provided'),
            'image_statuses' => array()
        );
    }

    // At this point, XPath expressions have already been evaluated (lines 736-776)
    // and $image_urls contains actual URLs, not XPath expressions
    // Split the URLs by newlines or commas/semicolons.
    // IMPORTANT: Do NOT split by spaces, because some image URLs can legitimately contain spaces
    // (which we later URL-encode to %20).
    $image_url_list = preg_split('/[\r\n,;]+/', $image_urls, -1, PREG_SPLIT_NO_EMPTY);

    error_log('Processing images - count: ' . count($image_url_list));

    // Sanitize post title for filename generation
    $base_filename = '';
    if (!empty($post_title)) {
        // Replace non-alphanumeric characters with dash
        $base_filename = preg_replace('/[^a-zA-Z0-9]+/', '-', $post_title);
        $base_filename = trim($base_filename, '-');
        $base_filename = strtolower($base_filename);
    }

    // Process each image URL
    foreach ($image_url_list as $index => $image_url) {
        $image_url = trim($image_url);
        if (empty($image_url)) {
            continue;
        }

        error_log('Processing image URL (original): ' . $image_url);

        // Normalize: decode HTML entities, then percent-encode (UrlEncoder handles
        // scheme/host/path/query/fragment including fallback encoding)
        list($original_url, $image_url) = wpezimport_normalize_image_url($image_url);
        error_log('Processing image URL (encoded): ' . $image_url);

        // Check if this image URL has already been downloaded in a previous import
        $cache = wpezimport_find_cached_image($image_url, $post_id, $existing_attachment_ids);

        if ($cache['mode'] === 'attached') {
            $existing_attach_id = $cache['attachment_id'];
            $attachments[] = $existing_attach_id;
            $processed_urls[] = $image_url;

            // Track as cached
            $image_statuses[] = array(
                'url' => $original_url,
                'encoded_url' => $image_url,
                'status' => 'cached',
                'message' => __('Already attached to this post', 'wpezimport'),
                'attachment_id' => $existing_attach_id
            );

            // Set as featured image if it's the first one and option is enabled
            if ($set_featured && $index === 0 && !has_post_thumbnail($post_id)) {
                set_post_thumbnail($post_id, $existing_attach_id);
            }

            continue;
        }

        if ($cache['mode'] === 'copy') {
            // Reuse bytes of an attachment belonging to a different post
            $image_data = $cache['data'];
            $response = null;
        }

        // If we don't have image data yet, download the image
        if (!isset($image_data)) {
            $dl = wpezimport_download_image($image_url, $allowed_image_mimes, $max_image_size);

            if (is_wp_error($dl)) {
                $error_msg = $dl->get_error_message();
                if ($dl->get_error_code() === 'empty_body') {
                    $errors[] = 'Empty image data from URL: ' . $image_url;
                } elseif (!in_array($dl->get_error_code(), array('bad_mime', 'too_large'), true)) {
                    $errors[] = 'Failed to download image: ' . $image_url . ' - ' . $error_msg;
                } else {
                    $errors[] = $error_msg . ' - ' . $image_url;
                }
                $image_statuses[] = array(
                    'url' => $original_url,
                    'encoded_url' => $image_url,
                    'status' => 'error',
                    'message' => $error_msg
                );
                continue;
            }

            $image_data    = $dl['data'];
            $response      = $dl['response'];
            $response_code = $dl['code'];

            // Track that we're fetching this image
            $current_image_status = array(
                'url' => $original_url,
                'encoded_url' => $image_url,
                'status' => 'fetched',
                'message' => 'HTTP ' . $response_code,
                'http_code' => $response_code
            );
        }

        // ── Determine file extension and validate MIME type ──
        $detection = wpezimport_detect_image_extension(isset($response) ? $response : null, $image_url, $image_data);
        $extension = $detection['extension'];
        $detected_mime = $detection['detected_mime'];

        // Final validation: reject if we couldn't determine a valid image extension
        if (empty($extension)) {
            $error_msg = sprintf(
                __('Rejected: not a recognized image type (detected: %s)', 'wpezimport'),
                $detected_mime ?: __('unknown', 'wpezimport')
            );
            $errors[] = $error_msg . ' - ' . $image_url;
            error_log('Image rejected (not an image): detected=' . $detected_mime . ' - ' . $image_url);
            $image_statuses[] = array(
                'url' => $original_url,
                'encoded_url' => $image_url,
                'status' => 'error',
                'message' => $error_msg
            );
            unset($image_data);
            continue;
        }

        // Create filename
        $filename = wpezimport_generate_image_filename($image_url, $extension, $base_filename, $index);

        // Create a unique filename to avoid overwrites
        $unique_filename = wp_unique_filename($import_dir, $filename);
        $file_path = $import_dir . '/' . $unique_filename;

        // Save the image file
        $result = file_put_contents($file_path, $image_data);
        if ($result === false) {
            $errors[] = 'Failed to save image: ' . $file_path;
            error_log('Failed to save image: ' . $file_path);
            $image_statuses[] = array(
                'url' => $original_url,
                'encoded_url' => $image_url,
                'status' => 'error',
                'message' => __('Failed to save file to disk', 'wpezimport')
            );
            continue;
        }

        // Clear image data variable for next iteration
        unset($image_data);

        // Prepare file data for attachment
        $file_type = wp_check_filetype($filename, null);
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert attachment into the database
        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
        if (is_wp_error($attach_id)) {
            $error_msg = $attach_id->get_error_message();
            $errors[] = 'Failed to create attachment: ' . $error_msg;
            error_log('Failed to create attachment: ' . $error_msg);
            $image_statuses[] = array(
                'url' => $original_url,
                'encoded_url' => $image_url,
                'status' => 'error',
                'message' => __('Failed to create attachment: ', 'wpezimport') . $error_msg
            );
            continue;
        }

        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Add to attachments array
        $attachments[] = $attach_id;

        // Set as featured image if this is the first image, post doesn't have a thumbnail, and option is enabled
        if ($set_featured && $index === 0 && !has_post_thumbnail($post_id)) {
            set_post_thumbnail($post_id, $attach_id);
        }

        // Store in our tracking table
        $wpdb->insert(
            $wpdb->prefix . 'wpezimport_images',
            array(
                'attachment_id' => $attach_id,
                'url' => $image_url,
                'filename' => $unique_filename
            ),
            array('%d', '%s', '%s')
        );

        $processed_urls[] = $image_url;
        error_log('Image successfully attached. ID: ' . $attach_id);

        // Add status for this successfully processed image
        if (isset($current_image_status)) {
            $current_image_status['attachment_id'] = $attach_id;
            $image_statuses[] = $current_image_status;
            unset($current_image_status);
        } else {
            // Image was copied from existing cache
            $image_statuses[] = array(
                'url' => $original_url,
                'encoded_url' => $image_url,
                'status' => 'cached',
                'message' => __('Copied from existing attachment', 'wpezimport'),
                'attachment_id' => $attach_id
            );
        }
    }

    // Clean up any attachments that were removed from the import
    if (!empty($existing_attachment_ids)) {
        foreach ($existing_attachment_ids as $attach_id) {
            // Delete the attachment
            wp_delete_attachment($attach_id, true);

            // Remove from our tracking table
            $wpdb->delete(
                $wpdb->prefix . 'wpezimport_images',
                array('attachment_id' => $attach_id),
                array('%d')
            );

            error_log('Deleted removed attachment ID: ' . $attach_id);
        }
    }

    return array(
        'attachments' => $attachments,
        'errors' => $errors,
        'processed_urls' => $processed_urls,
        'image_statuses' => $image_statuses
    );
}

/**
 * Clean up session data
 */
function wpezimport_clean_session() {
    check_ajax_referer('wpezimport_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
    }

    // Clean up draft if draft_id is provided
    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    if ($draft_id > 0) {
        wpezimport_delete_draft($draft_id);
    }
    wp_send_json_success(array('message' => 'Import data cleaned up.'));
}
add_action('wp_ajax_wpezimport_clean_session', 'wpezimport_clean_session');

/**
 * Get the appropriate PhpSpreadsheet reader for the file extension
 *
 * @param string $ext File extension
 * @return \PhpOffice\PhpSpreadsheet\Reader\IReader Reader object
 */
function wpezimport_get_spreadsheet_reader($ext, $delimiter = ',', $enclosure = '"') {
    switch (strtolower($ext)) {
        case 'xlsx':
            return new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        case 'xls':
            return new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        case 'csv':
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();

            // Handle tab delimiter
            if ($delimiter === 'tab') {
                $delimiter = "\t";
            }

            $reader->setDelimiter($delimiter);
            $reader->setEnclosure($enclosure);
            $reader->setSheetIndex(0);
            return $reader;
        default:
            throw new Exception('Unsupported file format: ' . $ext);
    }
}
