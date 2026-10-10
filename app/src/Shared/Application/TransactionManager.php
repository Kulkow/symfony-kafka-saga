<?php
declare(strict_types=1);
namespace App\Shared\Application;
interface TransactionManager
{
    public function run(callable $operation): mixed;
}
