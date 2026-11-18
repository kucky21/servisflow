<?php

declare(strict_types=1);

namespace App\Model\Enum;

enum OrderStatus: string
{
    case Received = 'received';
    case Diagnostics = 'diagnostics';
    case AwaitingApproval = 'awaiting_approval';
    case InRepair = 'in_repair';
    case AwaitingParts = 'awaiting_parts';
    case Completed = 'completed';
    case HandedOver = 'handed_over';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Přijato',
            self::Diagnostics => 'Diagnostika',
            self::AwaitingApproval => 'Čeká na schválení',
            self::InRepair => 'V opravě',
            self::AwaitingParts => 'Čeká na díly',
            self::Completed => 'Dokončeno',
            self::HandedOver => 'Předáno zákazníkovi',
            self::Cancelled => 'Zrušeno',
        };
    }

    /**
     * Sémantická barva (odpovídá CSS třídám .status-*).
     */
    public function color(): string
    {
        return match ($this) {
            self::Received => 'slate',
            self::Diagnostics => 'indigo',
            self::AwaitingApproval => 'amber',
            self::InRepair => 'blue',
            self::AwaitingParts => 'orange',
            self::Completed => 'green',
            self::HandedOver => 'teal',
            self::Cancelled => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Received => 'inbox',
            self::Diagnostics => 'stethoscope',
            self::AwaitingApproval => 'hourglass',
            self::InRepair => 'wrench',
            self::AwaitingParts => 'package-search',
            self::Completed => 'circle-check',
            self::HandedOver => 'hand-helping',
            self::Cancelled => 'circle-x',
        };
    }

    /**
     * Zakázka je rozpracovaná (není dokončená, předaná ani zrušená).
     */
    public function isActive(): bool
    {
        return !in_array($this, [self::Completed, self::HandedOver, self::Cancelled], true);
    }

    /**
     * Konečný stav – z něj již nevede žádný přechod.
     */
    public function isFinal(): bool
    {
        return $this === self::HandedOver || $this === self::Cancelled;
    }

    /**
     * Zakázka je považována za dokončenou (práce byla odvedena).
     */
    public function isDone(): bool
    {
        return $this === self::Completed || $this === self::HandedOver;
    }

    /**
     * Na zakázce lze evidovat práci a materiál.
     */
    public function allowsWork(): bool
    {
        return $this->isActive();
    }

    /**
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->isActive()));
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::activeCases());
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
