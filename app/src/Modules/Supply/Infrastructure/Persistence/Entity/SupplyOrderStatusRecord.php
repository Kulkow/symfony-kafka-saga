<?php
declare(strict_types=1);
namespace App\Modules\Supply\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Shared\Infrastructure\Persistence\Entity\StatusRecord;

#[ORM\Entity]
#[ORM\Table(name: 'order_statuses', schema: 'supply')]
final class SupplyOrderStatusRecord extends StatusRecord
{

}
