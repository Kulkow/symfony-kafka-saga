<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;
use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final readonly class OrderPosition
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public int $reservedQuantity = 0,
    ) {
        Assert::positive($productId, 'productId');
        Assert::positive($quantity, 'quantity');
        Assert::nonNegative($reservedQuantity, 'reservedQuantity');
        if ($reservedQuantity > $quantity) {
            throw new DomainViolation('Cannot reserve more products than ordered.');
        }
    }

    public function productionQuantity(): int
    {
        return $this->quantity - $this->reservedQuantity;
    }
}
