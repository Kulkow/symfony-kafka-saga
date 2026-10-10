<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence;
use App\Modules\Catalog\Domain\{Product, ProductComposition, ProductRepository};
use App\Modules\Catalog\Infrastructure\Persistence\Entity\{ProductCompositionRecord, ProductRecord};
use App\Shared\Application\IdentityGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class OrmProductRepository implements ProductRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('catalog.products'); }
    public function find(int $id): ?Product
    {
        $record = $this->entityManager->find(ProductRecord::class, $id);
        if ($record === null) { return null; }
        $composition = [];
        foreach ($this->entityManager->getRepository(ProductCompositionRecord::class)->findBy(['productId' => $id], ['materialId' => 'ASC']) as $position) {
            $composition[] = new ProductComposition($position->materialId, $position->quantity);
        }
        return new Product($record->id, $record->title, $record->price, $record->article, $composition);
    }
    public function findByArticle(string $article): ?Product
    {
        $record = $this->entityManager->getRepository(ProductRecord::class)->findOneBy(['article' => $article]);
        return $record === null ? null : $this->find($record->id);
    }
    public function save(Product $product): void
    {
        $this->requireTransaction();
        $record = new ProductRecord();
        $record->id = $product->id;
        $record->title = $product->title;
        $record->price = $product->price;
        $record->article = $product->article;
        $this->entityManager->persist($record);
        foreach ($product->composition as $position) {
            $record = new ProductCompositionRecord();
            $record->productId = $product->id;
            $record->materialId = $position->materialId;
            $record->quantity = $position->quantity;
            $this->entityManager->persist($record);
        }
        $this->entityManager->flush();
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Repository writes require an application transaction.');
        }
    }
}
