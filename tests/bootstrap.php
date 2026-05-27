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
global $_wp_filters;
$_wp_filters = [];

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
        global $_wp_filters;
        if ( ! isset( $_wp_filters[ $tag ] ) ) {
            $_wp_filters[ $tag ] = [];
        }
        if ( ! isset( $_wp_filters[ $tag ][ $priority ] ) ) {
            $_wp_filters[ $tag ][ $priority ] = [];
        }
        $_wp_filters[ $tag ][ $priority ][] = [
            'function'      => $function_to_add,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $tag, $function_to_remove, $priority = 10 ) {
        global $_wp_filters;
        if ( empty( $_wp_filters[ $tag ][ $priority ] ) ) {
            return false;
        }

        foreach ( $_wp_filters[ $tag ][ $priority ] as $index => $filter ) {
            if ( $filter['function'] === $function_to_remove ) {
                unset( $_wp_filters[ $tag ][ $priority ][ $index ] );
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {
        // Mock implementation
        return;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value, ...$args ) {
        global $_wp_filters;
        if ( empty( $_wp_filters[ $tag ] ) ) {
            return $value;
        }

        ksort( $_wp_filters[ $tag ] );
        $filter_args = array_merge( [ $value ], $args );
        foreach ( $_wp_filters[ $tag ] as $filters ) {
            foreach ( $filters as $filter ) {
                $accepted_args = max( 1, (int) $filter['accepted_args'] );
                $value = call_user_func_array( $filter['function'], array_slice( $filter_args, 0, $accepted_args ) );
                $filter_args[0] = $value;
            }
        }

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

if ( ! function_exists( 'size_format' ) ) {
    function size_format( $bytes, $decimals = 0 ) {
        return number_format( $bytes, $decimals ) . ' B';
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

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        global $_wp_options;
        if ( ! isset( $_wp_options ) ) {
            $_wp_options = [];
        }
        $_wp_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        global $_wp_options;
        if ( ! isset( $_wp_options ) ) {
            $_wp_options = [];
        }
        unset( $_wp_options[ $option ] );
        return true;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'has_action' ) ) {
    function has_action( $tag, $function_to_check = false ) {
        return false; // Mock - always return false
    }
}

if ( ! function_exists( 'has_filter' ) ) {
    function has_filter( $tag, $function_to_check = false ) {
        global $_wp_filters;
        if ( empty( $_wp_filters[ $tag ] ) ) {
            return false;
        }

        if ( false === $function_to_check ) {
            return true;
        }

        foreach ( $_wp_filters[ $tag ] as $priority => $filters ) {
            foreach ( $filters as $filter ) {
                if ( $filter['function'] === $function_to_check ) {
                    return $priority;
                }
            }
        }

        return false;
    }
}

if ( ! function_exists( 'did_action' ) ) {
    function did_action( $tag ) {
        return 0; // Mock - never done
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = '' ) {
        if ( is_object( $args ) ) {
            $parsed_args = get_object_vars( $args );
        } elseif ( is_array( $args ) ) {
            $parsed_args =& $args;
        } else {
            wp_parse_str( $args, $parsed_args );
        }

        if ( is_array( $defaults ) && $defaults ) {
            return array_merge( $defaults, $parsed_args );
        }
        return $parsed_args;
    }
}

if ( ! function_exists( 'wp_parse_str' ) ) {
    function wp_parse_str( $string, &$array ) {
        parse_str( $string, $array );

        if ( get_magic_quotes_gpc() ) {
            $array = stripslashes_deep( $array );
        }

        $array = apply_filters( 'wp_parse_str', $array );
    }
}

if ( ! function_exists( 'stripslashes_deep' ) ) {
    function stripslashes_deep( $value ) {
        return map_deep( $value, 'stripslashes_from_strings_only' );
    }
}

if ( ! function_exists( 'map_deep' ) ) {
    function map_deep( $value, $callback ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $index => $item ) {
                $value[ $index ] = map_deep( $item, $callback );
            }
        } elseif ( is_object( $value ) ) {
            $object_vars = get_object_vars( $value );
            foreach ( $object_vars as $property_name => $property_value ) {
                $value->$property_name = map_deep( $property_value, $callback );
            }
        } else {
            $value = call_user_func( $callback, $value );
        }

        return $value;
    }
}

if ( ! function_exists( 'stripslashes_from_strings_only' ) ) {
    function stripslashes_from_strings_only( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
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

if ( ! function_exists( 'wpvdb_is_sqlite' ) ) {
    function wpvdb_is_sqlite() {
        return false;
    }
}

if ( ! function_exists( 'wpvdb_should_log_to_error_log' ) ) {
    function wpvdb_should_log_to_error_log( $level = 'debug', $message = '', $context = array() ) {
        return false;
    }
}

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
    define( 'ARRAY_N', 'ARRAY_N' );
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
        global $_wp_options;
        if ( ! isset( $_wp_options ) ) {
            $_wp_options = [];
        }
        return isset( $_wp_options[ $option ] ) ? $_wp_options[ $option ] : $default;
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
        public $prefix = 'wp_';
        public $dbname = 'test_db';

        public function get_var( $query = null, $x = 0, $y = 0 ) {
            // Default mock behavior
            if ( strpos( $query, 'SELECT VERSION()' ) !== false ) {
                return '8.0.32'; // Default MySQL version
            }
            if ( strpos( $query, 'SELECT 1' ) !== false ) {
                return '1';
            }
            return '';
        }

        public function prepare( $query, ...$args ) {
            return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
        }

        public function get_results( $query = null, $output = OBJECT ) {
            return [];
        }

        public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
            return null;
        }

        public function query( $query ) {
            return 0;
        }

        public function insert( $table, $data, $format = null ) {
            return 1;
        }

        public function esc_like( $text ) {
            return addcslashes( $text, '_%\\' );
        }

        public function suppress_errors( $suppress = true ) {
            // Mock implementation
        }
    }
}

// Mock global wpdb for testing
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new wpdb();
}

// Define basic WordPress constants if not defined
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) );
}

// Load essential classes that can work in isolation
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-providers.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-models.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-cache.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-core.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-database.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-embedding-enqueuer.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-queue.php';
require_once dirname( __DIR__ ) . '/includes/class-wpvdb-security.php';
