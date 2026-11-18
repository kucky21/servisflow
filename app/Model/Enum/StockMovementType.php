<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Return = 'return';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Příjem',
            self::Issue => 'Výdej na zakázku',
            self::Return => 'Vrácení na sklad',
            self::Adjustment => 'Inventurní úprava',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Receipt => 'green',
            self::Issue => 'blue',
            self::Return => 'teal',
            self::Adjustment => 'amber',
        };
    }
}
