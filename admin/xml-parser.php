<?php
/**
 * Pure XML parsing functions for field mapping extraction
 *
 * These functions are designed to be testable without database dependencies.
 * They handle the extraction of data from XML elements based on field mappings.
 *
 * Note: These functions now delegate to the namespaced classes in src/ for better
 * organization. The functions are kept for backward compatibility.
 *
 * @see \Wpezimport\Wpezimport\XmlParser
 * @see \Wpezimport\Wpezimport\DataExtractor
 * @see \Wpezimport\Wpezimport\UnitConverter
 * @see \Wpezimport\Wpezimport\UrlEncoder
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

use Wpezimport\Wpezimport\XmlParser;
use Wpezimport\Wpezimport\DataExtractor;
use Wpezimport\Wpezimport\UnitConverter;
use Wpezimport\Wpezimport\UrlEncoder;

/**
 * Find repeating elements in an XML structure
 *
 * Recursively traverses the XML tree and identifies elements that appear multiple times
 * at the same level (siblings with the same name).
 *
 * @param SimpleXMLElement $xml The XML element to analyze
 * @param string $current_xpath The current XPath prefix
 * @param array &$counts Reference array to track element counts
 * @return array Array of repeating elements with xpath and count
 */
function wpezimport_find_repeating_elements($xml, $current_xpath = '', &$counts = array()) {
    return XmlParser::findRepeatingElements($xml, $current_xpath, $counts);
}

/**
 * Evaluate an XPath expression against an XML item with fallback variations
 *
 * @param SimpleXMLElement $xml_item The XML item to evaluate against
 * @param string $xpath_expr The XPath expression to evaluate
 * @return string The extracted value or empty string if not found
 */
function wpezimport_evaluate_xpath($xml_item, $xpath_expr) {
    return XmlParser::evaluateXpath($xml_item, (string)$xpath_expr);
}

/**
 * Evaluate an XPath expression and return all matching values
 *
 * @param SimpleXMLElement $xml_item The XML item to evaluate against
 * @param string $xpath_expr The XPath expression to evaluate
 * @return array Array of string values
 */
function wpezimport_evaluate_xpath_all($xml_item, $xpath_expr) {
    return XmlParser::evaluateXpathAll($xml_item, (string)$xpath_expr);
}

/**
 * Parse a field pattern containing XPath expressions in curly braces
 * and replace them with actual values from the XML item
 *
 * @param SimpleXMLElement $xml_item The XML item to extract values from
 * @param string $pattern The pattern string with XPath expressions in {curly braces}
 * @return string The pattern with all XPath expressions replaced with values
 */
function wpezimport_parse_field_pattern($xml_item, $pattern) {
    return XmlParser::parseFieldPattern($xml_item, (string)$pattern);
}

/**
 * Check if a string looks like a URL
 *
 * @param string $value The value to check
 * @return bool True if it looks like a URL
 */
function wpezimport_is_url($value) {
    return UrlEncoder::isUrl($value);
}

/**
 * Parse a field pattern and return all values (for multi-value fields like images)
 *
 * @param SimpleXMLElement $xml_item The XML item to extract values from
 * @param string $pattern The pattern string with XPath expressions in {curly braces}
 * @param bool $encode_urls Whether to encode URLs immediately after extraction (default false)
 * @return array Array of extracted values
 */
function wpezimport_parse_field_pattern_all($xml_item, $pattern, $encode_urls = false) {
    return XmlParser::parseFieldPatternAll($xml_item, (string)$pattern, $encode_urls);
}

/**
 * Extract mapped data from an XML item based on field mappings
 *
 * This is a pure function with no database dependencies.
 * It takes an XML item and field mappings and returns an associative array
 * of field_id => extracted_value pairs.
 *
 * @param SimpleXMLElement $xml_item The XML item to extract data from
 * @param array $field_mapping Array of field_id => pattern mappings
 * @param array $mapping_units Optional array of unit specifications for conversions
 * @return array Associative array with extracted and processed data
 */
function wpezimport_extract_mapped_data($xml_item, $field_mapping, $mapping_units = array()) {
    return DataExtractor::extractMappedData($xml_item, $field_mapping);
}

/**
 * Process extracted data for post creation
 *
 * Separates data into categories: post fields, meta fields, taxonomy data, and image data.
 * Also applies unit conversions where specified.
 *
 * @param array $extracted_data Raw extracted data from wpezimport_extract_mapped_data()
 * @param array $mapping_units Optional array of unit specifications for conversions
 * @return array Structured data ready for post creation with keys:
 *               - post_data: Core WP post fields (title, content, excerpt)
 *               - meta_fields: Custom field key-value pairs
 *               - taxonomy_data: Category and tag strings
 *               - image_data: Gallery and featured image URLs/patterns
 */
function wpezimport_prepare_post_data($extracted_data, $mapping_units = array()) {
    return DataExtractor::preparePostData($extracted_data, $mapping_units);
}

/**
 * Encode a URL that may contain spaces and other special characters
 *
 * This function properly encodes URLs where the path may contain unencoded
 * spaces or other special characters, while preserving the URL structure.
 *
 * @param string $url The URL to encode
 * @return string The properly encoded URL
 */
function wpezimport_encode_url($url) {
    return UrlEncoder::encode($url);
}

/**
 * Extract unique identifier value from an XML item based on a pattern
 *
 * This is a pure function extracted from process-import.php for testability.
 * It parses an XPath pattern (e.g., '{id}' or '{code}-{ean_code}') and
 * extracts the corresponding value(s) from the XML item.
 *
 * @param SimpleXMLElement|null $xml_item The XML item to extract from
 * @param string $pattern The pattern containing XPath expressions in {curly braces}
 * @return string The extracted unique identifier value, or empty string if not found
 */
function wpezimport_extract_unique_identifier($xml_item, $pattern) {
    return XmlParser::extractUniqueIdentifier($xml_item, (string)$pattern);
}

/**
 * Select repeating item elements from an XML document by XPath
 *
 * @param SimpleXMLElement|null $xml The XML document root
 * @param string $item_xpath XPath selecting the repeating items
 * @return array Array of SimpleXMLElement
 */
function wpezimport_get_xml_elements_by_xpath($xml, $item_xpath) {
    return XmlParser::getElementsByXpath($xml, (string)$item_xpath);
}

/**
 * Extract image URLs from an XML item based on a pattern
 *
 * URLs are encoded immediately after XPath evaluation to prevent URLs with
 * spaces or special characters from being broken by subsequent processing.
 *
 * @param SimpleXMLElement $xml_item The XML item to extract from
 * @param string $pattern The pattern containing XPath expressions
 * @param bool $encode_urls Whether to encode URLs (default true)
 * @return array Array with 'urls', 'raw_urls', and 'errors' keys
 */
function wpezimport_extract_image_urls($xml_item, $pattern, $encode_urls = true) {
    return DataExtractor::extractImageUrls($xml_item, (string)$pattern, $encode_urls);
}
