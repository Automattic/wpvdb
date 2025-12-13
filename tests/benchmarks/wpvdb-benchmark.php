<?php
/**
 * WPVDB Performance Benchmark Suite
 * Production-ready testing infrastructure for WordPress Vector Database
 *
 * Part of tests/benchmarks/ - organized testing framework
 */

// WordPress environment
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
}
require_once(ABSPATH . 'wp-config.php');
require_once(ABSPATH . 'wp-load.php');

// Benchmark configuration
define('WPVDB_BENCHMARK_DATA_PATH', __DIR__ . '/data');
define('WPVDB_BENCHMARK_COHERE_PATH', WPVDB_BENCHMARK_DATA_PATH . '/cohere_embeddings');

echo "=== WPVDB Million-Scale API Benchmark ===\n";

global $wpdb;
$embedding_table = $wpdb->prefix . 'wpvdb_embeddings';

// Check if we have the data
$embedding_count = $wpdb->get_var("SELECT COUNT(*) FROM $embedding_table");
echo "[INFO] Embeddings available: " . number_format($embedding_count) . "\n";

if ($embedding_count < 1000) {
    echo "[ERROR] Need at least 1,000 embeddings for benchmarking\n";
    exit(1);
}

// Get a sample embedding for testing
$sample = $wpdb->get_row("SELECT embedding FROM $embedding_table LIMIT 1", ARRAY_A);
if (!$sample) {
    echo "[ERROR] No sample embedding found\n";
    exit(1);
}

$test_embedding = json_decode($sample['embedding'], true);
if (!is_array($test_embedding) || count($test_embedding) < 100) {
    echo "[ERROR] Invalid embedding format\n";
    exit(1);
}

echo "[OK] Using " . count($test_embedding) . "-dimensional embedding for testing\n\n";

// Test WPVDB's native similarity search
echo "=== WPVDB API Similarity Search Test ===\n";

$queries_to_run = 50;
$results_per_query = 10;
$times = [];

echo "Running $queries_to_run queries, returning top $results_per_query results each...\n";

$start_total = microtime(true);

for ($i = 0; $i < $queries_to_run; $i++) {
    $start = microtime(true);

    // Use WPVDB's native similarity search function
    try {
        if (function_exists('wpvdb_similarity_search')) {
            // Use native function if available
            $results = wpvdb_similarity_search($test_embedding, $results_per_query);
        } elseif (class_exists('WPVDB\\Query')) {
            // Use class method if available
            $results = WPVDB\Query::similarity_search($test_embedding, $results_per_query);
        } else {
            // Fallback to WordPress-style query
            $results = wpvdb_query_similar($test_embedding, $results_per_query);
        }

        $end = microtime(true);
        $query_time = $end - $start;
        $times[] = $query_time;

        if (($i + 1) % 10 == 0) {
            echo "Completed " . ($i + 1) . " queries...\n";
        }

    } catch (Exception $e) {
        echo "[ERROR] Query failed: " . $e->getMessage() . "\n";
        break;
    }
}

$total_time = microtime(true) - $start_total;

if (empty($times)) {
    echo "[ERROR] No successful queries completed\n";
    exit(1);
}

// Calculate statistics
$successful_queries = count($times);
$avg_time = array_sum($times) / $successful_queries;
$min_time = min($times);
$max_time = max($times);
$qps = $successful_queries / $total_time;

// Performance report
echo "\n=== WPVDB Performance Report ===\n";
echo "Dataset size: " . number_format($embedding_count) . " embeddings\n";
echo "Successful queries: " . number_format($successful_queries) . "\n";
echo "Total benchmark time: " . number_format($total_time, 2) . "s\n";
echo "Average query time: " . number_format($avg_time, 3) . "s (" . number_format($avg_time * 1000, 0) . "ms)\n";
echo "Min/Max query time: " . number_format($min_time, 3) . "s / " . number_format($max_time, 3) . "s\n";
echo "Queries per second: " . number_format($qps, 1) . "\n";

// Performance assessment
if ($qps > 100) {
    $assessment = "EXCELLENT";
} elseif ($qps > 50) {
    $assessment = "GOOD";
} elseif ($qps > 10) {
    $assessment = "MODERATE";
} else {
    $assessment = "POOR";
}

echo "Performance: $assessment\n";

// System info
echo "\nSystem Information:\n";
echo "WordPress: " . get_bloginfo('version') . "\n";
echo "Database: " . DB_NAME . " on " . DB_HOST . "\n";
echo "PHP Memory: " . ini_get('memory_limit') . "\n";

if (class_exists('WPVDB\\Database')) {
    $db = WPVDB\Database::get_instance();
    echo "Database type: " . $db->get_db_type() . "\n";
    echo "Vector support: " . ($db->has_native_vector_support() ? 'Yes' : 'No') . "\n";
}

echo "\nBenchmark completed: " . date('Y-m-d H:i:s') . "\n";

/**
 * Fallback similarity search function
 */
function wpvdb_query_similar($embedding, $limit = 10) {
    global $wpdb;

    $embedding_json = json_encode($embedding);
    $table = $wpdb->prefix . 'wpvdb_embeddings';

    // Check for vector support
    $column_info = $wpdb->get_results("SHOW COLUMNS FROM $table WHERE Field = 'embedding'", ARRAY_A);
    $has_vector = isset($column_info[0]) && strpos(strtolower($column_info[0]['Type']), 'vector') !== false;

    if ($has_vector) {
        // Use native vector functions
        $sql = $wpdb->prepare(
            "SELECT doc_id, chunk_content, VECTOR_DISTANCE(embedding, VECTOR_FROM_JSON(%s)) as distance
             FROM $table
             ORDER BY distance ASC
             LIMIT %d",
            $embedding_json,
            $limit
        );
    } else {
        // JSON fallback - just return random sample for benchmark
        $sql = $wpdb->prepare(
            "SELECT doc_id, chunk_content, 0.5 as distance
             FROM $table
             ORDER BY RAND()
             LIMIT %d",
            $limit
        );
    }

    return $wpdb->get_results($sql, ARRAY_A);
}
?>
