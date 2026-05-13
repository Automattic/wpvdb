<?php
/**
 * Class ModelsTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Models;
use WPVDB\Providers;
use PHPUnit\Framework\TestCase;

/**
 * Test case for WPVDB Models class.
 */
class ModelsTest extends TestCase {

    /**
     * Test that Models class provides available models.
     */
    public function test_get_available_models() {
        $models = Models::get_available_models();

        $this->assertIsArray( $models );
        $this->assertNotEmpty( $models );
        $this->assertArrayHasKey( 'openai', $models );
        $this->assertArrayHasKey( 'automattic', $models );
        $this->assertArrayHasKey( 'specter', $models );
    }

    /**
     * Test getting expected models for each provider.
     */
    public function test_get_provider_models() {
        $expected = [
            'openai' => [
                'text-embedding-3-small',
                'text-embedding-3-large',
                'text-embedding-ada-002',
            ],
            'automattic' => [
                'nomic-embed-text-v2-moe',
                'nomic-embed-text-formatted',
                'text-embedding-3-small',
            ],
            'specter' => [
                'specter2',
            ],
        ];

        foreach ( $expected as $provider => $model_names ) {
            $models = Models::get_provider_models( $provider );

            $this->assertIsArray( $models );
            $this->assertNotEmpty( $models );
            foreach ( $model_names as $model_name ) {
                $this->assertArrayHasKey( $model_name, $models );
            }
        }
    }

    /**
     * Test getting models for non-existent provider.
     */
    public function test_get_provider_models_non_existent() {
        $models = Models::get_provider_models( 'nonexistent' );

        $this->assertIsArray( $models );
        $this->assertEmpty( $models );
    }

    /**
     * Test getting a specific model.
     */
    public function test_get_model_valid() {
        $model = Models::get_model( 'openai', 'text-embedding-3-small' );

        $this->assertIsArray( $model );
        $this->assertEquals( 'text-embedding-3-small', $model['name'] );
        $this->assertEquals( 'openai', $model['provider'] );
        $this->assertEquals( 1536, $model['dimensions'] );
        $this->assertIsString( $model['label'] );
    }

    /**
     * Test getting a non-existent model.
     */
    public function test_get_model_non_existent() {
        $model = Models::get_model( 'openai', 'non-existent-model' );
        $this->assertNull( $model );

        $model2 = Models::get_model( 'non-existent-provider', 'any-model' );
        $this->assertNull( $model2 );
    }

    /**
     * Test getting default model for each provider.
     */
    public function test_get_default_model_for_provider() {
        $expected = [
            'openai' => 'text-embedding-3-small',
            'automattic' => 'nomic-embed-text-v2-moe',
            'specter' => 'specter2',
        ];

        foreach ( $expected as $provider => $default_model ) {
            $this->assertEquals( $default_model, Models::get_default_model_for_provider( $provider ) );
        }
    }

    /**
     * Test getting default model for unknown provider.
     */
    public function test_get_default_model_for_provider_unknown() {
        $default_model = Models::get_default_model_for_provider( 'unknown-provider' );
        $this->assertEquals( '', $default_model );
    }

    /**
     * Test model dimensions are correct.
     */
    public function test_model_dimensions() {
        $expected = [
            'openai' => [
                'text-embedding-3-small' => 1536,
                'text-embedding-3-large' => 3072,
            ],
            'automattic' => [
                'nomic-embed-text-v2-moe' => 768,
                'text-embedding-3-small' => 1536,
            ],
            'specter' => [
                'specter2' => 768,
            ],
        ];

        foreach ( $expected as $provider => $models ) {
            foreach ( $models as $model_name => $dimensions ) {
                $model = Models::get_model( $provider, $model_name );
                $this->assertEquals( $dimensions, $model['dimensions'] );
            }
        }
    }

    /**
     * Test that all models have required fields.
     */
    public function test_all_models_have_required_fields() {
        $all_models = Models::get_available_models();

        foreach ( $all_models as $provider => $models ) {
            foreach ( $models as $model_name => $model ) {
                $this->assertArrayHasKey( 'name', $model, "Model $provider.$model_name missing 'name' field" );
                $this->assertArrayHasKey( 'label', $model, "Model $provider.$model_name missing 'label' field" );
                $this->assertArrayHasKey( 'dimensions', $model, "Model $provider.$model_name missing 'dimensions' field" );
                $this->assertArrayHasKey( 'provider', $model, "Model $provider.$model_name missing 'provider' field" );
                $this->assertArrayHasKey( 'request_format', $model, "Model $provider.$model_name missing 'request_format' field" );
                $this->assertArrayHasKey( 'response_format', $model, "Model $provider.$model_name missing 'response_format' field" );

                $this->assertIsString( $model['name'], "Model $provider.$model_name 'name' should be string" );
                $this->assertIsString( $model['label'], "Model $provider.$model_name 'label' should be string" );
                $this->assertIsInt( $model['dimensions'], "Model $provider.$model_name 'dimensions' should be integer" );
                $this->assertIsString( $model['provider'], "Model $provider.$model_name 'provider' should be string" );
                $this->assertIsString( $model['request_format'], "Model $provider.$model_name 'request_format' should be string" );
                $this->assertIsString( $model['response_format'], "Model $provider.$model_name 'response_format' should be string" );

                $this->assertGreaterThan( 0, $model['dimensions'], "Model $provider.$model_name dimensions should be positive" );
                $this->assertEquals( $provider, $model['provider'], "Model $provider.$model_name provider mismatch" );
            }
        }
    }

    /**
     * Test model name consistency.
     */
    public function test_model_name_consistency() {
        $all_models = Models::get_available_models();

        foreach ( $all_models as $provider => $models ) {
            foreach ( $models as $model_key => $model ) {
                $this->assertEquals(
                    $model_key,
                    $model['name'],
                    "Model key '$model_key' should match model name '{$model['name']}'"
                );
            }
        }
    }

    /**
     * Test selectable model filtering.
     */
    public function test_get_selectable_provider_models() {
        $openai_models = Models::get_selectable_provider_models( 'openai' );
        $automattic_models = Models::get_selectable_provider_models( 'automattic' );

        $this->assertArrayHasKey( 'text-embedding-3-small', $openai_models );
        $this->assertArrayHasKey( 'text-embedding-3-large', $openai_models );
        $this->assertArrayNotHasKey( 'text-embedding-ada-002', $openai_models );

        $this->assertArrayHasKey( 'nomic-embed-text-v2-moe', $automattic_models );
        $this->assertArrayHasKey( 'text-embedding-3-small', $automattic_models );
        $this->assertArrayNotHasKey( 'nomic-embed-text-formatted', $automattic_models );
    }

    /**
     * Test storage compatibility policy.
     */
    public function test_storage_compatibility() {
        $openai_base = 'https://api.openai.com/v1/';
        $proxy_base = 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/';

        $this->assertTrue( Models::is_storage_compatible( 'text-embedding-3-small', $openai_base, 768 ) );
        $this->assertTrue( Models::is_storage_compatible( 'text-embedding-3-large', $openai_base, 768 ) );
        $this->assertFalse( Models::is_storage_compatible( 'text-embedding-ada-002', $openai_base, 768 ) );
        $this->assertTrue( Models::is_storage_compatible( 'nomic-embed-text-v2-moe', $proxy_base, 768 ) );
        $this->assertFalse( Models::is_storage_compatible( 'nomic-embed-text-formatted', $proxy_base, 768 ) );
    }

    /**
     * Test request metadata helpers.
     */
    public function test_request_metadata_helpers() {
        $proxy_base = 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/';

        $this->assertEquals( 'a8c_nomic_native', Models::get_request_format( 'nomic-embed-text-v2-moe', $proxy_base ) );
        $this->assertEquals( 'embeddings/text', Models::get_endpoint( 'nomic-embed-text-v2-moe', $proxy_base ) );
        $this->assertEquals( [ 'model' => 'nomic-embed-text-v2-moe' ], Models::get_request_query_args( 'nomic-embed-text-v2-moe', $proxy_base ) );

        $this->assertEquals( 'openai', Models::get_request_format( 'nomic-embed-text-formatted', $proxy_base ) );
        $this->assertEquals( [ 'format' => 'openai' ], Models::get_request_query_args( 'nomic-embed-text-formatted', $proxy_base ) );

        $this->assertTrue( Models::supports_dimensions( 'text-embedding-3-small', $proxy_base ) );
        $this->assertFalse( Models::supports_dimensions( 'nomic-embed-text-v2-moe', $proxy_base ) );
    }

    /**
     * Test known provider bases do not fall through to another provider's metadata.
     */
    public function test_model_request_metadata_does_not_cross_known_providers() {
        $this->assertNull( Models::get_model_for_request( 'nomic-embed-text-v2-moe', 'https://api.openai.com/v1/' ) );
        $this->assertEquals( 'openai', Models::get_request_format( 'nomic-embed-text-v2-moe', 'https://api.openai.com/v1/' ) );
    }

    /**
     * Test Automattic AI proxy URL detection.
     */
    public function test_automattic_ai_proxy_url_detection() {
        $this->assertTrue( Providers::is_automattic_ai_proxy_url( 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1' ) );
        $this->assertTrue( Providers::is_automattic_ai_proxy_url( 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/embeddings' ) );
        $this->assertFalse( Providers::is_automattic_ai_proxy_url( 'http://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1/embeddings' ) );
        $this->assertFalse( Providers::is_automattic_ai_proxy_url( 'https://api.openai.com/v1/embeddings' ) );
    }
}
