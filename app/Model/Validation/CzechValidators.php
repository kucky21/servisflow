<?php

declare(strict_types=1);

namespace App\Model\Validation;

/**
 * Validátory českých identifikátorů.
 */
final class CzechValidators
{
    /**
     * IČO: 8 číslic, poslední je kontrolní (vážený součet mod 11).
     */
    public static function isValidIco(string $ico): bool
    {
        $ico = preg_replace('/\s+/', '', $ico) ?? '';
        if (preg_match('/^\d{8}$/', $ico) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $ico[$i] * (8 - $i);
        }

        $remainder = $sum % 11;
        $check = match ($remainder) {
            0 => 1,
            1 => 0,
            default => 11 - $remainder,
        };

        return (int) $ico[7] === $check;
    }

    /**
     * DIČ: kód země (2 písmena) + 8–12 číslic, např. CZ12345678.
     */
    public static function isValidDic(string $dic): bool
    {
        return preg_match('/^[A-Z]{2}\d{8,12}$/', strtoupper(trim($dic))) === 1;
    }

    /**
     * Telefon: volitelně +, číslice, mezery; 9–15 číslic.
     */
    public static function isValidPhone(string $phone): bool
    {
        if (preg_match('/^\+?[\d\s\-\/()]+$/', trim($phone)) !== 1) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) >= 9 && strlen($digits) <= 15;
    }

    public static function normalizeIco(string $ico): string
    {
        return preg_replace('/\s+/', '', $ico) ?? $ico;
    }
}
