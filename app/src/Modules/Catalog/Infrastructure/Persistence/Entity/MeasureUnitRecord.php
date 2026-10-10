<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'measure_units', schema: 'catalog')]
final class MeasureUnitRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    public string $title;
}
