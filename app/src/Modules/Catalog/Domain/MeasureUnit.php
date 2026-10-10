<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
use App\Shared\Domain\Assert;
final readonly class MeasureUnit
{
    public function __construct(public int $id, public string $title)
    {
        Assert::positive($id, 'measureUnitId');
        Assert::text($title, 'title');
    }
}
