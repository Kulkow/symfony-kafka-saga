<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence;
use App\Modules\Warehouse\Domain\{StockType, Warehouse, WarehousePosition, WarehousePositionReserve, WarehouseRepository};
use App\Modules\Warehouse\Infrastructure\Persistence\Entity\{WarehouseRecord, WarehousePositionRecord, WarehouseReserveRecord, ReceiptRecord};
use App\Shared\Application\IdentityGenerator;
use App\Shared\Domain\Assert;
use Doctrine\DBAL\{LockMode, Platforms\PostgreSQLPlatform};
use Doctrine\ORM\EntityManagerInterface;

final class OrmWarehouseRepository implements WarehouseRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('warehouse.warehouses'); }
    public function nextPositionIdentity(): int { return $this->identities->next('warehouse.positions'); }
    public function find(int $id): ?Warehouse
    {
        $record = $this->entityManager->find(WarehouseRecord::class, $id, $this->lockMode(true));
        return $record === null ? null : new Warehouse($record->id, $record->title);
    }
    public function findPosition(int $warehouseId, StockType $type, int $itemId): ?WarehousePosition
    {
        $record = $this->one(WarehousePositionRecord::class,
            ['warehouseId' => $warehouseId, 'type' => $type->value, 'itemId' => $itemId], lock: true);
        return $record === null ? null : new WarehousePosition($record->id, $record->warehouseId,
            StockType::from($record->type), $record->itemId, $record->measureUnitId, $record->quantity, $record->reservedQuantity);
    }
    public function save(Warehouse $warehouse): void
    {
        $this->requireTransaction();
        $record = new WarehouseRecord();
        $record->id = $warehouse->id;
        $record->title = $warehouse->title;
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }
    public function savePosition(WarehousePosition $position): void
    {
        $this->requireTransaction();
        $record = $this->entityManager->find(WarehousePositionRecord::class, $position->id) ?? new WarehousePositionRecord();
        $record->id = $position->id;
        $record->warehouseId = $position->warehouseId;
        $record->type = $position->type->value;
        $record->itemId = $position->itemId;
        $record->measureUnitId = $position->measureUnitId;
        $record->quantity = $position->quantity();
        $record->reservedQuantity = $position->reservedQuantity();
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }
    public function reservationsForOrder(int $orderId): array
    {
        return array_map(static fn (WarehouseReserveRecord $record) => new WarehousePositionReserve(
            $record->orderId, $record->positionId, $record->quantity),
            $this->entityManager->getRepository(WarehouseReserveRecord::class)->findBy(['orderId' => $orderId], ['positionId' => 'ASC']));
    }
    public function addReservation(WarehousePositionReserve $reservation): void
    {
        $this->requireTransaction();
        $record = $this->one(WarehouseReserveRecord::class, ['orderId' => $reservation->orderId, 'positionId' => $reservation->positionId]);
        if ($record === null) {
            $record = new WarehouseReserveRecord();
            $record->orderId = $reservation->orderId;
            $record->positionId = $reservation->positionId;
            $record->quantity = $reservation->quantity;
        } else {
            $record->quantity = Assert::add($record->quantity, $reservation->quantity);
        }
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }
    public function receiptExists(int $supplyOrderId): bool
    {
        return $this->entityManager->find(ReceiptRecord::class, $supplyOrderId) !== null;
    }
    public function recordReceipt(int $supplyOrderId, int $orderId): void
    {
        $this->requireTransaction();
        $record = new ReceiptRecord();
        $record->supplyOrderId = $supplyOrderId;
        $record->orderId = $orderId;
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    private function one(string $class, array $criteria, bool $lock = false): ?object
    {
        $query = $this->entityManager->createQueryBuilder()->select('record')->from($class, 'record');
        foreach ($criteria as $index => $value) {
            $query->andWhere('record.'.$index.' = :'.$index)->setParameter($index, $value);
        }
        $query = $query->setMaxResults(1)->getQuery();
        if (($mode = $this->lockMode($lock)) !== null) { $query->setLockMode($mode); }
        return $query->getOneOrNullResult();
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
