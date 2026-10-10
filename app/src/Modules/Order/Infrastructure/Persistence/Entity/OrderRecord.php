<?php
declare(strict_types=1);
namespace App\Modules\Order\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'orders', schema: 'orders')]
final class OrderRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    public string $number;

    #[ORM\Column(name: 'warehouse_id', type: 'bigint')]
    public int $warehouseId;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\Column(name: 'status_id', type: 'integer')]
    public int $statusId;

    #[ORM\Column(name: 'supply_status_id', type: 'integer')]
    public int $supplyStatusId;
}
