<?php
/**
 * PHPUnit hook for resetting standalone WordPress filter mocks.
 *
 * @package WPVDB
 */

use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * Reset the mocked WordPress filter registry around every test.
 */
class WPVDB_Filter_Reset_Hook implements BeforeTestHook, AfterTestHook {

	/**
	 * Reset filters before each test starts.
	 *
	 * @param string $test Test name.
	 */
	public function executeBeforeTest( string $test ): void {
		$this->reset_filters();
	}

	/**
	 * Reset filters after each test finishes.
	 *
	 * @param string $test Test name.
	 * @param float  $time Test runtime.
	 */
	public function executeAfterTest( string $test, float $time ): void {
		$this->reset_filters();
	}

	/**
	 * Reset the standalone filter registry.
	 */
	private function reset_filters(): void {
		$GLOBALS['_wp_filters'] = array();
	}
}
