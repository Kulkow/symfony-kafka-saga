<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'position_reserves', schema: 'warehouse')]
#[ORM\UniqueConstraint(columns: ['order_id', 'warehouse_position_id'])]
final class WarehouseReserveRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(name: 'order_id', type: 'bigint')]
    public int $orderId;

    #[ORM\Column(name: 'warehouse_position_id', type: 'bigint')]
    public int $positionId;

    #[ORM\Column(type: 'bigint')]
    public int $quantity;
}
