<?php
/**
 * Class SecurityTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Security;
use PHPUnit\Framework\TestCase;

/**
 * Test case for WPVDB Security class.
 */
class SecurityTest extends TestCase {

    /**
     * Test rate limit constants.
     */
    public function test_rate_limit_constants() {
        $this->assertEquals( 'wpvdb_rate_limit_', Security::RATE_LIMIT_PREFIX );

        $expected_limits = [
            'embed' => 60,
            'query' => 120,
            'vectors' => 60,
        ];
        $this->assertEquals( $expected_limits, Security::DEFAULT_LIMITS );
    }

    /**
     * Test rate limit check for new endpoint.
     */
    public function test_check_rate_limit_new_endpoint() {
        $result = Security::check_rate_limit( 'embed' );
        $this->assertTrue( $result );
    }

    /**
     * Test rate limit check with custom limit.
     */
    public function test_check_rate_limit_custom_limit() {
        $result = Security::check_rate_limit( 'custom_endpoint', 100 );
        $this->assertTrue( $result );
    }

    /**
     * Test rate limiting disabled.
     */
    public function test_check_rate_limit_disabled() {
        $result = Security::check_rate_limit( 'embed', 0 );
        $this->assertTrue( $result );

        $result2 = Security::check_rate_limit( 'embed', -1 );
        $this->assertTrue( $result2 );
    }

    /**
     * Test endpoint sanitization.
     */
    public function test_endpoint_sanitization() {
        // Test that special characters are handled
        $result = Security::check_rate_limit( 'test-endpoint_123' );
        $this->assertTrue( $result );
    }

    /**
     * Test default limits for known endpoints.
     */
    public function test_default_limits() {
        // The method should use default limits from the constants
        $result_embed = Security::check_rate_limit( 'embed' );
        $result_query = Security::check_rate_limit( 'query' );
        $result_vectors = Security::check_rate_limit( 'vectors' );

        $this->assertTrue( $result_embed );
        $this->assertTrue( $result_query );
        $this->assertTrue( $result_vectors );
    }

    /**
     * Test unknown endpoint gets default rate limit.
     */
    public function test_unknown_endpoint_default() {
        $result = Security::check_rate_limit( 'unknown_endpoint' );
        $this->assertTrue( $result );
    }

    /**
     * Test verify_nonce method with valid setup.
     */
    public function test_verify_nonce_valid() {
        // Mock WP_REST_Request
        $request = $this->createMockRequest();

        $result = Security::verify_nonce( $request );
        $this->assertTrue( $result );
    }

    /**
     * Test verify_nonce method with custom action.
     */
    public function test_verify_nonce_custom_action() {
        $request = $this->createMockRequest();

        $result = Security::verify_nonce( $request, 'custom_action' );
        $this->assertTrue( $result );
    }

    /**
     * Test log_security_event method.
     */
    public function test_log_security_event() {
        // This should not throw any exceptions
        Security::log_security_event( 'test_event', [ 'key' => 'value' ] );

        // Test with empty data
        Security::log_security_event( 'test_event' );

        $this->assertTrue( true ); // If we get here, no exceptions were thrown
    }

    /**
     * Test security event logging with different event types.
     */
    public function test_log_security_event_different_types() {
        $events = [
            'rate_limit_exceeded',
            'invalid_nonce',
            'unauthorized_access',
            'suspicious_request'
        ];

        foreach ( $events as $event ) {
            Security::log_security_event( $event, [ 'test' => true ] );
        }

        $this->assertTrue( true ); // If we get here, no exceptions were thrown
    }

    /**
     * Test that security functions handle edge cases.
     */
    public function test_edge_cases() {
        // Test with empty endpoint name
        $result = Security::check_rate_limit( '' );
        $this->assertTrue( $result );

        // Test with null limit
        $result2 = Security::check_rate_limit( 'test', null );
        $this->assertTrue( $result2 );

        // Test logging with null event
        Security::log_security_event( null );
        $this->assertTrue( true );
    }

    /**
     * Test rate limiting with very high limit.
     */
    public function test_rate_limit_high_limit() {
        $result = Security::check_rate_limit( 'test', 999999 );
        $this->assertTrue( $result );
    }

    /**
     * Test that methods are callable statically.
     */
    public function test_static_methods() {
        $this->assertTrue( method_exists( Security::class, 'check_rate_limit' ) );
        $this->assertTrue( method_exists( Security::class, 'verify_nonce' ) );
        $this->assertTrue( method_exists( Security::class, 'log_security_event' ) );

        // Test reflection to ensure methods are static
        $reflection = new \ReflectionClass( Security::class );
        $check_method = $reflection->getMethod( 'check_rate_limit' );
        $verify_method = $reflection->getMethod( 'verify_nonce' );
        $log_method = $reflection->getMethod( 'log_security_event' );

        $this->assertTrue( $check_method->isStatic() );
        $this->assertTrue( $verify_method->isStatic() );
        $this->assertTrue( $log_method->isStatic() );
    }

    /**
     * Create a mock WP_REST_Request object.
     */
    private function createMockRequest() {
        return new class {
            public function get_header( $key ) {
                if ( $key === 'X-WP-Nonce' ) {
                    return 'test_nonce';
                }
                return null;
            }

            public function get_param( $key ) {
                if ( $key === '_wpnonce' ) {
                    return 'test_nonce';
                }
                return null;
            }
        };
    }
}