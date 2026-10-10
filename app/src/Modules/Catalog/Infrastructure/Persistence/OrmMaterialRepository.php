<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence;
use App\Modules\Catalog\Domain\{Material, MaterialRepository};
use App\Modules\Catalog\Infrastructure\Persistence\Entity\MaterialRecord;
use App\Shared\Application\IdentityGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class OrmMaterialRepository implements MaterialRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('catalog.materials'); }
    public function find(int $id): ?Material
    {
        $record = $this->entityManager->find(MaterialRecord::class, $id);
        return $record === null ? null : new Material($record->id, $record->title, $record->measureUnitId);
    }
    public function save(Material $material): void
    {
        $this->requireTransaction();
        $record = new MaterialRecord();
        $record->id = $material->id;
        $record->title = $material->title;
        $record->measureUnitId = $material->measureUnitId;
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    private function requireTransaction(): void
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Repository writes require an application transaction.');
        }
    }
}
