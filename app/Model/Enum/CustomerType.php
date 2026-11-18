<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum CustomerType: string
{
    case Person = 'person';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Fyzická osoba',
            self::Company => 'Firma',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Person->value => self::Person->label(),
            self::Company->value => self::Company->label(),
        ];
    }
}
