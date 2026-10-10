<?php
declare(strict_types=1);
namespace App\Modules\Warehouse\Domain;
use App\Shared\Domain\Assert;
final readonly class Warehouse
{
    public function __construct(public int $id, public string $title)
    {
        Assert::positive($id, 'warehouseId');
        Assert::text($title, 'title');
    }
}
