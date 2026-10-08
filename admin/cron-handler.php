<?php
/**
 * Cron Handler for wpezimport
 *
 * Provides three public endpoints for external cron (wget/curl):
 *   - trigger:  starts or restarts an import (checks If-Modified-Since / ETag)
 *   - process:  resumes the import for up to ~2 minutes per run
 *   - cancel:   cancels a running import
 *
 * URLs are authenticated via import_id + import_key query parameters.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Generate a random import key for an import that doesn't have one yet.
 */
function wpezimport_ensure_import_key($import_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';

    $row = $wpdb->get_row($wpdb->prepare("SELECT import_key FROM $table WHERE id = %d", $import_id));
    if (!$row) {
        return false;
    }

    if (empty($row->import_key)) {
        $key = wp_generate_password(48, false, false);
        $wpdb->update($table, array('import_key' => $key), array('id' => $import_id), array('%s'), array('%d'));
        return $key;
    }

    return $row->import_key;
}

/**
 * Validate import_id + import_key from the request.
 * Returns the import row on success or sends JSON error and dies.
 */
function wpezimport_cron_validate_request() {
    $import_id  = isset($_GET['import_id'])  ? intval($_GET['import_id'])              : 0;
    $import_key = isset($_GET['import_key']) ? sanitize_text_field($_GET['import_key']) : '';

    if (empty($import_id) || empty($import_key)) {
        wp_send_json_error(array('message' => 'Missing import_id or import_key.'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';
    $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $import_id));

    if (!$import) {
        wp_send_json_error(array('message' => 'Import not found.'));
    }

    if (!hash_equals((string) $import->import_key, $import_key)) {
        wp_send_json_error(array('message' => 'Invalid import_key.'));
    }

    if ($import->type !== 'url' || empty($import->url)) {
        wp_send_json_error(array('message' => 'Only URL-based imports support cron scheduling.'));
    }

    return $import;
}

/**
 * Append a line to the cron_log column (serialized array).
 */
function wpezimport_cron_log($import_id, $message) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';

    $row = $wpdb->get_row($wpdb->prepare("SELECT cron_log FROM $table WHERE id = %d", $import_id));
    $log = !empty($row->cron_log) ? maybe_unserialize($row->cron_log) : array();
    if (!is_array($log)) {
        $log = array();
    }

    $log[] = '[' . current_time('Y-m-d H:i:s') . '] ' . $message;

    // Keep last 500 entries to prevent bloat
    if (count($log) > 500) {
        $log = array_slice($log, -500);
    }

    $wpdb->update($table, array('cron_log' => serialize($log)), array('id' => $import_id), array('%s'), array('%d'));
}

/**
 * Update cron state columns for an import.
 */
function wpezimport_cron_update_state($import_id, $data) {
    global $wpdb;
    $table = $wpdb->prefix . 'wpezimport_saved_imports';
    $wpdb->update($table, $data, array('id' => $import_id));
}

// ============================================================
// TRIGGER endpoint — delegates to shared wpezimport_trigger_import()
// ============================================================
function wpezimport_cron_trigger() {
    $import = wpezimport_cron_validate_request();
    $import_id = (int) $import->id;

    $result = wpezimport_trigger_import($import_id);

    wpezimport_cron_log($import_id, 'Trigger: ' . $result['message']);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

// ============================================================
// PROCESS endpoint — delegates to shared wpezimport_process_import_batch()
// ============================================================
function wpezimport_cron_process() {
    $import = wpezimport_cron_validate_request();
    $import_id = (int) $import->id;

    // Determine time limit: min(2 minutes, PHP max_execution_time - 10 seconds safety margin)
    $php_max = (int) ini_get('max_execution_time');
    if ($php_max <= 0) {
        $php_max = 300;
    }
    $time_limit = min(120, max(30, $php_max - 10));

    $result = wpezimport_process_import_batch($import_id, $time_limit);

    // Strip resolved_items — not needed for cron responses
    unset($result['resolved_items']);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

// ============================================================
// CANCEL endpoint
// ============================================================
function wpezimport_cron_cancel() {
    $import = wpezimport_cron_validate_request();
    $import_id = (int) $import->id;

    wpezimport_cron_update_state($import_id, array(
        'cron_status' => 'idle'
    ));

    wpezimport_cron_log($import_id, 'Cancel: import cancelled by external request. Progress preserved at item ' . $import->cron_last_item . ' (phase: ' . $import->cron_phase . ').');

    wp_send_json_success(array(
        'message' => 'Import cancelled. Progress has been preserved.',
        'status'  => 'idle',
        'phase'   => $import->cron_phase,
        'progress' => (int) $import->cron_last_item
    ));
}

// ============================================================
// Router — hooks into WordPress init to intercept requests
// ============================================================
function wpezimport_cron_router() {
    if (!isset($_GET['wpezimport_action'])) {
        return;
    }

    $action = sanitize_text_field($_GET['wpezimport_action']);

    // Set JSON content type header
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    switch ($action) {
        case 'trigger':
            wpezimport_cron_trigger();
            break;
        case 'process':
            wpezimport_cron_process();
            break;
        case 'cancel':
            wpezimport_cron_cancel();
            break;
        default:
            wp_send_json_error(array('message' => 'Unknown action: ' . $action));
    }

    // wp_send_json_* already calls die(), but just in case
    exit;
}
add_action('init', 'wpezimport_cron_router', 20);
