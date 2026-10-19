<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Persistence\Doctrine;

use App\Modules\Wallet\Application\TransactionManager;
use App\Shared\Application\TransactionManager as SharedTransactionManager;

final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private SharedTransactionManager $transactions) {}

    public function run(callable $operation): mixed
    {
        return $this->transactions->run($operation);
    }
}
