# Symfony Performance Benchmark: Doctrine vs Naive JOINs vs JSON Aggregation

This is a small Symfony + Doctrine + PostgreSQL benchmark project designed to demonstrate the performance benefits of [`rgalstyan/symfony-aggregated-queries`](https://github.com/rgalstyan/symfony-aggregated-queries).

It compares five common approaches for a “product catalog listing” with relations:

1) **Traditional Doctrine ORM**
   - Entities + joins for `ManyToOne`
   - `OneToMany` collections (images/reviews) are `EAGER`
   - Extra `COUNT(*) GROUP BY` queries (common for “listing + counters” endpoints)

2) **Doctrine JOIN FETCH (entities)**
   - DQL/QueryBuilder with `leftJoin()` + `select()` for collections (`images`, `reviews`)
   - Returns **fully hydrated entities** (UnitOfWork, identity map, lifecycle hooks)
   - Still produces a **Cartesian product** at the SQL level (images × reviews)

3) **Simple JOINs (naive)**
   - “Let’s just join everything into one query”
   - Causes a **Cartesian product** (massive duplicate result-set)
   - Requires **PHP-side deduplication** (hidden cost)

4) **JSON aggregation (symfony-aggregated-queries)**
   - Single SQL query
   - Relations/collections/counts are aggregated in SQL (JSON)
   - Returns arrays (DTO-style, no entity hydration)

5) **JOIN + PHP grouping (structured arrays)**
   - Same JOIN-based query (no JSON aggregation)
   - Flat result-set is grouped/deduplicated in PHP to build the same nested structure as JSON aggregation
   - Returns arrays (DTO-style, no entity hydration)

> This benchmark focuses on **read-only listing queries** (arrays/DTOs). It does not measure writes, lifecycle events, lazy-loading, etc.

## What the code does

The benchmark lives in:

- Repository: `src/Repository/ProductRepository.php`
  - `findAllTraditional(int $limit)`
  - `findAllWithDoctrineJoinFetch(int $limit)`
  - `findAllWithSimpleJoins(int $limit)`
  - `findAllWithSimpleJoinsFlat(int $limit)`
  - `findAllAggregated(int $limit)`
- Command: `src/Command/PerformanceTestCommand.php`

SQL queries are counted via a DBAL middleware: `App\Doctrine\QueryCounterMiddleware` (counts DBAL `query/exec/execute` calls).

## Database dataset (fixtures)

Fixtures generate a realistic e-commerce dataset (~900k rows total):

- `categories`: 500
- `brands`: 1,000
- `products`: 100,000
- `product_images`: 300,000 (3 per product)
- `product_reviews`: 500,000 (5 per product)

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

## Why “just add JOINs” does not work

Many developers try to “fix N+1” by joining multiple `OneToMany` relations:

```sql
SELECT p.*, i.*, r.*
FROM products p
LEFT JOIN product_images i ON i.product_id = p.id
LEFT JOIN product_reviews r ON r.product_id = p.id;
```

This creates a **Cartesian product**:

- 1 product with 3 images and 5 reviews => **15 rows returned** (3 × 5)
- 1,000 products with the same distribution => **15,000 rows returned**

So you still end up paying:

- extra DB → app transfer size (lots of duplicates)
- expensive PHP deduplication/grouping
- higher memory usage

Doctrine JOIN FETCH and naive JOINs both “look like 1 query”, but they can still be slow because of the multiplied row set.

JSON aggregation solves this by keeping **one row per product**, while still returning full collections.

## Run after cloning

1) Install dependencies:

```bash
composer install
```

2) Configure DB connection:

```bash
cp .env.local.example .env.local
```

Edit `.env.local` and set your own `DATABASE_URL`.
If your password contains special characters, URL-encode it (e.g. `&` => `%26`, `@` => `%40`).
In Symfony `.env*` files, escape percent signs as `%%` (e.g. `%26` => `%%26`).

3) Create database (optional):

```bash
php bin/console doctrine:database:create
```

4) Run migrations:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

5) Load fixtures (can take 10–30 minutes depending on your machine):

```bash
php bin/console doctrine:fixtures:load --no-interaction
```

6) Run the benchmark:

```bash
# default is 500 (max is 2000)
php bin/console app:performance-test
php bin/console app:performance-test --limit=1000

# optional: warmup rounds to reduce cold-cache bias (default is 1)
php bin/console app:performance-test --warmup=0
php bin/console app:performance-test --warmup=2
```

## Interpreting the output

Each approach prints:

- `Time`: wall-clock duration for the query + processing needed for that approach
- `Memory`: delta of `memory_get_usage(false)` around the measured code block (reported in KB + MB)
- `Queries`: DBAL-level query count for that approach
- `DB rows`: shown for JOIN-based approaches to highlight the Cartesian product (for JSON aggregation this equals products returned)

## At a glance (with this dataset)

In this project’s fixtures each product has **3 images** and **5 reviews**. With naive JOINs:

- `DB rows` per product = `3 × 5 = 15`
- `DB rows` for `--limit=1000` = `1000 × 15 = 15000`

## Example (what to expect)

Your numbers will vary by machine and DB state, but the typical trend is:

- Traditional Doctrine: many queries, higher memory, slower
- Doctrine JOIN FETCH: few queries but **huge `DB rows`** + full entity hydration cost
- Simple JOINs: 1 query but **huge `DB rows`** + PHP dedup cost
- JSON aggregation: 1 query, **small `DB rows`**, fastest, lowest memory

## Package documentation

https://github.com/rgalstyan/symfony-aggregated-queries
