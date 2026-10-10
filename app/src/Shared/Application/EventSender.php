<?php
declare(strict_types=1);
namespace App\Shared\Application;
use App\Shared\Domain\DomainEvent;
interface EventSender
{
    /** Return only after the broker has acknowledged delivery; otherwise throw. */
    public function send(DomainEvent $event): void;
}
