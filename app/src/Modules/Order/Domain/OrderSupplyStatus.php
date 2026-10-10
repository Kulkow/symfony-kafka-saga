<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;
enum OrderSupplyStatus: string
{
    case Draft = 'draft';
    case InWork = 'in_work';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Completed = 'completed';
}
