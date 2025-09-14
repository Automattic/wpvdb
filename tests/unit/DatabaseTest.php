<?php
/**
 * Class DatabaseTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Database;
use PHPUnit\Framework\TestCase;

/**
 * Test case for WPVDB Database class.
 */
class DatabaseTest extends TestCase {

    /**
     * Database instance
     *
     * @var Database
     */
    private $database;

    /**
     * Set up test fixtures.
     */
    public function setUp(): void {
        parent::setUp();
        $this->database = new Database();
    }

    /**
     * Test that Database class can be instantiated.
     */
    public function test_database_instantiation() {
        $this->assertInstanceOf( Database::class, $this->database );
    }

    /**
     * Test get_db_type method with default MySQL version.
     */
    public function test_get_db_type_mysql() {
        $result = $this->database->get_db_type();
        // Default mock returns MySQL version
        $this->assertEquals( 'mysql', $result );
    }

    /**
     * Test are_fallbacks_enabled method.
     */
    public function test_are_fallbacks_enabled_default() {
        $result = $this->database->are_fallbacks_enabled();
        // Should return false by default (mocked apply_filters returns false)
        $this->assertFalse( $result );
    }

    /**
     * Test has_native_vector_support method basic functionality.
     */
    public function test_has_native_vector_support_basic() {
        // This should call the method without throwing exceptions
        $result = $this->database->has_native_vector_support();
        $this->assertIsBool( $result );
    }

    /**
     * Test database type caching functionality.
     */
    public function test_database_type_caching() {
        // Call get_db_type multiple times on same instance
        $result1 = $this->database->get_db_type();
        $result2 = $this->database->get_db_type();
        $result3 = $this->database->get_db_type();

        // Should return consistent results (cached)
        $this->assertEquals( $result1, $result2 );
        $this->assertEquals( $result2, $result3 );
        $this->assertIsString( $result1 );
    }

    /**
     * Test fallback caching.
     */
    public function test_fallback_caching() {
        // Call are_fallbacks_enabled multiple times
        $result1 = $this->database->are_fallbacks_enabled();
        $result2 = $this->database->are_fallbacks_enabled();

        // Should return consistent results (cached)
        $this->assertEquals( $result1, $result2 );
        $this->assertIsBool( $result1 );
    }
}