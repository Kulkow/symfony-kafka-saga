<?php
declare(strict_types=1);
namespace App\Modules\Supply\Domain;
interface SupplyRequestRepository
{
    public function nextIdentity(): int;
    public function find(int $id): ?SupplyRequest;
    public function findByOrder(int $orderId): ?SupplyRequest;
    public function save(SupplyRequest $request): void;
}
