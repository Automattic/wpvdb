#!/usr/bin/env python3
"""
WPVDB Dataset Setup Script
Downloads and prepares benchmark datasets with pre-computed embeddings
"""

import os
import sys
import json
import requests
from pathlib import Path
from datasets import load_dataset
from tqdm import tqdm

# Configuration
DATA_DIR = Path(__file__).parent / "data"
DATASETS = {
    'cohere_embeddings': {
        'name': 'Cohere Wikipedia Embeddings',
        'huggingface_id': 'Cohere/wikipedia-22-12-en-embeddings',
        'size_gb': 35,
        'embeddings_count': 41000000,
        'dimensions': 768,
        'format': 'parquet',
        'sample_size': 140000  # Download subset for testing
    }
}

def setup_directories():
    """Create necessary directories"""
    DATA_DIR.mkdir(parents=True, exist_ok=True)

def download_cohere_dataset(sample_size=140000):
    """Download Cohere Wikipedia embeddings dataset"""
    print(f"📥 Downloading Cohere Wikipedia embeddings (sample: {sample_size:,} records)")

    try:
        # Load dataset from HuggingFace
        dataset = load_dataset(
            "Cohere/wikipedia-22-12-en-embeddings",
            "en",
            split=f"train[:{sample_size}]",
            streaming=True
        )

        # Create output directory
        output_dir = DATA_DIR / "cohere_embeddings"
        output_dir.mkdir(exist_ok=True)

        # Process in chunks
        chunk_size = 10000
        chunk_num = 0
        current_chunk = []
        total_processed = 0

        print(f"💾 Processing {sample_size:,} records in chunks of {chunk_size:,}")

        for item in tqdm(dataset, desc="Processing"):
            # Extract relevant fields
            record = {
                'text': item.get('text', ''),
                'emb': item.get('emb', [])
            }

            current_chunk.append(record)
            total_processed += 1

            # Save chunk when full
            if len(current_chunk) >= chunk_size:
                chunk_file = output_dir / f"chunk_{chunk_num:06d}.json"
                with open(chunk_file, 'w', encoding='utf-8') as f:
                    json.dump(current_chunk, f, separators=(',', ':'))

                print(f"✅ Saved chunk {chunk_num:,}: {len(current_chunk):,} records")
                current_chunk = []
                chunk_num += 1

            if total_processed >= sample_size:
                break

        # Save remaining records
        if current_chunk:
            chunk_file = output_dir / f"chunk_{chunk_num:06d}.json"
            with open(chunk_file, 'w', encoding='utf-8') as f:
                json.dump(current_chunk, f, separators=(',', ':'))
            print(f"✅ Saved final chunk {chunk_num:,}: {len(current_chunk):,} records")

        print(f"🎉 Dataset download complete: {total_processed:,} records in {chunk_num + 1:,} chunks")
        return True

    except Exception as e:
        print(f"❌ Error downloading dataset: {e}")
        return False

def validate_dataset():
    """Validate downloaded dataset"""
    print("🔍 Validating dataset...")

    cohere_dir = DATA_DIR / "cohere_embeddings"
    if not cohere_dir.exists():
        print("❌ Cohere embeddings directory not found")
        return False

    chunk_files = list(cohere_dir.glob("chunk_*.json"))
    if not chunk_files:
        print("❌ No chunk files found")
        return False

    total_records = 0
    valid_embeddings = 0

    for chunk_file in chunk_files:
        try:
            with open(chunk_file, 'r', encoding='utf-8') as f:
                data = json.load(f)

            for item in data:
                total_records += 1
                if item.get('emb') and len(item.get('emb', [])) == 768:
                    valid_embeddings += 1

        except Exception as e:
            print(f"❌ Error validating {chunk_file}: {e}")
            return False

    print(f"✅ Validation complete:")
    print(f"   - Total records: {total_records:,}")
    print(f"   - Valid embeddings: {valid_embeddings:,}")
    print(f"   - Chunk files: {len(chunk_files):,}")

    return valid_embeddings > 0

def main():
    """Main setup function"""
    print("=== WPVDB Dataset Setup ===\n")

    # Setup
    setup_directories()

    # Download dataset
    if not download_cohere_dataset(sample_size=140000):
        sys.exit(1)

    # Validate
    if not validate_dataset():
        sys.exit(1)

    print("\n🎉 Dataset setup complete!")
    print("\nNext steps:")
    print("1. Run benchmarks: php tests/benchmarks/wpvdb-benchmark.php")
    print("2. Load data: Use the benchmark script's load function")
    print("3. Test performance: Run similarity search benchmarks")

if __name__ == "__main__":
    main()