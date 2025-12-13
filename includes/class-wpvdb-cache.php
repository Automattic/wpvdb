<?php
namespace WPVDB;

defined('ABSPATH') || exit;

/**
 * Caching utilities for WPVDB
 * Provides smart caching for embeddings, queries, and expensive operations
 */
class Cache {
    
    /**
     * Cache group for WordPress object cache
     */
    const CACHE_GROUP = 'wpvdb';
    
    /**
     * Default cache expiration times (in seconds)
     */
    const EXPIRATION_TIMES = [
        'embedding' => 3600,        // 1 hour
        'query_result' => 1800,     // 30 minutes
        'model_config' => 7200,     // 2 hours
        'db_stats' => 300,          // 5 minutes
    ];
    
    /**
     * Get cached embedding by hash
     *
     * @param string $text Text that was embedded
     * @param string $model Model used for embedding
     * @return array|false Embedding array or false if not cached
     */
    public static function get_embedding($text, $model) {
        $cache_key = self::get_embedding_cache_key($text, $model);
        return wp_cache_get($cache_key, self::CACHE_GROUP);
    }
    
    /**
     * Cache an embedding
     *
     * @param string $text Text that was embedded
     * @param string $model Model used for embedding
     * @param array $embedding Embedding vector
     * @return bool Success
     */
    public static function set_embedding($text, $model, $embedding) {
        $cache_key = self::get_embedding_cache_key($text, $model);
        $expiration = self::EXPIRATION_TIMES['embedding'];
        
        return wp_cache_set($cache_key, $embedding, self::CACHE_GROUP, $expiration);
    }
    
    /**
     * Get cached query result
     *
     * @param string $query_text Query text
     * @param string $model Model used
     * @param int $limit Result limit
     * @return array|false Query results or false if not cached
     */
    public static function get_query_result($query_text, $model, $limit) {
        $cache_key = self::get_query_cache_key($query_text, $model, $limit);
        return wp_cache_get($cache_key, self::CACHE_GROUP);
    }
    
    /**
     * Cache a query result
     *
     * @param string $query_text Query text
     * @param string $model Model used
     * @param int $limit Result limit
     * @param array $results Query results
     * @return bool Success
     */
    public static function set_query_result($query_text, $model, $limit, $results) {
        $cache_key = self::get_query_cache_key($query_text, $model, $limit);
        $expiration = self::EXPIRATION_TIMES['query_result'];
        
        return wp_cache_set($cache_key, $results, self::CACHE_GROUP, $expiration);
    }
    
    /**
     * Get cached database statistics
     *
     * @return array|false Database stats or false if not cached
     */
    public static function get_db_stats() {
        return wp_cache_get('db_stats', self::CACHE_GROUP);
    }
    
    /**
     * Cache database statistics
     *
     * @param array $stats Database statistics
     * @return bool Success
     */
    public static function set_db_stats($stats) {
        $expiration = self::EXPIRATION_TIMES['db_stats'];
        return wp_cache_set('db_stats', $stats, self::CACHE_GROUP, $expiration);
    }
    
    /**
     * Invalidate cache when embeddings are updated
     *
     * @param int $doc_id Document ID that was updated
     */
    public static function invalidate_document_cache($doc_id) {
        // Invalidate query results since document embeddings changed
        self::flush_cache_group('query_');
        
        // Invalidate database stats
        wp_cache_delete('db_stats', self::CACHE_GROUP);
        
        // Log cache invalidation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[WPVDB CACHE] Invalidated cache for document {$doc_id}");
        }
    }
    
    /**
     * Generate cache key for embedding
     *
     * @param string $text Text content
     * @param string $model Model name
     * @return string Cache key
     */
    private static function get_embedding_cache_key($text, $model) {
        // Use hash to avoid very long cache keys and ensure uniqueness
        $text_hash = hash('sha256', $text);
        return "embedding_{$model}_{$text_hash}";
    }
    
    /**
     * Generate cache key for query result
     *
     * @param string $query_text Query text
     * @param string $model Model name
     * @param int $limit Result limit
     * @return string Cache key
     */
    private static function get_query_cache_key($query_text, $model, $limit) {
        // Use hash to avoid very long cache keys
        $query_hash = hash('sha256', $query_text);
        return "query_{$model}_{$query_hash}_{$limit}";
    }
    
    /**
     * Flush all cache entries with a specific prefix
     *
     * @param string $prefix Cache key prefix
     */
    private static function flush_cache_group($prefix) {
        // WordPress doesn't have a built-in way to flush by prefix
        // So we'll use a cache invalidation marker
        $invalidation_key = "invalidation_{$prefix}_" . time();
        wp_cache_set($invalidation_key, true, self::CACHE_GROUP, 60);
        
        // In a more robust implementation, you might maintain a list of keys
        // or use a more advanced caching system like Redis
    }
    
    /**
     * Clear all WPVDB caches
     */
    public static function flush_all() {
        // WordPress doesn't have wp_cache_flush_group, so we'll use invalidation timestamps
        $timestamp = time();
        wp_cache_set('cache_invalidation_timestamp', $timestamp, self::CACHE_GROUP, DAY_IN_SECONDS);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WPVDB CACHE] Flushed all caches');
        }
    }
    
    /**
     * Check if cache is still valid based on invalidation timestamp
     *
     * @param string $cache_key Cache key to check
     * @param int $cached_time When the item was cached
     * @return bool Whether cache is still valid
     */
    public static function is_cache_valid($cache_key, $cached_time) {
        $invalidation_timestamp = wp_cache_get('cache_invalidation_timestamp', self::CACHE_GROUP);
        
        if ($invalidation_timestamp && $invalidation_timestamp > $cached_time) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get cache statistics
     *
     * @return array Cache usage statistics
     */
    public static function get_cache_stats() {
        return [
            'group' => self::CACHE_GROUP,
            'expiration_times' => self::EXPIRATION_TIMES,
            'invalidation_timestamp' => wp_cache_get('cache_invalidation_timestamp', self::CACHE_GROUP),
            'memory_usage' => self::estimate_cache_memory_usage(),
        ];
    }
    
    /**
     * Estimate memory usage of cached items
     * This is approximate since we can't directly measure WordPress object cache
     *
     * @return string Estimated memory usage
     */
    private static function estimate_cache_memory_usage() {
        // This is a rough estimate - actual implementation would depend on cache backend
        $test_embedding = wp_cache_get('test_embedding_size', self::CACHE_GROUP);
        if ($test_embedding) {
            $size_per_embedding = strlen(serialize($test_embedding));
            return "~{$size_per_embedding} bytes per embedding";
        }
        
        return 'Unknown';
    }
    
    /**
     * Preload frequently accessed embeddings
     * This can be called during maintenance or cron jobs
     */
    public static function preload_popular_embeddings() {
        global $wpdb;
        
        // Get most frequently queried embeddings (if we tracked this)
        $table_name = $wpdb->prefix . 'wpvdb_embeddings';
        
        // Simple approach: cache recent embeddings
        $recent_embeddings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT chunk_content, model, embedding FROM {$table_name} 
                 WHERE embedding_date > DATE_SUB(NOW(), INTERVAL 1 DAY)
                 ORDER BY embedding_date DESC 
                 LIMIT %d",
                50
            ),
            ARRAY_A
        );
        
        $preloaded = 0;
        foreach ($recent_embeddings as $row) {
            if (!empty($row['chunk_content']) && !empty($row['embedding'])) {
                $embedding = json_decode($row['embedding'], true);
                if (is_array($embedding)) {
                    self::set_embedding($row['chunk_content'], $row['model'], $embedding);
                    $preloaded++;
                }
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[WPVDB CACHE] Preloaded {$preloaded} embeddings");
        }
        
        return $preloaded;
    }
}
