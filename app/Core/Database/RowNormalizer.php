<?php

declare(strict_types=1);

namespace App\Core\Database;

use Nette\Database\DateTime;
use Nette\Database\Helpers;
use Nette\Database\IStructure;
use Nette\Database\ResultSet;

/**
 * Normalizace řádků z databáze, která zachovává NUMERIC/DECIMAL hodnoty jako řetězce.
 *
 * Výchozí normalizér Nette Database převádí decimální čísla na float, což je pro
 * finanční výpočty nepřijatelné (ztráta přesnosti). Ostatní typy (datum, bool, int)
 * normalizuje standardně, data vrací jako immutable Nette\Database\DateTime.
 */
final class RowNormalizer
{
    /**
     * @param array<mixed> $row
     * @return array<mixed>
     */
    public function __invoke(array $row, ResultSet $resultSet): array
    {
        $decimals = [];
        foreach ($resultSet->getColumnTypes() as $key => $type) {
            if ($type === IStructure::FIELD_DECIMAL && isset($row[$key])) {
                $decimals[$key] = (string) $row[$key];
            }
        }

        $row = Helpers::normalizeRow($row, $resultSet, DateTime::class);

        foreach ($decimals as $key => $value) {
            $row[$key] = $value;
        }

        return $row;
    }
}
