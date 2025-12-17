<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

final class QueryCountingConnection extends AbstractConnectionMiddleware
{
    public function __construct(\Doctrine\DBAL\Driver\Connection $connection, private readonly QueryCounter $queryCounter)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        return new QueryCountingStatement(parent::prepare($sql), $this->queryCounter);
    }

    public function query(string $sql): Result
    {
        $this->queryCounter->increment();

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->queryCounter->increment();

        return parent::exec($sql);
    }
}

