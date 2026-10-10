<?php
declare(strict_types=1);
namespace App\Shared\Domain;

final readonly class DomainEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $name,
        public string $key,
        public array $data,
        public \DateTimeImmutable $occurredAt,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $name === '' || $key === '') {
            throw new DomainViolation('Invalid event envelope.');
        }
    }

    public static function record(string $name, string $key, array $data): self
    {
        return new self(bin2hex(random_bytes(16)), $name, $key, $data, new \DateTimeImmutable());
    }

    public function toArray(): array
    {
        return ['eventId' => $this->id, 'type' => $this->name, 'key' => $this->key,
            'data' => $this->data, 'occurredAt' => $this->occurredAt->format(DATE_ATOM)];
    }

    public static function fromArray(array $value): self
    {
        foreach (['eventId', 'type', 'key', 'occurredAt'] as $field) {
            if (!isset($value[$field]) || !is_string($value[$field])) {
                throw new DomainViolation('Invalid event field: '.$field);
            }
        }
        if (!isset($value['data']) || !is_array($value['data'])) {
            throw new DomainViolation('Invalid event data.');
        }
        return new self($value['eventId'], $value['type'], $value['key'], $value['data'],
            new \DateTimeImmutable($value['occurredAt']));
    }
}
