<?php
declare(strict_types=1);
namespace App\Shared\Application;
use App\Shared\Domain\DomainEvent;
interface EventPublisher
{
    public function publish(DomainEvent $event): void;
}
