<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'receipts', schema: 'warehouse')]
final class ReceiptRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'supply_order_id', type: 'bigint')]
    public int $supplyOrderId;

    #[ORM\Column(name: 'order_id', type: 'bigint')]
    public int $orderId;
}
