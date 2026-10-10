<?php
declare(strict_types=1);
namespace App\Modules\Order\Application\Contract;
final readonly class OrderData
{
    public function __construct(
        public int $id, public string $number, public int $warehouseId,
        public string $status, public string $supplyStatus, public array $positions,
    ) {}
}
