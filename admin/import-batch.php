<?php
/**
 * Unified Import Batch Processing
 *
 * Provides a shared, resumable, time-aware import processor used by both
 * the web UI (via AJAX) and external automation (cron/webhook).
 *
 * Each XML item is fully processed (post + images) before moving to the next.
 * Progress is tracked in wpezimport_saved_imports so processing can resume
 * after a PHP timeout or between scheduled runs.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Wpezimport\Wpezimport\UnitConverter;

// Ensure helper functions are available
if (!function_exists('wpezimport_process_images')) {
    require_once plugin_dir_path(__FILE__) . 'process-import.php';
}
if (!function_exists('wpezimport_get_import')) {
    require_once plugin_dir_path(__FILE__) . 'import-processor.php';
}

/**
 * Update import run-state columns in wpezimport_saved_imports.
 *
 * @param int   $import_id
 * @param array $data Column => value pairs (cron_status, cron_last_item, etc.)
 */
function wpezimport_update_import_state($import_id, $data) {
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'wpezimport_saved_imports',
        $data,
        array('id' => $import_id)
    );
}

/**
 * Append a log line to the import's cron_log column.
 *
 * @param int    $import_id
 * @param string $message
 */
function wpezimport_import_log($import_id, $message) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';
    $row = $wpdb->get_row($wpdb->prepare("SELECT cron_log FROM $table WHERE id = %d", $import_id));
    $log = ($row && !empty($row->cron_log)) ? maybe_unserialize($row->cron_log) : array();
    if (!is_array($log)) $log = array();
    $log[] = '[' . current_time('mysql') . '] ' . $message;
    $wpdb->update($table, array('cron_log' => serialize($log)), array('id' => $import_id));
}

// ============================================================
// TRIGGER — fetch URL, reset progress, set status
// ============================================================

/**
 * Trigger (start/restart) an import run.
 *
 * For URL imports: fetches the XML and saves it to a temp file so the batch
 * processor doesn't have to re-download on every call.
 *
 * For file imports: the file is assumed to already exist at file_path (uploaded
 * via the confirm page).
 *
 * @param int    $import_id     Saved-import ID.
 * @param string $file_override Optional local file path (used for file-type imports
 *                               where the confirm page uploaded a new file).
 * @return array  {success: bool, message: string}
 */
function wpezimport_trigger_import($import_id, $file_override = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';

    $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $import_id));
    if (!$import) {
        return array('success' => false, 'message' => __('Import not found.', 'ezimport'));
    }

    // Prevent re-triggering while actively processing
    $lock_timeout = (int) apply_filters('wpezimport_processing_lock_timeout', 119);
    if ($import->cron_status === 'processing') {
        $started = strtotime($import->cron_started_at);
        if ($started && (time() - $started) < $lock_timeout) {
            return array('success' => false, 'message' => __('Import is currently being processed.', 'ezimport'));
        }
        // Stale lock — allow re-trigger
    }

    $run_file = '';

    // ── URL imports: download XML to a temp file ──
    if ($import->type === 'url' && !empty($import->url)) {
        // Conditional GET headers for change detection
        $request_headers = array();
        if (!empty($import->cron_etag)) {
            $request_headers['If-None-Match'] = $import->cron_etag;
        }
        if (!empty($import->cron_last_modified)) {
            $request_headers['If-Modified-Since'] = $import->cron_last_modified;
        }

        $response = wp_remote_get($import->url, array(
            'timeout'   => 60,
            'sslverify' => apply_filters('wpezimport_sslverify', true),
            'headers'   => $request_headers,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => __('Failed to fetch URL: ', 'ezimport') . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);

        // 304 Not Modified — content hasn't changed
        if ($http_code === 304) {
            if ($import->cron_status === 'idle' && $import->cron_phase === 'complete') {
                return array('success' => true, 'message' => __('Content has not changed since last import. Nothing to do.', 'ezimport'), 'status' => 'idle');
            }
            // Was incomplete — re-use existing temp file if present, or re-fetch without conditions
            $existing_file = get_transient('wpezimport_run_file_' . $import_id);
            if ($existing_file && file_exists($existing_file)) {
                $run_file = $existing_file;
            } else {
                // Force full fetch
                $response = wp_remote_get($import->url, array('timeout' => 60, 'sslverify' => apply_filters('wpezimport_sslverify', true)));
                if (is_wp_error($response)) {
                    return array('success' => false, 'message' => __('Failed to fetch URL: ', 'ezimport') . $response->get_error_message());
                }
            }
        }

        if (empty($run_file)) {
            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                return array('success' => false, 'message' => __('Empty response from URL.', 'ezimport'));
            }

            // Save to temp file
            $upload_dir = wp_upload_dir();
            $dir = $upload_dir['basedir'] . '/wpezimport';
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
            $run_file = $dir . '/run_' . $import_id . '_' . time() . '.xml';
            if (file_put_contents($run_file, $body) === false) {
                return array('success' => false, 'message' => __('Failed to save downloaded XML.', 'ezimport'));
            }

            // Cache headers for next trigger
            $etag = wp_remote_retrieve_header($response, 'etag');
            $last_modified = wp_remote_retrieve_header($response, 'last-modified');
            $content_hash = md5($body);
        }

        // Clean up any previous temp file
        $prev_file = get_transient('wpezimport_run_file_' . $import_id);
        if ($prev_file && $prev_file !== $run_file && file_exists($prev_file)) {
            wp_delete_file($prev_file);
        }
        set_transient('wpezimport_run_file_' . $import_id, $run_file, 7200);
    }

    // ── File imports: use override or existing file_path ──
    if ($import->type === 'file') {
        if (!empty($file_override) && file_exists($file_override)) {
            $run_file = $file_override;
            set_transient('wpezimport_run_file_' . $import_id, $run_file, 7200);
        } elseif (!empty($import->file_path) && file_exists($import->file_path)) {
            $run_file = $import->file_path;
            set_transient('wpezimport_run_file_' . $import_id, $run_file, 7200);
        } else {
            return array('success' => false, 'message' => __('No file available. Please upload a file first.', 'ezimport'));
        }
    }

    // ── Reset progress ──
    $update = array(
        'cron_status'      => 'triggered',
        'cron_phase'       => 'items',
        'cron_last_item'   => 0,
        'cron_total_items' => 0,
        'cron_started_at'  => current_time('mysql'),
        'cron_log'         => serialize(array()),
        'cron_created'     => 0,
        'cron_updated'     => 0,
        'cron_errors'      => 0,
    );
    if (isset($etag))         $update['cron_etag']          = $etag ?: '';
    if (isset($last_modified)) $update['cron_last_modified'] = $last_modified ?: '';
    if (isset($content_hash))  $update['cron_content_hash']  = $content_hash;

    wpezimport_update_import_state($import_id, $update);
    wpezimport_import_log($import_id, 'Triggered. File: ' . basename($run_file));

    return array('success' => true, 'message' => __('Import triggered.', 'ezimport'), 'status' => 'triggered');
}

// ============================================================
// BATCH PROCESSOR — process items, resumable, time-aware
// ============================================================

/**
 * Process a batch of import items.
 *
 * Resumes from the last successfully processed item and runs until either all
 * items are complete or the time limit is reached. Each item is fully resolved
 * (post create/update + image downloads) before advancing.
 *
 * @param int $import_id  Saved-import ID.
 * @param int $time_limit Maximum seconds to run (0 = no limit, not recommended).
 * @return array Result with status, counters, and resolved items for this batch.
 */
function wpezimport_process_import_batch($import_id, $time_limit = 55) {
    global $wpdb;
    $table   = $wpdb->prefix . 'wpezimport_saved_imports';
    $start_time = time();

    // Batch pauses itself via $time_limit; give PHP some headroom for the current item
    if ($time_limit > 0 && function_exists('set_time_limit')) {
        @set_time_limit($time_limit + 60);
    }

    $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $import_id));
    if (!$import) {
        return array('success' => false, 'status' => 'error', 'message' => __('Import not found.', 'ezimport'));
    }

    if (!in_array($import->cron_status, array('triggered', 'processing'), true)) {
        return array(
            'success' => true,
            'status'  => $import->cron_status,
            'message' => __('Import is not in a runnable state.', 'ezimport'),
            'phase'   => $import->cron_phase,
        );
    }

    // Prevent concurrent processing
    $lock_timeout = (int) apply_filters('wpezimport_processing_lock_timeout', 119);
    if ($import->cron_status === 'processing') {
        $started = strtotime($import->cron_started_at);
        if ($started && (time() - $started) < $lock_timeout) {
            return array('success' => false, 'status' => 'processing', 'message' => __('Import is already being processed.', 'ezimport'));
        }
    }

    // Mark as processing
    wpezimport_update_import_state($import_id, array(
        'cron_status'     => 'processing',
        'cron_started_at' => current_time('mysql'),
    ));

    // ── Load options ──
    $options  = !empty($import->options) ? maybe_unserialize($import->options) : array();
    $mappings = isset($options['mappings']) ? $options['mappings'] : array();

    $post_type   = isset($options['default_post_type'])   ? $options['default_post_type']   : 'post';
    $post_status = isset($options['default_post_status']) ? $options['default_post_status'] : 'draft';
    $post_author = isset($options['default_author'])      ? (int) $options['default_author'] : get_current_user_id();

    $unique_identifier         = isset($options['unique_identifier_source'])  ? $options['unique_identifier_source']  : '';
    $set_first_image_featured  = isset($options['set_first_image_featured'])  ? ($options['set_first_image_featured'] === 'yes')  : true;
    $draft_if_no_images        = isset($options['draft_if_no_images'])        ? ($options['draft_if_no_images'] === 'yes')        : false;
    $categories_match_existing = isset($options['categories_match_existing']) ? ($options['categories_match_existing'] === 'yes') : false;
    $tags_match_existing       = isset($options['tags_match_existing'])       ? ($options['tags_match_existing'] === 'yes')       : false;
    $update_flags              = isset($options['update_flags'])              ? $options['update_flags']                             : array();
    $mapping_units             = isset($options['mapping_units']) && is_array($options['mapping_units']) ? $options['mapping_units'] : array();


    // ── Locate the data file ──
    $data_file = get_transient('wpezimport_run_file_' . $import_id);
    if (!$data_file || !file_exists($data_file)) {
        // Fallback for file imports
        if (!empty($import->file_path) && file_exists($import->file_path)) {
            $data_file = $import->file_path;
        } elseif ($import->type === 'url' && !empty($import->url)) {
            // Last resort: re-fetch
            $resp = wp_remote_get($import->url, array('timeout' => 60, 'sslverify' => apply_filters('wpezimport_sslverify', true)));
            if (!is_wp_error($resp) && !empty(wp_remote_retrieve_body($resp))) {
                $dir = wp_upload_dir()['basedir'] . '/wpezimport';
                if (!file_exists($dir)) wp_mkdir_p($dir);
                $data_file = $dir . '/run_' . $import_id . '_' . time() . '.xml';
                file_put_contents($data_file, wp_remote_retrieve_body($resp));
                set_transient('wpezimport_run_file_' . $import_id, $data_file, 7200);
            }
        }
        if (!$data_file || !file_exists($data_file)) {
            wpezimport_update_import_state($import_id, array('cron_status' => 'triggered'));
            return array('success' => false, 'status' => 'error', 'message' => __('Data file not found. Please trigger the import again.', 'ezimport'));
        }
    }

    // ── Determine format: XML or column-based (CSV/XLS/XLSX) ──
    $is_xml = !empty($import->is_xml) || !empty($options['is_xml']);

    $items = wpezimport_batch_load_items($data_file, $is_xml, $options);

    if (is_wp_error($items)) {
        wpezimport_update_import_state($import_id, array('cron_status' => 'triggered'));
        return array('success' => false, 'status' => 'error', 'message' => $items->get_error_message());
    }
    if (empty($items)) {
        wpezimport_update_import_state($import_id, array(
            'cron_status' => 'idle',
            'cron_phase'  => 'complete',
        ));
        return array('success' => true, 'status' => 'complete', 'message' => __('No items found.', 'ezimport'), 'total' => 0);
    }

    $total_items = count($items);
    $last_item   = (int) $import->cron_last_item;
    $created     = (int) $import->cron_created;
    $updated     = (int) $import->cron_updated;
    $errors_cnt  = (int) $import->cron_errors;
    $skipped     = 0;
    $no_change   = 0;
    $processed   = 0;
    $resolved_items = array();

    wpezimport_update_import_state($import_id, array('cron_total_items' => $total_items));

    // ── Main processing loop ──
    for ($i = $last_item; $i < $total_items; $i++) {

        // Time check BEFORE processing an item
        if ($time_limit > 0 && (time() - $start_time) >= $time_limit) {
            wpezimport_update_import_state($import_id, array(
                'cron_status'    => 'triggered',
                'cron_last_item' => $i,
                'cron_created'   => $created,
                'cron_updated'   => $updated,
                'cron_errors'    => $errors_cnt,
            ));
            wpezimport_import_log($import_id, sprintf('Paused at item %d/%d (time limit). Created: %d, Updated: %d, Errors: %d.', $i, $total_items, $created, $updated, $errors_cnt));

            return array(
                'success'  => true,
                'status'   => 'processing',
                /* translators: 1: current item number, 2: total number of items. */
                'message'  => sprintf(__('Paused at item %1$d of %2$d.', 'ezimport'), $i, $total_items),
                'last_item'  => $i,
                'total'      => $total_items,
                'created'    => $created,
                'updated'    => $updated,
                'skipped'    => $skipped,
                'no_change'  => $no_change,
                'errors'     => $errors_cnt,
                'processed_this_batch' => $processed,
                'resolved_items'       => $resolved_items,
            );
        }

        $item       = $items[$i];
        $item_index = $i + 1;
        $item_str   = $is_xml ? $item->asXML() : json_encode($item);

        // ── Extract field values ──
        $built = wpezimport_batch_build_item_data($item, $mappings, $is_xml, array(
            'post_type'   => $post_type,
            'post_status' => $post_status,
            'post_author' => $post_author,
        ), $mapping_units);

        $post_data       = $built['post_data'];
        $meta_data       = $built['meta_data'];
        $image_urls_list = $built['image_urls'];
        $unique_value    = null;

        $item_resolution = array(
            'item_index'    => $item_index,
            'fields'        => $built['fields'],
            'resolution'    => array(),
            'id_resolution' => array(),
        );

        // Default title
        $item_title = !empty($post_data['post_title']) ? $post_data['post_title'] : (__('Imported Item', 'ezimport') . ' ' . gmdate('Y-m-d H:i:s'));
        if (empty($post_data['post_title'])) {
            $post_data['post_title'] = $item_title;
        }

        // ── Unique identifier ──
        if (!empty($unique_identifier)) {
            $unique_value = $is_xml
                ? wpezimport_batch_extract_unique_value($item, $unique_identifier, $mappings)
                : wpezimport_batch_extract_unique_value_csv($item, $unique_identifier, $mappings);
        }

        $item_resolution['id_resolution'] = array(
            'xpath'           => $unique_identifier,
            'extracted_value' => $unique_value,
            'status'          => empty($unique_identifier) ? 'not_configured' : (empty($unique_value) ? 'extraction_failed' : 'value_extracted'),
            'duplicate_found' => false,
            'existing_post_id' => null,
        );

        // ── Skip only when unique_identifier IS set but resolved to empty ──
        if (!empty($unique_identifier) && empty($unique_value)) {
            $skipped++;
            $item_resolution['resolution'] = array(
                'status'  => 'skipped',
                /* translators: %s: the configured unique identifier. */
                'message' => sprintf(__('Unique identifier "%s" resolved to empty value', 'ezimport'), $unique_identifier),
                'title'   => $item_title,
            );
            $resolved_items[] = $item_resolution;
            $processed++;
            wpezimport_update_import_state($import_id, array('cron_last_item' => $i + 1));
            continue;
        }

        // ── Lookup existing post (only when unique_identifier is configured) ──
        $existing_post_id = 0;
        if (!empty($unique_identifier) && !empty($unique_value)) {
            $existing_posts = get_posts(array(
                'post_type'   => $post_type,
                'meta_key'    => '_wpezimport_' . $unique_identifier,
                'meta_value'  => $unique_value,
                'numberposts' => 1,
                'post_status' => 'any',
                'fields'      => 'ids',
            ));
            if (!empty($existing_posts)) {
                $existing_post_id = $existing_posts[0];
                $item_resolution['id_resolution']['duplicate_found']  = true;
                $item_resolution['id_resolution']['existing_post_id'] = $existing_post_id;
                $item_resolution['id_resolution']['status']           = 'duplicate_found';
            } else {
                $item_resolution['id_resolution']['status'] = 'no_duplicate';
            }
        }

        $post_id = 0;

        // ── UPDATE existing post ──
        if ($existing_post_id > 0) {
            $diff = wpezimport_batch_diff_existing(
                get_post($existing_post_id),
                $existing_post_id,
                $item_resolution['fields'],
                $post_data,
                $meta_data,
                $update_flags
            );

            $item_resolution['fields'] = $diff['fields'];

            if (!$diff['has_changes']) {
                $no_change++;
                $post_id = $existing_post_id;
                $item_resolution['resolution'] = array(
                    'status'    => 'no_change',
                    'post_id'   => $existing_post_id,
                    'unique_id' => $unique_value,
                    'title'     => $item_title,
                );
            } else {
                $result_id = wp_update_post($diff['post'], true);

                if (!is_wp_error($result_id)) {
                    $post_id = $result_id;
                    $updated++;
                    foreach ($diff['meta'] as $mk => $mv) {
                        update_post_meta($post_id, $mk, $mv);
                    }
                    update_post_meta($post_id, '_wpezimport_' . $unique_identifier, $unique_value);
                    $item_resolution['resolution'] = array(
                        'status'    => 'updated',
                        'post_id'   => $post_id,
                        'unique_id' => $unique_value,
                        'title'     => $item_title,
                    );
                } else {
                    $errors_cnt++;
                    $item_resolution['resolution'] = array(
                        'status'  => 'error',
                        'message' => $result_id->get_error_message(),
                        'title'   => $item_title,
                    );
                }
            }

        // ── CREATE new post ──
        } else {
            $result_id = wp_insert_post($post_data, true);

            if (!is_wp_error($result_id)) {
                $post_id = $result_id;
                $created++;

                foreach ($meta_data as $mk => $mv) {
                    add_post_meta($post_id, $mk, $mv);
                }
                if (!empty($unique_value)) {
                    add_post_meta($post_id, '_wpezimport_' . $unique_identifier, $unique_value);
                }

                $item_resolution['resolution'] = array(
                    'status'    => 'created',
                    'post_id'   => $post_id,
                    'unique_id' => $unique_value,
                    'title'     => $item_title,
                );
            } else {
                $errors_cnt++;
                $item_resolution['resolution'] = array(
                    'status'  => 'error',
                    'message' => $result_id->get_error_message(),
                    'title'   => $item_title,
                );
            }
        }

        // ── Taxonomy: categories & tags (for created AND updated posts) ──
        if ($post_id > 0) {
            if ($is_xml) {
                wpezimport_batch_process_taxonomies($post_id, $item, $mappings, $post_type, $categories_match_existing, $tags_match_existing);
            } else {
                wpezimport_batch_process_taxonomies_csv($post_id, $item, $mappings, $post_type, $categories_match_existing, $tags_match_existing);
            }
        }

        // ── Images: process immediately for this item ──
        if ($post_id > 0) {
            // Collect image_gallery URLs
            if (isset($mappings['image_gallery']) && !empty($mappings['image_gallery'])) {
                if ($is_xml) {
                    if (function_exists('wpezimport_extract_image_urls_from_xpath')) {
                        $extracted = wpezimport_extract_image_urls_from_xpath($item, $mappings['image_gallery']);
                        if (!empty($extracted['urls'])) {
                            $image_urls_list = array_merge($image_urls_list, $extracted['urls']);
                        }
                    }
                } else {
                    $gallery_val = wpezimport_batch_extract_value_csv($item, $mappings['image_gallery']);
                    if (!empty($gallery_val)) {
                        $urls = preg_split('/[\s,;|]+/', $gallery_val);
                        $image_urls_list = array_merge($image_urls_list, $urls);
                    }
                }
            }

            // Deduplicate and clean
            $image_urls_list = array_values(array_unique(array_filter(array_map(function ($u) {
                return trim((string) $u);
            }, $image_urls_list), function ($u) {
                return $u !== '';
            })));

            if (!empty($image_urls_list)) {
                $img_result = wpezimport_process_images(
                    $post_id,
                    implode("\n", $image_urls_list),
                    $item_title,
                    array('set_featured' => $set_first_image_featured),
                    0,
                    $item_str
                );

                if (isset($img_result['image_statuses'])) {
                    $item_resolution['image_statuses'] = $img_result['image_statuses'];
                }

                if ($draft_if_no_images && empty($img_result['attachments'])) {
                    wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
                }
            }
        }

        $resolved_items[] = $item_resolution;
        $processed++;

        // Persist progress after every item
        wpezimport_update_import_state($import_id, array(
            'cron_last_item' => $i + 1,
            'cron_created'   => $created,
            'cron_updated'   => $updated,
            'cron_errors'    => $errors_cnt,
        ));
    }

    // ── All items processed — mark complete ──
    wpezimport_update_import_state($import_id, array(
        'cron_status'    => 'idle',
        'cron_phase'     => 'complete',
        'cron_last_item' => $total_items,
        'cron_created'   => $created,
        'cron_updated'   => $updated,
        'cron_errors'    => $errors_cnt,
        'last_run'       => current_time('mysql'),
    ));

    // Clean up temp file
    $tmp = get_transient('wpezimport_run_file_' . $import_id);
    if ($tmp && file_exists($tmp)) {
        wp_delete_file($tmp);
    }
    delete_transient('wpezimport_run_file_' . $import_id);

    // Save statistics
    if (function_exists('wpezimport_save_statistics')) {
        wpezimport_save_statistics($import_id, array(
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors_cnt,
        ));
    }

    wpezimport_import_log($import_id, sprintf('Complete. Items: %d, Created: %d, Updated: %d, NoChange: %d, Skipped: %d, Errors: %d.', $total_items, $created, $updated, $no_change, $skipped, $errors_cnt));

    return array(
        'success'    => true,
        'status'     => 'complete',
        /* translators: %d: number of processed items. */
        'message'    => sprintf(__('Import complete. %d items processed.', 'ezimport'), $total_items),
        'last_item'  => $total_items,
        'total'      => $total_items,
        'created'    => $created,
        'updated'    => $updated,
        'skipped'    => $skipped,
        'no_change'  => $no_change,
        'errors'     => $errors_cnt,
        'processed_this_batch' => $processed,
        'resolved_items'       => $resolved_items,
    );
}

// ============================================================
// Load import items from a data file (XML feed or CSV/XLS/XLSX)
// ============================================================

/**
 * Load the list of importable items from a data file.
 *
 * XML files are selected via $options['selected_xpath'] (default '//item');
 * column-based files are converted to associative arrays keyed by header.
 *
 * @param string $data_file Absolute path to the data file
 * @param bool   $is_xml    Whether the file is an XML feed
 * @param array  $options   Saved import options
 * @return array|WP_Error   List of items (SimpleXMLElement or assoc arrays), or WP_Error
 */
function wpezimport_batch_load_items($data_file, $is_xml, array $options = array()) {
    if ($is_xml) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($data_file);
        if ($xml === false) {
            $err = '';
            foreach (libxml_get_errors() as $e) { $err .= $e->message . ' '; }
            libxml_clear_errors();
            return new WP_Error('xml_parse', __('XML parse error: ', 'ezimport') . trim($err));
        }

        $xml_path = isset($options['selected_xpath']) && !empty($options['selected_xpath']) ? $options['selected_xpath'] : '//item';
        $items = @$xml->xpath($xml_path);
        return ($items === false) ? array() : $items;
    }

    // ── CSV / XLS / XLSX ──
    require_once plugin_dir_path(dirname(__FILE__)) . 'vendor/autoload.php';
    if (!function_exists('wpezimport_get_spreadsheet_reader')) {
        require_once plugin_dir_path(__FILE__) . 'process-import.php';
    }

    $file_ext      = strtolower(pathinfo($data_file, PATHINFO_EXTENSION));
    $csv_delimiter = isset($options['csv_delimiter']) ? $options['csv_delimiter'] : ',';
    $csv_enclosure = isset($options['csv_enclosure']) ? $options['csv_enclosure'] : '"';

    try {
        $reader      = wpezimport_get_spreadsheet_reader($file_ext, $csv_delimiter, $csv_enclosure);
        $spreadsheet = $reader->load($data_file);
        $rows        = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    } catch (Exception $e) {
        return new WP_Error('file_parse', __('File parse error: ', 'ezimport') . $e->getMessage());
    }

    if (empty($rows)) {
        return array();
    }

    // Build header map from first row
    $header_map = array();
    foreach ($rows[0] as $idx => $h) {
        if (!empty($h)) $header_map[$idx] = $h;
    }

    $skip_first = isset($options['skip_first_row']) ? $options['skip_first_row'] : true;
    $start_row  = $skip_first ? 1 : 0;

    // Convert rows to associative arrays
    $items = array();
    for ($r = $start_row; $r < count($rows); $r++) {
        $row_data = array();
        foreach ($rows[$r] as $idx => $val) {
            if (isset($header_map[$idx])) {
                $row_data[$header_map[$idx]] = $val;
            }
        }
        $items[] = $row_data;
    }

    return $items;
}

// ============================================================
// Build post/meta/image data for one item from the field mappings
// ============================================================

/**
 * Extract mapped field values for a single item and build the WP write-sets.
 *
 * @param SimpleXMLElement|array $item          XML item or CSV assoc row
 * @param array                  $mappings      field_key => mapping expression
 * @param bool                   $is_xml        Whether the item is an XML element
 * @param array                  $post_defaults Defaults for post_type/post_status/post_author
 * @param array                  $mapping_units Unit conversions for _weight/_length/_width/_height
 * @return array {
 *     @type array $post_data  wp_insert_post()-compatible fields
 *     @type array $meta_data  meta key => value (units converted, _price/_stock_status derived)
 *     @type array $image_urls Collected image_url_* values
 *     @type array $fields     field_key => ['xpath' => expr, 'value' => extracted]
 * }
 */
function wpezimport_batch_build_item_data($item, array $mappings, $is_xml, array $post_defaults, array $mapping_units = array()) {
    $post_data       = $post_defaults;
    $meta_data       = array();
    $image_urls      = array();
    $fields          = array();

    foreach ($mappings as $field_key => $mapping_expr) {
        if (empty($mapping_expr)) continue;

        $value = $is_xml
            ? wpezimport_batch_extract_value($item, $mapping_expr)
            : wpezimport_batch_extract_value_csv($item, $mapping_expr);

        $fields[$field_key] = array(
            'xpath' => $mapping_expr,
            'value' => $value,
        );

        // Collect image URLs
        if (strpos($field_key, 'image_url_') === 0) {
            $v = trim($value);
            if ($v !== '') $image_urls[] = $v;
            continue;
        }
        if ($field_key === 'image_gallery') continue;

        // Map to WP fields
        switch ($field_key) {
            case 'post_title':
            case 'post_content':
            case 'post_excerpt':
            case 'post_status':
            case 'post_date':
                $post_data[$field_key] = $value;
                break;
            case 'post_name':
                $post_data['post_name'] = sanitize_title($value);
                break;
            case 'post_categories':
            case 'post_tags':
            case 'post_brand':
                // Handled by wpezimport_batch_process_taxonomies()
                break;
            default:
                $mk = (strpos($field_key, 'custom_field_') === 0) ? substr($field_key, 13) : $field_key;
                // Apply unit conversions (same rules as DataExtractor::preparePostData)
                if ($mk === '_weight' && $value !== '' && $value !== null) {
                    $value = UnitConverter::convertWeight($value, isset($mapping_units['_weight']) ? $mapping_units['_weight'] : 'kg');
                    $fields[$field_key]['value'] = $value;
                } elseif (in_array($mk, array('_length', '_width', '_height'), true) && $value !== '' && $value !== null) {
                    $unit = isset($mapping_units[$mk]) ? $mapping_units[$mk] : (isset($mapping_units['_dimensions']) ? $mapping_units['_dimensions'] : 'm');
                    $value = UnitConverter::convertDimension($value, $unit);
                    $fields[$field_key]['value'] = $value;
                }
                $meta_data[$mk] = $value;
                break;
        }
    }

    // Auto stock status
    if (isset($meta_data['_stock_status']) && $meta_data['_stock_status'] === 'auto') {
        $qty = isset($meta_data['_stock']) ? floatval($meta_data['_stock']) : 0;
        $meta_data['_stock_status'] = ($qty > 0) ? 'instock' : 'outofstock';
    }

    // Auto _price: WooCommerce reads _price for display/sorting
    if (isset($meta_data['_regular_price']) || isset($meta_data['_sale_price'])) {
        $sale    = isset($meta_data['_sale_price']) ? $meta_data['_sale_price'] : '';
        $regular = isset($meta_data['_regular_price']) ? $meta_data['_regular_price'] : '';
        $meta_data['_price'] = ($sale !== '') ? $sale : $regular;
    }

    return array(
        'post_data'  => $post_data,
        'meta_data'  => $meta_data,
        'image_urls' => $image_urls,
        'fields'     => $fields,
    );
}

// ============================================================
// Diff mapped data against an existing post, respecting update flags
// ============================================================

/**
 * Compare mapped values with an existing post and produce the filtered
 * write-sets for wp_update_post() / update_post_meta().
 *
 * A field is updated when its value changed AND (no update flags are defined
 * OR the flag for this field is '1'). Taxonomy and image fields are skipped.
 *
 * @param object $post         The existing post (WP_Post-like object)
 * @param int    $post_id      Existing post ID
 * @param array  $fields       field_key => ['xpath' => expr, 'value' => new value]
 * @param array  $post_data    Built post fields from wpezimport_batch_build_item_data()
 * @param array  $meta_data    Built meta fields from wpezimport_batch_build_item_data()
 * @param array  $update_flags field_key => '1'|'0'
 * @return array {
 *     @type array $fields      Input fields enriched with old_value/changed/update_allowed
 *     @type array $post        wp_update_post()-compatible data (contains 'ID')
 *     @type array $meta        meta key => new value, only changed + allowed
 *     @type bool  $has_changes Whether anything should be written
 * }
 */
function wpezimport_batch_diff_existing($post, $post_id, array $fields, array $post_data, array $meta_data, array $update_flags = array()) {
    $update_post_data = array('ID' => $post_id);
    $update_meta_data = array();
    $has_changes = false;

    foreach ($fields as $fk => $fd) {
        if (strpos($fk, 'image_url_') === 0 || $fk === 'image_gallery') continue;
        if ($fk === 'post_categories' || $fk === 'post_tags' || $fk === 'post_brand') continue;

        $new_val = $fd['value'];
        $allowed = empty($update_flags) || (isset($update_flags[$fk]) && $update_flags[$fk] === '1');

        switch ($fk) {
            case 'post_title':   $old_val = $post->post_title;   break;
            case 'post_content': $old_val = $post->post_content; break;
            case 'post_excerpt': $old_val = $post->post_excerpt; break;
            case 'post_name':    $old_val = $post->post_name;    break;
            case 'post_status':  $old_val = $post->post_status;  break;
            case 'post_date':    $old_val = $post->post_date;    break;
            default:
                $mk = (strpos($fk, 'custom_field_') === 0) ? substr($fk, 13) : $fk;
                $old_val = get_post_meta($post_id, $mk, true);
                break;
        }

        $changed = ((string) $old_val !== (string) $new_val);
        $fields[$fk]['old_value'] = $old_val;
        $fields[$fk]['changed']   = $changed;
        $fields[$fk]['update_allowed'] = $allowed;

        if ($changed && $allowed) {
            $has_changes = true;
            if (isset($post_data[$fk])) {
                $update_post_data[$fk] = $post_data[$fk];
            } elseif (isset($meta_data[$fk])) {
                $update_meta_data[$fk] = $meta_data[$fk];
            } else {
                $mk = (strpos($fk, 'custom_field_') === 0) ? substr($fk, 13) : $fk;
                if (isset($meta_data[$mk])) {
                    $update_meta_data[$mk] = $meta_data[$mk];
                }
            }
        }
    }

    return array(
        'fields'      => $fields,
        'post'        => $update_post_data,
        'meta'        => $update_meta_data,
        'has_changes' => $has_changes,
    );
}

// ============================================================
// Helper: extract a single field value from an XML item
// ============================================================
function wpezimport_batch_extract_value($item, $xpath) {
    // Patterns may embed multiple {xpath} tokens — substitute all of them
    if (strpos($xpath, '{') !== false) {
        return preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($item) {
            try {
                $r = @$item->xpath($m[1]);
                if ($r !== false && !empty($r)) return (string) $r[0];
            } catch (Exception $e) { /* skip */ }
            return '';
        }, $xpath);
    }

    $value = '';
    if (strpos($xpath, '/') !== false) {
        try {
            $r = @$item->xpath($xpath);
            if ($r !== false && !empty($r)) $value = (string) $r[0];
        } catch (Exception $e) { /* skip */ }
    } else {
        if (isset($item->$xpath)) {
            $value = (string) $item->$xpath;
        }
    }

    return $value;
}

// ============================================================
// Helper: extract unique identifier value
// ============================================================
function wpezimport_batch_extract_unique_value($item, $unique_identifier, $mappings) {
    $value = null;

    // 1. Try as XPath pattern (e.g. {id[1]})
    if (preg_match('/\{(.+?)\}/', $unique_identifier, $m)) {
        try {
            $r = @$item->xpath($m[1]);
            if ($r !== false && !empty($r)) $value = (string) $r[0];
        } catch (Exception $e) { /* skip */ }
    }

    // 2. Try as mapping field key
    if (empty($value) && isset($mappings[$unique_identifier]) && !empty($mappings[$unique_identifier])) {
        $value = wpezimport_batch_extract_value($item, $mappings[$unique_identifier]);
    }

    return $value;
}

// ============================================================
// Helper: process categories and tags for a post
// ============================================================
function wpezimport_batch_process_taxonomies($post_id, $item, $mappings, $post_type, $categories_match, $tags_match) {
    if (!function_exists('wpezimport_process_taxonomy_terms')) {
        require_once plugin_dir_path(__FILE__) . 'process-import.php';
    }

    // Categories
    if (isset($mappings['post_categories']) && !empty($mappings['post_categories'])) {
        $names = array();
        $exprs = preg_split('/[\s\n]+/', $mappings['post_categories']);
        foreach ($exprs as $expr) {
            if (empty(trim($expr))) continue;
            if (preg_match('/\{(.+?)\}/', $expr, $m)) $expr = $m[1];
            try {
                $r = @$item->xpath($expr);
                if ($r !== false && !empty($r)) {
                    foreach ($r as $node) {
                        $n = trim((string) $node);
                        if ($n !== '') $names[] = $n;
                    }
                }
            } catch (Exception $e) { /* skip */ }
        }
        if (!empty($names)) {
            $tax = ($post_type === 'product') ? 'product_cat' : 'category';
            wpezimport_process_taxonomy_terms($post_id, implode(',', $names), $tax, $categories_match);
        }
    }

    // Tags
    if (isset($mappings['post_tags']) && !empty($mappings['post_tags'])) {
        $names = array();
        $exprs = preg_split('/[\s\n]+/', $mappings['post_tags']);
        foreach ($exprs as $expr) {
            if (empty(trim($expr))) continue;
            if (preg_match('/\{(.+?)\}/', $expr, $m)) $expr = $m[1];
            try {
                $r = @$item->xpath($expr);
                if ($r !== false && !empty($r)) {
                    foreach ($r as $node) {
                        $n = trim((string) $node);
                        if ($n !== '') $names[] = $n;
                    }
                }
            } catch (Exception $e) { /* skip */ }
        }
        if (!empty($names)) {
            $tax = ($post_type === 'product') ? 'product_tag' : 'post_tag';
            wpezimport_process_taxonomy_terms($post_id, implode(',', $names), $tax, $tags_match);
        }
    }

    // Brand (product_brand taxonomy — always create if missing)
    if (isset($mappings['post_brand']) && !empty($mappings['post_brand'])) {
        $names = array();
        $exprs = preg_split('/[\s\n]+/', $mappings['post_brand']);
        foreach ($exprs as $expr) {
            if (empty(trim($expr))) continue;
            if (preg_match('/\{(.+?)\}/', $expr, $m)) $expr = $m[1];
            try {
                $r = @$item->xpath($expr);
                if ($r !== false && !empty($r)) {
                    foreach ($r as $node) {
                        $n = trim((string) $node);
                        if ($n !== '') $names[] = $n;
                    }
                }
            } catch (Exception $e) { /* skip */ }
        }
        if (!empty($names)) {
            wpezimport_process_taxonomy_terms($post_id, implode(',', $names), 'product_brand', false);
        }
    }
}

// ============================================================
// Helper: extract a single field value from a CSV/XLS row (assoc array)
// ============================================================
function wpezimport_batch_extract_value_csv($row, $column_name) {
    $column_name = trim($column_name);
    // Strip curly braces if present (user may have typed {Column Name})
    if (preg_match('/^\{(.+?)\}$/', $column_name, $m)) {
        $column_name = trim($m[1]);
    }
    return isset($row[$column_name]) ? (string) $row[$column_name] : '';
}

// ============================================================
// Helper: extract unique identifier value from a CSV/XLS row
// ============================================================
function wpezimport_batch_extract_unique_value_csv($row, $unique_identifier, $mappings) {
    // 1. Try as direct column name
    $value = wpezimport_batch_extract_value_csv($row, $unique_identifier);

    // 2. Try as mapping field key
    if (empty($value) && isset($mappings[$unique_identifier]) && !empty($mappings[$unique_identifier])) {
        $value = wpezimport_batch_extract_value_csv($row, $mappings[$unique_identifier]);
    }

    return !empty($value) ? $value : null;
}

// ============================================================
// Helper: process categories and tags for a post (CSV/XLS)
// ============================================================
function wpezimport_batch_process_taxonomies_csv($post_id, $row, $mappings, $post_type, $categories_match, $tags_match) {
    if (!function_exists('wpezimport_process_taxonomy_terms')) {
        require_once plugin_dir_path(__FILE__) . 'process-import.php';
    }

    // Categories
    if (isset($mappings['post_categories']) && !empty($mappings['post_categories'])) {
        $raw = wpezimport_batch_extract_value_csv($row, $mappings['post_categories']);
        $raw = trim($raw);
        if ($raw !== '') {
            $tax = ($post_type === 'product') ? 'product_cat' : 'category';
            wpezimport_process_taxonomy_terms($post_id, $raw, $tax, $categories_match);
        }
    }

    // Tags
    if (isset($mappings['post_tags']) && !empty($mappings['post_tags'])) {
        $raw = wpezimport_batch_extract_value_csv($row, $mappings['post_tags']);
        $raw = trim($raw);
        if ($raw !== '') {
            $tax = ($post_type === 'product') ? 'product_tag' : 'post_tag';
            wpezimport_process_taxonomy_terms($post_id, $raw, $tax, $tags_match);
        }
    }

    // Brand (product_brand taxonomy — always create if missing)
    if (isset($mappings['post_brand']) && !empty($mappings['post_brand'])) {
        $raw = wpezimport_batch_extract_value_csv($row, $mappings['post_brand']);
        $raw = trim($raw);
        if ($raw !== '') {
            wpezimport_process_taxonomy_terms($post_id, $raw, 'product_brand', false);
        }
    }
}

// ============================================================
// AJAX endpoint — web UI calls this in a polling loop
// ============================================================

/**
 * AJAX handler: trigger an import.
 */
function wpezimport_ajax_trigger_import() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ezimport')));
    }
    check_ajax_referer('wpezimport_batch_nonce', 'nonce');

    $import_id = isset($_POST['import_id']) ? intval($_POST['import_id']) : 0;
    if ($import_id <= 0) {
        wp_send_json_error(array('message' => __('Missing import ID.', 'ezimport')));
    }

    $result = wpezimport_trigger_import($import_id);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
add_action('wp_ajax_wpezimport_trigger_import', 'wpezimport_ajax_trigger_import');

/**
 * AJAX handler: process one batch of items.
 */
function wpezimport_ajax_process_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'ezimport')));
    }
    check_ajax_referer('wpezimport_batch_nonce', 'nonce');

    $import_id = isset($_POST['import_id']) ? intval($_POST['import_id']) : 0;
    if ($import_id <= 0) {
        wp_send_json_error(array('message' => __('Missing import ID.', 'ezimport')));
    }

    // Use a conservative time limit (leave headroom for PHP max_execution_time)
    $php_max = (int) ini_get('max_execution_time');
    if ($php_max <= 0) $php_max = 300;
    $time_limit = min(55, max(20, $php_max - 10));

    $result = wpezimport_process_import_batch($import_id, $time_limit);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
add_action('wp_ajax_wpezimport_process_batch', 'wpezimport_ajax_process_batch');
