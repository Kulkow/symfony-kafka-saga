<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application\Contract;
final readonly class MaterialData
{
    public function __construct(public int $id, public string $title, public int $measureUnitId) {}
}
