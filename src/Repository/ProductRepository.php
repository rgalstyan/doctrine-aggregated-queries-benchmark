<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Rgalstyan\SymfonyAggregatedQueries\Repository\AggregatedRepositoryTrait;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    use AggregatedRepositoryTrait;

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
}

