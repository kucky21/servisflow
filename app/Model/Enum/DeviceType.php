<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum DeviceType: string
{
    case Laptop = 'laptop';
    case Desktop = 'desktop';
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Monitor = 'monitor';
    case Printer = 'printer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Laptop => 'Notebook',
            self::Desktop => 'Stolní počítač',
            self::Phone => 'Mobilní telefon',
            self::Tablet => 'Tablet',
            self::Monitor => 'Monitor',
            self::Printer => 'Tiskárna',
            self::Other => 'Jiné',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Laptop => 'laptop',
            self::Desktop => 'monitor-cog',
            self::Phone => 'smartphone',
            self::Tablet => 'tablet',
            self::Monitor => 'monitor',
            self::Printer => 'printer',
            self::Other => 'cpu',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
