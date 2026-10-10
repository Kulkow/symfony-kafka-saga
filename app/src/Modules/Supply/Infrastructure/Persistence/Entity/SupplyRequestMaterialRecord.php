<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'request_materials', schema: 'supply')]
#[ORM\UniqueConstraint(columns: ['supply_request_id', 'material_id'])]
final class SupplyRequestMaterialRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(name: 'supply_request_id', type: 'bigint')]
    public int $supplyRequestId;

    #[ORM\Column(name: 'material_id', type: 'bigint')]
    public int $materialId;

    #[ORM\Column(type: 'bigint')]
    public int $quantity;
}
