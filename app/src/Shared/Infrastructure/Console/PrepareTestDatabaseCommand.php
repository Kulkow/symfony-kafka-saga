<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\{DependencyFactory, MigratorConfiguration};
use Doctrine\Migrations\Version\Comparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test:database:prepare', description: 'Migrate test_template and recreate the test database from it')]
final class PrepareTestDatabaseCommand extends Command
{
    private const TEMPLATE = 'test_template';

    public function __construct(
        private readonly DependencyFactory $migrations,
        private readonly string $testDatabaseUrl,
        private readonly string $applicationDatabaseUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
        $params = $parser->parse($this->testDatabaseUrl);
        $name = $params['dbname'] ?? '';
        $applicationName = $parser->parse($this->applicationDatabaseUrl)['dbname'] ?? '';
        if (($params['driver'] ?? null) !== 'pdo_pgsql'
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*_test$/D', $name)
            || $name === $applicationName
            || $applicationName === self::TEMPLATE) {
            throw new \InvalidArgumentException('Use a separate PostgreSQL database ending in _test; the application database must differ from it and test_template.');
        }

        $admin = DriverManager::getConnection(array_replace($params, ['dbname' => 'postgres']));
        try {
            // Serialize preparation so two invocations cannot migrate or clone the same template concurrently.
            $admin->executeQuery("SELECT pg_advisory_lock(hashtext('test:database:prepare'))")->free();
            if (!$admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [self::TEMPLATE])) {
                $admin->executeStatement('CREATE DATABASE '.$admin->quoteIdentifier(self::TEMPLATE).' TEMPLATE template0');
            }
            $this->migrateTemplate($params, $output);

            // Do not disconnect other clients forcibly: an active test run must finish first.
            $admin->executeStatement('DROP DATABASE IF EXISTS '.$admin->quoteIdentifier($name));
            $admin->executeStatement('CREATE DATABASE '.$admin->quoteIdentifier($name)
                .' TEMPLATE '.$admin->quoteIdentifier(self::TEMPLATE));
            $output->writeln('Test database recreated: '.$name.' (template: '.self::TEMPLATE.')');

            return Command::SUCCESS;
        } finally {
            // Closing the session also releases the advisory lock.
            $admin->close();
        }
    }

    private function migrateTemplate(array $params, OutputInterface $output): void
    {
        $template = DriverManager::getConnection(array_replace($params, ['dbname' => self::TEMPLATE]));
        try {
            $configuration = clone $this->migrations->getConfiguration();
            $configuration->setConnectionName(null);
            $configuration->setEntityManagerName(null);
            $factory = DependencyFactory::fromConnection(
                new ExistingConfiguration($configuration),
                new ExistingConnection($template),
            );
            $factory->setService(Comparator::class, $this->migrations->getVersionComparator());
            $factory->getMetadataStorage()->ensureInitialized();
            $latest = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
            $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
            $factory->getMigrator()->migrate(
                $plan,
                (new MigratorConfiguration())->setAllOrNothing($configuration->isAllOrNothing()),
            );
            $output->writeln('Template updated: '.self::TEMPLATE.' ('.count($plan).' migrations applied)');
        } finally {
            // PostgreSQL requires the template to have no active connections while cloning.
            $template->close();
        }
    }
}
