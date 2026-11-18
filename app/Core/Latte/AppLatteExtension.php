<?php

declare(strict_types=1);

namespace App\Core\Latte;

use App\Model\Enum\CustomerType;
use App\Model\Enum\DeviceType;
use App\Model\Enum\OrderPriority;
use App\Model\Enum\OrderStatus;
use App\Model\Enum\StockMovementType;
use App\Model\Enum\UserRole;
use App\Model\Money\Money;
use Latte\Extension;
use Nette\Utils\Html;

/**
 * Filtry a funkce šablon: formátování peněz, času, dat a sjednocené odznaky stavů.
 */
final class AppLatteExtension extends Extension
{
    public function getFilters(): array
    {
        return [
            'money' => self::money(...),
            'hours' => self::hours(...),
            'czDate' => self::czDate(...),
            'czDateTime' => self::czDateTime(...),
            'relative' => self::relative(...),
            'initials' => self::initials(...),
            'prettyJson' => self::prettyJson(...),
        ];
    }

    public function getFunctions(): array
    {
        return [
            'icon' => self::icon(...),
            'statusBadge' => self::statusBadge(...),
            'priorityBadge' => self::priorityBadge(...),
            'movementBadge' => self::movementBadge(...),
            'statusLabel' => static fn (string $s): string => OrderStatus::from($s)->label(),
            'roleLabel' => static fn (string $r): string => UserRole::from($r)->label(),
            'deviceTypeLabel' => static fn (string $t): string => DeviceType::from($t)->label(),
            'deviceIcon' => static fn (string $t): string => DeviceType::from($t)->icon(),
            'customerTypeLabel' => static fn (string $t): string => CustomerType::from($t)->label(),
            'isOverdue' => self::isOverdue(...),
        ];
    }

    public static function money(Money|string|int|null $value, bool $currency = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return ($value instanceof Money ? $value : Money::of($value))->format($currency);
    }

    /**
     * Minuty → „1,5 h“ / „45 min“.
     */
    public static function hours(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return $minutes . "\u{00A0}min";
        }
        $hours = $minutes / 60;
        $formatted = rtrim(rtrim(number_format($hours, 2, ',', ''), '0'), ',');

        return $formatted . "\u{00A0}h";
    }

    public static function czDate(?\DateTimeInterface $date): string
    {
        return $date === null ? '—' : $date->format('j. n. Y');
    }

    public static function czDateTime(?\DateTimeInterface $date): string
    {
        return $date === null ? '—' : $date->format('j. n. Y H:i');
    }

    public static function relative(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }
        $diff = time() - $date->getTimestamp();

        return match (true) {
            $diff < 60 => 'právě teď',
            $diff < 3600 => 'před ' . intdiv($diff, 60) . ' min',
            $diff < 86400 => 'před ' . intdiv($diff, 3600) . ' h',
            $diff < 172800 => 'včera ' . $date->format('H:i'),
            $diff < 604800 => 'před ' . intdiv($diff, 86400) . ' dny',
            default => $date->format('j. n. Y'),
        };
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials;
    }

    public static function prettyJson(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        try {
            return \Nette\Utils\Json::encode(\Nette\Utils\Json::decode($json), pretty: true);
        } catch (\Nette\Utils\JsonException) {
            return $json;
        }
    }

    public static function icon(string $name, string $class = ''): Html
    {
        return Html::el('i')
            ->setAttribute('data-lucide', $name)
            ->setAttribute('class', trim('icon ' . $class))
            ->setAttribute('aria-hidden', 'true');
    }

    public static function statusBadge(OrderStatus|string $status): Html
    {
        $status = $status instanceof OrderStatus ? $status : OrderStatus::from($status);

        return Html::el('span', ['class' => 'badge-status tone-' . $status->color()])
            ->addHtml(self::icon($status->icon()))
            ->addText($status->label());
    }

    public static function priorityBadge(OrderPriority|string $priority): Html
    {
        $priority = $priority instanceof OrderPriority ? $priority : OrderPriority::from($priority);

        return Html::el('span', ['class' => 'badge-priority tone-' . $priority->color()])
            ->addHtml(Html::el('span', ['class' => 'dot']))
            ->addText($priority->label());
    }

    public static function movementBadge(StockMovementType|string $type): Html
    {
        $type = $type instanceof StockMovementType ? $type : StockMovementType::from($type);

        return Html::el('span', ['class' => 'badge-status tone-' . $type->color()])->setText($type->label());
    }

    public static function isOverdue(?\DateTimeInterface $dueDate, string $status): bool
    {
        return $dueDate !== null
            && OrderStatus::from($status)->isActive()
            && $dueDate < new \DateTimeImmutable('today');
    }
}
