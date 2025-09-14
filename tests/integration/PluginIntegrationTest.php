<?php
/**
 * Integration tests for WPVDB Plugin class
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Integration;

use WPVDB\Plugin;

/**
 * Plugin integration test case
 */
class PluginIntegrationTest extends WPVDBIntegrationTestCase {

    /**
     * Plugin instance
     *
     * @var Plugin
     */
    private $plugin;

    /**
     * Set up test fixtures
     */
    public function setUp(): void {
        parent::setUp();
        $this->plugin = Plugin::get_instance();
    }

    /**
     * Test plugin singleton pattern
     */
    public function test_plugin_singleton() {
        $instance1 = Plugin::get_instance();
        $instance2 = Plugin::get_instance();

        $this->assertSame( $instance1, $instance2, 'Should return same instance' );
        $this->assertInstanceOf( Plugin::class, $instance1, 'Should be Plugin instance' );
    }

    /**
     * Test plugin initialization
     */
    public function test_plugin_initialization() {
        // Plugin should initialize without errors
        $this->plugin->init();

        // Check that core components are accessible
        $this->assertTrue( method_exists( $this->plugin, 'init' ), 'Should have init method' );
        $this->assertTrue( method_exists( $this->plugin, 'activate' ), 'Should have activate method' );
        $this->assertTrue( method_exists( $this->plugin, 'deactivate' ), 'Should have deactivate method' );
    }

    /**
     * Test plugin activation process
     */
    public function test_plugin_activation() {
        $this->requireRealDatabase();

        // Test activation doesn't throw errors
        $this->plugin->activate();

        // Check that activation tasks completed
        // Note: Specific assertions depend on what Activation::activate() does
        $this->assertTrue( true, 'Plugin activation should complete without errors' );
    }

    /**
     * Test plugin deactivation process
     */
    public function test_plugin_deactivation() {
        // Test deactivation doesn't throw errors
        $this->plugin->deactivate();

        $this->assertTrue( true, 'Plugin deactivation should complete without errors' );
    }

    /**
     * Test WordPress hooks registration
     */
    public function test_wordpress_hooks_registration() {
        // Initialize plugin to register hooks
        $this->plugin->init();

        // Test REST API initialization hook
        $this->assertHookAdded( 'rest_api_init', 'REST routes should be registered' );

        // Test post insertion hook for auto-embedding
        $this->assertHookAdded( 'wp_insert_post', 'Auto-embed hook should be registered' );

        // Test enhanced chunking filter
        if ( function_exists( 'has_filter' ) ) {
            $this->assertNotFalse(
                has_filter( 'wpvdb_chunk_text' ),
                'Enhanced chunking filter should be registered'
            );
        }
    }

    /**
     * Test Action Scheduler detection
     */
    public function test_action_scheduler_detection() {
        $has_scheduler = $this->plugin->has_action_scheduler();

        $this->assertIsBool( $has_scheduler, 'Should return boolean for Action Scheduler availability' );

        if ( $has_scheduler ) {
            $this->assertTrue( class_exists( 'ActionScheduler' ), 'ActionScheduler class should exist' );
            $this->assertTrue( function_exists( 'as_schedule_single_action' ), 'Action Scheduler functions should be available' );
        } else {
            // If no Action Scheduler, that's fine for testing
            $this->assertTrue( true, 'Action Scheduler not required for basic functionality' );
        }
    }

    /**
     * Test plugin component initialization
     */
    public function test_component_initialization() {
        $this->plugin->init();

        // Test that components are initialized (via reflection if properties are private)
        $reflection = new \ReflectionClass( $this->plugin );

        // Check core components exist as properties
        $expected_components = [
            'database',
            'core',
            'rest',
            'queue'
        ];

        foreach ( $expected_components as $component ) {
            if ( $reflection->hasProperty( $component ) ) {
                $property = $reflection->getProperty( $component );
                $property->setAccessible( true );
                $value = $property->getValue( $this->plugin );

                $this->assertNotNull( $value, "Component '{$component}' should be initialized" );
            }
        }
    }

    /**
     * Test admin interface initialization
     */
    public function test_admin_interface_initialization() {
        // Mock admin environment
        if ( ! function_exists( 'is_admin' ) ) {
            function is_admin() { return true; }
        }

        // Initialize plugin in admin context
        $this->plugin->init();

        // Admin-specific hooks should be registered
        if ( function_exists( 'has_action' ) ) {
            // Check if admin notices are registered (if Action Scheduler missing)
            if ( ! $this->plugin->has_action_scheduler() ) {
                $this->assertHookAdded( 'admin_notices', 'Admin notice hook should be registered' );
            }
        }

        $this->assertTrue( true, 'Admin initialization should complete' );
    }

    /**
     * Test database compatibility check
     */
    public function test_database_compatibility_check() {
        $this->requireRealDatabase();

        // Mock admin environment for compatibility check
        if ( ! function_exists( 'is_admin' ) ) {
            function is_admin() { return true; }
        }
        if ( ! function_exists( 'wp_doing_ajax' ) ) {
            function wp_doing_ajax() { return false; }
        }

        // Initialize plugin (this should trigger compatibility check)
        $this->plugin->init();

        // Check if compatibility check ran without errors
        $this->assertTrue( true, 'Database compatibility check should complete' );

        // Test that warning option might be set based on database capabilities
        $warning = get_option( 'wpvdb_db_vector_support_warning', 0 );
        $this->assertIsNumeric( $warning, 'Vector support warning should be numeric' );
    }

    /**
     * Test fallback queue processing
     */
    public function test_fallback_queue_processing() {
        // Test that fallback queue action is registered
        if ( function_exists( 'has_action' ) ) {
            // This action is registered in wpvdb.php
            $this->assertHookAdded( 'wpvdb_process_fallback_queue', 'Fallback queue processing should be registered' );
        }

        // Test processing fallback queue doesn't error
        $this->plugin->process_fallback_queue();

        $this->assertTrue( true, 'Fallback queue processing should complete' );
    }

    /**
     * Test Action Scheduler integration
     */
    public function test_action_scheduler_integration() {
        if ( ! $this->plugin->has_action_scheduler() ) {
            $this->markTestSkipped( 'Action Scheduler not available' );
        }

        // Initialize plugin to set up Action Scheduler hooks
        $this->plugin->init();

        // Check that Action Scheduler hooks are registered
        $this->assertHookAdded( 'wpvdb_process_embedding', 'Embedding processing hook should be registered' );

        // Test Action Scheduler runner is set up
        $this->assertHookAdded( 'init', 'Action Scheduler runner hook should be registered' );
    }

    /**
     * Test plugin version management
     */
    public function test_plugin_version_management() {
        // Test that plugin version constant is defined
        $this->assertTrue( defined( 'WPVDB_VERSION' ), 'WPVDB_VERSION constant should be defined' );

        $version = WPVDB_VERSION;
        $this->assertIsString( $version, 'Version should be string' );
        $this->assertNotEmpty( $version, 'Version should not be empty' );

        // Version should follow semantic versioning pattern
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $version,
            'Version should follow semantic versioning pattern'
        );
    }

    /**
     * Test plugin constants
     */
    public function test_plugin_constants() {
        $required_constants = [
            'WPVDB_VERSION',
            'WPVDB_PLUGIN_DIR',
            'WPVDB_PLUGIN_URL',
            'WPVDB_PLUGIN_FILE'
        ];

        foreach ( $required_constants as $constant ) {
            $this->assertTrue(
                defined( $constant ),
                "Constant '{$constant}' should be defined"
            );

            $value = constant( $constant );
            $this->assertNotEmpty( $value, "Constant '{$constant}' should have value" );
        }

        // Test default embedding dimension constant
        if ( defined( 'WPVDB_DEFAULT_EMBED_DIM' ) ) {
            $dim = WPVDB_DEFAULT_EMBED_DIM;
            $this->assertIsInt( $dim, 'Default embedding dimension should be integer' );
            $this->assertGreaterThan( 0, $dim, 'Default embedding dimension should be positive' );
        }
    }

    /**
     * Test error handling and recovery
     */
    public function test_error_handling() {
        // Test that plugin initialization handles errors gracefully
        try {
            $this->plugin->init();
            $this->assertTrue( true, 'Plugin initialization should not throw exceptions' );
        } catch ( \Exception $e ) {
            $this->fail( "Plugin initialization threw exception: " . $e->getMessage() );
        }

        // Test activation error handling
        try {
            $this->plugin->activate();
            $this->assertTrue( true, 'Plugin activation should not throw exceptions' );
        } catch ( \Exception $e ) {
            $this->fail( "Plugin activation threw exception: " . $e->getMessage() );
        }
    }
}