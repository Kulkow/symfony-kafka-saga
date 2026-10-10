<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
interface ProductRepository
{
    public function nextIdentity(): int;
    public function find(int $id): ?Product;
    public function findByArticle(string $article): ?Product;
    public function save(Product $product): void;
}
