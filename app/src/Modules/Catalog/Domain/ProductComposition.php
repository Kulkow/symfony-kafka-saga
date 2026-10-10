<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
use App\Shared\Domain\Assert;
final readonly class ProductComposition
{
    public function __construct(public int $materialId, public int $quantity)
    {
        Assert::positive($materialId, 'materialId');
        Assert::positive($quantity, 'quantity');
    }
}
