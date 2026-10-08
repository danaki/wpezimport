<?php
/**
 * AJAX handler for XML sample navigation (split from mapping-page.php for safe inclusion)
 */

if (!defined('ABSPATH')) {
    exit;
}

function wpezimport_navigate_xml_sample() {
    // Check nonce
    check_ajax_referer('wpezimport_save_mapping', 'wpezimport_mapping_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
    }

    // Get draft_id from POST
    $draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;
    if ($draft_id <= 0) {
        wp_send_json_error(array('message' => 'Missing draft ID'));
        return;
    }

    $draft = wpezimport_get_draft($draft_id);
    if (!$draft) {
        wp_send_json_error(array('message' => 'Draft not found'));
        return;
    }

    $samples = isset($draft->state['xml_samples']) ? $draft->state['xml_samples'] : array();
    $current_index = isset($draft->state['current_sample_index']) ? intval($draft->state['current_sample_index']) : 0;

    if (empty($samples)) {
        wp_send_json_error(array('message' => 'No XML samples available'));
        return;
    }

    $direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : 'next';

    // Validate navigation direction
    $valid_directions = array('prev', 'next', 'init');
    if (!in_array($direction, $valid_directions, true)) {
        wp_send_json_error(array('message' => 'Invalid navigation direction'));
        return;
    }

    // Calculate the new index
    if ($direction === 'prev') {
        $new_index = max(0, $current_index - 1);
    } else if ($direction === 'next') {
        $new_index = min(count($samples) - 1, $current_index + 1);
    } else if ($direction === 'init') {
        $new_index = 0;
    } else {
        $new_index = $current_index;
    }

    // Update the draft
    wpezimport_update_draft($draft_id, array('current_sample_index' => $new_index));

    // Get the XML sample
    $xml_sample = $samples[$new_index];

    // Prepare the sample counter
    $sample_counter_html = '<span class="wpezimport-product-counter">' . sprintf(__('Sample %d of %d', 'wpezimport'), $new_index + 1, count($samples)) . '</span>';

    // Process the XML for display
    $item_xpath = isset($draft->state['xml_item_path']) ? $draft->state['xml_item_path'] : '';
    $xml = simplexml_load_string($xml_sample);
    $position_map = array();
    $html = wpezimport_process_xml_node($xml, $item_xpath, true, 0, $position_map);

    // Prepare the response
    $response = array(
        'formatted_xml' => $html,
        'current_index' => $new_index,
        'total_samples' => count($samples),
        'has_prev' => $new_index > 0,
        'has_next' => $new_index < count($samples) - 1,
        'counter_text' => sprintf(__('Sample %d of %d', 'wpezimport'), $new_index + 1, count($samples)),
        'sample_counter_html' => $sample_counter_html
    );

    wp_send_json_success($response);
    exit;
}
