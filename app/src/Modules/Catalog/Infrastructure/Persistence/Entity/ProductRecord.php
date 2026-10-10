<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products', schema: 'catalog')]
final class ProductRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(type: 'string', length: 255)]
    public string $title;

    #[ORM\Column(type: 'bigint')]
    public int $price;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    public string $article;
}
