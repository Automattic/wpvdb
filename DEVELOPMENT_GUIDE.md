# WordPress Vector Database (WPVDB) Development Guide

## Development Environment
- Start local environment: `docker-compose up -d`
- Test environments:
  - MySQL: http://localhost:9080 (admin: http://localhost:9180)
  - MariaDB: http://localhost:9081 (admin: http://localhost:9181)
- Admin credentials (default): admin/password

## Diagnostic Tools
- Run database vector compatibility tests: Admin > WPVDB > Status
- Generate test embeddings: Admin > WPVDB > Embeddings > Generate Test Embedding
- Reset embeddings: Admin > WPVDB > Embeddings > Reset All Embeddings

## Code Style Guidelines
- Follow WordPress coding standards 
- Class names: Prefixed with `WPVDB_`, Snake_Case
- File names: `class-wpvdb-{component}.php` for classes
- Functions: snake_case with descriptive names
- Hook prefix: `wpvdb_`
- Constants: `WPVDB_CONSTANT_NAME` (uppercase with underscores)
- Use WordPress error handling (WP_Error objects)
- Sanitize all inputs and escape all outputs
- Document functions with PHPDoc blocks
- 4-space indentation, opening brackets on same line