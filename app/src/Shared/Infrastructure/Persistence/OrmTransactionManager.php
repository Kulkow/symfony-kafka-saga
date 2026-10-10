<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Persistence;

use App\Shared\Application\TransactionManager;
use Doctrine\ORM\EntityManagerInterface;

final class OrmTransactionManager implements TransactionManager
{
    private int $depth = 0;
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function run(callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $outer = $this->depth === 0;
        ++$this->depth;
        $connection->beginTransaction();
        try {
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();
            if ($outer) { $this->entityManager->clear(); }
            return $result;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) { $connection->rollBack(); }
            // Rollback must also discard managed state; otherwise the next read could see stale stock.
            if ($this->entityManager->isOpen()) { $this->entityManager->clear(); }
            throw $exception;
        } finally {
            --$this->depth;
        }
    }
}
