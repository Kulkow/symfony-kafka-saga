<?php
declare(strict_types=1);
namespace App\Modules\Order\Infrastructure\Persistence;
use App\Modules\Order\Domain\{Order, OrderPosition, OrderRepository, OrderStatus, OrderSupplyStatus};
use App\Modules\Order\Infrastructure\Persistence\Entity\{OrderPositionRecord, OrderRecord, OrderStatusRecord, OrderSupplyStatusRecord};
use App\Shared\Application\{EventPublisher, IdentityGenerator};
use Doctrine\DBAL\{LockMode, Platforms\PostgreSQLPlatform};
use Doctrine\ORM\EntityManagerInterface;

final class OrmOrderRepository implements OrderRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
        private readonly EventPublisher $events,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('orders.orders'); }
    public function find(int $id): ?Order
    {
        $record = $this->record($id, lock: true);
        if ($record === null) { return null; }
        $positions = [];
        foreach ($this->entityManager->getRepository(OrderPositionRecord::class)->findBy(['orderId' => $id], ['productId' => 'ASC']) as $position) {
            $positions[] = new OrderPosition($position->productId, $position->quantity, $position->reservedQuantity);
        }
        return new Order($record->id, $record->number, $record->warehouseId, $record->date, $positions,
            OrderStatus::from($this->statusSlug(OrderStatusRecord::class, $record->statusId)),
            OrderSupplyStatus::from($this->statusSlug(OrderSupplyStatusRecord::class, $record->supplyStatusId)));
    }
    public function findByNumber(string $number): ?Order
    {
        $record = $this->entityManager->getRepository(OrderRecord::class)->findOneBy(['number' => $number]);
        return $record === null ? null : $this->find($record->id);
    }
    public function save(Order $order): void
    {
        $this->requireTransaction();
        $record = $this->record($order->id) ?? new OrderRecord();
        $record->id = $order->id;
        $record->number = $order->number;
        $record->warehouseId = $order->warehouseId;
        $record->date = $order->date;
        $record->statusId = $this->statusId(OrderStatusRecord::class, $order->status());
        $record->supplyStatusId = $this->statusId(OrderSupplyStatusRecord::class, $order->supplyStatus());
        $this->entityManager->persist($record);
        foreach ($order->positions() as $position) {
            $record = $this->entityManager->find(OrderPositionRecord::class, ['orderId' => $order->id, 'productId' => $position->productId]) ?? new OrderPositionRecord();
            $record->orderId = $order->id;
            $record->productId = $position->productId;
            $record->quantity = $position->quantity;
            $record->reservedQuantity = $position->reservedQuantity;
            $this->entityManager->persist($record);
        }
        $this->entityManager->flush();
        foreach ($order->releaseEvents() as $event) { $this->events->publish($event); }
    }

    private function record(int $id, bool $lock = false): ?OrderRecord
    {
        return $this->entityManager->find(OrderRecord::class, $id, $this->lockMode($lock));
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
