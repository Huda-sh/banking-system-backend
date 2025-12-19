<?php

namespace App\Enums;

enum Direction: string
{
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';

    public function getLabel(): string
    {
        return match ($this) {
            self::INCOMING => 'Incoming',
            self::OUTGOING => 'Outgoing',
        };
    }

    public static function toArray(): array
    {
        return array_map(fn($c) => $c->value, self::cases());
    }
}
