<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderNumberSequenceRepository;

/**
 * Generuje čísla zakázek ve formátu SRV-RRRR-NNNN (číselná řada se nuluje každý rok).
 *
 * Jedinečnost zaručuje:
 *  1. atomický UPSERT do order_number_sequences (řádek roku je zamčen do konce transakce),
 *  2. unikátní omezení service_orders.order_number jako poslední pojistka.
 */
final class OrderNumberGenerator
{
    public const string PREFIX = 'SRV';

    public function __construct(
        private readonly OrderNumberSequenceRepository $sequences,
    ) {
    }

    /**
     * Musí být voláno uvnitř transakce, která zakázku vkládá.
     */
    public function next(?\DateTimeInterface $date = null): string
    {
        $year = (int) ($date ?? new \DateTimeImmutable())->format('Y');

        return self::format($year, $this->sequences->nextValue($year));
    }

    public static function format(int $year, int $sequence): string
    {
        if ($sequence < 1) {
            throw new \InvalidArgumentException('Pořadové číslo musí být kladné.');
        }

        return sprintf('%s-%04d-%04d', self::PREFIX, $year, $sequence);
    }

    public static function isValid(string $number): bool
    {
        return preg_match('/^SRV-\d{4}-\d{4,}$/', $number) === 1;
    }
}
