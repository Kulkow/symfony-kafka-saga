<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Application;
use App\Modules\Catalog\Application\Contract\CatalogReader;
use App\Modules\Warehouse\Application\Contract\{ReservationPlan, WarehouseDirectory};
use App\Modules\Warehouse\Domain\{StockType, Warehouse, WarehousePosition, WarehousePositionReserve, WarehouseRepository};
use App\Shared\Application\{EventPublisher, NotFound, TransactionManager};
use App\Shared\Domain\{Assert, DomainEvent, DomainViolation};

final readonly class WarehouseService implements WarehouseDirectory
{
    public function __construct(
        private WarehouseRepository $warehouses,
        private ReservationPlanRepository $plans,
        private CatalogReader $catalog,
        private TransactionManager $transactions,
        private EventPublisher $events,
    ) {}

    public function warehouseExists(int $id): bool { return $this->warehouses->find($id) !== null; }

    public function createWarehouse(string $title): int
    {
        return $this->transactions->run(function () use ($title): int {
            $warehouse = new Warehouse($this->warehouses->nextIdentity(), $title);
            $this->warehouses->save($warehouse);
            return $warehouse->id;
        });
    }

    public function receiveStock(int $warehouseId, int $type, int $itemId, int $quantity, int $measureUnitId): void
    {
        $this->transactions->run(function () use ($warehouseId, $type, $itemId, $quantity, $measureUnitId): void {
            $this->requireWarehouse($warehouseId);
            $stockType = StockType::from($type);
            if (!$this->catalog->measureUnitExists($measureUnitId)) {
                throw new NotFound('Measure unit not found.');
            }
            if ($stockType === StockType::Material) {
                if ($this->catalog->material($itemId)->measureUnitId !== $measureUnitId) {
                    throw new DomainViolation('Material measure unit does not match the catalog.');
                }
            } else {
                $this->catalog->product($itemId);
            }
            $position = $this->position($warehouseId, $stockType, $itemId, $measureUnitId);
            $position->receive($quantity);
            $this->warehouses->savePosition($position);
        });
    }

    /** Reserve finished products first, then materials for only the missing products. */
    public function reserveForOrder(int $orderId, int $warehouseId, array $products): ReservationPlan
    {
        return $this->transactions->run(function () use ($orderId, $warehouseId, $products): ReservationPlan {
            Assert::positive($orderId, 'orderId');
            $products = Assert::quantities($products);
            $this->requireWarehouse($warehouseId); // Lock the warehouse before touching inventory.
            $existing = $this->plans->find($orderId);
            if ($existing !== null) {
                if ($this->plans->warehouseForOrder($orderId) !== $warehouseId
                    || $this->originalProducts($existing) !== $products) {
                    throw new DomainViolation('An order already has a different reservation plan.');
                }
                return $existing;
            }
            $reservedProducts = $production = $requirements = $shortages = [];
            foreach ($products as $productId => $quantity) {
                $this->catalog->product($productId);
                $position = $this->warehouses->findPosition($warehouseId, StockType::Product, $productId);
                $reserved = $position === null ? 0 : $this->reserve($position, $orderId, $quantity);
                if ($reserved > 0) { $reservedProducts[$productId] = $reserved; }
                if ($reserved < $quantity) { $production[$productId] = $quantity - $reserved; }
            }
            foreach ($this->catalog->materialsFor($production) as $material) {
                $requirements[$material->materialId] = $material->quantity;
                $position = $this->position($warehouseId, StockType::Material, $material->materialId, $material->measureUnitId);
                $reserved = $this->reserve($position, $orderId, $material->quantity);
                if ($reserved < $material->quantity) {
                    $shortages[$material->materialId] = $material->quantity - $reserved;
                }
            }
            $plan = new ReservationPlan($reservedProducts, $production, $requirements, $shortages);
            $this->plans->save($orderId, $warehouseId, $plan);
            $this->events->publish(DomainEvent::record('warehouse.reserved', 'order:'.$orderId,
                ['orderId' => $orderId, 'warehouseId' => $warehouseId] + $plan->toArray()));
            return $plan;
        });
    }

    /** Receipt and reservation of the shortages are one transaction. */
    public function receiveSupply(int $supplyOrderId, int $orderId, int $warehouseId, array $materials): void
    {
        $this->transactions->run(function () use ($supplyOrderId, $orderId, $warehouseId, $materials): void {
            $this->requireWarehouse($warehouseId);
            $plan = $this->plans->find($orderId) ?? throw new NotFound('Reservation plan not found.');
            if ($this->plans->warehouseForOrder($orderId) !== $warehouseId
                || Assert::quantities($materials) !== $plan->shortages) {
                throw new DomainViolation('Delivery must match the order shortage plan.');
            }
            if ($this->warehouses->receiptExists($supplyOrderId)) {
                return;
            }
            foreach ($materials as $materialId => $quantity) {
                $material = $this->catalog->material($materialId);
                $position = $this->position($warehouseId, StockType::Material, $materialId, $material->measureUnitId);
                $position->receive($quantity);
                if ($this->reserve($position, $orderId, $quantity) !== $quantity) {
                    throw new DomainViolation('Delivered materials could not be reserved.');
                }
            }
            $this->warehouses->recordReceipt($supplyOrderId, $orderId);
            $this->events->publish(DomainEvent::record('warehouse.supply_received', 'order:'.$orderId,
                ['orderId' => $orderId, 'supplyOrderId' => $supplyOrderId]));
        });
    }

    public function plan(int $orderId): ?ReservationPlan { return $this->plans->find($orderId); }

    private function reserve(WarehousePosition $position, int $orderId, int $quantity): int
    {
        $reserved = $position->reserveUpTo($quantity);
        $this->warehouses->savePosition($position);
        if ($reserved > 0) {
            $this->warehouses->addReservation(new WarehousePositionReserve($orderId, $position->id, $reserved));
        }
        return $reserved;
    }

    private function requireWarehouse(int $id): void
    {
        if (!$this->warehouseExists($id)) { throw new NotFound('Warehouse not found: '.$id); }
    }

    private function position(int $warehouseId, StockType $type, int $itemId, int $measureUnitId): WarehousePosition
    {
        $position = $this->warehouses->findPosition($warehouseId, $type, $itemId);
        if ($position !== null && $position->measureUnitId !== $measureUnitId) {
            throw new DomainViolation('Stock measure unit cannot be changed.');
        }
        return $position ?? new WarehousePosition($this->warehouses->nextPositionIdentity(),
            $warehouseId, $type, $itemId, $measureUnitId, 0);
    }

    private function originalProducts(ReservationPlan $plan): array
    {
        $products = $plan->reservedProducts;
        foreach ($plan->productionProducts as $id => $quantity) {
            $products[$id] = ($products[$id] ?? 0) + $quantity;
        }
        ksort($products);
        return $products;
    }
}
