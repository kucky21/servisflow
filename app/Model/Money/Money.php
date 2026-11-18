<?php

declare(strict_types=1);

namespace App\Model\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Neměnná peněžní částka v CZK s přesností na haléře.
 *
 * Interně používá BigDecimal – žádná float aritmetika. Mezivýsledky se
 * zaokrouhlují obchodně (HALF_UP) na 2 desetinná místa.
 */
final class Money implements \JsonSerializable, \Stringable
{
    public const int SCALE = 2;
    public const string CURRENCY = 'CZK';

    private function __construct(
        private readonly BigDecimal $amount,
    ) {
    }

    /**
     * @param BigNumber|int|string $amount Řetězec ve formátu „1234.50“ (z databáze nebo formuláře)
     */
    public static function of(BigNumber|int|string $amount): self
    {
        if (is_string($amount)) {
            $amount = str_replace([' ', ','], ['', '.'], trim($amount));
        }

        return new self(BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public static function ofNullable(BigNumber|int|string|null $amount): ?self
    {
        return $amount === null || $amount === '' ? null : self::of($amount);
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    public function plus(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    /**
     * Násobení (např. jednotková cena × množství). Výsledek je zaokrouhlen na haléře.
     */
    public function multipliedBy(BigNumber|int|string $multiplier): self
    {
        return new self(
            $this->amount->multipliedBy($multiplier)->toScale(self::SCALE, RoundingMode::HALF_UP),
        );
    }

    /**
     * Procentní část částky (např. sleva 10 % nebo DPH 21 %), zaokrouhlená na haléře.
     */
    public function percentage(BigNumber|int|string $percent): self
    {
        return new self(
            $this->amount
                ->multipliedBy($percent)
                ->dividedBy(100, self::SCALE, RoundingMode::HALF_UP),
        );
    }

    /**
     * Cena za čas: hodinová sazba × minuty / 60.
     */
    public function forMinutes(int $minutes): self
    {
        return new self(
            $this->amount
                ->multipliedBy($minutes)
                ->dividedBy(60, self::SCALE, RoundingMode::HALF_UP),
        );
    }

    /**
     * @param iterable<self> $items
     */
    public static function sum(iterable $items): self
    {
        $total = self::zero();
        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->amount->isGreaterThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function getAmount(): BigDecimal
    {
        return $this->amount;
    }

    /**
     * Hodnota pro uložení do databáze (NUMERIC), např. „1234.50“.
     */
    public function toDecimalString(): string
    {
        return (string) $this->amount;
    }

    /**
     * Lidsky čitelný formát: „1 234,50 Kč“.
     */
    public function format(bool $withCurrency = true): string
    {
        $string = (string) $this->amount;
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '-');
        [$integer, $fraction] = explode('.', $string) + [1 => '00'];

        // Oddělení tisíců nezlomitelnou mezerou (regex – strrev by rozbil vícebajtový znak)
        $integer = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", $integer);
        $formatted = ($negative ? '−' : '') . $integer . ',' . $fraction;

        return $withCurrency ? $formatted . "\u{00A0}Kč" : $formatted;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
