# Symfony Aggregated Queries — Performance Test (Symfony + PostgreSQL)

This repository is a production-style demo/benchmark that compares:

1. A “traditional” Doctrine ORM approach (JOIN + EAGER collections + separate COUNT queries)
2. The [`rgalstyan/symfony-aggregated-queries`](https://github.com/rgalstyan/symfony-aggregated-queries) approach, which aggregates relations/collections/counts into JSON in a single SQL query

The goal is to show how a typical “product catalog listing” can dramatically reduce SQL round-trips and Doctrine hydration overhead.

> **Note**: This benchmark intentionally focuses on **read-only, DTO-style queries** (arrays instead of Doctrine entities). It does not measure write performance, lifecycle events, or entity mutation scenarios.

## What this benchmark compares

The comparison happens in `App\Repository/ProductRepository`:

* `findAllTraditional(int $limit)`:

    * loads `Product` as Doctrine entities
    * `images` and `reviews` collections are marked `fetch: 'EAGER'` (in real-world Doctrine usage, OneToMany EAGER often still triggers additional queries)
    * additionally runs 2 `COUNT(*) GROUP BY` queries (a very common “listing + counters” pattern)

* `findAllAggregated(int $limit)`:

    * uses `AggregatedRepositoryTrait`
    * returns arrays (no entity hydration by default)
    * loads `category`, `brand`, `images`, `reviews`, `images_count`, `reviews_count` in a single SQL query

SQL queries are counted via a DBAL middleware `App\Doctrine\QueryCounterMiddleware` (counts `query/exec/execute` calls).

## Database dataset (fixtures)

Fixtures generate a realistic e-commerce dataset (~900k rows total):

* `categories`: 500
* `brands`: 1,000
* `products`: 100,000
* `product_images`: 300,000 (3 per product)
* `product_reviews`: 500,000 (5 per product)

Quick verification query:

```sql
SELECT table_name, cnt
FROM (
         SELECT 'brands' table_name, COUNT(*) cnt FROM brands
         UNION ALL
         SELECT 'categories', COUNT(*) FROM categories
         UNION ALL
         SELECT 'products', COUNT(*) FROM products
         UNION ALL
         SELECT 'product_images', COUNT(*) FROM product_images
         UNION ALL
         SELECT 'product_reviews', COUNT(*) FROM product_reviews
     ) t
ORDER BY table_name;
```

## Example output (limit=1000)

One sample run:

```
PRODUCTS PERFORMANCE TEST
Dataset size: 1000 products

TRADITIONAL DOCTRINE (1000 records)
Time:    167.49ms
Memory:  24953.3 KB (24.37 MB)
Queries: 23

AGGREGATED QUERIES (1000 records)
Time:    28.17ms
Memory:  7076.3 KB (6.91 MB)
Queries: 1

IMPROVEMENT
Speed:   83.2% faster (167.49ms → 28.17ms)
Memory:  71.6% less (24953.3 KB → 7076.3 KB)
Queries: 22 fewer (23 → 1)

EXCELLENT! Over 70% improvement!
```

Notes:

* timings depend on your machine, DB state, and caches
* memory usage mainly reflects Doctrine entity hydration vs array-based results
* “Aggregated” should typically be `Queries: 1` because relations, collections, and counts are produced by a single SQL statement
