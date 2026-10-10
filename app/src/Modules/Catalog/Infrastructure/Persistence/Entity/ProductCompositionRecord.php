<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_compositions', schema: 'catalog')]
#[ORM\UniqueConstraint(columns: ['product_id', 'material_id'])]
final class ProductCompositionRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(name: 'product_id', type: 'bigint')]
    public int $productId;

    #[ORM\Column(name: 'material_id', type: 'bigint')]
    public int $materialId;

    #[ORM\Column(type: 'bigint')]
    public int $quantity;
}
