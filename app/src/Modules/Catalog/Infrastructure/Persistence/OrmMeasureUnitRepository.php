<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Infrastructure\Persistence;
use App\Modules\Catalog\Domain\{MeasureUnit, MeasureUnitRepository};
use App\Modules\Catalog\Infrastructure\Persistence\Entity\MeasureUnitRecord;
use App\Shared\Application\IdentityGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class OrmMeasureUnitRepository implements MeasureUnitRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IdentityGenerator $identities,
    ) {}

    public function nextIdentity(): int { return $this->identities->next('catalog.measure_units'); }
    public function find(int $id): ?MeasureUnit
    {
        $record = $this->entityManager->find(MeasureUnitRecord::class, $id);
        return $record === null ? null : new MeasureUnit($record->id, $record->title);
    }
    public function findByTitle(string $title): ?MeasureUnit
    {
        $record = $this->entityManager->getRepository(MeasureUnitRecord::class)->findOneBy(['title' => $title]);
        return $record === null ? null : new MeasureUnit($record->id, $record->title);
    }
    public function save(MeasureUnit $unit): void
    {
        $this->requireTransaction();
        $record = new MeasureUnitRecord();
        $record->id = $unit->id;
        $record->title = $unit->title;
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
