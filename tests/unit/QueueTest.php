<?php
/**
 * Class QueueTest
 *
 * Unit tests for WPVDB_Queue pure helpers (currently: build_item).
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPVDB\Models;
use WPVDB\WPVDB_Queue;

class QueueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		global $_wp_options;
		$_wp_options = [];
	}

	public function test_build_item_returns_canonical_shape() {
		$item = WPVDB_Queue::build_item( 42 );

		$this->assertIsArray( $item );
		$this->assertEqualsCanonicalizing( [ 'post_id', 'model', 'provider' ], array_keys( $item ) );
		$this->assertSame( 42, $item['post_id'] );
		$this->assertIsString( $item['model'] );
		$this->assertIsString( $item['provider'] );
	}

	public function test_build_item_casts_post_id_to_int() {
		$item = WPVDB_Queue::build_item( '123' );
		$this->assertSame( 123, $item['post_id'] );
	}

	public function test_build_item_uses_active_provider_from_settings() {
		global $_wp_options;
		$_wp_options['wpvdb_settings'] = [
			'active_provider' => 'automattic',
			'active_model'    => 'nomic-embed-text-v2-moe',
		];

		$item = WPVDB_Queue::build_item( 1 );
		$this->assertSame( 'automattic', $item['provider'] );
		$this->assertSame( 'nomic-embed-text-v2-moe', $item['model'] );
	}

	public function test_build_item_falls_back_to_openai_when_no_active_provider() {
		global $_wp_options;
		$_wp_options['wpvdb_settings'] = [
			'active_provider' => '',
			'active_model'    => '',
		];

		$item = WPVDB_Queue::build_item( 1 );
		$this->assertSame( 'openai', $item['provider'] );
	}

	public function test_build_item_provider_override_uses_that_providers_default_model() {
		global $_wp_options;
		$_wp_options['wpvdb_settings'] = [
			'active_provider' => 'openai',
			'active_model'    => 'text-embedding-3-small',
		];

		$item = WPVDB_Queue::build_item( 1, [ 'provider' => 'automattic' ] );
		$this->assertSame( 'automattic', $item['provider'] );
		$this->assertSame( Models::get_default_model_for_provider( 'automattic' ), $item['model'] );
	}

	public function test_build_item_model_override_takes_precedence() {
		global $_wp_options;
		$_wp_options['wpvdb_settings'] = [
			'active_provider' => 'openai',
			'active_model'    => 'text-embedding-3-small',
		];

		$item = WPVDB_Queue::build_item( 1, [ 'model' => 'custom-model-name' ] );
		$this->assertSame( 'custom-model-name', $item['model'] );
		$this->assertSame( 'openai', $item['provider'] );
	}

	public function test_build_item_both_overrides_used() {
		$item = WPVDB_Queue::build_item( 1, [ 'provider' => 'automattic', 'model' => 'custom' ] );
		$this->assertSame( 'automattic', $item['provider'] );
		$this->assertSame( 'custom', $item['model'] );
	}

	public function test_build_item_ignores_empty_string_overrides() {
		global $_wp_options;
		$_wp_options['wpvdb_settings'] = [
			'active_provider' => 'automattic',
			'active_model'    => 'nomic-embed-text-v2-moe',
		];

		$item = WPVDB_Queue::build_item( 1, [ 'provider' => '', 'model' => '' ] );
		$this->assertSame( 'automattic', $item['provider'] );
		$this->assertSame( 'nomic-embed-text-v2-moe', $item['model'] );
	}

	public function test_build_item_ignores_non_string_overrides() {
		global $_wp_options;
		$_wp_options['wpvdb_settings'] = [
			'active_provider' => 'automattic',
			'active_model'    => 'nomic-embed-text-v2-moe',
		];

		$item = WPVDB_Queue::build_item( 1, [ 'provider' => null, 'model' => 42 ] );
		$this->assertSame( 'automattic', $item['provider'] );
		$this->assertSame( 'nomic-embed-text-v2-moe', $item['model'] );
	}
}
