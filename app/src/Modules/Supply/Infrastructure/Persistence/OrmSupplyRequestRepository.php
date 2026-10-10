<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence;
use App\Modules\Supply\Domain\{StatusChange, SupplyRequest, SupplyRequestRepository, SupplyStatus};
use App\Modules\Supply\Infrastructure\Persistence\Entity\{SupplyRequestRecord, SupplyRequestMaterialRecord, SupplyRequestStatusLogRecord, SupplyStatusRecord};
use App\Shared\Application\{EventPublisher, IdentityGenerator};
use Doctrine\DBAL\{LockMode, Platforms\PostgreSQLPlatform};
use Doctrine\ORM\EntityManagerInterface;

final class OrmSupplyRequestRepository implements SupplyRequestRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
        private readonly EventPublisher $events,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('supply.requests'); }
    public function find(int $id): ?SupplyRequest
    {
        $record = $this->entityManager->find(SupplyRequestRecord::class, $id, $this->lockMode(true));
        return $record === null ? null : new SupplyRequest($record->id, $record->orderId, $record->warehouseId,
            $record->date, $this->materials(SupplyRequestMaterialRecord::class, 'supplyRequestId', $id),
            SupplyStatus::from($this->statusSlug(SupplyStatusRecord::class, $record->statusId)),
            $this->history(SupplyRequestStatusLogRecord::class, 'supplyRequestId', SupplyStatusRecord::class, $id));
    }
    public function findByOrder(int $orderId): ?SupplyRequest
    {
        $record = $this->entityManager->getRepository(SupplyRequestRecord::class)->findOneBy(['orderId' => $orderId]);
        return $record === null ? null : $this->find($record->id);
    }
    public function save(SupplyRequest $request): void
    {
        $this->requireTransaction();
        $record = $this->entityManager->find(SupplyRequestRecord::class, $request->id);
        $new = $record === null;
        $record ??= new SupplyRequestRecord();
        $record->id = $request->id;
        $record->orderId = $request->orderId;
        $record->warehouseId = $request->warehouseId;
        $record->date = $request->date;
        $record->statusId = $this->statusId(SupplyStatusRecord::class, $request->status());
        $this->entityManager->persist($record);
        if ($new) {
            foreach ($request->materials as $id => $quantity) {
                $record = new SupplyRequestMaterialRecord();
                $record->supplyRequestId = $request->id;
                $record->materialId = $id;
                $record->quantity = $quantity;
                $this->entityManager->persist($record);
            }
            $this->entityManager->flush();
        }
        $this->saveHistory(SupplyRequestStatusLogRecord::class, 'supplyRequestId', SupplyStatusRecord::class, $request->id, $request->history());
        foreach ($request->releaseEvents() as $event) { $this->events->publish($event); }
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
