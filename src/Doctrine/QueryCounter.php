<?php

declare(strict_types=1);

namespace App\Doctrine;

final class QueryCounter
{
    private int $count = 0;

    public function reset(): void
    {
        $this->count = 0;
    }

    public function increment(): void
    {
        ++$this->count;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}

