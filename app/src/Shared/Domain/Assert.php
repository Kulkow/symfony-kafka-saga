<?php
declare(strict_types=1);
namespace App\Shared\Domain;

final class Assert
{
    public static function positive(int $value, string $field): void
    {
        if ($value < 1) {
            throw new DomainViolation($field.' must be positive.');
        }
    }

    public static function nonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new DomainViolation($field.' must not be negative.');
        }
    }

    public static function text(string $value, string $field): void
    {
        if (trim($value) === '' || mb_strlen($value) > 255) {
            throw new DomainViolation($field.' must contain 1–255 characters.');
        }
    }

    /** @param array<int, int> $quantities @return array<int, int> */
    public static function quantities(array $quantities, bool $allowEmpty = false): array
    {
        if (!$allowEmpty && $quantities === []) {
            throw new DomainViolation('At least one position is required.');
        }
        foreach ($quantities as $id => $quantity) {
            if (!is_int($id) || !is_int($quantity)) {
                throw new DomainViolation('Position IDs and quantities must be integers.');
            }
            self::positive($id, 'itemId');
            self::positive($quantity, 'quantity');
        }
        ksort($quantities);
        return $quantities;
    }

    public static function multiply(int $left, int $right): int
    {
        self::positive($left, 'quantity');
        self::positive($right, 'quantity');
        if ($left > intdiv(PHP_INT_MAX, $right)) {
            throw new DomainViolation('Quantity overflow.');
        }
        return $left * $right;
    }

    public static function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new DomainViolation('Quantity overflow.');
        }
        return $left + $right;
    }
}
