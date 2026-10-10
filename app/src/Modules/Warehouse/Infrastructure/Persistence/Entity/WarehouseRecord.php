<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'warehouses', schema: 'warehouse')]
final class WarehouseRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(type: 'string', length: 255)]
    public string $title;
}
