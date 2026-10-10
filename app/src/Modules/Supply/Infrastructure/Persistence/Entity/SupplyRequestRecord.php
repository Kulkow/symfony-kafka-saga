<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'requests', schema: 'supply')]
final class SupplyRequestRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(name: 'order_id', type: 'bigint', unique: true)]
    public int $orderId;

    #[ORM\Column(name: 'warehouse_id', type: 'bigint')]
    public int $warehouseId;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\Column(name: 'status_id', type: 'integer')]
    public int $statusId;
}
