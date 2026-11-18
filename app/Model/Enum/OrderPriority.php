<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum OrderPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Nízká',
            self::Normal => 'Běžná',
            self::High => 'Vysoká',
            self::Urgent => 'Urgentní',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'slate',
            self::Normal => 'blue',
            self::High => 'orange',
            self::Urgent => 'red',
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
