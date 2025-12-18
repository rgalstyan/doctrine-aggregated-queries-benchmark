<?php

declare(strict_types=1);

namespace App\Command;

use App\Doctrine\QueryCounter;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:performance-test',
    description: 'Compare Doctrine (entities) vs JOINs vs JSON aggregation'
)]
final class PerformanceTestCommand extends Command
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT = 2000;
    private const DEFAULT_WARMUP = 1;
    private const IMAGES_PER_PRODUCT = 3;
    private const REVIEWS_PER_PRODUCT = 5;

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly QueryCounter $queryCounter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Number of products to load',
            self::DEFAULT_LIMIT
        );

        $this->addOption(
            'warmup',
            'w',
            InputOption::VALUE_OPTIONAL,
            'Warmup rounds (not measured) to reduce cold-cache bias',
            self::DEFAULT_WARMUP
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->prepareLimit($input);
        $warmupRounds = $this->prepareWarmupRounds($input);

        $this->printHeader($output, $limit);

        $this->warmUp($output, $limit, $warmupRounds);

        $traditional = $this->measureTraditional($output, $limit);
        $doctrineJoinFetch = $this->measureDoctrineJoinFetch($output, $limit);
        $simpleJoins = $this->measureSimpleJoins($output, $limit);
        $aggregated = $this->measureAggregated($output, $limit);
        $joinPhpGrouping = $this->measureJoinPhpGroupingStructuredArrays($output, $limit);

        $this->printComparison($output, $traditional, $doctrineJoinFetch, $simpleJoins, $aggregated, $joinPhpGrouping);

        return Command::SUCCESS;
    }

    private function measureTraditional(OutputInterface $output, int $limit): PerformanceResult
    {
        $output->writeln("\n<comment>TRADITIONAL DOCTRINE ({$limit} records)</comment>");
        $output->writeln(str_repeat('━', 50));

        $this->entityManager->clear();
        $this->queryCounter->reset();
        gc_collect_cycles();

        $memoryBefore = memory_get_usage(false);
        $startTime = microtime(true);

        $result = $this->productRepository->findAllTraditional($limit);
        $resultCount = count($result);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = max(0, memory_get_usage(false) - $memoryBefore);
        $queries = $this->queryCounter->getCount();

        unset($result);
        $this->entityManager->clear();
        gc_collect_cycles();

        $output->writeln("Time:    {$duration}ms");
        $output->writeln(sprintf(
            'Memory:  %s KB (%.2f MB)',
            $this->formatKilobytes($memoryBytes),
            $memoryBytes / 1024 / 1024
        ));
        $output->writeln("Queries: {$queries}");
        $output->writeln("Result:  {$resultCount} Product entities");

        return new PerformanceResult($duration, $memoryBytes, $queries, null, $resultCount);
    }

    private function measureDoctrineJoinFetch(OutputInterface $output, int $limit): PerformanceResult
    {
        $output->writeln("\n<comment>DOCTRINE JOIN FETCH (entities) ({$limit} records)</comment>");
        $output->writeln(str_repeat('━', 50));

        $this->entityManager->clear();
        $this->queryCounter->reset();
        gc_collect_cycles();

        $memoryBefore = memory_get_usage(false);
        $startTime = microtime(true);

        $result = $this->productRepository->findAllWithDoctrineJoinFetch($limit);
        $resultCount = count($result);
        $rowsReturnedEstimate = $resultCount * self::IMAGES_PER_PRODUCT * self::REVIEWS_PER_PRODUCT;

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = max(0, memory_get_usage(false) - $memoryBefore);
        $queries = $this->queryCounter->getCount();

        unset($result);
        $this->entityManager->clear();
        gc_collect_cycles();

        $output->writeln("Time:    {$duration}ms");
        $output->writeln(sprintf(
            'Memory:  %s KB (%.2f MB)',
            $this->formatKilobytes($memoryBytes),
            $memoryBytes / 1024 / 1024
        ));
        $output->writeln("Queries: {$queries}");
        $output->writeln("DB rows: ~{$rowsReturnedEstimate} (Cartesian product in SQL)");
        $output->writeln("Result:  {$resultCount} Product entities");

        return new PerformanceResult($duration, $memoryBytes, $queries, $rowsReturnedEstimate, $resultCount);
    }

    private function measureSimpleJoins(OutputInterface $output, int $limit): PerformanceResult
    {
        $output->writeln("\n<comment>SIMPLE JOINS (naive) ({$limit} records)</comment>");
        $output->writeln(str_repeat('━', 50));

        $this->entityManager->clear();
        $this->queryCounter->reset();
        gc_collect_cycles();

        $memoryBefore = memory_get_usage(false);
        $startTime = microtime(true);

        $result = $this->productRepository->findAllWithSimpleJoins($limit);
        $resultCount = count($result);
        $rowsReturned = $this->productRepository->getLastSimpleJoinsRowCount();

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = max(0, memory_get_usage(false) - $memoryBefore);
        $queries = $this->queryCounter->getCount();

        unset($result);
        $this->entityManager->clear();
        gc_collect_cycles();

        $output->writeln("Time:    {$duration}ms");
        $output->writeln(sprintf(
            'Memory:  %s KB (%.2f MB)',
            $this->formatKilobytes($memoryBytes),
            $memoryBytes / 1024 / 1024
        ));
        $output->writeln("Queries: {$queries}");
        $output->writeln("DB rows: {$rowsReturned} (Cartesian product!)");
        $output->writeln("Result:  {$resultCount} products (after deduplication)");

        return new PerformanceResult($duration, $memoryBytes, $queries, $rowsReturned, $resultCount);
    }

    private function measureAggregated(OutputInterface $output, int $limit): PerformanceResult
    {
        $output->writeln("\n<comment>AGGREGATED QUERIES ({$limit} records)</comment>");
        $output->writeln(str_repeat('━', 50));

        $this->entityManager->clear();
        $this->queryCounter->reset();
        gc_collect_cycles();

        $memoryBefore = memory_get_usage(false);
        $startTime = microtime(true);

        $result = $this->productRepository->findAllAggregated($limit);
        $resultCount = count($result);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = max(0, memory_get_usage(false) - $memoryBefore);
        $queries = $this->queryCounter->getCount();

        unset($result);
        $this->entityManager->clear();
        gc_collect_cycles();

        $output->writeln("Time:    {$duration}ms");
        $output->writeln(sprintf(
            'Memory:  %s KB (%.2f MB)',
            $this->formatKilobytes($memoryBytes),
            $memoryBytes / 1024 / 1024
        ));
        $output->writeln("Queries: {$queries}");
        $output->writeln("DB rows: {$resultCount}");
        $output->writeln("Result:  {$resultCount} products (arrays)");

        return new PerformanceResult($duration, $memoryBytes, $queries, $resultCount, $resultCount);
    }

    private function measureJoinPhpGroupingStructuredArrays(OutputInterface $output, int $limit): PerformanceResult
    {
        $output->writeln("\n<comment>JOIN + PHP GROUPING (structured arrays) ({$limit} records)</comment>");
        $output->writeln(str_repeat('━', 50));

        $this->entityManager->clear();
        $this->queryCounter->reset();
        gc_collect_cycles();

        $memoryBefore = memory_get_usage(false);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $startTime = microtime(true);

        $rows = $this->productRepository->findAllWithSimpleJoinsFlat($limit);
        $rowsProcessed = count($rows);

        $result = $this->groupSimpleJoinFlatRowsToAggregatedShape($rows);
        $resultCount = count($result);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $memoryBytes = max(0, memory_get_usage(false) - $memoryBefore);
        $peakMemoryBytes = memory_get_peak_usage(false);
        $queries = $this->queryCounter->getCount();

        unset($rows, $result);
        $this->entityManager->clear();
        gc_collect_cycles();

        $output->writeln("Time:    {$duration}ms");
        $output->writeln(sprintf(
            'Memory:  %s KB (%.2f MB)',
            $this->formatKilobytes($memoryBytes),
            $memoryBytes / 1024 / 1024
        ));
        $output->writeln(sprintf(
            'Peak:    %s KB (%.2f MB)',
            $this->formatKilobytes($peakMemoryBytes),
            $peakMemoryBytes / 1024 / 1024
        ));
        $output->writeln("Queries: {$queries}");
        $output->writeln("DB rows: {$rowsProcessed} (flat JOIN result set processed)");
        $output->writeln("Result:  {$resultCount} products (structured arrays)");

        return new PerformanceResult($duration, $memoryBytes, $queries, $rowsProcessed, $resultCount);
    }

    private function printComparison(
        OutputInterface $output,
        PerformanceResult $traditional,
        PerformanceResult $doctrineJoinFetch,
        PerformanceResult $simpleJoins,
        PerformanceResult $aggregated,
        PerformanceResult $joinPhpGrouping,
    ): void {
        $lineLength = 99;

        $output->writeln("\n" . str_repeat('━', $lineLength));
        $output->writeln('<info>COMPARISON</info>');
        $output->writeln(str_repeat('━', $lineLength));

        $output->writeln(sprintf(
            "%-26s | %-8s | %10s | %12s | %7s | %10s | %8s",
            'Approach',
            'Return',
            'Time (ms)',
            'Mem (KB)',
            'Queries',
            'DB rows',
            'Products',
        ));
        $output->writeln(str_repeat('─', $lineLength));

        $output->writeln(sprintf(
            "%-26s | %-8s | %10.2f | %12s | %7d | %10s | %8d",
            '1) Traditional Doctrine',
            'entities',
            $traditional->timeMs,
            $this->formatKilobytes($traditional->memoryBytes),
            $traditional->queryCount,
            'N/A',
            (int) ($traditional->resultCount ?? 0),
        ));

        $output->writeln(sprintf(
            "%-26s | %-8s | %10.2f | %12s | %7d | %10d | %8d",
            '2) Doctrine JOIN fetch',
            'entities',
            $doctrineJoinFetch->timeMs,
            $this->formatKilobytes($doctrineJoinFetch->memoryBytes),
            $doctrineJoinFetch->queryCount,
            (int) ($doctrineJoinFetch->rowsReturned ?? 0),
            (int) ($doctrineJoinFetch->resultCount ?? 0),
        ));

        $output->writeln(sprintf(
            "%-26s | %-8s | %10.2f | %12s | %7d | %10d | %8d",
            '3) Simple JOINs (naive)',
            'arrays',
            $simpleJoins->timeMs,
            $this->formatKilobytes($simpleJoins->memoryBytes),
            $simpleJoins->queryCount,
            (int) ($simpleJoins->rowsReturned ?? 0),
            (int) ($simpleJoins->resultCount ?? 0),
        ));

        $output->writeln(sprintf(
            "%-26s | %-8s | %10.2f | %12s | %7d | %10d | %8d",
            '4) JSON aggregation',
            'arrays',
            $aggregated->timeMs,
            $this->formatKilobytes($aggregated->memoryBytes),
            $aggregated->queryCount,
            (int) ($aggregated->rowsReturned ?? 0),
            (int) ($aggregated->resultCount ?? 0),
        ));

        $output->writeln(sprintf(
            "%-26s | %-8s | %10.2f | %12s | %7d | %10d | %8d",
            '5) JOIN + PHP grouping',
            'arrays',
            $joinPhpGrouping->timeMs,
            $this->formatKilobytes($joinPhpGrouping->memoryBytes),
            $joinPhpGrouping->queryCount,
            (int) ($joinPhpGrouping->rowsReturned ?? 0),
            (int) ($joinPhpGrouping->resultCount ?? 0),
        ));

        $output->writeln(str_repeat('━', $lineLength));

        $output->writeln("\n" . str_repeat('━', $lineLength));
        $output->writeln('<info>IMPROVEMENT (JSON aggregation)</info>');
        $output->writeln(str_repeat('━', $lineLength));

        $this->printImprovementAgainst($output, 'Traditional', $traditional, $aggregated);
        $this->printImprovementAgainst($output, 'Doctrine JOIN fetch', $doctrineJoinFetch, $aggregated);
        $this->printImprovementAgainst($output, 'Simple JOINs', $simpleJoins, $aggregated);
        $this->printImprovementAgainst($output, 'JOIN + PHP grouping', $joinPhpGrouping, $aggregated);

        $timeImprovementVsTraditional = $this->calculateImprovement($traditional->timeMs, $aggregated->timeMs);
        if ($timeImprovementVsTraditional > 70) {
            $output->writeln('<info>EXCELLENT! Over 70% improvement!</info>');
        } elseif ($timeImprovementVsTraditional > 50) {
            $output->writeln('<info>GREAT! Over 50% improvement!</info>');
        } else {
            $output->writeln('<comment>Good improvement</comment>');
        }

        $output->writeln("\n" . str_repeat('━', $lineLength));
        $output->writeln('<info>WHY MULTIPLE OneToMany JOINs ARE A TRAP</info>');
        $output->writeln(str_repeat('━', $lineLength));

        $output->writeln('Joining multiple OneToMany relations multiplies rows (Cartesian product).');
        $output->writeln(sprintf(
            'With this dataset: %d images × %d reviews = %d DB rows per product.',
            self::IMAGES_PER_PRODUCT,
            self::REVIEWS_PER_PRODUCT,
            self::IMAGES_PER_PRODUCT * self::REVIEWS_PER_PRODUCT,
        ));
        if (($simpleJoins->rowsReturned ?? 0) > 0 && ($simpleJoins->resultCount ?? 0) > 0) {
            $factor = ($simpleJoins->rowsReturned ?? 0) / max(1, (int) $simpleJoins->resultCount);
            $output->writeln(sprintf(
                'This run: %d DB rows for %d products (%.1fx multiplier).',
                (int) $simpleJoins->rowsReturned,
                (int) $simpleJoins->resultCount,
                $factor
            ));
        }
        $output->writeln('Doctrine JOIN FETCH hides duplicates via the identity map, but still transfers and hydrates them.');
        $output->writeln('Simple JOINs avoids entity hydration but pays the cost in PHP deduplication.');
        $output->writeln('JSON aggregation keeps one DB row per product and returns already-grouped collections.');

        $output->writeln(str_repeat('━', $lineLength) . "\n");
    }

    private function printImprovementAgainst(
        OutputInterface $output,
        string $label,
        PerformanceResult $baseline,
        PerformanceResult $aggregated
    ): void {
        $timeImprovement = $this->calculateImprovement($baseline->timeMs, $aggregated->timeMs);
        $memoryImprovement = $this->calculateImprovement($baseline->memoryBytes, $aggregated->memoryBytes);
        $queriesSaved = $baseline->queryCount - $aggregated->queryCount;

        $timeWord = $timeImprovement >= 0 ? 'faster' : 'slower';
        $memoryWord = $memoryImprovement >= 0 ? 'less' : 'more';

        $output->writeln(sprintf(
            'vs %s: Speed %.1f%% %s (%.2fms → %.2fms)',
            $label,
            abs($timeImprovement),
            $timeWord,
            $baseline->timeMs,
            $aggregated->timeMs
        ));
        $output->writeln(sprintf(
            'vs %s: Memory %.1f%% %s (%s KB → %s KB)',
            $label,
            abs($memoryImprovement),
            $memoryWord,
            $this->formatKilobytes($baseline->memoryBytes),
            $this->formatKilobytes($aggregated->memoryBytes),
        ));
        if ($queriesSaved > 0) {
            $output->writeln(sprintf(
                'vs %s: Queries %d fewer (%d → %d)',
                $label,
                $queriesSaved,
                $baseline->queryCount,
                $aggregated->queryCount
            ));
        } elseif ($queriesSaved < 0) {
            $output->writeln(sprintf(
                'vs %s: Queries %d more (%d → %d)',
                $label,
                abs($queriesSaved),
                $baseline->queryCount,
                $aggregated->queryCount
            ));
        } else {
            $output->writeln(sprintf('vs %s: Queries same (%d)', $label, $aggregated->queryCount));
        }

        if ($baseline->rowsReturned !== null && $aggregated->rowsReturned !== null) {
            $baselineRows = max(1, (int) $baseline->rowsReturned);
            $aggregatedRows = max(1, (int) $aggregated->rowsReturned);
            if ($baselineRows === $aggregatedRows) {
                $output->writeln(sprintf('vs %s: DB rows same (%d)', $label, $aggregatedRows));
            } elseif ($baselineRows > $aggregatedRows) {
                $output->writeln(sprintf(
                    'vs %s: DB rows %dx fewer (%d → %d)',
                    $label,
                    max(1, (int) round($baselineRows / $aggregatedRows)),
                    $baselineRows,
                    $aggregatedRows
                ));
            } else {
                $output->writeln(sprintf(
                    'vs %s: DB rows %dx more (%d → %d)',
                    $label,
                    max(1, (int) round($aggregatedRows / $baselineRows)),
                    $baselineRows,
                    $aggregatedRows
                ));
            }
        }

        $output->writeln('');
    }

    private function calculateImprovement(float $baseline, float $optimized): float
    {
        if ($baseline <= 0.0) {
            return 0.0;
        }

        return round((($baseline - $optimized) / $baseline) * 100, 1);
    }

    private function prepareLimit(InputInterface $input): int
    {
        $limit = (int) $input->getOption('limit');

        return max(1, min($limit, self::MAX_LIMIT));
    }

    private function prepareWarmupRounds(InputInterface $input): int
    {
        $warmup = (int) $input->getOption('warmup');

        return max(0, $warmup);
    }

    private function warmUp(OutputInterface $output, int $limit, int $rounds): void
    {
        if ($rounds <= 0) {
            return;
        }

        $output->writeln(sprintf(
            "\n<comment>WARMUP (%dx) - stabilizing DB cache to avoid order bias</comment>",
            $rounds
        ));

        for ($i = 0; $i < $rounds; ++$i) {
            $this->entityManager->clear();
            $this->queryCounter->reset();
            gc_collect_cycles();

            $rows = $this->productRepository->findAllWithSimpleJoinsFlat($limit);
            $result = $this->groupSimpleJoinFlatRowsToAggregatedShape($rows);
            unset($rows, $result);

            $result = $this->productRepository->findAllAggregated($limit);
            unset($result);

            $this->entityManager->clear();
            gc_collect_cycles();
        }
    }

    private function printHeader(OutputInterface $output, int $limit): void
    {
        $output->writeln(str_repeat('━', 50));
        $output->writeln('<info>PRODUCTS PERFORMANCE TEST</info>');
        $output->writeln(str_repeat('━', 50));
        $output->writeln("Dataset size: {$limit} products");
    }

    private function formatKilobytes(int $bytes): string
    {
        return number_format($bytes / 1024, 1, '.', '');
    }

    /**
     * Convert a flat Cartesian product result-set into the same structure as the JSON aggregation output.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function groupSimpleJoinFlatRowsToAggregatedShape(array $rows): array
    {
        $products = [];
        $imagesSeen = [];
        $reviewsSeen = [];

        foreach ($rows as $row) {
            $productIdKey = (string) $row['product_id'];

            if (!isset($products[$productIdKey])) {
                $products[$productIdKey] = [
                    'id' => $row['product_id'],
                    'name' => $row['product_name'],
                    'description' => $row['product_description'],
                    'price' => $row['product_price'],
                    'stock' => $row['product_stock'],
                    'created_at' => $row['product_created_at'],
                    'updated_at' => $row['product_updated_at'],
                    'category_id' => $row['category_id'],
                    'brand_id' => $row['brand_id'],
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

                $imagesSeen[$productIdKey] = [];
                $reviewsSeen[$productIdKey] = [];
            }

            $imageId = $row['image_id'];
            if ($imageId !== null) {
                $imageIdKey = (string) $imageId;
                if (!isset($imagesSeen[$productIdKey][$imageIdKey])) {
                    $products[$productIdKey]['images'][] = [
                        'id' => (int) $imageId,
                        'url' => $row['image_url'],
                        'position' => (int) $row['image_position'],
                    ];
                    $imagesSeen[$productIdKey][$imageIdKey] = true;
                    ++$products[$productIdKey]['images_count'];
                }
            }

            $reviewId = $row['review_id'];
            if ($reviewId !== null) {
                $reviewIdKey = (string) $reviewId;
                if (!isset($reviewsSeen[$productIdKey][$reviewIdKey])) {
                    $products[$productIdKey]['reviews'][] = [
                        'id' => (int) $reviewId,
                        'author' => $row['review_author'],
                        'rating' => (int) $row['review_rating'],
                        'comment' => $row['review_comment'],
                    ];
                    $reviewsSeen[$productIdKey][$reviewIdKey] = true;
                    ++$products[$productIdKey]['reviews_count'];
                }
            }
        }

        return array_values($products);
    }
}

final class PerformanceResult
{
    public function __construct(
        public readonly float $timeMs,
        public readonly int $memoryBytes,
        public readonly int $queryCount,
        public readonly ?int $rowsReturned = null,
        public readonly ?int $resultCount = null,
    ) {
    }
}
