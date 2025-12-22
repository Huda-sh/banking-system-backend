<?php


namespace App\Enums;

enum FrequencyEnum: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';


    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }


    public function getLabel(): string
    {
        return match($this) {
            self::DAILY => 'يومي',
            self::WEEKLY => 'أسبوعي',
            self::MONTHLY => 'شهري',
        };
    }


    public function calculateNextDate(\Carbon\Carbon $date): \Carbon\Carbon
    {
        return match($this) {
            self::DAILY => $date->copy()->addDay(),
            self::WEEKLY => $date->copy()->addWeek(),
            self::MONTHLY => $date->copy()->addMonth(),
        };
    }


    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }
}
