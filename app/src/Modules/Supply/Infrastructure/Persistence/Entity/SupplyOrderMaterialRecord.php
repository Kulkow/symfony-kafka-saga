<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_materials', schema: 'supply')]
final class SupplyOrderMaterialRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'supply_order_id', type: 'bigint')]
    public int $supplyOrderId;

    #[ORM\Id]
    #[ORM\Column(name: 'material_id', type: 'bigint')]
    public int $materialId;

    #[ORM\Column(type: 'bigint')]
    public int $quantity;
}
