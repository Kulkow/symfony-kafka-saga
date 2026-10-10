<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application\Contract;
interface CatalogReader
{
    public function product(int $id): ProductData;
    public function material(int $id): MaterialData;
    public function measureUnitExists(int $id): bool;
    /** @param array<int, int> $products @return array<int, MaterialRequirement> */
    public function materialsFor(array $products): array;
}
