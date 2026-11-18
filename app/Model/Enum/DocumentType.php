<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum DocumentType: string
{
    case Intake = 'intake';
    case ServiceReport = 'service';
    case PriceBreakdown = 'pricing';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'Příjmový protokol',
            self::ServiceReport => 'Servisní protokol',
            self::PriceBreakdown => 'Cenový rozpis',
        };
    }

    public function filePrefix(): string
    {
        return match ($this) {
            self::Intake => 'prijmovy-protokol',
            self::ServiceReport => 'servisni-protokol',
            self::PriceBreakdown => 'cenovy-rozpis',
        };
    }

    /**
     * Servisní protokol dokládá provedenou opravu – lze jej vystavit až po dokončení.
     */
    public function isAvailableFor(OrderStatus $status): bool
    {
        return match ($this) {
            self::Intake, self::PriceBreakdown => true,
            self::ServiceReport => $status->isDone(),
        };
    }
}
