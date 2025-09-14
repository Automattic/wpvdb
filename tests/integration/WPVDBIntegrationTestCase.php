<?php
/**
 * Base integration test case for WPVDB
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that need WordPress functionality
 */
abstract class WPVDBIntegrationTestCase extends TestCase {

    /**
     * Set up integration test environment
     */
    public function setUp(): void {
        parent::setUp();

        // Reset WordPress state
        $this->resetWordPressState();

        // Ensure plugin is loaded
        $this->loadPlugin();
    }

    /**
     * Reset WordPress state between tests
     */
    protected function resetWordPressState() {
        // Reset global state
        global $wpdb;

        // Clean up any test data
        $this->cleanupTestData();

        // Reset options
        $this->resetTestOptions();
    }

    /**
     * Load the WPVDB plugin for testing
     */
    protected function loadPlugin() {
        // Plugin should be loaded via bootstrap, but ensure it's available
        if ( ! class_exists( 'WPVDB\Plugin' ) ) {
            require_once dirname( dirname( __DIR__ ) ) . '/wpvdb.php';
        }
    }

    /**
     * Clean up test data from database
     */
    protected function cleanupTestData() {
        global $wpdb;

        if ( ! $wpdb ) {
            return;
        }

        // Clean up any test tables or data
        $test_prefixes = [
            'wpvdb_test_',
            'test_wpvdb_'
        ];

        foreach ( $test_prefixes as $prefix ) {
            $tables = $wpdb->get_results(
                $wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $wpdb->esc_like( $wpdb->prefix . $prefix ) . '%'
                ),
                ARRAY_N
            );

            foreach ( $tables as $table ) {
                $wpdb->query( "DROP TABLE IF EXISTS {$table[0]}" );
            }
        }
    }

    /**
     * Reset test-related WordPress options
     */
    protected function resetTestOptions() {
        $test_options = [
            'wpvdb_test_option',
            'wpvdb_db_vector_support_warning',
            'wpvdb_require_auth',
            'wpvdb_fallback_storage',
        ];

        foreach ( $test_options as $option ) {
            delete_option( $option );
        }
    }

    /**
     * Create a test database table for testing
     *
     * @param string $table_name Table name without prefix
     * @param array  $columns    Column definitions
     * @return bool True on success
     */
    protected function createTestTable( $table_name, $columns = [] ) {
        global $wpdb;

        if ( empty( $columns ) ) {
            $columns = [
                'id INT AUTO_INCREMENT PRIMARY KEY',
                'content TEXT',
                'embedding JSON',
                'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ];
        }

        $full_table_name = $wpdb->prefix . 'wpvdb_test_' . $table_name;
        $sql = "CREATE TABLE {$full_table_name} (" . implode( ', ', $columns ) . ")";

        $result = $wpdb->query( $sql );

        return $result !== false;
    }

    /**
     * Check if we have a real database connection for integration tests
     *
     * @return bool True if real DB available
     */
    protected function hasRealDatabase() {
        global $wpdb;

        if ( ! $wpdb || ! method_exists( $wpdb, 'get_var' ) ) {
            return false;
        }

        // Try a simple query
        $result = $wpdb->get_var( "SELECT 1" );
        return $result === '1';
    }

    /**
     * Skip test if no real database available
     */
    protected function requireRealDatabase() {
        if ( ! $this->hasRealDatabase() ) {
            $this->markTestSkipped( 'Real database connection required for integration test' );
        }
    }

    /**
     * Assert that a WordPress option exists and has expected value
     *
     * @param string $option   Option name
     * @param mixed  $expected Expected value
     * @param string $message  Optional failure message
     */
    protected function assertOptionEquals( $option, $expected, $message = '' ) {
        $actual = get_option( $option );

        if ( empty( $message ) ) {
            $message = "Option '{$option}' should equal expected value";
        }

        $this->assertEquals( $expected, $actual, $message );
    }

    /**
     * Assert that a WordPress option exists
     *
     * @param string $option  Option name
     * @param string $message Optional failure message
     */
    protected function assertOptionExists( $option, $message = '' ) {
        if ( empty( $message ) ) {
            $message = "Option '{$option}' should exist";
        }

        $this->assertNotFalse( get_option( $option, false ), $message );
    }

    /**
     * Assert that a database table exists
     *
     * @param string $table_name Table name (without prefix)
     * @param string $message    Optional failure message
     */
    protected function assertTableExists( $table_name, $message = '' ) {
        global $wpdb;

        $this->requireRealDatabase();

        $full_table_name = $wpdb->prefix . $table_name;
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like( $full_table_name )
            )
        );

        if ( empty( $message ) ) {
            $message = "Table '{$full_table_name}' should exist";
        }

        $this->assertEquals( $full_table_name, $result, $message );
    }

    /**
     * Assert that a WordPress hook has been added
     *
     * @param string $hook     Hook name
     * @param mixed  $callback Callback function/method
     * @param string $message  Optional failure message
     */
    protected function assertHookAdded( $hook, $callback = null, $message = '' ) {
        if ( function_exists( 'has_action' ) ) {
            if ( $callback === null ) {
                $result = has_action( $hook );
                $this->assertNotFalse( $result, $message ?: "Hook '{$hook}' should be registered" );
            } else {
                $result = has_action( $hook, $callback );
                $this->assertNotFalse( $result, $message ?: "Hook '{$hook}' should have callback registered" );
            }
        } else {
            $this->markTestSkipped( 'WordPress hook functions not available' );
        }
    }

    /**
     * Clean up after each test
     */
    public function tearDown(): void {
        $this->cleanupTestData();
        parent::tearDown();
    }
}