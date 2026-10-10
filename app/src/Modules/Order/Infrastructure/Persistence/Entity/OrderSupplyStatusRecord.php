<?php
declare(strict_types=1);
namespace App\Modules\Order\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Shared\Infrastructure\Persistence\Entity\StatusRecord;

#[ORM\Entity]
#[ORM\Table(name: 'supply_statuses', schema: 'orders')]
final class OrderSupplyStatusRecord extends StatusRecord
{

}
