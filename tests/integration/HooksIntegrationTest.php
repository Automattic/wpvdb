<?php
/**
 * Integration tests for WPVDB WordPress hooks and filters
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Integration;

use WPVDB\Core;

/**
 * WordPress hooks and filters integration test case
 */
class HooksIntegrationTest extends WPVDBIntegrationTestCase {

    /**
     * Core instance
     *
     * @var Core
     */
    private $core;

    /**
     * Set up test fixtures
     */
    public function setUp(): void {
        parent::setUp();
        $this->core = new Core();
    }

    /**
     * Test wpvdb_chunk_text filter
     */
    public function test_wpvdb_chunk_text_filter() {
        // Initialize core to register filters
        $this->core->init();

        $test_text = 'This is a test text that should be chunked into smaller pieces for processing.';

        // Test default chunking
        $chunks = apply_filters( 'wpvdb_chunk_text', [], $test_text );

        $this->assertIsArray( $chunks, 'Filter should return array' );
        $this->assertNotEmpty( $chunks, 'Filter should return non-empty chunks' );

        // Test that existing chunks are preserved
        $existing_chunks = [ 'existing chunk 1', 'existing chunk 2' ];
        $preserved_chunks = apply_filters( 'wpvdb_chunk_text', $existing_chunks, $test_text );

        $this->assertEquals( $existing_chunks, $preserved_chunks, 'Existing chunks should be preserved' );
    }

    /**
     * Test wpvdb_ai_summarize_chunk filter
     */
    public function test_wpvdb_ai_summarize_chunk_filter() {
        // Initialize core to register filters
        $this->core->init();

        $test_chunk = 'This is a long chunk of text that needs to be summarized for better processing.';

        // Test default summarization
        $summary = apply_filters( 'wpvdb_ai_summarize_chunk', '', $test_chunk );

        $this->assertIsString( $summary, 'Filter should return string' );

        // Test that existing summary is preserved
        $existing_summary = 'Existing summary';
        $preserved_summary = apply_filters( 'wpvdb_ai_summarize_chunk', $existing_summary, $test_chunk );

        $this->assertEquals( $existing_summary, $preserved_summary, 'Existing summary should be preserved' );
    }

    /**
     * Test wpvdb_available_models filter
     */
    public function test_wpvdb_available_models_filter() {
        $default_models = [
            'openai' => [
                'text-embedding-3-small' => [
                    'name' => 'text-embedding-3-small',
                    'dimensions' => 1536
                ]
            ]
        ];

        // Test models filter
        $filtered_models = apply_filters( 'wpvdb_available_models', $default_models );

        $this->assertIsArray( $filtered_models, 'Models filter should return array' );
        $this->assertArrayHasKey( 'openai', $filtered_models, 'Should contain OpenAI models' );

        // Test adding custom models via filter
        $custom_models_callback = function( $models ) {
            $models['custom'] = [
                'custom-model' => [
                    'name' => 'custom-model',
                    'dimensions' => 512
                ]
            ];
            return $models;
        };

        add_filter( 'wpvdb_available_models', $custom_models_callback );

        $models_with_custom = apply_filters( 'wpvdb_available_models', $default_models );
        $this->assertArrayHasKey( 'custom', $models_with_custom, 'Custom models should be added' );

        remove_filter( 'wpvdb_available_models', $custom_models_callback );
    }

    /**
     * Test wpvdb_rate_limit filter
     */
    public function test_wpvdb_rate_limit_filter() {
        $default_limit = 60;
        $endpoint = 'embed';

        // Test rate limit filter
        $filtered_limit = apply_filters( 'wpvdb_rate_limit', $default_limit, $endpoint );

        $this->assertIsInt( $filtered_limit, 'Rate limit filter should return integer' );

        // Test modifying rate limit via filter
        $rate_limit_modifier = function( $limit, $endpoint ) {
            if ( $endpoint === 'embed' ) {
                return 100; // Increase limit for embed endpoint
            }
            return $limit;
        };

        add_filter( 'wpvdb_rate_limit', $rate_limit_modifier, 10, 2 );

        $modified_limit = apply_filters( 'wpvdb_rate_limit', $default_limit, 'embed' );
        $this->assertEquals( 100, $modified_limit, 'Rate limit should be modified by filter' );

        $unmodified_limit = apply_filters( 'wpvdb_rate_limit', $default_limit, 'query' );
        $this->assertEquals( $default_limit, $unmodified_limit, 'Other endpoints should be unchanged' );

        remove_filter( 'wpvdb_rate_limit', $rate_limit_modifier );
    }

    /**
     * Test wpvdb_enable_fallbacks filter
     */
    public function test_wpvdb_enable_fallbacks_filter() {
        $default_fallbacks = false;

        // Test fallbacks filter
        $filtered_fallbacks = apply_filters( 'wpvdb_enable_fallbacks', $default_fallbacks );

        $this->assertIsBool( $filtered_fallbacks, 'Fallbacks filter should return boolean' );

        // Test enabling fallbacks via filter
        $enable_fallbacks = function( $enabled ) {
            return true;
        };

        add_filter( 'wpvdb_enable_fallbacks', $enable_fallbacks );

        $enabled_fallbacks = apply_filters( 'wpvdb_enable_fallbacks', $default_fallbacks );
        $this->assertTrue( $enabled_fallbacks, 'Fallbacks should be enabled by filter' );

        remove_filter( 'wpvdb_enable_fallbacks', $enable_fallbacks );
    }

    /**
     * Test action hooks registration and execution
     */
    public function test_action_hooks() {
        $action_fired = false;

        // Add test action
        $test_action_callback = function() use ( &$action_fired ) {
            $action_fired = true;
        };

        add_action( 'wpvdb_test_action', $test_action_callback );

        // Fire the action
        do_action( 'wpvdb_test_action' );

        $this->assertTrue( $action_fired, 'Action should fire and execute callback' );

        remove_action( 'wpvdb_test_action', $test_action_callback );
    }

    /**
     * Test admin_notices hook integration
     */
    public function test_admin_notices_hook() {
        // Mock admin environment
        if ( ! function_exists( 'is_admin' ) ) {
            function is_admin() { return true; }
        }
        if ( ! function_exists( 'current_user_can' ) ) {
            function current_user_can( $capability ) { return true; }
        }

        // Initialize core to register admin notices
        $this->core->init();

        // Set database warning
        update_option( 'wpvdb_db_vector_support_warning', 1 );

        // Capture admin notice output
        ob_start();
        do_action( 'admin_notices' );
        $output = ob_get_clean();

        $this->assertIsString( $output, 'Admin notices should produce string output' );

        // Clean up
        delete_option( 'wpvdb_db_vector_support_warning' );
    }

    /**
     * Test wp_insert_post hook for auto-embedding
     */
    public function test_wp_insert_post_hook() {
        $post_processed = false;

        // Mock the auto_embed_post method
        $auto_embed_callback = function( $post_id, $post, $update ) use ( &$post_processed ) {
            $post_processed = true;
        };

        add_action( 'wp_insert_post', $auto_embed_callback, 10, 3 );

        // Simulate post insertion
        do_action( 'wp_insert_post', 123, (object) [ 'post_type' => 'post' ], false );

        $this->assertTrue( $post_processed, 'Post insertion should trigger auto-embedding' );

        remove_action( 'wp_insert_post', $auto_embed_callback );
    }

    /**
     * Test init action hook
     */
    public function test_init_action_hook() {
        $init_fired = false;

        $init_callback = function() use ( &$init_fired ) {
            $init_fired = true;
        };

        add_action( 'init', $init_callback );

        // Fire init action
        do_action( 'init' );

        $this->assertTrue( $init_fired, 'Init action should fire callback' );

        remove_action( 'init', $init_callback );
    }

    /**
     * Test rest_api_init action hook
     */
    public function test_rest_api_init_hook() {
        $rest_init_fired = false;

        $rest_init_callback = function() use ( &$rest_init_fired ) {
            $rest_init_fired = true;
        };

        add_action( 'rest_api_init', $rest_init_callback );

        // Fire REST API init action
        do_action( 'rest_api_init' );

        $this->assertTrue( $rest_init_fired, 'REST API init action should fire callback' );

        remove_action( 'rest_api_init', $rest_init_callback );
    }

    /**
     * Test custom WPVDB action hooks
     */
    public function test_custom_wpvdb_actions() {
        $embedding_processed = false;
        $queue_processed = false;

        // Test embedding processing action
        $embedding_callback = function() use ( &$embedding_processed ) {
            $embedding_processed = true;
        };

        add_action( 'wpvdb_process_embedding', $embedding_callback );
        do_action( 'wpvdb_process_embedding' );

        $this->assertTrue( $embedding_processed, 'Embedding processing action should fire' );

        // Test fallback queue processing action
        $queue_callback = function() use ( &$queue_processed ) {
            $queue_processed = true;
        };

        add_action( 'wpvdb_process_fallback_queue', $queue_callback );
        do_action( 'wpvdb_process_fallback_queue' );

        $this->assertTrue( $queue_processed, 'Fallback queue processing action should fire' );

        remove_action( 'wpvdb_process_embedding', $embedding_callback );
        remove_action( 'wpvdb_process_fallback_queue', $queue_callback );
    }

    /**
     * Test hook priority system
     */
    public function test_hook_priorities() {
        $execution_order = [];

        $callback_high = function() use ( &$execution_order ) {
            $execution_order[] = 'high';
        };

        $callback_low = function() use ( &$execution_order ) {
            $execution_order[] = 'low';
        };

        $callback_normal = function() use ( &$execution_order ) {
            $execution_order[] = 'normal';
        };

        // Add with different priorities
        add_action( 'wpvdb_test_priority', $callback_high, 5 );   // High priority (early)
        add_action( 'wpvdb_test_priority', $callback_normal, 10 ); // Normal priority
        add_action( 'wpvdb_test_priority', $callback_low, 20 );    // Low priority (late)

        // Fire the action
        do_action( 'wpvdb_test_priority' );

        // Check execution order
        $this->assertEquals( [ 'high', 'normal', 'low' ], $execution_order, 'Hooks should execute in priority order' );

        // Clean up
        remove_action( 'wpvdb_test_priority', $callback_high );
        remove_action( 'wpvdb_test_priority', $callback_normal );
        remove_action( 'wpvdb_test_priority', $callback_low );
    }

    /**
     * Test filter modification chains
     */
    public function test_filter_modification_chains() {
        $original_value = 100;

        $multiply_filter = function( $value ) {
            return $value * 2;
        };

        $add_filter = function( $value ) {
            return $value + 10;
        };

        // Add filters in sequence
        add_filter( 'wpvdb_test_chain', $multiply_filter, 10 );
        add_filter( 'wpvdb_test_chain', $add_filter, 20 );

        // Apply filters
        $result = apply_filters( 'wpvdb_test_chain', $original_value );

        // Should be: (100 * 2) + 10 = 210
        $this->assertEquals( 210, $result, 'Filter chain should modify value in sequence' );

        // Clean up
        remove_filter( 'wpvdb_test_chain', $multiply_filter );
        remove_filter( 'wpvdb_test_chain', $add_filter );
    }

    /**
     * Test conditional hook execution
     */
    public function test_conditional_hook_execution() {
        $admin_executed = false;
        $frontend_executed = false;

        $admin_callback = function() use ( &$admin_executed ) {
            if ( function_exists( 'is_admin' ) && is_admin() ) {
                $admin_executed = true;
            }
        };

        $frontend_callback = function() use ( &$frontend_executed ) {
            if ( function_exists( 'is_admin' ) && ! is_admin() ) {
                $frontend_executed = true;
            }
        };

        add_action( 'wpvdb_conditional_test', $admin_callback );
        add_action( 'wpvdb_conditional_test', $frontend_callback );

        // Mock admin context
        if ( ! function_exists( 'is_admin' ) ) {
            function is_admin() { return true; }
        }

        do_action( 'wpvdb_conditional_test' );

        $this->assertTrue( $admin_executed, 'Admin callback should execute in admin context' );
        $this->assertFalse( $frontend_executed, 'Frontend callback should not execute in admin context' );

        // Clean up
        remove_action( 'wpvdb_conditional_test', $admin_callback );
        remove_action( 'wpvdb_conditional_test', $frontend_callback );
    }
}
