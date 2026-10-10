<?php
declare(strict_types=1);
namespace App\Modules\Order\Domain;
enum OrderStatus: string
{
    case New = 'new';
    case InWork = 'in_work';
    case InProduction = 'in_production';
    case Produced = 'producted';
    case Completed = 'completed';
}
