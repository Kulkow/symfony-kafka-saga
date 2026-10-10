<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Persistence;
use Doctrine\Migrations\Version\{Comparator, Version};

final class MigrationComparator implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return strcmp($this->timestamp($a), $this->timestamp($b)) ?: strcmp((string) $a, (string) $b);
    }
    private function timestamp(Version $version): string
    {
        if (!preg_match('/Version(\d{14})$/D', (string) $version, $matches)) {
            throw new \InvalidArgumentException('Use migration class names VersionYYYYMMDDHHMMSS.');
        }
        return $matches[1];
    }
}
