<?php
/**
 * Class SettingsTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB\Providers;
use WPVDB\Settings;

/**
 * Test case for WPVDB Settings class.
 */
class SettingsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $_wp_options;
        $_wp_options = [];
    }

    public function test_validate_settings_populates_missing_active_model() {
        $validated = Settings::validate_settings(
            [
                'active_provider' => 'automattic',
                'automattic' => [
                    'default_model' => 'nomic-embed-text-v2-moe',
                ],
            ]
        );

        $this->assertEquals( 'automattic', $validated['active_provider'] );
        $this->assertEquals( 'nomic-embed-text-v2-moe', $validated['active_model'] );
        $this->assertEquals( 'nomic-embed-text-v2-moe', $validated['default_model'] );
    }

    public function test_validate_settings_replaces_mismatched_active_model() {
        $validated = Settings::validate_settings(
            [
                'active_provider' => 'automattic',
                'active_model' => 'text-embedding-ada-002',
                'automattic' => [
                    'default_model' => 'nomic-embed-text-v2-moe',
                ],
            ]
        );

        $this->assertEquals( 'nomic-embed-text-v2-moe', $validated['active_model'] );
    }

    public function test_validate_settings_strips_endpoint_suffixes_from_api_base() {
        $validated = Settings::validate_settings(
            [
                'active_provider' => 'automattic',
                'automattic' => [
                    'api_base' => 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/embeddings/text',
                ],
                'openai' => [
                    'api_base' => 'https://api.openai.com/v1/embeddings',
                ],
            ]
        );

        $this->assertEquals( Providers::get_api_base( 'automattic' ), $validated['automattic']['api_base'] );
        $this->assertEquals( Providers::get_api_base( 'openai' ), $validated['openai']['api_base'] );
    }

    public function test_migrate_stored_settings_normalizes_existing_values() {
        update_option(
            'wpvdb_settings',
            [
                'active_provider' => 'automattic',
                'active_model' => 'text-embedding-ada-002',
                'automattic' => [
                    'api_base' => 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/embeddings',
                ],
            ]
        );

        $this->assertTrue( Settings::migrate_stored_settings() );

        $settings = get_option( 'wpvdb_settings' );
        $this->assertEquals( 'nomic-embed-text-v2-moe', $settings['active_model'] );
        $this->assertEquals( 'nomic-embed-text-v2-moe', $settings['automattic']['default_model'] );
        $this->assertEquals( Providers::get_api_base( 'automattic' ), $settings['automattic']['api_base'] );
    }
}
