<?php
/**
 * PHPUnit bootstrap file for wpezimport plugin tests
 */

// Define WordPress constants needed by guarded files
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Provide plugin_dir_path() stub if not available (used in require chains)
if (!function_exists('trailingslashit')) {
    function trailingslashit($value) {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return trailingslashit(dirname($file));
    }
}

// Plugin code calls error_log() liberally; keep it out of the test output
ini_set('error_log', sys_get_temp_dir() . '/wpezimport-phpunit.log');

// First, let's include the plugin autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__).'/vendor/antecedent/patchwork/Patchwork.php';

WP_Mock::setUsePatchwork(true);
WP_Mock::bootstrap();

// Minimal WP_Error stub used by plugin functions returning WP_Error
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// Include the admin/process-upload.php file that contains the function we want to test
require_once dirname(__DIR__) . '/admin/process-upload.php';

// Include the xml-parser.php file with pure parsing functions
require_once dirname(__DIR__) . '/admin/xml-parser.php';

// Include batch processor for helper function tests
require_once dirname(__DIR__) . '/admin/import-batch.php';

// Include process-mapping.php for wpezimport_parse_pattern()
require_once dirname(__DIR__) . '/admin/process-mapping.php';

// WP function stubs needed when loading the main plugin file
foreach (array('register_activation_hook', 'register_deactivation_hook', 'plugin_dir_url') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return null; }');
    }
}

// Include the main plugin file (defines wpezimport_handle_manage_actions etc.)
require_once dirname(__DIR__) . '/wpezimport.php';
