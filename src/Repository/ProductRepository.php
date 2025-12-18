<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Rgalstyan\SymfonyAggregatedQueries\Repository\AggregatedRepositoryTrait;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    use AggregatedRepositoryTrait;

    private int $lastSimpleJoinsRowCount = 0;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findAllTraditional(int $limit): array
    {
        $products = $this->createQueryBuilder('p')
            ->select('p, c, b')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.brand', 'b')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $productIds = array_values(array_filter(array_map(
            static fn (Product $product): ?int => $product->getId(),
            $products
        )));

        if ($productIds === []) {
            return $products;
        }

        $em = $this->getEntityManager();

        $em->createQueryBuilder()
            ->select('IDENTITY(i.product) AS product_id, COUNT(i.id) AS images_count')
            ->from(ProductImage::class, 'i')
            ->where('i.product IN (:ids)')
            ->groupBy('i.product')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getArrayResult();

        $em->createQueryBuilder()
            ->select('IDENTITY(r.product) AS product_id, COUNT(r.id) AS reviews_count')
            ->from(ProductReview::class, 'r')
            ->where('r.product IN (:ids)')
            ->groupBy('r.product')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getArrayResult();

        return $products;
    }

    public function findAllAggregated(int $limit): array
    {
        return $this->aggregatedQuery()
            ->withJsonRelation('category', ['id', 'name', 'slug'])
            ->withJsonRelation('brand', ['id', 'name', 'country'])
            ->withJsonCollection('images', ['id', 'url', 'position'])
            ->withJsonCollection('reviews', ['id', 'author', 'rating', 'comment'])
            ->withCount('reviews')
            ->withCount('images')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->getResult();
    }

    /**
     * Fetch products using Doctrine fetch-joins (fully hydrated entities).
     *
     * This is a common “I will just join everything” approach:
     * - returns Doctrine entities (UnitOfWork, identity map, lifecycle hooks, etc.)
     * - still creates a Cartesian product at the SQL level for multiple OneToMany joins
     *
     * Note: to reliably return exactly $limit products, it first selects product IDs and then
     * fetch-joins all relations for those IDs.
     *
     * @return list<Product>
     */
    public function findAllWithDoctrineJoinFetch(int $limit): array
    {
        $productIds = $this->createQueryBuilder('p')
            ->select('p.id')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        if ($productIds === []) {
            return [];
        }

        /** @var list<int> $productIds */
        $productIds = array_map('intval', $productIds);

        return $this->createQueryBuilder('p')
            ->select('p', 'c', 'b', 'i', 'r')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.brand', 'b')
            ->leftJoin('p.images', 'i')
            ->leftJoin('p.reviews', 'r')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $productIds)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fetch products using simple JOINs (naive approach).
     *
     * This creates a Cartesian product: for 1 product with 3 images and 5 reviews,
     * the database returns 15 rows (3 × 5) that must be deduplicated in PHP.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllWithSimpleJoins(int $limit): array
    {
        $sql = <<<'SQL'
            SELECT
                p.id AS product_id,
                p.name AS product_name,
                p.description AS product_description,
                p.price AS product_price,
                p.stock AS product_stock,
                p.created_at AS product_created_at,
                p.updated_at AS product_updated_at,

                c.id AS category_id,
                c.name AS category_name,
                c.slug AS category_slug,

                b.id AS brand_id,
                b.name AS brand_name,
                b.country AS brand_country,

                i.id AS image_id,
                i.url AS image_url,
                i.position AS image_position,

                r.id AS review_id,
                r.author AS review_author,
                r.rating AS review_rating,
                r.comment AS review_comment
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN brands b ON p.brand_id = b.id
            LEFT JOIN product_images i ON i.product_id = p.id
            LEFT JOIN product_reviews r ON r.product_id = p.id
            WHERE p.id IN (
                SELECT id FROM products ORDER BY id ASC LIMIT :limit
            )
            ORDER BY p.id ASC, i.position ASC, r.created_at DESC
            SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        $this->lastSimpleJoinsRowCount = count($rows);

        return $this->deduplicateCartesianProduct($rows);
    }

    public function getLastSimpleJoinsRowCount(): int
    {
        return $this->lastSimpleJoinsRowCount;
    }

    /**
     * Deduplicate Cartesian product and group collections.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function deduplicateCartesianProduct(array $rows): array
    {
        $products = [];
        $imagesSeen = [];
        $reviewsSeen = [];

        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];

            if (!isset($products[$productId])) {
                $products[$productId] = [
                    'id' => $productId,
                    'name' => $row['product_name'],
                    'description' => $row['product_description'],
                    'price' => (float) $row['product_price'],
                    'stock' => (int) $row['product_stock'],
                    'created_at' => $row['product_created_at'],
                    'updated_at' => $row['product_updated_at'],
                    'category' => $row['category_id'] !== null ? [
                        'id' => (int) $row['category_id'],
                        'name' => $row['category_name'],
                        'slug' => $row['category_slug'],
                    ] : null,
                    'brand' => $row['brand_id'] !== null ? [
                        'id' => (int) $row['brand_id'],
                        'name' => $row['brand_name'],
                        'country' => $row['brand_country'],
                    ] : null,
                    'images' => [],
                    'reviews' => [],
                    'images_count' => 0,
                    'reviews_count' => 0,
                ];
                $imagesSeen[$productId] = [];
                $reviewsSeen[$productId] = [];
            }

            $imageId = $row['image_id'];
            if ($imageId !== null && !isset($imagesSeen[$productId][$imageId])) {
                $products[$productId]['images'][] = [
                    'id' => (int) $imageId,
                    'url' => $row['image_url'],
                    'position' => (int) $row['image_position'],
                ];
                $imagesSeen[$productId][$imageId] = true;
                ++$products[$productId]['images_count'];
            }

            $reviewId = $row['review_id'];
            if ($reviewId !== null && !isset($reviewsSeen[$productId][$reviewId])) {
                $products[$productId]['reviews'][] = [
                    'id' => (int) $reviewId,
                    'author' => $row['review_author'],
                    'rating' => (int) $row['review_rating'],
                    'comment' => $row['review_comment'],
                ];
                $reviewsSeen[$productId][$reviewId] = true;
                ++$products[$productId]['reviews_count'];
            }
        }

        return array_values($products);
    }
}
