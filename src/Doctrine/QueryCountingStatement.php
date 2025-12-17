<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;

final class QueryCountingStatement extends AbstractStatementMiddleware
{
    public function __construct(\Doctrine\DBAL\Driver\Statement $statement, private readonly QueryCounter $queryCounter)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $this->queryCounter->increment();

        return parent::execute();
    }
}

