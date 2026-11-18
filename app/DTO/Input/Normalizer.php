<?php

declare(strict_types=1);

namespace App\DTO\Input;

use Nette\Utils\Strings;

/**
 * Pomocné převody hodnot z formulářů (ArrayHash / pole) na typované hodnoty DTO.
 */
final class Normalizer
{
    /**
     * @param iterable<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function toArray(iterable $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? Strings::trim((string) $value) : '';
    }

    public static function nullableString(mixed $value): ?string
    {
        $string = self::string($value);

        return $string === '' ? null : $string;
    }

    public static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Desetinné číslo z formuláře jako řetězec (podpora desetinné čárky), nebo null.
     */
    public static function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $string = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], self::string($value));

        return is_numeric($string) ? $string : null;
    }

    public static function nullableDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }
        $string = self::nullableString($value);
        if ($string === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $string);

        return $date === false ? null : $date;
    }
}
