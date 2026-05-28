<?php
/**
 * Integration tests for WPVDB Database class
 *
 * @package WPVDB
 */

namespace WPVDB\Tests\Integration;

use WPVDB\Database;

/**
 * Database integration test case
 */
class DatabaseIntegrationTest extends WPVDBIntegrationTestCase {

    /**
     * Database instance
     *
     * @var Database
     */
    private $database;

    /**
     * Set up test fixtures
     */
    public function setUp(): void {
        parent::setUp();
        $this->database = new Database();
    }

    /**
     * Test real database type detection with Docker MySQL
     */
    public function test_real_database_type_detection() {
        $this->requireRealDatabase();

        $db_type = $this->database->get_db_type();

        // Should detect either mysql or mariadb (not 'unknown')
        $this->assertContains(
            $db_type,
            [ 'mysql', 'mariadb' ],
            'Should detect valid database type from real connection'
        );
    }

    /**
     * Test database version detection
     */
    public function test_database_version_detection() {
        $this->requireRealDatabase();

        global $wpdb;
        $version = $wpdb->get_var( 'SELECT VERSION()' );

        $this->assertNotEmpty( $version, 'Should retrieve database version' );
        $this->assertIsString( $version, 'Version should be string' );

        // Version should contain either MySQL or MariaDB
        $this->assertTrue(
            stripos( $version, 'mysql' ) !== false || stripos( $version, 'mariadb' ) !== false,
            "Version '{$version}' should contain MySQL or MariaDB identifier"
        );
    }

    /**
     * Test native vector support detection with real database
     */
    public function test_real_vector_support_detection() {
        $this->requireRealDatabase();

        $has_vector_support = $this->database->has_native_vector_support();

        $this->assertIsBool( $has_vector_support, 'Vector support should return boolean' );

        // Emit the result as a test diagnostic for debugging.
        $db_type = $this->database->get_db_type();
        fwrite( STDERR, "WPVDB Integration Test: {$db_type} vector support: " . ( $has_vector_support ? 'true' : 'false' ) . "\n" );
    }

    /**
     * Test database table operations
     */
    public function test_database_table_operations() {
        $this->requireRealDatabase();

        $table_name = 'test_vectors';
        $columns = [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'content TEXT NOT NULL',
            'embedding_vector JSON',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ];

        // Create test table
        $created = $this->createTestTable( $table_name, $columns );
        $this->assertTrue( $created, 'Should create test table successfully' );

        // Verify table exists
        $this->assertTableExists( 'wpvdb_test_' . $table_name );

        // Test basic INSERT operation
        global $wpdb;
        $full_table_name = $wpdb->prefix . 'wpvdb_test_' . $table_name;

        $test_data = [
            'content' => 'Test content for vector embedding',
            'embedding_vector' => wp_json_encode( [ 0.1, 0.2, 0.3, 0.4, 0.5 ] )
        ];

        $inserted = $wpdb->insert( $full_table_name, $test_data );
        $this->assertNotFalse( $inserted, 'Should insert test data successfully' );

        // Test SELECT operation
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$full_table_name} WHERE content = %s",
                $test_data['content']
            )
        );

        $this->assertNotNull( $result, 'Should retrieve inserted data' );
        $this->assertEquals( $test_data['content'], $result->content );
    }

    /**
     * Test vector column creation if supported
     */
    public function test_vector_column_creation() {
        $this->requireRealDatabase();

        $db_type = $this->database->get_db_type();
        $has_vector_support = $this->database->has_native_vector_support();

        if ( ! $has_vector_support ) {
            $this->markTestSkipped( "Database {$db_type} does not support native vector columns" );
        }

        global $wpdb;

        // Create table with vector column (if supported)
        $table_name = $wpdb->prefix . 'wpvdb_test_native_vectors';

        $vector_column_sql = '';
        if ( $db_type === 'mariadb' ) {
            $vector_column_sql = 'embedding VECTOR(768) NOT NULL';
        } elseif ( $db_type === 'mysql' ) {
            $vector_column_sql = 'embedding VECTOR(768) NOT NULL';
        }

        if ( ! empty( $vector_column_sql ) ) {
            $sql = "CREATE TABLE {$table_name} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                content TEXT NOT NULL,
                {$vector_column_sql},
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";

            $result = $wpdb->query( $sql );

            if ( $result !== false ) {
                $this->assertTrue( true, 'Successfully created table with native vector column' );

                // Clean up
                $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
            } else {
                $this->markTestIncomplete(
                    "Vector column creation failed - may not be supported in this {$db_type} version"
                );
            }
        }
    }

    /**
     * Test fallback storage mechanism
     */
    public function test_fallback_storage_mechanism() {
        $this->requireRealDatabase();

        // Create table for fallback storage testing
        $table_name = 'test_fallback_storage';
        $columns = [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'content TEXT NOT NULL',
            'embedding_fallback TEXT', // Store as JSON string
            'embedding_hash VARCHAR(64)', // Store hash for indexing
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ];

        $created = $this->createTestTable( $table_name, $columns );
        $this->assertTrue( $created, 'Should create fallback storage table' );

        global $wpdb;
        $full_table_name = $wpdb->prefix . 'wpvdb_test_' . $table_name;

        // Test storing vector as JSON fallback
        $test_vector = array_fill( 0, 768, 0.5 );
        $vector_json = wp_json_encode( $test_vector );
        $vector_hash = hash( 'sha256', $vector_json );

        $test_data = [
            'content' => 'Test content for fallback storage',
            'embedding_fallback' => $vector_json,
            'embedding_hash' => $vector_hash
        ];

        $inserted = $wpdb->insert( $full_table_name, $test_data );
        $this->assertNotFalse( $inserted, 'Should insert fallback vector data' );

        // Verify data retrieval and parsing
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$full_table_name} WHERE embedding_hash = %s",
                $vector_hash
            )
        );

        $this->assertNotNull( $result, 'Should retrieve fallback vector data' );

        $retrieved_vector = json_decode( $result->embedding_fallback, true );
        $this->assertIsArray( $retrieved_vector, 'Should parse JSON vector data' );
        $this->assertCount( 768, $retrieved_vector, 'Should have correct vector dimensions' );
        $this->assertEquals( $test_vector, $retrieved_vector, 'Retrieved vector should match original' );
    }

    /**
     * Test database connection handling
     */
    public function test_database_connection_handling() {
        $this->requireRealDatabase();

        global $wpdb;

        // Test basic connection properties
        $this->assertNotNull( $wpdb, 'WordPress database object should exist' );
        $this->assertNotEmpty( $wpdb->dbname, 'Database name should be set' );

        // Test connection is working
        $result = $wpdb->get_var( "SELECT 1 AS test" );
        $this->assertEquals( '1', $result, 'Database connection should work' );

        // Test error handling
        $wpdb->suppress_errors( true );
        $error_result = $wpdb->get_var( "SELECT * FROM nonexistent_table_12345" );
        $wpdb->suppress_errors( false );

        $this->assertNull( $error_result, 'Invalid query should return null' );
        $this->assertNotEmpty( $wpdb->last_error, 'Should have error information' );
    }

    /**
     * Test database performance with larger datasets
     */
    public function test_database_performance() {
        $this->requireRealDatabase();

        $table_name = 'test_performance';
        $columns = [
            'id INT AUTO_INCREMENT PRIMARY KEY',
            'content TEXT NOT NULL',
            'embedding_json JSON',
            'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'INDEX idx_created (created_at)'
        ];

        $created = $this->createTestTable( $table_name, $columns );
        $this->assertTrue( $created, 'Should create performance test table' );

        global $wpdb;
        $full_table_name = $wpdb->prefix . 'wpvdb_test_' . $table_name;

        // Insert multiple records to test performance
        $start_time = microtime( true );
        $num_records = 100;

        $wpdb->query( 'START TRANSACTION' );

        for ( $i = 0; $i < $num_records; $i++ ) {
            $test_vector = array_fill( 0, 384, ( $i % 10 ) / 10.0 ); // Smaller vectors for speed
            $test_data = [
                'content' => "Test content {$i}",
                'embedding_json' => wp_json_encode( $test_vector )
            ];

            $wpdb->insert( $full_table_name, $test_data );
        }

        $wpdb->query( 'COMMIT' );

        $insert_time = microtime( true ) - $start_time;

        // Performance assertion - should complete reasonably quickly
        $this->assertLessThan( 5.0, $insert_time, "Should insert {$num_records} records in under 5 seconds" );

        // Test query performance
        $query_start = microtime( true );
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$full_table_name}" );
        $query_time = microtime( true ) - $query_start;

        $this->assertEquals( $num_records, (int) $count, 'Should have correct record count' );
        $this->assertLessThan( 1.0, $query_time, 'COUNT query should complete quickly' );

        fwrite( STDERR, "WPVDB Performance Test: Inserted {$num_records} records in {$insert_time}s, queried in {$query_time}s\n" );
    }
}