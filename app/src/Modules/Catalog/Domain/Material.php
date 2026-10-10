<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
use App\Shared\Domain\Assert;
final readonly class Material
{
    public function __construct(public int $id, public string $title, public int $measureUnitId)
    {
        Assert::positive($id, 'materialId');
        Assert::positive($measureUnitId, 'measureUnitId');
        Assert::text($title, 'title');
    }
}
