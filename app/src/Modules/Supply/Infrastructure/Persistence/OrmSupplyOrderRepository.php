<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence;
use App\Modules\Supply\Domain\{StatusChange, SupplyOrder, SupplyOrderRepository, SupplyStatus};
use App\Modules\Supply\Infrastructure\Persistence\Entity\{SupplyOrderRecord, SupplyOrderMaterialRecord, SupplyOrderStatusLogRecord, SupplyOrderStatusRecord};
use App\Shared\Application\{EventPublisher, IdentityGenerator};
use Doctrine\DBAL\{LockMode, Platforms\PostgreSQLPlatform};
use Doctrine\ORM\EntityManagerInterface;

final class OrmSupplyOrderRepository implements SupplyOrderRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
        private readonly EventPublisher $events,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('supply.orders'); }
    public function find(int $id): ?SupplyOrder
    {
        $record = $this->entityManager->find(SupplyOrderRecord::class, $id, $this->lockMode(true));
        return $record === null ? null : new SupplyOrder($record->id, $record->supplyRequestId, $record->orderId,
            $record->date, $this->materials(SupplyOrderMaterialRecord::class, 'supplyOrderId', $id),
            SupplyStatus::from($this->statusSlug(SupplyOrderStatusRecord::class, $record->statusId)),
            $this->history(SupplyOrderStatusLogRecord::class, 'supplyOrderId', SupplyOrderStatusRecord::class, $id));
    }
    public function findByRequest(int $requestId): ?SupplyOrder
    {
        $record = $this->entityManager->getRepository(SupplyOrderRecord::class)->findOneBy(['supplyRequestId' => $requestId]);
        return $record === null ? null : $this->find($record->id);
    }
    public function save(SupplyOrder $order): void
    {
        $this->requireTransaction();
        $record = $this->entityManager->find(SupplyOrderRecord::class, $order->id);
        $new = $record === null;
        $record ??= new SupplyOrderRecord();
        $record->id = $order->id;
        $record->supplyRequestId = $order->supplyRequestId;
        $record->orderId = $order->orderId;
        $record->date = $order->date;
        $record->statusId = $this->statusId(SupplyOrderStatusRecord::class, $order->status());
        $this->entityManager->persist($record);
        if ($new) {
            foreach ($order->materials as $id => $quantity) {
                $record = new SupplyOrderMaterialRecord();
                $record->supplyOrderId = $order->id;
                $record->materialId = $id;
                $record->quantity = $quantity;
                $this->entityManager->persist($record);
            }
            $this->entityManager->flush();
        }
        $this->saveHistory(SupplyOrderStatusLogRecord::class, 'supplyOrderId', SupplyOrderStatusRecord::class, $order->id, $order->history());
        foreach ($order->releaseEvents() as $event) { $this->events->publish($event); }
    }

    private function materials(string $class, string $parent, int $id): array
    {
        $materials = [];
        foreach ($this->entityManager->getRepository($class)->findBy([$parent => $id], ['materialId' => 'ASC']) as $record) {
            $materials[$record->materialId] = $record->quantity;
        }
        return $materials;
    }

    private function history(string $class, string $parent, string $statuses, int $id): array
    {
        $history = [];
        foreach ($this->entityManager->getRepository($class)->findBy([$parent => $id], ['version' => 'ASC']) as $record) {
            $history[] = new StatusChange(SupplyStatus::from($this->statusSlug($statuses, $record->oldStatusId)),
                SupplyStatus::from($this->statusSlug($statuses, $record->newStatusId)), $record->date);
        }
        return $history;
    }

    private function saveHistory(string $class, string $parent, string $statuses, int $id, array $history): void
    {
        foreach ($history as $index => $change) {
            if ($this->entityManager->getRepository($class)->findOneBy([$parent => $id, 'version' => $index + 1]) !== null) { continue; }
            $record = new $class();
            $record->$parent = $id;
            $record->version = $index + 1;
            $record->date = $change->date;
            $record->oldStatusId = $this->statusId($statuses, $change->oldStatus);
            $record->newStatusId = $this->statusId($statuses, $change->newStatus);
            $this->entityManager->persist($record);
        }
        $this->entityManager->flush();
    }

    private function statusId(string $class, \BackedEnum $status): int
    {
        return ($this->entityManager->getRepository($class)->findOneBy(['slug' => $status->value])
            ?? throw new \LogicException('Status dictionary entry missing: '.$status->value))->id;
    }

    private function statusSlug(string $class, int $id): string
    {
        return ($this->entityManager->find($class, $id)
            ?? throw new \LogicException('Status dictionary entry missing.'))->slug;
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Repository writes require an application transaction.');
        }
    }

    private function lockMode(bool $lock): ?LockMode
    {
        $connection = $this->entityManager->getConnection();
        return $lock && $connection->isTransactionActive() && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? LockMode::PESSIMISTIC_WRITE : null;
    }
}
