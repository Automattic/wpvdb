<?php
/**
 * Class Test_WPVDB_Core
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use WPVDB\Core;
use PHPUnit\Framework\TestCase;

/**
 * Test case for WPVDB Core class.
 */
class CoreTest extends TestCase {

    /**
     * Core instance
     *
     * @var Core
     */
    private $core;

    /**
     * Set up test fixtures.
     */
    public function setUp(): void {
        parent::setUp();
        $this->core = new Core();
    }

    /**
     * Test that Core class can be instantiated.
     */
    public function test_core_instantiation() {
        $this->assertInstanceOf( Core::class, $this->core );
    }

    /**
     * Test default chunking functionality.
     */
    public function test_default_chunking() {
        $text = 'This is a test text with multiple words that should be chunked properly.';
        $chunks = $this->core->default_chunking( [], $text );

        $this->assertIsArray( $chunks );
        $this->assertNotEmpty( $chunks );

        // Test that existing chunks are returned as-is
        $existing_chunks = [ 'existing chunk' ];
        $result = $this->core->default_chunking( $existing_chunks, $text );
        $this->assertEquals( $existing_chunks, $result );
    }

    /**
     * Test chunking with empty text.
     */
    public function test_chunking_with_empty_text() {
        $chunks = $this->core->default_chunking( [], '' );
        $this->assertIsArray( $chunks );
        // Should still return an array, even if empty text
    }

    /**
     * Test chunking with very long text.
     */
    public function test_chunking_with_long_text() {
        $text = str_repeat( 'This is a test sentence. ', 1000 );
        $chunks = $this->core->default_chunking( [], $text );

        $this->assertIsArray( $chunks );
        $this->assertNotEmpty( $chunks );

        // With long text, should produce multiple chunks
        $this->assertGreaterThan( 1, count( $chunks ) );
    }

    /**
     * Test that init method doesn't throw errors.
     */
    public function test_init_method() {
        // This should not throw any exceptions
        $this->core->init();
        $this->assertTrue( true ); // If we get here, init() worked
    }

    /**
     * Test maybe_show_db_warning_notice method doesn't throw errors.
     */
    public function test_maybe_show_db_warning_notice() {
        // Capture output to prevent test output pollution
        ob_start();
        $this->core->maybe_show_db_warning_notice();
        $output = ob_get_clean();

        // Should not throw exceptions and output should be string
        $this->assertIsString( $output );
    }
}