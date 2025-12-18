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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->prepareLimit($input);

        $this->printHeader($output, $limit);

        $traditional = $this->measureTraditional($output, $limit);
        $doctrineJoinFetch = $this->measureDoctrineJoinFetch($output, $limit);
        $simpleJoins = $this->measureSimpleJoins($output, $limit);
        $aggregated = $this->measureAggregated($output, $limit);

        $this->printComparison($output, $traditional, $doctrineJoinFetch, $simpleJoins, $aggregated);

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

    private function printComparison(
        OutputInterface $output,
        PerformanceResult $traditional,
        PerformanceResult $doctrineJoinFetch,
        PerformanceResult $simpleJoins,
        PerformanceResult $aggregated
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

        $output->writeln(str_repeat('━', $lineLength));

        $output->writeln("\n" . str_repeat('━', $lineLength));
        $output->writeln('<info>IMPROVEMENT (JSON aggregation)</info>');
        $output->writeln(str_repeat('━', $lineLength));

        $this->printImprovementAgainst($output, 'Traditional', $traditional, $aggregated);
        $this->printImprovementAgainst($output, 'Doctrine JOIN fetch', $doctrineJoinFetch, $aggregated);
        $this->printImprovementAgainst($output, 'Simple JOINs', $simpleJoins, $aggregated);

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
