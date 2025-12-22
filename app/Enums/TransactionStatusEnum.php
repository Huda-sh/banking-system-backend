<?php

namespace App\Enums;

enum TransactionStatusEnum: string
{
    case SCHEDULED = 'scheduled';
    case EXECUTED = 'executed';
    case FAILED = 'failed';


    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }


    public function getLabel(): string
    {
        return match($this) {
            self::SCHEDULED => 'مجدولة',
            self::EXECUTED => 'منفذة',
            self::FAILED => 'فاشلة',
        };
    }


    public function getColor(): string
    {
        return match($this) {
            self::SCHEDULED => 'bg-blue-100 text-blue-800',
            self::EXECUTED => 'bg-green-100 text-green-800',
            self::FAILED => 'bg-red-100 text-red-800',
        };
    }


    public function isScheduled(): bool
    {
        return $this === self::SCHEDULED;
    }


    public function isExecuted(): bool
    {
        return $this === self::EXECUTED;
    }


    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }


    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }
}
