<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Domain;
use App\Shared\Domain\Assert;
use App\Shared\Domain\DomainViolation;

final readonly class Product
{
    /** @param list<ProductComposition> $composition */
    public function __construct(
        public int $id,
        public string $title,
        public int $price,
        public string $article,
        public array $composition,
    ) {
        Assert::positive($id, 'productId');
        Assert::text($title, 'title');
        Assert::text($article, 'article');
        Assert::nonNegative($price, 'price');
        $seen = [];
        foreach ($composition as $position) {
            if (!$position instanceof ProductComposition || isset($seen[$position->materialId])) {
                throw new DomainViolation('Product composition must contain unique materials.');
            }
            $seen[$position->materialId] = true;
        }
    }

    /** @return array<int, int> */
    public function materialsFor(int $productQuantity): array
    {
        Assert::positive($productQuantity, 'quantity');
        if ($this->composition === []) {
            throw new DomainViolation('Product '.$this->id.' has no production composition.');
        }
        $requirements = [];
        foreach ($this->composition as $position) {
            $requirements[$position->materialId] = Assert::multiply($position->quantity, $productQuantity);
        }
        return $requirements;
    }
}
