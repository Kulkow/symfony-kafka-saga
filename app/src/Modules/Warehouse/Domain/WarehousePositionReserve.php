<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Domain;
use App\Shared\Domain\Assert;

final readonly class WarehousePositionReserve
{
    public function __construct(
        public int $orderId,
        public int $positionId,
        public int $quantity,
    ) {
        Assert::positive($orderId, 'orderId');
        Assert::positive($positionId, 'positionId');
        Assert::positive($quantity, 'quantity');
    }
}
