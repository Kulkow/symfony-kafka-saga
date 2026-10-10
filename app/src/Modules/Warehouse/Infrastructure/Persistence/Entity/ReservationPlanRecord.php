<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reservation_plans', schema: 'warehouse')]
final class ReservationPlanRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'order_id', type: 'bigint')]
    public int $orderId;

    #[ORM\Column(name: 'warehouse_id', type: 'bigint')]
    public int $warehouseId;

    #[ORM\Column(type: 'json')]
    public array $plan;
}
