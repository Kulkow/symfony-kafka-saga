<?php
declare(strict_types=1);
namespace App\Shared\Application;
interface IdentityGenerator
{
    public function next(string $table): int;
}
