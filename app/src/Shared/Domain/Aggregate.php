<?php
declare(strict_types=1);
namespace App\Shared\Domain;

abstract class Aggregate
{
    /** @var list<DomainEvent> */
    private array $events = [];

    protected function record(string $name, string $key, array $data): void
    {
        $this->events[] = DomainEvent::record($name, $key, $data);
    }

    /** @return list<DomainEvent> */
    final public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }
}
