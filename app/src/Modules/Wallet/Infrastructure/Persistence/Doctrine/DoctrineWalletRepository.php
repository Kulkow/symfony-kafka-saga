<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use App\Modules\Wallet\Domain\{Wallet, WalletId, WalletRepository};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineWalletRepository implements WalletRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletMapper $mapper,
    ) {}

    public function findById(WalletId $id): ?Wallet
    {
        $record = $this->entityManager->find(WalletRecord::class, $id->value);

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    /**
     * @throws OptimisticLockException
     * @throws ORMException
     */
    public function findByIdForUpdate(WalletId $id): ?Wallet
    {
        $this->requireTransaction();
        $record = $this->entityManager->find(WalletRecord::class, $id->value, LockMode::PESSIMISTIC_WRITE);
        if ($record === null) {
            return null;
        }

        // Reload managed state after acquiring the lock to avoid a stale balance.
        $this->entityManager->refresh($record, LockMode::PESSIMISTIC_WRITE);

        return $this->mapper->toDomain($record);
    }

    public function add(Wallet $wallet): void
    {
        $this->requireTransaction();
        if ($this->entityManager->find(WalletRecord::class, $wallet->id->value) !== null) {
            throw new \LogicException('Wallet already exists: '.$wallet->id->value);
        }

        $this->entityManager->persist($this->mapper->toRecord($wallet));
        $this->entityManager->flush();
    }

    public function save(Wallet $wallet): void
    {
        $this->requireTransaction();
        $record = $this->entityManager->find(WalletRecord::class, $wallet->id->value)
            ?? throw new \LogicException('Wallet does not exist: '.$wallet->id->value);

        $this->mapper->toRecord($wallet, $record);
        $this->entityManager->flush();
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Wallet writes and locking reads require an application transaction.');
        }
    }
}
