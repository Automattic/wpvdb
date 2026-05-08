# WordPress Vector Database (WPVDB)

A WordPress plugin that enables vector database functionality for semantic search and AI-powered content recommendations.

## Features

- Store and query vector embeddings in your WordPress database
- Native vector type support for MySQL 8.0.32+ and MariaDB 11.7+
- Fallback JSON storage for compatibility with older database versions 
- Generate embeddings using OpenAI's API or Automattic's API
- Extend WP_Query with vector similarity search via `vdb_vector_query`
- Automatic content chunking and embedding generation
- Optional chunk summarization using AI
- REST API endpoints for embedding generation and vector search
- Background processing with Action Scheduler (if available)
- Admin interface for managing vector database settings
- Block editor integration

## Requirements

- WordPress 6.0+
- PHP 7.4+
- For optimal performance:
  - MySQL 8.0.32+ (with native VECTOR type) OR
  - MariaDB 11.7+ (with VECTOR type and VECTOR index)
- Action Scheduler (recommended but not required)

## Installation

1. Upload the plugin files to the `/wp-content/plugins/wpvdb` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure your API key and settings through the Vector DB settings page.

## Using the Docker Testing Environment

This plugin includes a Docker setup to test with both MySQL 8.0.32+ and MariaDB 11.7+ vector databases.

### Starting the Environment

```bash
docker-compose up -d
```

This will start two separate WordPress instances:

- **WordPress with MySQL 8.0.32+**: http://localhost:9080
- **WordPress with MariaDB 11.7+**: http://localhost:9081

Both instances have:
- Admin username: `admin` 
- Admin password: `password`

### Database Administration

- **MySQL Admin (Adminer)**: http://localhost:9180
- **MariaDB Admin (Adminer)**: http://localhost:9181
- Login with:
  - System: MySQL (or MariaDB)
  - Server: mysql (or mariadb)
  - Username: root
  - Password: wordpress
  - Database: wordpress

### Testing Vector Functionality

#### For MySQL 8.0.32+:

1. Visit http://localhost:9080/wp-admin
2. Configure API keys in Vector DB settings
3. Generate embeddings for sample content
4. Test vector search using the native MySQL vector type

#### For MariaDB 11.7+:

1. Visit http://localhost:9081/wp-admin
2. Configure API keys in Vector DB settings
3. Generate embeddings for sample content
4. Test vector search using MariaDB's vector implementation

### Database Comparison

| Feature | MySQL 8.0.32+ | MariaDB 11.7+ |
|---------|------------|---------------|
| Vector Type | VECTOR(dim) | VECTOR(dim) |
| Max Dimensions | 16383 | No stated limit |
| Vector Index | No native support | VECTOR index type |
| Algorithm | N/A | Modified HNSW |
| Distance Functions | STRING_TO_VECTOR function | Built-in (VEC_DISTANCE_EUCLIDEAN, etc.) |
| Optimization | Minimal | Specialized for vector search |

## Usage

### Generating Embeddings

```php
// Generate an embedding for a text chunk
$embedding = WPVDB\Core::get_embedding('Your text to embed', 'text-embedding-3-small', 'https://api.openai.com/v1/', 'your-api-key');

// Store the embedding
WPVDB\REST::insert_embedding_row(
    $post_id,
    'chunk-0',
    'Your text to embed',
    'Optional summary',
    $embedding,
    'text-embedding-3-small', // model identifier
    'post',                   // doc_type (defaults to 'post')
    0                         // chunk_index (zero-based position)
);
```

### Vector Search

```php
// Search posts by vector similarity
$args = array(
    'post_type' => 'post',
    'posts_per_page' => 5,
    'vdb_vector_query' => 'Your search query'
);

$query = new WP_Query($args);
```

### Chunk Text

```php
// Chunk a long text into manageable pieces
$chunks = apply_filters('wpvdb_chunk_text', [], 'Your long text content');

// Use enhanced chunking with custom chunk size
$chunks = WPVDB\Core::enhanced_chunking([], 'Your long text content', 500);
```

## REST API Endpoints

- `POST /wp-json/vdb/v1/embed` - Generate embeddings for text
- `POST /wp-json/vdb/v1/vectors` - Store vectors for content
- `POST /wp-json/vdb/v1/query` - Perform vector search
- `GET /wp-json/vdb/v1/metadata` - Get information about the vector database
- `POST /wp-json/wp/v2/wpvdb/reembed` - Re-embed content from the block editor

## Configuration

You can securely configure API keys by adding constants to your wp-config.php file:

```php
define('WPVDB_OPENAI_API_KEY', 'your-openai-api-key');
define('WPVDB_AUTOMATTIC_API_KEY', 'your-automattic-api-key');
```

## Documentation

For more detailed documentation, please see the [Wiki](https://github.com/your-username/wpvdb/wiki).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GPL v2 or later.