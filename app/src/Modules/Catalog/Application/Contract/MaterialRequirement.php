<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application\Contract;
final readonly class MaterialRequirement
{
    public function __construct(public int $materialId, public int $quantity, public int $measureUnitId) {}
}
