<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Application;

interface TransactionManager
{
    public function run(callable $operation): mixed;
}
