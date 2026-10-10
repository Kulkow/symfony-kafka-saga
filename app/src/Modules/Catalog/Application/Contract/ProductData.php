<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application\Contract;
final readonly class ProductData
{
    public function __construct(public int $id, public string $title, public int $price, public string $article) {}
}
