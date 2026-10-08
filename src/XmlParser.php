<?php
/**
 * XML Parser class for handling XML parsing operations
 *
 * This class provides pure functions for parsing XML data without database dependencies.
 * All methods are static and can be unit tested independently.
 *
 * @package Wpezimport\Wpezimport
 */

namespace Wpezimport\Wpezimport;

class XmlParser
{
    /**
     * Find repeating elements in an XML structure
     *
     * Recursively traverses the XML tree and identifies elements that appear multiple times
     * at the same level (siblings with the same name).
     *
     * @param \SimpleXMLElement|null $xml The XML element to analyze
     * @param string $current_xpath The current XPath prefix
     * @param array $counts Reference array to track element counts
     * @return array Array of repeating elements with xpath and count
     */
    public static function findRepeatingElements($xml, string $current_xpath = '', array &$counts = []): array
    {
        $repeating_elements = [];

        if (empty($xml)) {
            return $repeating_elements;
        }

        $node_name = $xml->getName();
        $node_xpath = $current_xpath . '/' . $node_name;

        // Count children by name
        $child_counts = [];
        foreach ($xml->children() as $child) {
            $child_name = $child->getName();
            if (!isset($child_counts[$child_name])) {
                $child_counts[$child_name] = 0;
            }
            $child_counts[$child_name]++;
        }

        // Track which children are repeating and recurse
        $processed_children = [];
        foreach ($xml->children() as $child) {
            $child_name = $child->getName();
            $child_xpath = $node_xpath . '/' . $child_name;

            // If this child type repeats and we haven't recorded it yet
            if ($child_counts[$child_name] > 1 && !isset($processed_children[$child_name])) {
                $repeating_elements[] = [
                    'xpath' => $child_xpath,
                    'count' => $child_counts[$child_name]
                ];
                $processed_children[$child_name] = true;
            }

            // Recurse into children
            $child_repeating = self::findRepeatingElements($child, $node_xpath, $counts);
            $repeating_elements = array_merge($repeating_elements, $child_repeating);
        }

        return $repeating_elements;
    }

    /**
     * Evaluate an XPath expression against an XML item with fallback variations
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to evaluate against
     * @param string $xpath_expr The XPath expression to evaluate
     * @return string The extracted value or empty string if not found
     */
    public static function evaluateXpath($xml_item, string $xpath_expr): string
    {
        if (empty($xml_item) || empty($xpath_expr)) {
            return '';
        }

        $result = '';

        // Try different XPath formats if the expression starts with /
        if (strpos($xpath_expr, '/') === 0) {
            $xpath_variations = [
                $xpath_expr,
                ltrim($xpath_expr, '/'),
                '.' . $xpath_expr,
                'descendant::' . ltrim($xpath_expr, '/')
            ];

            foreach ($xpath_variations as $xpath_var) {
                $nodes = @$xml_item->xpath($xpath_var);

                if (!empty($nodes)) {
                    $result = (string)$nodes[0];
                    break;
                }
            }
        } else {
            // Regular XPath without leading slash
            $nodes = @$xml_item->xpath($xpath_expr);

            if (!empty($nodes)) {
                $result = (string)$nodes[0];
            }
        }

        return $result;
    }

    /**
     * Evaluate an XPath expression and return all matching values
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to evaluate against
     * @param string $xpath_expr The XPath expression to evaluate
     * @return array Array of string values
     */
    public static function evaluateXpathAll($xml_item, string $xpath_expr): array
    {
        if (empty($xml_item) || empty($xpath_expr)) {
            return [];
        }

        $results = [];

        // Try different XPath formats if the expression starts with /
        if (strpos($xpath_expr, '/') === 0) {
            $xpath_variations = [
                $xpath_expr,
                ltrim($xpath_expr, '/'),
                '.' . $xpath_expr,
                'descendant::' . ltrim($xpath_expr, '/')
            ];

            foreach ($xpath_variations as $xpath_var) {
                $nodes = @$xml_item->xpath($xpath_var);

                if (!empty($nodes)) {
                    foreach ($nodes as $node) {
                        $value = trim((string)$node);
                        if (!empty($value)) {
                            $results[] = $value;
                        }
                    }
                    break;
                }
            }
        } else {
            // Regular XPath without leading slash
            $nodes = @$xml_item->xpath($xpath_expr);

            if (!empty($nodes)) {
                foreach ($nodes as $node) {
                    $value = trim((string)$node);
                    if (!empty($value)) {
                        $results[] = $value;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Parse a field pattern containing XPath expressions in curly braces
     * and replace them with actual values from the XML item
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to extract values from
     * @param string $pattern The pattern string with XPath expressions in {curly braces}
     * @return string The pattern with all XPath expressions replaced with values
     */
    public static function parseFieldPattern($xml_item, string $pattern): string
    {
        if (empty($pattern)) {
            return '';
        }

        // If pattern doesn't contain curly braces, return as-is (it's a literal value)
        if (strpos($pattern, '{') === false) {
            return $pattern;
        }

        // Extract XPath expressions from the pattern
        preg_match_all('/{([^}]+)}/', $pattern, $matches);

        if (empty($matches[1])) {
            return $pattern;
        }

        $field_value = $pattern;

        foreach ($matches[1] as $xpath_expr) {
            $result = self::evaluateXpath($xml_item, $xpath_expr);
            $field_value = str_replace('{' . $xpath_expr . '}', $result, $field_value);
        }

        return $field_value;
    }

    /**
     * Parse a field pattern and return all values (for multi-value fields like images)
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to extract values from
     * @param string $pattern The pattern string with XPath expressions in {curly braces}
     * @param bool $encode_urls Whether to encode URLs immediately after extraction
     * @return array Array of extracted values
     */
    public static function parseFieldPatternAll($xml_item, string $pattern, bool $encode_urls = false): array
    {
        if (empty($pattern)) {
            return [];
        }

        // If pattern doesn't contain curly braces, split by whitespace and return
        if (strpos($pattern, '{') === false) {
            $values = preg_split('/[\s,]+/', trim($pattern), -1, PREG_SPLIT_NO_EMPTY);
            if ($encode_urls) {
                foreach ($values as &$value) {
                    if (UrlEncoder::isUrl($value)) {
                        $value = UrlEncoder::encode($value);
                    }
                }
            }
            return $values;
        }

        // Extract XPath expressions from the pattern
        preg_match_all('/{([^}]+)}/', $pattern, $matches);

        if (empty($matches[1])) {
            return [$pattern];
        }

        $all_values = [];

        foreach ($matches[1] as $xpath_expr) {
            $results = self::evaluateXpathAll($xml_item, $xpath_expr);

            // Encode URLs immediately after XPath evaluation if requested
            if ($encode_urls) {
                foreach ($results as &$result) {
                    if (UrlEncoder::isUrl($result)) {
                        $result = UrlEncoder::encode($result);
                    }
                }
            }

            $all_values = array_merge($all_values, $results);
        }

        return $all_values;
    }

    /**
     * Extract unique identifier value from an XML item based on a pattern
     *
     * @param \SimpleXMLElement|null $xml_item The XML item to extract from
     * @param string $pattern The pattern containing XPath expressions in {curly braces}
     * @return string The extracted unique identifier value, or empty string if not found
     */
    public static function extractUniqueIdentifier($xml_item, string $pattern): string
    {
        if (empty($xml_item) || empty($pattern)) {
            return '';
        }

        // A bare element name / XPath (no braces) should be evaluated, not returned literally
        if (strpos($pattern, '{') === false) {
            return trim(self::evaluateXpath($xml_item, $pattern));
        }

        $value = self::parseFieldPattern($xml_item, $pattern);

        return trim($value);
    }

    /**
     * Select repeating item elements from an XML document by XPath
     *
     * Tries the given XPath as-is, then falls back to a descendant search on the
     * last path segment so that both absolute ("/root/items/item") and relative
     * ("item") selectors work.
     *
     * @param \SimpleXMLElement|null $xml The XML document root
     * @param string $item_xpath XPath selecting the repeating items
     * @return array Array of \SimpleXMLElement
     */
    public static function getElementsByXpath($xml, string $item_xpath): array
    {
        if (empty($xml)) {
            return [];
        }

        $item_xpath = trim($item_xpath);
        if ($item_xpath === '') {
            return [];
        }

        $candidates = [$item_xpath];
        $last = trim(substr($item_xpath, (int) strrpos($item_xpath, '/')), '/');
        if ($last !== '' && $last !== $item_xpath) {
            $candidates[] = '//' . $last;
        }

        foreach ($candidates as $query) {
            $nodes = @$xml->xpath($query);
            if ($nodes !== false && !empty($nodes)) {
                return array_values($nodes);
            }
        }

        return [];
    }
}
