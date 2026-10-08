<?php
/**
 * Data Extractor class for field mapping extraction
 *
 * This class handles extracting data from XML items based on field mappings
 * and preparing the data for WordPress post creation.
 *
 * @package Wpezimport\Wpezimport
 */

namespace Wpezimport\Wpezimport;

class DataExtractor
{
    /**
     * Extract mapped data from an XML item based on field mappings
     *
     * This is a pure function with no database dependencies.
     * It takes an XML item and field mappings and returns an associative array
     * of field_id => extracted_value pairs.
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to extract data from
     * @param array $field_mapping Array of field_id => pattern mappings
     * @return array Associative array with extracted data
     */
    public static function extractMappedData($xml_item, array $field_mapping): array
    {
        if (empty($xml_item) || empty($field_mapping)) {
            return [];
        }

        $extracted_data = [];

        foreach ($field_mapping as $field_id => $pattern) {
            if (empty($pattern)) {
                continue;
            }

            // Parse the pattern to extract value(s)
            $value = XmlParser::parseFieldPattern($xml_item, $pattern);

            if (!empty($value) || $value === '0') {
                $extracted_data[$field_id] = $value;
            }
        }

        return $extracted_data;
    }

    /**
     * Process extracted data for post creation
     *
     * Separates data into categories: post fields, meta fields, taxonomy data, and image data.
     * Also applies unit conversions where specified.
     *
     * @param array $extracted_data Raw extracted data from extractMappedData()
     * @param array $mapping_units Optional array of unit specifications for conversions
     * @return array Structured data ready for post creation with keys:
     *               - post_data: Core WP post fields (title, content, excerpt)
     *               - meta_fields: Custom field key-value pairs
     *               - taxonomy_data: Category and tag strings
     *               - image_data: Gallery and featured image URLs/patterns
     */
    public static function preparePostData(array $extracted_data, array $mapping_units = []): array
    {
        $result = [
            'post_data' => [],
            'meta_fields' => [],
            'taxonomy_data' => [
                'category' => '',
                'post_tag' => ''
            ],
            'image_data' => [
                'image_url_1' => '',
                'image_url_2' => '',
                'image_url_3' => '',
                'image_url_4' => ''
            ]
        ];

        if (empty($extracted_data)) {
            return $result;
        }

        foreach ($extracted_data as $key => $value) {
            // Core post fields
            if ($key === 'post_title') {
                $result['post_data']['post_title'] = $value;
                continue;
            }
            if ($key === 'post_content') {
                $result['post_data']['post_content'] = $value;
                continue;
            }
            if ($key === 'post_excerpt') {
                $result['post_data']['post_excerpt'] = $value;
                continue;
            }

            // Taxonomy fields
            if ($key === 'post_category' || $key === 'post_categories') {
                $result['taxonomy_data']['category'] = $value;
                continue;
            }
            if ($key === 'post_tags') {
                $result['taxonomy_data']['post_tag'] = $value;
                continue;
            }

            // Image fields (store raw URLs, will be processed after post creation)
            if (preg_match('/^image_url_[1-4]$/', $key)) {
                $result['image_data'][$key] = $value;
                continue;
            }

            // Apply unit conversions for weight
            if ($key === '_weight' && !empty($value)) {
                $value = UnitConverter::convertWeight($value, $mapping_units['_weight'] ?? 'kg');
            }

            // Apply unit conversions for dimensions
            if (in_array($key, ['_length', '_width', '_height']) && !empty($value)) {
                $value = UnitConverter::convertDimension($value, $mapping_units['_dimensions'] ?? 'm');
            }

            // Everything else goes to meta fields
            $result['meta_fields'][$key] = $value;
        }

        return $result;
    }

    /**
     * Extract image URLs from an XML item based on a pattern
     *
     * URLs are encoded immediately after XPath evaluation to prevent URLs with
     * spaces or special characters from being broken by subsequent processing.
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to extract from
     * @param string $pattern The pattern containing XPath expressions
     * @param bool $encode_urls Whether to encode URLs (default true)
     * @return array Array with 'urls', 'raw_urls', and 'errors' keys
     */
    public static function extractImageUrls($xml_item, string $pattern, bool $encode_urls = true): array
    {
        $result = [
            'urls' => [],
            'raw_urls' => [],
            'errors' => []
        ];

        if (empty($xml_item) || empty($pattern)) {
            return $result;
        }

        // If pattern contains curly braces, it has XPath expressions
        if (strpos($pattern, '{') !== false) {
            // Get raw URLs first (without encoding) for raw_urls
            $result['raw_urls'] = XmlParser::parseFieldPatternAll($xml_item, $pattern, false);

            // Get encoded URLs
            if ($encode_urls) {
                $result['urls'] = XmlParser::parseFieldPatternAll($xml_item, $pattern, true);
            } else {
                $result['urls'] = $result['raw_urls'];
            }
        } else {
            // Direct URLs - split only by newlines and commas (safer delimiters)
            $raw_urls = preg_split('/[\n\r,]+/', trim($pattern), -1, PREG_SPLIT_NO_EMPTY);
            $raw_urls = array_map('trim', $raw_urls);
            $raw_urls = array_filter($raw_urls);

            $result['raw_urls'] = $raw_urls;

            if ($encode_urls) {
                foreach ($raw_urls as $url) {
                    $result['urls'][] = UrlEncoder::encode($url);
                }
            } else {
                $result['urls'] = $raw_urls;
            }
        }

        return $result;
    }
}
