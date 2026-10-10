<?php
declare(strict_types=1);
namespace App\Modules\Supply\Application\Contract;
final readonly class SupplyRequestData
{
    public function __construct(
        public int $id, public int $orderId, public int $warehouseId, public string $status,
        public array $materials, public ?int $supplyOrderId,
    ) {}
}
