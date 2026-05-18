<?php
/**
 * Class LoggerTest
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB\Logger;

/**
 * Test case for WPVDB Logger class.
 */
class LoggerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $_wp_options;
        $_wp_options = [];
        Logger::clear_logs();
    }

    protected function tearDown(): void {
        Logger::clear_logs();
        parent::tearDown();
    }

    public function test_storage_is_disabled_by_default() {
        $this->assertFalse( Logger::storage_enabled() );
    }

    public function test_log_entries_are_available_in_memory_when_storage_is_disabled() {
        Logger::error( 'Test error', [ 'api_key' => 'secret' ] );

        $logs = Logger::get_logs();
        $this->assertCount( 1, $logs );
        $this->assertEquals( 'error', $logs[0]['level'] );
        $this->assertEquals( 'Test error', $logs[0]['message'] );
        $this->assertEquals( '[REDACTED]', $logs[0]['context']['api_key'] );
        $this->assertFalse( get_option( Logger::LOG_OPTION, false ) );
    }

    public function test_flush_does_not_write_option_when_storage_is_disabled() {
        Logger::error( 'Test error' );
        Logger::flush_pending_entries();

        $this->assertFalse( get_option( Logger::LOG_OPTION, false ) );
        $this->assertCount( 1, Logger::get_logs() );
    }

    public function test_clear_logs_does_not_relog_clear_event() {
        Logger::error( 'Test error' );
        Logger::clear_logs();

        $this->assertSame( [], Logger::get_logs() );
        $this->assertFalse( get_option( Logger::LOG_OPTION, false ) );
    }
}
