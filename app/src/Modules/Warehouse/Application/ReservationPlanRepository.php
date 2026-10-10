<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Application;
use App\Modules\Warehouse\Application\Contract\ReservationPlan;
interface ReservationPlanRepository
{
    public function find(int $orderId): ?ReservationPlan;
    public function save(int $orderId, int $warehouseId, ReservationPlan $plan): void;
    public function warehouseForOrder(int $orderId): int;
}
