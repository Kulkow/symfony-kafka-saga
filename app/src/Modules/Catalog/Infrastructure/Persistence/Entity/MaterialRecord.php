<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'materials', schema: 'catalog')]
final class MaterialRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(type: 'string', length: 255)]
    public string $title;

    #[ORM\Column(name: 'measure_unit_id', type: 'bigint')]
    public int $measureUnitId;
}
