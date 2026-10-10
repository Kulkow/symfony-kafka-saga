<?php
declare(strict_types=1);
namespace App\Modules\Order\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'positions', schema: 'orders')]
final class OrderPositionRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'order_id', type: 'bigint')]
    public int $orderId;

    #[ORM\Id]
    #[ORM\Column(name: 'product_id', type: 'bigint')]
    public int $productId;

    #[ORM\Column(type: 'bigint')]
    public int $quantity;

    #[ORM\Column(name: 'reserved_quantity', type: 'bigint')]
    public int $reservedQuantity;
}
