<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Domain;
interface WarehouseRepository
{
    public function nextIdentity(): int;
    public function nextPositionIdentity(): int;
    public function find(int $id): ?Warehouse;
    public function findPosition(int $warehouseId, StockType $type, int $itemId): ?WarehousePosition;
    public function save(Warehouse $warehouse): void;
    public function savePosition(WarehousePosition $position): void;
    /** @return list<WarehousePositionReserve> */
    public function reservationsForOrder(int $orderId): array;
    public function addReservation(WarehousePositionReserve $reservation): void;
    public function receiptExists(int $supplyOrderId): bool;
    public function recordReceipt(int $supplyOrderId, int $orderId): void;
}
