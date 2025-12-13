=== WPVDB - WordPress Vector Database ===
Contributors: automattic, jameslepage
Tags: ai, embedding, vector-database, semantic-search, openai, machine-learning
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform WordPress into a powerful vector database with native or fallback support for embeddings, semantic search, and AI-powered content discovery.

== Description ==

WPVDB (WordPress Vector Database) transforms your WordPress site into a powerful vector database, enabling advanced AI-powered features like semantic search, content recommendations, and intelligent content discovery.

= Key Features =

* **Vector Storage**: Store and search vector embeddings with native database support (MariaDB 11.7+ / MySQL 8.0.32+) or JSON fallback
* **Semantic Search**: Find content by meaning, not just keywords
* **Multiple AI Providers**: Support for OpenAI, Automattic, and other embedding providers
* **Automatic Embedding**: Auto-generate embeddings for posts, pages, and custom post types
* **REST API**: Full REST API for integration with external applications
* **Smart Chunking**: Intelligent text chunking that respects semantic boundaries
* **Performance Optimized**: Built-in caching, pagination, and memory management
* **Security First**: Encrypted API key storage, rate limiting, and comprehensive input validation

= Supported Databases =

* **MariaDB 11.7+**: Full native vector support with HNSW indexing
* **MySQL 8.0.32+**: Native vector operations and indexing
* **Older Databases**: Automatic fallback with JSON storage (reduced performance)

= AI Provider Support =

* **OpenAI**: text-embedding-3-small, text-embedding-3-large, text-embedding-ada-002
* **Automattic**: WordPress.com AI services
* **Extensible**: Add custom providers via filters

= Use Cases =

* **Content Discovery**: Help users find related content by meaning
* **Knowledge Base**: Build intelligent documentation search
* **E-commerce**: Product recommendations based on descriptions
* **Research**: Semantic search through large content collections
* **Multilingual Sites**: Language-agnostic content matching

= Developer Friendly =

* Comprehensive hooks and filters
* Full REST API documentation
* WordPress coding standards compliant
* Extensive logging and debugging tools
* Performance monitoring built-in

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wpvdb` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to Settings > WPVDB to configure your AI provider and API keys
4. Choose your embedding model and configure auto-embedding settings
5. Start generating embeddings for your content!

= Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher  
* One of the supported databases (see description)
* API key from a supported AI provider

= Recommended =

* MariaDB 11.7+ or MySQL 8.0.32+ for best performance
* At least 512MB PHP memory limit
* Action Scheduler plugin (automatically included)

== Frequently Asked Questions ==

= What are vector embeddings? =

Vector embeddings are numerical representations of text that capture semantic meaning. They allow computers to understand that "car" and "automobile" are similar concepts, enabling semantic search beyond keyword matching.

= Do I need a specific database version? =

For best performance, use MariaDB 11.7+ or MySQL 8.0.32+ which support native vector operations. Older databases will work with JSON fallback storage, but with reduced performance.

= Which AI providers are supported? =

Currently OpenAI and Automattic are supported. You can add custom providers using the `wpvdb_generate_embedding` filter.

= How much does it cost to use? =

The plugin is free, but you'll need an API key from an AI provider. OpenAI charges approximately $0.10 per 1M tokens for embeddings (very affordable for most sites).

= Can I use this with WooCommerce? =

Yes! The plugin works with any post type, including WooCommerce products. Enable auto-embedding for the 'product' post type to get semantic product search.

= Is my data secure? =

Yes. API keys are encrypted using WordPress salts, all inputs are sanitized, and we follow WordPress security best practices. Your content is only sent to the AI provider for embedding generation.

= How do I backup my embeddings? =

Embeddings are stored in your WordPress database in the `wp_wpvdb_embeddings` table. Include this table in your regular database backups.

== Screenshots ==

1. Main settings page showing provider configuration
2. Embedding management interface with batch operations  
3. Database status and vector support detection
4. REST API endpoints for developers
5. Performance monitoring and statistics

== Changelog ==

= 1.0.13 =
* Security: Added encrypted API key storage with AES-256-CBC
* Security: Fixed SQL injection vulnerabilities with proper query preparation
* Security: Added comprehensive input sanitization for all endpoints
* Security: Implemented rate limiting system with user/IP tracking
* Performance: Fixed N+1 query issues with paginated processing
* Performance: Added intelligent caching system for embeddings and queries
* Performance: Optimized database schema with compound indexes
* Performance: Added memory management for large datasets
* Logging: Implemented centralized logging system with multiple levels
* Logging: Added performance monitoring and timing
* Standards: Full WordPress coding standards compliance
* Standards: Added comprehensive PHPDoc documentation
* Standards: Proper internationalization support
* Database: Added automated maintenance system with scheduled tasks
* Database: Integrity monitoring and orphaned data cleanup
* Settings: Enhanced validation with WordPress Settings API integration
* Settings: Configuration validation with detailed error messages
* UI: Better error messages and user feedback

= 1.0.8 =
* Initial public release
* Basic vector storage and search functionality
* OpenAI and Automattic provider support
* REST API endpoints
* Auto-embedding for posts and pages

== Upgrade Notice ==

= 1.0.13 =
Major security and performance update. Existing API keys will be automatically encrypted on first settings save. Database performance significantly improved.

= 1.0.8 =
Initial release. Please configure your AI provider API key after activation.

== Developer Documentation ==

= Hooks and Filters =

* `wpvdb_generate_embedding` - Custom embedding generation
* `wpvdb_chunk_text` - Custom text chunking
* `wpvdb_ai_summarize_chunk` - Custom chunk summarization
* `wpvdb_settings_updated` - Triggered when settings are updated
* `wpvdb_rate_limit` - Customize rate limits
* `wpvdb_required_capability` - Change required capability (default: edit_posts)

= REST API Endpoints =

* `POST /wp-json/wpvdb/v1/embed` - Generate and store embeddings
* `POST /wp-json/wpvdb/v1/vectors` - Store pre-computed embeddings
* `POST /wp-json/wpvdb/v1/query` - Semantic search query
* `GET /wp-json/wpvdb/v1/meta` - Database metadata and statistics

Full API documentation available at: https://github.com/automattic/wpvdb

= Custom Providers =

```php
add_filter('wpvdb_generate_embedding', function($embedding, $text, $model, $api_base, $api_key) {
    if ($embedding !== null) {
        return $embedding; // Another provider handled it
    }
    
    // Your custom embedding logic here
    return $your_embedding_array;
}, 10, 5);
```

== Privacy Policy ==

This plugin sends your content to third-party AI providers (like OpenAI) to generate embeddings. Please ensure your privacy policy reflects this data processing. The plugin:

* Only sends content you choose to embed
* Does not store or log the content sent to providers  
* Encrypts API keys in your database
* Does not share data with any parties except your chosen AI provider
* Provides options to disable auto-embedding for sensitive content

== Support ==

* Documentation: https://docs.wpvdb.com
* Support Forum: https://wordpress.org/support/plugin/wpvdb
* GitHub Issues: https://github.com/automattic/wpvdb/issues
* Professional Support: Available through Automattic

== Contributing ==

This plugin is open source! Contribute on GitHub:
https://github.com/automattic/wpvdb

Areas where contributions are especially welcome:
* Additional AI provider integrations
* UI/UX improvements  
* Performance optimizations
* Translation into more languages
* Bug reports and testing
