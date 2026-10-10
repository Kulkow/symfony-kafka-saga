<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Application\Contract;
interface WarehouseDirectory
{
    public function warehouseExists(int $id): bool;
}
