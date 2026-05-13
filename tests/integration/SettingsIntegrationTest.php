<?php
/**
 * Integration tests for WPVDB Settings class
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Integration;

use WPVDB\Models;
use WPVDB\Settings;

/**
 * Settings integration test case
 */
class SettingsIntegrationTest extends WPVDBIntegrationTestCase {

    /**
     * Test settings registration with WordPress
     */
    public function test_settings_registration() {
        // Initialize settings
        Settings::init();

        // Verify settings registration doesn't error
        if ( function_exists( 'did_action' ) && function_exists( 'do_action' ) ) {
            // Trigger admin_init action to register settings
            do_action( 'admin_init' );

            $this->assertTrue( true, 'Settings registration should complete without errors' );
        } else {
            $this->markTestSkipped( 'WordPress admin functions not available' );
        }
    }

    /**
     * Test default settings values
     */
    public function test_default_settings() {
        $defaults = Settings::get_defaults();

        // Test that defaults are properly structured
        $this->assertIsArray( $defaults, 'Defaults should be array' );
        $this->assertArrayHasKey( 'active_provider', $defaults );
        $this->assertArrayHasKey( 'default_model', $defaults );
        $this->assertArrayHasKey( 'chunk_size', $defaults );
        $this->assertArrayHasKey( 'batch_size', $defaults );

        // Test default values
        $this->assertEquals( 'openai', $defaults['active_provider'] );
        $this->assertEquals( Models::get_default_model_for_provider( 'openai' ), $defaults['default_model'] );
        $this->assertIsInt( $defaults['chunk_size'] );
        $this->assertGreaterThan( 0, $defaults['chunk_size'] );
    }

    /**
     * Test settings persistence
     */
    public function test_settings_persistence() {
        $test_settings = [
            'active_provider' => 'automattic',
            'default_model' => Models::get_default_model_for_provider( 'automattic' ),
            'chunk_size' => 300,
            'batch_size' => 10,
            'require_auth' => 0
        ];

        // Save settings
        update_option( 'wpvdb_settings', $test_settings );

        // Retrieve and verify
        $saved_settings = get_option( 'wpvdb_settings' );
        $this->assertEquals( $test_settings, $saved_settings, 'Settings should persist correctly' );

        // Test validated settings merge with defaults
        $validated = Settings::get_validated_settings();
        $this->assertArrayHasKey( 'active_provider', $validated );
        $this->assertArrayHasKey( 'openai', $validated ); // Default should be merged
        $this->assertEquals( 'automattic', $validated['active_provider'] );
        $this->assertEquals( 300, $validated['chunk_size'] );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test individual setting getters
     */
    public function test_setting_getters() {
        $test_settings = [
            'chunk_size' => 150,
            'batch_size' => 8,
            'active_provider' => 'openai',
            'enable_summarization' => 1,
            'auto_embed_post_types' => [ 'post', 'page', 'custom' ]
        ];

        update_option( 'wpvdb_settings', $test_settings );

        // Test individual getters
        $this->assertEquals( 150, Settings::get_chunk_size() );
        $this->assertEquals( 8, Settings::get_batch_size() );
        $this->assertEquals( 'openai', Settings::get_active_provider() );
        $this->assertTrue( Settings::is_summarization_enabled() );
        $this->assertEquals( [ 'post', 'page', 'custom' ], Settings::get_auto_embed_post_types() );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test API key management
     */
    public function test_api_key_management() {
        // Test with no API key
        $api_key = Settings::get_api_key();
        $this->assertIsString( $api_key, 'API key should be string (even if empty)' );

        // Test with settings-stored API key
        $test_settings = [
            'active_provider' => 'openai',
            'openai' => [
                'api_key' => 'test_openai_key'
            ]
        ];

        update_option( 'wpvdb_settings', $test_settings );

        $api_key = Settings::get_api_key();
        $this->assertEquals( 'test_openai_key', $api_key );

        // Test provider-specific API key retrieval
        $openai_key = Settings::get_api_key_for_provider( 'openai' );
        $this->assertEquals( 'test_openai_key', $openai_key );

        $automattic_key = Settings::get_api_key_for_provider( 'automattic' );
        $this->assertEmpty( $automattic_key );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test API base URL management
     */
    public function test_api_base_management() {
        $api_base = Settings::get_api_base();
        $this->assertIsString( $api_base, 'API base should be string' );

        // Test with custom API base
        $test_settings = [
            'active_provider' => 'openai',
            'openai' => [
                'api_base' => 'https://custom.openai.endpoint/v1'
            ]
        ];

        update_option( 'wpvdb_settings', $test_settings );

        $custom_base = Settings::get_api_base();
        $this->assertStringContainsString( 'custom.openai.endpoint', $custom_base );

        // Test provider-specific API base
        $openai_base = Settings::get_api_base_for_provider( 'openai' );
        $this->assertEquals( 'https://custom.openai.endpoint/v1', $openai_base );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test model management
     */
    public function test_model_management() {
        // Test default model
        $default_model = Settings::get_default_model();
        $this->assertIsString( $default_model, 'Default model should be string' );
        $this->assertNotEmpty( $default_model, 'Default model should not be empty' );

        // Test with custom model settings
        $test_settings = [
            'active_provider' => 'automattic',
            'default_model' => Models::get_default_model_for_provider( 'openai' ),
            'automattic' => [
                'default_model' => Models::get_default_model_for_provider( 'automattic' )
            ]
        ];

        update_option( 'wpvdb_settings', $test_settings );

        $active_model = Settings::get_active_model();
        $this->assertIsString( $active_model, 'Active model should be string' );

        $provider_model = Settings::get_model_for_provider( 'automattic' );
        $this->assertEquals( Models::get_default_model_for_provider( 'automattic' ), $provider_model );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test settings validation
     */
    public function test_settings_validation() {
        $invalid_settings = [
            'active_provider' => 'invalid_provider',
            'chunk_size' => -50, // Invalid negative
            'batch_size' => 'not_a_number',
            'require_auth' => 'yes' // Should be boolean
        ];

        // Test validation
        $validated = Settings::validate_settings( $invalid_settings );

        $this->assertIsArray( $validated, 'Validation should return array' );

        // Check that invalid values were corrected or removed
        if ( isset( $validated['chunk_size'] ) ) {
            $this->assertGreaterThan( 0, $validated['chunk_size'], 'Chunk size should be positive' );
        }

        if ( isset( $validated['batch_size'] ) ) {
            $this->assertIsInt( $validated['batch_size'], 'Batch size should be integer' );
        }
    }

    /**
     * Test configuration validation
     */
    public function test_configuration_validation() {
        // Test with valid configuration
        $valid_settings = [
            'active_provider' => 'openai',
            'openai' => [
                'api_key' => 'test_key'
            ]
        ];

        update_option( 'wpvdb_settings', $valid_settings );

        $is_valid = Settings::is_configuration_valid();
        $this->assertIsBool( $is_valid, 'Configuration validation should return boolean' );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test pending changes functionality
     */
    public function test_pending_changes() {
        // Test with no pending changes
        $pending = Settings::get_pending_change_details();
        $this->assertFalse( $pending, 'Should return false when no pending changes' );

        // Test with pending changes
        $test_settings = [
            'pending_provider' => 'automattic',
            'pending_model' => Models::get_default_model_for_provider( 'openai' )
        ];

        update_option( 'wpvdb_settings', $test_settings );

        $pending = Settings::get_pending_change_details();
        $this->assertNotFalse( $pending, 'Should return details when pending changes exist' );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test settings with WordPress constants
     */
    public function test_settings_with_constants() {
        // Define test constant if not already defined
        if ( ! defined( 'WPVDB_OPENAI_API_KEY' ) ) {
            define( 'WPVDB_OPENAI_API_KEY', 'constant_test_key' );
        }

        $settings = [
            'active_provider' => 'openai'
        ];

        update_option( 'wpvdb_settings', $settings );

        // API key should come from constant
        $api_key = Settings::get_api_key();
        $this->assertEquals( 'constant_test_key', $api_key, 'Should use constant over settings' );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }

    /**
     * Test settings sanitization
     */
    public function test_settings_sanitization() {
        $dirty_settings = [
            'active_provider' => '<script>alert("xss")</script>openai',
            'chunk_size' => '300<script>',
            'auto_embed_post_types' => [ 'post', '<script>page</script>' ]
        ];

        $sanitized = Settings::validate_settings( $dirty_settings );

        // Check that dangerous content was sanitized
        if ( isset( $sanitized['active_provider'] ) ) {
            $this->assertStringNotContainsString( '<script>', $sanitized['active_provider'] );
        }

        if ( isset( $sanitized['auto_embed_post_types'] ) && is_array( $sanitized['auto_embed_post_types'] ) ) {
            foreach ( $sanitized['auto_embed_post_types'] as $post_type ) {
                $this->assertStringNotContainsString( '<script>', $post_type );
            }
        }
    }

    /**
     * Test settings edge cases
     */
    public function test_settings_edge_cases() {
        // Test with null settings
        delete_option( 'wpvdb_settings' );
        update_option( 'wpvdb_settings', null );

        $validated = Settings::get_validated_settings();
        $this->assertIsArray( $validated, 'Should return array even with null settings' );

        // Test with empty array
        update_option( 'wpvdb_settings', [] );
        $validated = Settings::get_validated_settings();
        $this->assertIsArray( $validated, 'Should return array with empty settings' );
        $this->assertArrayHasKey( 'active_provider', $validated, 'Should merge defaults' );

        // Test with string instead of array
        update_option( 'wpvdb_settings', 'invalid' );
        $validated = Settings::get_validated_settings();
        $this->assertIsArray( $validated, 'Should handle invalid settings format' );

        // Clean up
        delete_option( 'wpvdb_settings' );
    }
}
