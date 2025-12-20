<?php

namespace App\Enums;

enum Direction: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public function getLabel(): string
    {
        return match ($this) {
            self::DEBIT => 'Debit',
            self::CREDIT => 'Credit',
        };
    }

    public static function toArray(): array
    {
        return array_map(fn($c) => $c->value, self::cases());
    }
}
