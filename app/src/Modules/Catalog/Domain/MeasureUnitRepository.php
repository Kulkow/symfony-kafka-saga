<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
interface MeasureUnitRepository
{
    public function nextIdentity(): int;
    public function find(int $id): ?MeasureUnit;
    public function findByTitle(string $title): ?MeasureUnit;
    public function save(MeasureUnit $unit): void;
}
