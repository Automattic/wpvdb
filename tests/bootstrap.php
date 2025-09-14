<?php
/**
 * PHPUnit bootstrap file for WPVDB plugin tests.
 *
 * @package WPVDB
 */

// Composer autoloader must be loaded first
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Use fallback testing mode without WordPress environment for now
echo "Using standalone testing mode.\n";

// Mock essential WordPress functions for testing
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {
        // Mock implementation
        return;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        // Simple mock that just returns the value unchanged
        return $value;
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        // WordPress sanitize_key removes everything except a-z, 0-9, _ and -
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        // Mock implementation - always return false (not found)
        return false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        // Mock implementation - always return true
        return true;
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        // Mock implementation - return 0 (not logged in)
        return 0;
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        // Mock implementation - return a simple salt
        return 'mock_salt_for_testing';
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        // Simple mock sanitization
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        // Mock implementation - return true for testing
        return true;
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        // Mock implementation - return false
        return false;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        // Mock implementation
        if ( $type === 'mysql' ) {
            return date( 'Y-m-d H:i:s' );
        }
        return time();
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        // Mock translation function
        return $text;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( intval( $maybeint ) );
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return false;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

// Define WordPress constants
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', false );
}

if ( ! defined( 'REST_REQUEST' ) ) {
    define( 'REST_REQUEST', false );
}

// Mock WP_Error class
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( ! empty( $code ) ) {
                $this->errors[ $code ][] = $message;
                if ( ! empty( $data ) ) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        public function get_error_code() {
            return key( $this->errors );
        }

        public function get_error_message( $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
        }

        public function get_error_data( $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
        }
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        return false;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text, $domain = 'default' ) {
        echo esc_html( $text );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return trailingslashit( dirname( $file ) );
    }
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return trailingslashit( dirname( $file ) );
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return untrailingslashit( $string ) . '/';
    }
}

if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $string ) {
        return rtrim( $string, '/\\' );
    }
}

// Mock wpdb class for testing
if ( ! class_exists( 'wpdb' ) ) {
    class wpdb {
        public $last_error = '';
        public $last_result = [];
        public $num_rows = 0;

        public function get_var( $query = null, $x = 0, $y = 0 ) {
            // Default mock behavior
            if ( strpos( $query, 'SELECT VERSION()' ) !== false ) {
                return '8.0.32'; // Default MySQL version
            }
            return '';
        }

        public function prepare( $query, ...$args ) {
            return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
        }

        public function get_results( $query = null, $output = OBJECT ) {
            return [];
        }

        public function query( $query ) {
            return 0;
        }
    }
}

// Mock global wpdb for testing
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new wpdb();
}

// Mock Logger class methods
if ( ! class_exists( 'WPVDB\Logger' ) ) {
    class MockLogger {
        public static function debug( $message, $context = [] ) {
            // Mock implementation
        }

        public static function info( $message, $context = [] ) {
            // Mock implementation
        }

        public static function log_exception( $exception, $message = '' ) {
            // Mock implementation
        }

        public static function error( $message, $context = [] ) {
            // Mock implementation
        }
    }

    // Create alias in WPVDB namespace
    class_alias( 'MockLogger', 'WPVDB\Logger' );
}

// Define basic WordPress constants if not defined
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
}

// Load essential classes that can work in isolation
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-core.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-database.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-models.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-security.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-utils.php';