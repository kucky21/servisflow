<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrátor',
            self::Manager => 'Vedoucí servisu',
            self::Technician => 'Technik',
        };
    }

    /**
     * Vedoucí a administrátor mají přístup k obchodním datům (zákazníci, ceny).
     */
    public function isManagerial(): bool
    {
        return $this === self::Admin || $this === self::Manager;
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
