<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'positions', schema: 'warehouse')]
#[ORM\UniqueConstraint(columns: ['warehouse_id', 'type', 'item_id'])]
final class WarehousePositionRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(name: 'warehouse_id', type: 'bigint')]
    public int $warehouseId;

    #[ORM\Column(type: 'integer')]
    public int $type;

    #[ORM\Column(name: 'item_id', type: 'bigint')]
    public int $itemId;

    #[ORM\Column(type: 'bigint')]
    public int $quantity;

    #[ORM\Column(name: 'reserved_quantity', type: 'bigint')]
    public int $reservedQuantity;

    #[ORM\Column(name: 'measure_unit_id', type: 'bigint')]
    public int $measureUnitId;
}
