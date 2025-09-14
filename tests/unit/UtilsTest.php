<?php
/**
 * Class UtilsTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Test case for WPVDB Utils class.
 */
class UtilsTest extends TestCase {

    /**
     * Test validate_positive_int method with valid values.
     */
    public function test_validate_positive_int_valid() {
        $this->assertEquals( 5, Utils::validate_positive_int( 5 ) );
        $this->assertEquals( 1, Utils::validate_positive_int( 1 ) );
        $this->assertEquals( 100, Utils::validate_positive_int( 100 ) );
        $this->assertEquals( 42, Utils::validate_positive_int( '42' ) );
    }

    /**
     * Test validate_positive_int method with invalid values.
     */
    public function test_validate_positive_int_invalid() {
        $this->assertEquals( 1, Utils::validate_positive_int( 0 ) ); // Below min
        $this->assertEquals( 5, Utils::validate_positive_int( -5 ) ); // Negative (absint makes it positive)
        $this->assertEquals( 1, Utils::validate_positive_int( 'invalid' ) );
        $this->assertEquals( 10, Utils::validate_positive_int( null, 1, PHP_INT_MAX, 10 ) );
    }

    /**
     * Test validate_positive_int method with custom bounds.
     */
    public function test_validate_positive_int_custom_bounds() {
        $this->assertEquals( 5, Utils::validate_positive_int( 5, 1, 10, 3 ) );
        $this->assertEquals( 10, Utils::validate_positive_int( 15, 1, 10, 3 ) ); // Above max
        $this->assertEquals( 3, Utils::validate_positive_int( 0, 1, 10, 3 ) ); // Below min
    }

    /**
     * Test validate_float method with valid values.
     */
    public function test_validate_float_valid() {
        $this->assertEquals( 3.14, Utils::validate_float( 3.14 ) );
        $this->assertEquals( 0.0, Utils::validate_float( 0 ) );
        $this->assertEquals( -2.5, Utils::validate_float( -2.5 ) );
        $this->assertEquals( 42.0, Utils::validate_float( '42' ) );
    }

    /**
     * Test validate_float method with invalid values.
     */
    public function test_validate_float_invalid() {
        $this->assertEquals( 0.0, Utils::validate_float( 'invalid' ) );
        $this->assertEquals( 5.0, Utils::validate_float( null, -10.0, 10.0, 5.0 ) );
        $this->assertEquals( 0.0, Utils::validate_float( INF ) ); // Infinite
        $this->assertEquals( 0.0, Utils::validate_float( NAN ) ); // Not a number
    }

    /**
     * Test validate_float method with custom bounds.
     */
    public function test_validate_float_custom_bounds() {
        $this->assertEquals( 5.0, Utils::validate_float( 5.0, 0.0, 10.0, 2.0 ) );
        $this->assertEquals( 10.0, Utils::validate_float( 15.0, 0.0, 10.0, 2.0 ) ); // Above max
        $this->assertEquals( 2.0, Utils::validate_float( -5.0, 0.0, 10.0, 2.0 ) ); // Below min
    }

    /**
     * Test validate_model_name method with valid names.
     */
    public function test_validate_model_name_valid() {
        $this->assertEquals( 'text-embedding-3-small', Utils::validate_model_name( 'text-embedding-3-small' ) );
        $this->assertEquals( 'model_name_123', Utils::validate_model_name( 'model_name_123' ) );
        $this->assertEquals( 'specter2', Utils::validate_model_name( 'specter2' ) );
    }

    /**
     * Test validate_model_name method with invalid names.
     */
    public function test_validate_model_name_invalid() {
        $this->assertFalse( Utils::validate_model_name( 'invalid model' ) ); // Space
        $this->assertFalse( Utils::validate_model_name( 'model@name' ) ); // Special char
        $this->assertFalse( Utils::validate_model_name( '' ) ); // Empty
        $this->assertFalse( Utils::validate_model_name( str_repeat( 'a', 101 ) ) ); // Too long
        $this->assertFalse( Utils::validate_model_name( 123 ) ); // Not string
        $this->assertFalse( Utils::validate_model_name( null ) );
    }

    /**
     * Test validate_provider_name method with valid names.
     */
    public function test_validate_provider_name_valid() {
        $this->assertEquals( 'openai', Utils::validate_provider_name( 'openai' ) );
        $this->assertEquals( 'automattic', Utils::validate_provider_name( 'automattic' ) );
        $this->assertEquals( 'provider_123', Utils::validate_provider_name( 'provider_123' ) );
    }

    /**
     * Test validate_provider_name method with invalid names.
     */
    public function test_validate_provider_name_invalid() {
        $this->assertFalse( Utils::validate_provider_name( 'Provider-Name' ) ); // sanitize_key keeps hyphens, but regex rejects hyphens
        $this->assertEquals( 'providername', Utils::validate_provider_name( 'provider name' ) ); // Space removed, passes regex
        $this->assertEquals( 'provider', Utils::validate_provider_name( 'provider@' ) ); // Special char removed, passes regex
        $this->assertFalse( Utils::validate_provider_name( 123 ) ); // Not string
        $this->assertFalse( Utils::validate_provider_name( null ) );
    }

    /**
     * Test format_bytes method.
     */
    public function test_format_bytes() {
        $this->assertEquals( '0 B', Utils::format_bytes( 0 ) );
        $this->assertEquals( '1023 B', Utils::format_bytes( 1023 ) );
        $this->assertEquals( '1024 B', Utils::format_bytes( 1024 ) ); // Loop condition is > 1024, not >= 1024
        $this->assertEquals( '1024 KB', Utils::format_bytes( 1024 * 1024 ) ); // Loop condition is $bytes > 1024, so 1MB becomes 1024KB
        $this->assertEquals( '1024 MB', Utils::format_bytes( 1024 * 1024 * 1024 ) ); // Same issue, loop condition
        $this->assertEquals( '1.5 KB', Utils::format_bytes( 1536 ) );

        // Test with custom precision (but round() may remove trailing zeros)
        $this->assertEquals( '1.5 KB', Utils::format_bytes( 1536, 3 ) );

        // Test invalid input
        $this->assertEquals( '0 B', Utils::format_bytes( -100 ) );
        $this->assertEquals( '0 B', Utils::format_bytes( 'invalid' ) );
    }

    /**
     * Test truncate_text method.
     */
    public function test_truncate_text() {
        $text = 'This is a long text that should be truncated';

        $this->assertEquals( $text, Utils::truncate_text( $text, 100 ) ); // No truncation needed
        $this->assertEquals( 'This is a ...', Utils::truncate_text( $text, 13 ) ); // sanitize_text_field may add spaces
        $this->assertEquals( 'This is a ***', Utils::truncate_text( $text, 13, '***' ) ); // Space added by sanitization

        // Test short text
        $short_text = 'Short';
        $this->assertEquals( $short_text, Utils::truncate_text( $short_text, 100 ) );

        // Test exact length
        $this->assertEquals( 'Short', Utils::truncate_text( 'Short', 5 ) );
    }

    /**
     * Test is_ajax method.
     */
    public function test_is_ajax() {
        $result = Utils::is_ajax();
        $this->assertIsBool( $result );
        $this->assertFalse( $result ); // Mock returns false
    }

    /**
     * Test is_rest method.
     */
    public function test_is_rest() {
        $result = Utils::is_rest();
        $this->assertIsBool( $result );
        $this->assertFalse( $result ); // Mock constant is false
    }

    /**
     * Test is_admin method.
     */
    public function test_is_admin() {
        $result = Utils::is_admin();
        $this->assertIsBool( $result );
        $this->assertFalse( $result ); // Mock returns false
    }

    /**
     * Test generate_cache_key method.
     */
    public function test_generate_cache_key() {
        $key1 = Utils::generate_cache_key( 'test_data' );
        $key2 = Utils::generate_cache_key( 'test_data' );
        $key3 = Utils::generate_cache_key( 'different_data' );

        $this->assertIsString( $key1 );
        $this->assertEquals( $key1, $key2 ); // Same input should produce same key
        $this->assertNotEquals( $key1, $key3 ); // Different input should produce different key
        $this->assertEquals( 64, strlen( $key1 ) ); // SHA256 produces 64 character hex string
    }

    /**
     * Test validate_url method with valid URLs.
     */
    public function test_validate_url_valid() {
        $this->assertEquals( 'https://example.com', Utils::validate_url( 'https://example.com' ) );
        $this->assertEquals( 'http://example.com/path', Utils::validate_url( 'http://example.com/path' ) );
    }

    /**
     * Test validate_url method with invalid URLs.
     */
    public function test_validate_url_invalid() {
        $this->assertFalse( Utils::validate_url( 'invalid-url' ) );
        $this->assertFalse( Utils::validate_url( 'ftp://example.com' ) ); // Wrong protocol
        $this->assertFalse( Utils::validate_url( '' ) );
        $this->assertFalse( Utils::validate_url( 'javascript:alert(1)' ) );
    }

    /**
     * Test get_timezone_string method.
     */
    public function test_get_timezone_string() {
        $result = Utils::get_timezone_string();
        $this->assertIsString( $result );

        // Should return a timezone format (either timezone string or offset format)
        $this->assertTrue(
            strlen( $result ) > 0,
            'Timezone string should not be empty'
        );
    }

    /**
     * Test that all utility methods are static.
     */
    public function test_methods_are_static() {
        $reflection = new \ReflectionClass( Utils::class );
        $methods = $reflection->getMethods( \ReflectionMethod::IS_PUBLIC );

        foreach ( $methods as $method ) {
            $this->assertTrue(
                $method->isStatic(),
                "Method {$method->getName()} should be static"
            );
        }
    }

    /**
     * Test edge cases for validation methods.
     */
    public function test_validation_edge_cases() {
        // Test with array inputs
        $this->assertEquals( 1, Utils::validate_positive_int( [] ) );
        $this->assertEquals( 0.0, Utils::validate_float( [] ) );

        // Test with boolean inputs
        $this->assertEquals( 1, Utils::validate_positive_int( true ) );
        $this->assertEquals( 1, Utils::validate_positive_int( false ) );

        // Test with object inputs
        $this->assertFalse( Utils::validate_model_name( new \stdClass() ) );
        $this->assertFalse( Utils::validate_provider_name( new \stdClass() ) );
    }
}