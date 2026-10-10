<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
interface MaterialRepository
{
    public function nextIdentity(): int;
    public function find(int $id): ?Material;
    public function save(Material $material): void;
}
