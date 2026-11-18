<?php

declare(strict_types=1);

namespace App\DTO\Input;

final readonly class WorkLogData
{
    public function __construct(
        public string $description,
        public int $minutes,
        public \DateTimeImmutable $workDate,
    ) {
    }

    /**
     * Doba se zadává v hodinách (např. „1,5“) a převádí se na celé minuty.
     *
     * @param iterable<string, mixed> $values
     */
    public static function fromArray(iterable $values): self
    {
        $v = Normalizer::toArray($values);
        $hours = Normalizer::nullableDecimal($v['hours'] ?? null) ?? '0';

        return new self(
            description: Normalizer::string($v['description'] ?? ''),
            minutes: self::hoursToMinutes($hours),
            workDate: Normalizer::nullableDate($v['work_date'] ?? null) ?? new \DateTimeImmutable('today'),
        );
    }

    public static function hoursToMinutes(string $hours): int
    {
        return \Brick\Math\BigDecimal::of($hours)
            ->multipliedBy(60)
            ->toScale(0, \Brick\Math\RoundingMode::HALF_UP)
            ->toInt();
    }
}
