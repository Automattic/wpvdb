<?php
/**
 * Class ModelsTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Models;
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

        // Check that expected providers are present
        $this->assertArrayHasKey( 'openai', $models );
        $this->assertArrayHasKey( 'automattic', $models );
        $this->assertArrayHasKey( 'specter', $models );

        // Check OpenAI models structure
        $openai_models = $models['openai'];
        $this->assertIsArray( $openai_models );
        $this->assertArrayHasKey( 'text-embedding-3-small', $openai_models );

        $small_model = $openai_models['text-embedding-3-small'];
        $this->assertArrayHasKey( 'name', $small_model );
        $this->assertArrayHasKey( 'label', $small_model );
        $this->assertArrayHasKey( 'dimensions', $small_model );
        $this->assertArrayHasKey( 'provider', $small_model );
    }

    /**
     * Test getting models for a specific provider.
     */
    public function test_get_provider_models_openai() {
        $openai_models = Models::get_provider_models( 'openai' );

        $this->assertIsArray( $openai_models );
        $this->assertNotEmpty( $openai_models );
        $this->assertArrayHasKey( 'text-embedding-3-small', $openai_models );
        $this->assertArrayHasKey( 'text-embedding-3-large', $openai_models );
        $this->assertArrayHasKey( 'text-embedding-ada-002', $openai_models );
    }

    /**
     * Test getting models for Automattic provider.
     */
    public function test_get_provider_models_automattic() {
        $automattic_models = Models::get_provider_models( 'automattic' );

        $this->assertIsArray( $automattic_models );
        $this->assertNotEmpty( $automattic_models );
        $this->assertArrayHasKey( 'a8cai-embeddings-small-1', $automattic_models );
        $this->assertArrayHasKey( 'a8cai-embeddings-large-1', $automattic_models );
    }

    /**
     * Test getting models for SPECTER provider.
     */
    public function test_get_provider_models_specter() {
        $specter_models = Models::get_provider_models( 'specter' );

        $this->assertIsArray( $specter_models );
        $this->assertNotEmpty( $specter_models );
        $this->assertArrayHasKey( 'specter2', $specter_models );
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
     * Test getting default model for OpenAI.
     */
    public function test_get_default_model_for_provider_openai() {
        $default_model = Models::get_default_model_for_provider( 'openai' );
        $this->assertEquals( 'text-embedding-3-small', $default_model );
    }

    /**
     * Test getting default model for Automattic.
     */
    public function test_get_default_model_for_provider_automattic() {
        $default_model = Models::get_default_model_for_provider( 'automattic' );
        $this->assertEquals( 'a8cai-embeddings-small-1', $default_model );
    }

    /**
     * Test getting default model for SPECTER.
     */
    public function test_get_default_model_for_provider_specter() {
        $default_model = Models::get_default_model_for_provider( 'specter' );
        $this->assertEquals( 'specter2', $default_model );
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
        $small_model = Models::get_model( 'openai', 'text-embedding-3-small' );
        $this->assertEquals( 1536, $small_model['dimensions'] );

        $large_model = Models::get_model( 'openai', 'text-embedding-3-large' );
        $this->assertEquals( 3072, $large_model['dimensions'] );

        $automattic_small = Models::get_model( 'automattic', 'a8cai-embeddings-small-1' );
        $this->assertEquals( 512, $automattic_small['dimensions'] );

        $automattic_large = Models::get_model( 'automattic', 'a8cai-embeddings-large-1' );
        $this->assertEquals( 1024, $automattic_large['dimensions'] );

        $specter_model = Models::get_model( 'specter', 'specter2' );
        $this->assertEquals( 768, $specter_model['dimensions'] );
    }

    /**
     * Test that all models have required fields.
     */
    public function test_all_models_have_required_fields() {
        $all_models = Models::get_available_models();

        foreach ( $all_models as $provider => $models ) {
            foreach ( $models as $model_name => $model ) {
                // Each model should have these required fields
                $this->assertArrayHasKey( 'name', $model, "Model $provider.$model_name missing 'name' field" );
                $this->assertArrayHasKey( 'label', $model, "Model $provider.$model_name missing 'label' field" );
                $this->assertArrayHasKey( 'dimensions', $model, "Model $provider.$model_name missing 'dimensions' field" );
                $this->assertArrayHasKey( 'provider', $model, "Model $provider.$model_name missing 'provider' field" );

                // Validate field types
                $this->assertIsString( $model['name'], "Model $provider.$model_name 'name' should be string" );
                $this->assertIsString( $model['label'], "Model $provider.$model_name 'label' should be string" );
                $this->assertIsInt( $model['dimensions'], "Model $provider.$model_name 'dimensions' should be integer" );
                $this->assertIsString( $model['provider'], "Model $provider.$model_name 'provider' should be string" );

                // Validate dimensions are positive
                $this->assertGreaterThan( 0, $model['dimensions'], "Model $provider.$model_name dimensions should be positive" );

                // Validate provider matches
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
                // Model key should match the model's name field
                $this->assertEquals(
                    $model_key,
                    $model['name'],
                    "Model key '$model_key' should match model name '{$model['name']}'"
                );
            }
        }
    }
}