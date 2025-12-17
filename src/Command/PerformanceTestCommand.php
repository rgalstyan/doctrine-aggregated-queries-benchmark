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
    description: 'Compare traditional Doctrine vs Aggregated Queries performance'
)]
final class PerformanceTestCommand extends Command
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT = 2000;

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
        $aggregated = $this->measureAggregated($output, $limit);

        $this->printImprovement($output, $traditional, $aggregated);

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

        return new PerformanceResult($duration, $memoryBytes, $queries);
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

        return new PerformanceResult($duration, $memoryBytes, $queries);
    }

    private function printImprovement(
        OutputInterface $output,
        PerformanceResult $traditional,
        PerformanceResult $aggregated
    ): void {
        $output->writeln("\n" . str_repeat('━', 50));
        $output->writeln('<info>IMPROVEMENT</info>');
        $output->writeln(str_repeat('━', 50));

        $timeImprovement = $this->calculateImprovement($traditional->timeMs, $aggregated->timeMs);
        $memoryImprovement = $this->calculateImprovement($traditional->memoryBytes, $aggregated->memoryBytes);
        $queriesSaved = $traditional->queryCount - $aggregated->queryCount;

        $output->writeln(sprintf(
            'Speed:   %.1f%% faster (%.2fms → %.2fms)',
            $timeImprovement,
            $traditional->timeMs,
            $aggregated->timeMs
        ));
        $output->writeln(sprintf(
            'Memory:  %.1f%% less (%s KB → %s KB)',
            $memoryImprovement,
            $this->formatKilobytes($traditional->memoryBytes),
            $this->formatKilobytes($aggregated->memoryBytes),
        ));
        $output->writeln(sprintf(
            'Queries: %d fewer (%d → %d)',
            $queriesSaved,
            $traditional->queryCount,
            $aggregated->queryCount
        ));

        $output->writeln('');

        if ($timeImprovement > 70) {
            $output->writeln('<info>EXCELLENT! Over 70% improvement!</info>');
        } elseif ($timeImprovement > 50) {
            $output->writeln('<info>GREAT! Over 50% improvement!</info>');
        } else {
            $output->writeln('<comment>Good improvement</comment>');
        }

        $output->writeln(str_repeat('━', 50) . "\n");
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
    ) {
    }
}
