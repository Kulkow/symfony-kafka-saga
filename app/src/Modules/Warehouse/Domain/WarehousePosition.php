<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Domain;
use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final class WarehousePosition
{
    public function __construct(
        public readonly int $id,
        public readonly int $warehouseId,
        public readonly StockType $type,
        public readonly int $itemId,
        public readonly int $measureUnitId,
        private int $quantity,
        private int $reservedQuantity = 0,
    ) {
        foreach (['id' => $id, 'warehouseId' => $warehouseId, 'itemId' => $itemId,
            'measureUnitId' => $measureUnitId] as $field => $value) {
            Assert::positive($value, $field);
        }
        Assert::nonNegative($quantity, 'quantity');
        Assert::nonNegative($reservedQuantity, 'reservedQuantity');
        if ($reservedQuantity > $quantity) {
            throw new DomainViolation('Reserved stock exceeds available stock.');
        }
    }

    public function quantity(): int { return $this->quantity; }
    public function reservedQuantity(): int { return $this->reservedQuantity; }
    public function availableQuantity(): int { return $this->quantity - $this->reservedQuantity; }

    public function reserveUpTo(int $requested): int
    {
        Assert::positive($requested, 'quantity');
        $reserved = min($requested, $this->availableQuantity());
        $this->reservedQuantity += $reserved;
        return $reserved;
    }

    public function receive(int $quantity): void
    {
        Assert::positive($quantity, 'quantity');
        $this->quantity = Assert::add($this->quantity, $quantity);
    }

    public function release(int $quantity): void
    {
        Assert::positive($quantity, 'quantity');
        if ($quantity > $this->reservedQuantity) {
            throw new DomainViolation('Cannot release more than reserved.');
        }
        $this->reservedQuantity -= $quantity;
    }
}
