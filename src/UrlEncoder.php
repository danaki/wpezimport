<?php
/**
 * URL Encoder class for handling URL encoding operations
 *
 * This class provides methods for properly encoding URLs that may contain
 * spaces and other special characters while preserving URL structure.
 *
 * @package Wpezimport\Wpezimport
 */

namespace Wpezimport\Wpezimport;

class UrlEncoder
{
    /**
     * Check if a string looks like a URL
     *
     * @param mixed $value The value to check
     * @return bool True if it looks like a URL
     */
    public static function isUrl($value): bool
    {
        if (empty($value)) {
            return false;
        }
        // Check for common URL schemes
        return preg_match('/^https?:\/\//i', trim($value)) === 1;
    }

    /**
     * Encode a URL that may contain spaces and other special characters
     *
     * This function properly encodes URLs where the path may contain unencoded
     * spaces or other special characters, while preserving the URL structure.
     *
     * @param mixed $url The URL to encode
     * @return string The properly encoded URL
     */
    public static function encode($url): string
    {
        if (empty($url)) {
            return '';
        }

        $url = (string)$url;

        // Pre-encode spaces to allow parse_url to work correctly
        $pre_encoded_url = str_replace(' ', '%20', $url);
        $parsed = wp_parse_url($pre_encoded_url);

        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            // If parse_url fails, do basic encoding of known problematic characters
            return str_replace(
                [' ', '"', '<', '>', '{', '}', '|', '\\', '^', '`', '[', ']'],
                ['%20', '%22', '%3C', '%3E', '%7B', '%7D', '%7C', '%5C', '%5E', '%60', '%5B', '%5D'],
                $url
            );
        }

        // Build the encoded URL
        $encoded_url = $parsed['scheme'] . '://';

        // Add user info if present
        if (isset($parsed['user'])) {
            $encoded_url .= rawurlencode($parsed['user']);
            if (isset($parsed['pass'])) {
                $encoded_url .= ':' . rawurlencode($parsed['pass']);
            }
            $encoded_url .= '@';
        }

        // Add host
        $encoded_url .= $parsed['host'];

        // Add port if present
        if (isset($parsed['port'])) {
            $encoded_url .= ':' . $parsed['port'];
        }

        // Encode path segments
        if (isset($parsed['path'])) {
            $path_parts = explode('/', $parsed['path']);
            $encoded_path_parts = [];
            foreach ($path_parts as $part) {
                // Decode first in case it's already partially encoded, then encode
                $encoded_path_parts[] = rawurlencode(rawurldecode($part));
            }
            $encoded_url .= implode('/', $encoded_path_parts);
        }

        // Add query string if present
        if (isset($parsed['query'])) {
            // Encode each key/value, preserving '=' and '&' structure
            $pairs = [];
            foreach (explode('&', $parsed['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $kv = explode('=', $pair, 2);
                $encoded = rawurlencode(rawurldecode($kv[0]));
                if (isset($kv[1])) {
                    $encoded .= '=' . rawurlencode(rawurldecode($kv[1]));
                }
                $pairs[] = $encoded;
            }
            $encoded_url .= '?' . implode('&', $pairs);
        }

        // Add fragment if present
        if (isset($parsed['fragment'])) {
            $encoded_url .= '#' . rawurlencode($parsed['fragment']);
        }

        return $encoded_url;
    }

    /**
     * Decode a URL-encoded string
     *
     * @param string $url The URL to decode
     * @return string The decoded URL
     */
    public static function decode(string $url): string
    {
        return rawurldecode($url);
    }
}
