# WPVDB Claude Code Agent Instructions

## Project Guidelines

### Code Style Requirements
- **NO EMOJIS**: Never use emojis in any output, code, comments, commit messages, or documentation
- Follow WordPress coding standards strictly
- Use professional, technical language in all documentation
- Class names: Prefixed with `WPVDB_`, Snake_Case format
- File names: `class-wpvdb-{component}.php` for classes
- Functions: snake_case with descriptive names
- Hook prefix: `wpvdb_`
- Constants: `WPVDB_CONSTANT_NAME` (uppercase with underscores)

### Development Environment
- Start local environment: `docker-compose up -d`
- Test environments:
  - MySQL: http://localhost:9080 (admin: http://localhost:9181)
  - MariaDB: http://localhost:9081 (admin: http://localhost:9182)
- Admin credentials: admin/password

### Testing Infrastructure
- All benchmark tests are located in `tests/benchmarks/`
- Use production-ready testing methodology with real datasets
- Performance reports must be professional without emojis or casual language
- Maintain organized file structure within tests directory

### Performance Benchmarking
- Target million-scale testing with authentic Cohere Wikipedia embeddings
- Use pre-computed embeddings, never generate synthetic data
- Focus on production-ready performance metrics
- Document sustained throughput and query response times
- Validate memory management and scalability

### Security and Best Practices
- Use WordPress error handling (WP_Error objects)
- Sanitize all inputs and escape all outputs
- Never expose or log secrets and keys
- Never commit secrets or keys to repository
- Follow WordPress security best practices

### Documentation Standards
- Use PHPDoc blocks for all functions
- Professional technical language only
- Include performance data in tabular format when relevant
- Focus on production deployment guidance
- Avoid casual or unprofessional terminology

### Git Commit Standards
- Never use emojis in commit messages
- Follow existing commit message format from repository
- Include detailed technical descriptions
- Reference specific improvements and changes
- Always include Claude Code attribution without emojis

### File Organization
- Prefer editing existing files over creating new ones
- Never create documentation files unless explicitly requested
- Maintain clean, organized directory structure
- Use gitignore for large datasets and temporary files
- Keep production code separate from testing infrastructure

## Current Project Status

The WordPress Vector Database (WPVDB) plugin has been successfully tested at million-scale with 1,000,000 pre-computed Cohere embeddings, demonstrating production-ready performance with 1,263 embeddings/second sustained throughput and sub-millisecond query response times.

Testing infrastructure is organized in `tests/benchmarks/` with comprehensive documentation and professional performance reports suitable for production evaluation.