<?php
declare(strict_types=1);
namespace App\Modules\Supply\Domain;
interface SupplyOrderRepository
{
    public function nextIdentity(): int;
    public function find(int $id): ?SupplyOrder;
    public function findByRequest(int $requestId): ?SupplyOrder;
    public function save(SupplyOrder $order): void;
}
