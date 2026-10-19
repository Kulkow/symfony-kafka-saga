<?php
declare(strict_types=1);

namespace App\Modules\Wallet\Tests\Support;

use App\Kernel;
use App\Modules\Wallet\Application\TransactionManager;
use App\Modules\Wallet\Domain\WalletRepository;
use App\Modules\Wallet\Infrastructure\Persistence\Doctrine\WalletRecord;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $connection;
    protected WalletRepository $wallets;
    protected TransactionManager $transactions;
    protected string $schema;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel(['environment' => 'test']);
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $entityManager->getConnection();
        $params = $this->connection->getParams();
        self::assertSame('pdo_pgsql', $params['driver']);
        self::assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*_test$/D', $params['dbname']);
        $applicationParams = (new DsnParser())->parse($_ENV['DATABASE_URL']);
        self::assertNotSame($applicationParams['dbname'] ?? null, $params['dbname'], 'Test database must differ from DATABASE_URL.');
        self::assertSame($params['dbname'], $this->connection->fetchOne('SELECT current_database()'));
        $this->connection->executeStatement("SET statement_timeout = '10s'");
        $this->schema = 'wallet_test_'.bin2hex(random_bytes(8));
        $metadata = $entityManager->getClassMetadata(WalletRecord::class);
        $metadata->setPrimaryTable(['name' => 'wallets', 'schema' => $this->schema]);
        $this->connection->transactional(fn () => (new SchemaTool($entityManager))->createSchema([$metadata]));
        $this->wallets = $container->get(WalletRepository::class);
        $this->transactions = $container->get(TransactionManager::class);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->connection)) {
                while ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                if (isset($this->schema)) {
                    $this->connection->executeStatement('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE');
                }
                $this->connection->close();
            }
        } finally {
            parent::tearDown();
        }
    }
}
