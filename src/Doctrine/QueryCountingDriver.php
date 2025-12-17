<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class QueryCountingDriver extends AbstractDriverMiddleware
{
    public function __construct(DriverInterface $driver, private readonly QueryCounter $queryCounter)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): DriverConnection
    {
        return new QueryCountingConnection(parent::connect($params), $this->queryCounter);
    }
}

