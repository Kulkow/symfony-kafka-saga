<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application;
use App\Modules\Catalog\Application\Contract\{CatalogReader, MaterialData, MaterialRequirement, ProductData};
use App\Modules\Catalog\Domain\{Material, MaterialRepository, MeasureUnit, MeasureUnitRepository, Product, ProductComposition, ProductRepository};
use App\Shared\Application\{NotFound, TransactionManager};
use App\Shared\Domain\{Assert, DomainViolation};

final readonly class CatalogService implements CatalogReader
{
    public function __construct(
        private ProductRepository $products,
        private MaterialRepository $materials,
        private MeasureUnitRepository $units,
        private TransactionManager $transactions,
    ) {}

    public function createMeasureUnit(string $title): int
    {
        return $this->transactions->run(function () use ($title): int {
            $existing = $this->units->findByTitle($title);
            if ($existing !== null) {
                return $existing->id;
            }
            $unit = new MeasureUnit($this->units->nextIdentity(), $title);
            $this->units->save($unit);
            return $unit->id;
        });
    }

    public function createMaterial(string $title, int $measureUnitId): int
    {
        return $this->transactions->run(function () use ($title, $measureUnitId): int {
            if (!$this->measureUnitExists($measureUnitId)) {
                throw new NotFound('Measure unit not found.');
            }
            $material = new Material($this->materials->nextIdentity(), $title, $measureUnitId);
            $this->materials->save($material);
            return $material->id;
        });
    }

    /** Price is stored in integer minor currency units. @param array<int, int> $composition */
    public function createProduct(string $title, int $price, string $article, array $composition): int
    {
        return $this->transactions->run(function () use ($title, $price, $article, $composition): int {
            if ($this->products->findByArticle($article) !== null) {
                throw new DomainViolation('Product article already exists.');
            }
            $positions = [];
            foreach (Assert::quantities($composition, true) as $materialId => $quantity) {
                $this->material($materialId);
                $positions[] = new ProductComposition($materialId, $quantity);
            }
            $product = new Product($this->products->nextIdentity(), $title, $price, $article, $positions);
            $this->products->save($product);
            return $product->id;
        });
    }

    public function product(int $id): ProductData
    {
        $product = $this->products->find($id) ?? throw new NotFound('Product not found: '.$id);
        return new ProductData($product->id, $product->title, $product->price, $product->article);
    }

    public function material(int $id): MaterialData
    {
        $material = $this->materials->find($id) ?? throw new NotFound('Material not found: '.$id);
        return new MaterialData($material->id, $material->title, $material->measureUnitId);
    }

    public function measureUnitExists(int $id): bool { return $this->units->find($id) !== null; }

    public function materialsFor(array $products): array
    {
        $totals = [];
        foreach (Assert::quantities($products, true) as $productId => $quantity) {
            $product = $this->products->find($productId) ?? throw new NotFound('Product not found: '.$productId);
            foreach ($product->materialsFor($quantity) as $materialId => $required) {
                $totals[$materialId] = Assert::add($totals[$materialId] ?? 0, $required);
            }
        }
        ksort($totals);
        $requirements = [];
        foreach ($totals as $materialId => $quantity) {
            $requirements[$materialId] = new MaterialRequirement($materialId, $quantity,
                $this->material($materialId)->measureUnitId);
        }
        return $requirements;
    }
}
