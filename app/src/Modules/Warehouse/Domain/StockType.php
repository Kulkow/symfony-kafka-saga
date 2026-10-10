<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Domain;
enum StockType: int
{
    case Product = 1;
    case Material = 2;
}
