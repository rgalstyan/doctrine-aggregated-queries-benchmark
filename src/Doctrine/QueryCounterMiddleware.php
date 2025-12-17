<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

final class QueryCounterMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly QueryCounter $queryCounter)
    {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new QueryCountingDriver($driver, $this->queryCounter);
    }
}

