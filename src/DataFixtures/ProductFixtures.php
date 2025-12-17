<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductReview;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

final class ProductFixtures extends Fixture
{
    private const BATCH_SIZE = 500;
    private const CATEGORIES_COUNT = 500;
    private const BRANDS_COUNT = 1000;
    private const PRODUCTS_COUNT = 100000;
    private const IMAGES_PER_PRODUCT = 3;
    private const REVIEWS_PER_PRODUCT = 5;

    public function load(ObjectManager $manager): void
    {
        $output = new ConsoleOutput();
        $output->writeln("\n<info>Starting fixtures generation…</info>\n");

        $categoryIds = $this->createCategories($manager, $output);
        $brandIds = $this->createBrands($manager, $output);
        $this->createProducts($manager, $output, $categoryIds, $brandIds);

        $output->writeln("\n<info>Fixtures loaded successfully!</info>\n");
    }

    /**
     * @return list<int>
     */
    private function createCategories(ObjectManager $manager, ConsoleOutput $output): array
    {
        $output->writeln('<comment>Creating categories…</comment>');
        $progressBar = new ProgressBar($output, self::CATEGORIES_COUNT);
        $progressBar->start();

        $categoryIds = [];
        $batch = [];

        for ($i = 1; $i <= self::CATEGORIES_COUNT; $i++) {
            $category = new Category();
            $category
                ->setName("Category {$i}")
                ->setSlug("category-{$i}")
                ->setDescription("Description for category {$i}");

            $manager->persist($category);
            $batch[] = $category;

            if ($i % self::BATCH_SIZE === 0) {
                $manager->flush();
                foreach ($batch as $entity) {
                    $id = $entity->getId();
                    if ($id !== null) {
                        $categoryIds[] = $id;
                    }
                }
                $manager->clear();
                $batch = [];
            }

            $progressBar->advance();
        }

        if ($batch !== []) {
            $manager->flush();
            foreach ($batch as $entity) {
                $id = $entity->getId();
                if ($id !== null) {
                    $categoryIds[] = $id;
                }
            }
            $manager->clear();
        }

        $progressBar->finish();
        $output->writeln("\n");

        return $categoryIds;
    }

    /**
     * @return list<int>
     */
    private function createBrands(ObjectManager $manager, ConsoleOutput $output): array
    {
        $output->writeln('<comment>Creating brands…</comment>');
        $progressBar = new ProgressBar($output, self::BRANDS_COUNT);
        $progressBar->start();

        $brandIds = [];
        $batch = [];

        for ($i = 1; $i <= self::BRANDS_COUNT; $i++) {
            $brand = new Brand();
            $brand
                ->setName("Brand {$i}")
                ->setSlug("brand-{$i}")
                ->setCountry('US');

            $manager->persist($brand);
            $batch[] = $brand;

            if ($i % self::BATCH_SIZE === 0) {
                $manager->flush();
                foreach ($batch as $entity) {
                    $id = $entity->getId();
                    if ($id !== null) {
                        $brandIds[] = $id;
                    }
                }
                $manager->clear();
                $batch = [];
            }

            $progressBar->advance();
        }

        if ($batch !== []) {
            $manager->flush();
            foreach ($batch as $entity) {
                $id = $entity->getId();
                if ($id !== null) {
                    $brandIds[] = $id;
                }
            }
            $manager->clear();
        }

        $progressBar->finish();
        $output->writeln("\n");

        return $brandIds;
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $brandIds
     */
    private function createProducts(ObjectManager $manager, ConsoleOutput $output, array $categoryIds, array $brandIds): void
    {
        $output->writeln('<comment>Creating products (+ images + reviews)…</comment>');
        $progressBar = new ProgressBar($output, self::PRODUCTS_COUNT);
        $progressBar->start();

        for ($i = 1; $i <= self::PRODUCTS_COUNT; $i++) {
            $categoryId = $categoryIds[array_rand($categoryIds)];
            $brandId = $brandIds[array_rand($brandIds)];

            $product = new Product();
            $product
                ->setName("Product {$i}")
                ->setDescription("Description for product {$i}")
                ->setPrice(number_format(random_int(100, 100000) / 100, 2, '.', ''))
                ->setStock(random_int(0, 1000))
                ->setCategory($manager->getReference(Category::class, $categoryId))
                ->setBrand($manager->getReference(Brand::class, $brandId));

            $manager->persist($product);

            for ($j = 1; $j <= self::IMAGES_PER_PRODUCT; $j++) {
                $image = new ProductImage();
                $image
                    ->setProduct($product)
                    ->setUrl("https://cdn.example.test/products/{$i}/{$j}.jpg")
                    ->setPosition($j);

                $manager->persist($image);
            }

            for ($j = 1; $j <= self::REVIEWS_PER_PRODUCT; $j++) {
                $review = new ProductReview();
                $review
                    ->setProduct($product)
                    ->setAuthor('User ' . random_int(1, 1000000))
                    ->setRating(random_int(1, 5))
                    ->setComment("Review {$j} for product {$i}");

                $manager->persist($review);
            }

            if ($i % self::BATCH_SIZE === 0) {
                $manager->flush();
                $manager->clear();
                gc_collect_cycles();
            }

            $progressBar->advance();
        }

        $manager->flush();
        $manager->clear();

        $progressBar->finish();
        $output->writeln("\n");
    }
}

