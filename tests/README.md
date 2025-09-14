# WPVDB Testing Suite

This directory contains the comprehensive testing infrastructure for the WordPress Vector Database (WPVDB) plugin.

## Directory Structure

```
tests/
├── README.md                    # This file
├── benchmarks/                  # Performance benchmarking tools
│   ├── wpvdb-benchmark.php     # Main benchmark script
│   ├── dataset-setup.py        # Dataset preparation script
│   ├── data/                   # Benchmark datasets
│   │   └── cohere_embeddings/  # Pre-computed Cohere embeddings
│   └── WPVDB-MILLION-SCALE-FINAL-REPORT.md
└── (future: unit/, integration/, etc.)
```

## Quick Start

### 1. Run Performance Benchmarks

```bash
# Basic performance test (uses existing data)
php tests/benchmarks/wpvdb-benchmark.php

# Full million-scale test (requires dataset)
php tests/benchmarks/wpvdb-benchmark.php --million-scale
```

### 2. Dataset Setup (if needed)

```bash
# Setup benchmark datasets
cd tests/benchmarks
python dataset-setup.py
```

## Benchmark Tests

### Performance Testing
- **Million-scale loading**: Test data ingestion at scale (1M+ embeddings)
- **Query performance**: Measure similarity search response times
- **Memory usage**: Monitor resource consumption
- **Database comparison**: Test MySQL vs MariaDB performance

### Current Results
- **Loading Rate**: 1,263 embeddings/second sustained
- **Query Performance**: 0.2-0.6ms for basic operations
- **Dataset Size**: 1,000,000 pre-computed Cohere embeddings
- **Memory Usage**: Efficient handling up to 8GB

## Development

### Adding New Tests

1. **Benchmarks**: Add new performance tests to `benchmarks/`
2. **Unit Tests**: (Future) Add to `unit/`
3. **Integration Tests**: (Future) Add to `integration/`

### Test Data

All benchmark data is stored in `tests/benchmarks/data/` and includes:

- **Cohere embeddings**: 140K Wikipedia articles with 768-dimensional vectors
- **JSON format**: Compatible across database versions
- **Chunked files**: Optimized for batch processing

### Docker Integration

Tests are designed to work with the Docker development environment:

```bash
# Start containers
docker-compose up -d

# Run benchmarks in container
docker exec wpvdb-wordpress-mysql-1 php /var/www/html/tests/benchmarks/wpvdb-benchmark.php
```

## Reports

Performance reports are generated in the `benchmarks/` directory:

- **performance-report.md**: Comprehensive million-scale testing results
- **Real-time output**: Progress tracking during benchmark execution
- **Comparative analysis**: Performance across different configurations

## Contributing

1. Keep tests focused and isolated
2. Use existing benchmark data when possible
3. Document performance expectations
4. Include both positive and negative test cases
5. Follow WordPress coding standards for PHP tests

## Requirements

- **PHP**: 8.0+ with WordPress environment
- **Memory**: 4-8GB for large-scale tests
- **Database**: MySQL 9.0+ or MariaDB 11.7+
- **Python**: 3.8+ for dataset preparation
- **Docker**: For containerized testing

---

This testing infrastructure ensures WPVDB maintains high performance and reliability across different scales and configurations.