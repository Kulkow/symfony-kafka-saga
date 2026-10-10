<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_status_logs', schema: 'supply')]
#[ORM\UniqueConstraint(columns: ['supply_order_id', 'version'])]
final class SupplyOrderStatusLogRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    public ?int $id = null;

    #[ORM\Column(name: 'supply_order_id', type: 'bigint')]
    public int $supplyOrderId;

    #[ORM\Column(type: 'integer')]
    public int $version;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $date;

    #[ORM\Column(name: 'old_status_id', type: 'integer')]
    public int $oldStatusId;

    #[ORM\Column(name: 'new_status_id', type: 'integer')]
    public int $newStatusId;
}
