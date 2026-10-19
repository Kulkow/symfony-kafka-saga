<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

final readonly class WalletId
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
