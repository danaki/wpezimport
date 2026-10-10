<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper function to get all meta keys from the wp_postmeta table
 */
function wpezimport_get_meta_keys() {
    global $wpdb;
    $meta_keys = $wpdb->get_col(
        $wpdb->prepare("SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key != %s ORDER BY meta_key", '')
    );
    return $meta_keys;
}

/**
 * Process XML node and its children, making them draggable
 *
 * @param SimpleXMLElement $node The XML node to process
 * @param string $base_xpath The base XPath for this node
 * @param bool $is_root Whether this is the root node
 * @param int $depth Current depth in the XML tree
 * @return string The formatted HTML
 */
function wpezimport_process_xml_node($node, $base_xpath, $is_root = false, $depth = 0, &$position_map = array()) {
    if (!$node) {
        return '';
    }
    $output = '';
    $node_name = $node->getName();
    if (!$node_name) {
        return '';
    }
    // Track position for this path and node name
    $parent_path = $base_xpath;
    if (!isset($position_map[$parent_path])) {
        $position_map[$parent_path] = array();
    }
    if (!isset($position_map[$parent_path][$node_name])) {
        $position_map[$parent_path][$node_name] = 0;
    }
    $position_map[$parent_path][$node_name]++;
    // Get current position
    $position = $position_map[$parent_path][$node_name];

    // Build the XPath with position for all elements
    if ($is_root) {
        $current_xpath = '';
    } else {
        $current_xpath = ltrim($base_xpath . '/' . $node_name . '[' . $position . ']', '/');
    }

    // Output node with attributes
    $output .= '<div class="wpezimport-xml-node">';
    // Opening tag: tag name span (draggable), then attribute spans, then closing angle bracket
    $output .= '<span class="wpezimport-xml-tag">'
        . '<span class="wpezimport-xml-tag-name" draggable="true" data-xpath="' . esc_attr($current_xpath) . '">&lt;<span class="wpezimport-xml-tag-name-contents">' . esc_html($node_name) . '</span></span>';
    // Output attributes as draggable spans
    foreach ($node->attributes() as $attr_name => $attr_value) {
        $attr_xpath = $current_xpath . '/@' . $attr_name;
        $output .= ' <span class="wpezimport-xml-attribute" draggable="true" data-xpath="' . esc_attr($attr_xpath) . '">' .
            esc_html($attr_name) . '="' . esc_html((string)$attr_value) . '"</span>';
    }
    $output .= '&gt;</span>';
    // Output children
    $children = $node->children();
    if ($children->count() > 0) {
        foreach ($children as $child) {
            $output .= wpezimport_process_xml_node($child, $current_xpath, false, $depth + 1, $position_map);
        }
    } else {
        $output .= esc_html((string)$node);
    }
    $output .= '<span class="wpezimport-xml-tag">&lt;/<span class="wpezimport-xml-tag-name-contents">' . esc_html($node_name) . '</span>&gt;</span>';
    $output .= '</div>';
    return $output;
}
