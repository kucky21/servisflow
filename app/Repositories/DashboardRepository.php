<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Model\Enum\OrderStatus;
use Nette\Database\Explorer;
use Nette\Database\Row;

/**
 * Agregační dotazy pro dashboard. Vše se počítá přímo v PostgreSQL.
 * Volitelný filtr technika omezuje statistiky na jeho zakázky.
 */
final class DashboardRepository
{
    public function __construct(
        private readonly Explorer $db,
    ) {
    }

    /**
     * @return array{active: int, completedThisMonth: int, overdue: int, awaitingParts: int, completedValueThisMonth: string, unassigned: int}
     */
    public function getOrderCounters(?int $technicianId): array
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId);
        $active = OrderStatus::activeValues();

        $row = $this->db->query(
            "SELECT
                COUNT(*) FILTER (WHERE status IN (?)) AS active,
                COUNT(*) FILTER (WHERE completed_at >= date_trunc('month', now()) AND status IN ('completed', 'handed_over')) AS completed_month,
                COUNT(*) FILTER (WHERE status IN (?) AND due_date < CURRENT_DATE) AS overdue,
                COUNT(*) FILTER (WHERE status = 'awaiting_parts') AS awaiting_parts,
                COUNT(*) FILTER (WHERE status IN (?) AND assigned_technician_id IS NULL) AS unassigned,
                COALESCE(SUM(final_price) FILTER (
                    WHERE completed_at >= date_trunc('month', now()) AND status IN ('completed', 'handed_over')
                ), 0)::numeric(14,2) AS completed_value_month
             FROM service_orders
             WHERE TRUE {$techSql}",
            $active,
            $active,
            $active,
            ...$techParams,
        )->fetch();

        if (!$row instanceof Row) {
            throw new \LogicException('Aggregate query returned no row.');
        }

        return [
            'active' => (int) $row->active,
            'completedThisMonth' => (int) $row->completed_month,
            'overdue' => (int) $row->overdue,
            'awaitingParts' => (int) $row->awaiting_parts,
            'unassigned' => (int) $row->unassigned,
            'completedValueThisMonth' => (string) $row->completed_value_month,
        ];
    }

    /**
     * Počet aktivních zakázek po termínu a v termínu dnes.
     */
    public function countDueToday(?int $technicianId): int
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId);

        return (int) $this->db->query(
            "SELECT COUNT(*) FROM service_orders WHERE status IN (?) AND due_date = CURRENT_DATE {$techSql}",
            OrderStatus::activeValues(),
            ...$techParams,
        )->fetchField();
    }

    /**
     * @return array<string, int> status => počet
     */
    public function getStatusCounts(?int $technicianId): array
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId);

        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS cnt FROM service_orders WHERE TRUE {$techSql} GROUP BY status",
            ...$techParams,
        )->fetchPairs('status', 'cnt');

        $result = [];
        foreach (OrderStatus::cases() as $status) {
            $result[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Dokončené zakázky a jejich hodnota po měsících (včetně měsíců bez zakázek).
     *
     * @return list<array{month: string, count: int, value: string}>
     */
    public function getMonthlyCompleted(int $months, ?int $technicianId): array
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId, 'o.');

        $rows = $this->db->query(
            "SELECT to_char(m.month, 'YYYY-MM') AS month,
                    COUNT(o.id) AS cnt,
                    COALESCE(SUM(o.final_price), 0)::numeric(14,2) AS value
             FROM generate_series(
                    date_trunc('month', now()) - make_interval(months => ?),
                    date_trunc('month', now()),
                    interval '1 month'
                  ) AS m(month)
             LEFT JOIN service_orders o
                    ON date_trunc('month', o.completed_at) = m.month
                   AND o.status IN ('completed', 'handed_over') {$techSql}
             GROUP BY m.month
             ORDER BY m.month",
            $months - 1,
            ...$techParams,
        )->fetchAll();

        return array_values(array_map(static fn (Row $r): array => [
            'month' => (string) $r->month,
            'count' => (int) $r->cnt,
            'value' => (string) $r->value,
        ], $rows));
    }

    /**
     * Přijaté zakázky po měsících (pro srovnání s dokončenými).
     *
     * @return array<string, int>
     */
    public function getMonthlyReceived(int $months, ?int $technicianId): array
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId, 'o.');

        $rows = $this->db->query(
            "SELECT to_char(m.month, 'YYYY-MM') AS month, COUNT(o.id) AS cnt
             FROM generate_series(
                    date_trunc('month', now()) - make_interval(months => ?),
                    date_trunc('month', now()),
                    interval '1 month'
                  ) AS m(month)
             LEFT JOIN service_orders o ON date_trunc('month', o.received_at) = m.month {$techSql}
             GROUP BY m.month
             ORDER BY m.month",
            $months - 1,
            ...$techParams,
        )->fetchPairs('month', 'cnt');

        return array_map(intval(...), $rows);
    }

    /**
     * Poslední aktivity: změny stavů, komentáře a výkazy práce.
     *
     * @return list<Row>
     */
    public function getRecentActivity(int $limit, ?int $technicianId): array
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId, 'o.');

        $rows = $this->db->query(
            "SELECT * FROM (
                SELECT 'status' AS kind, h.created_at, o.id AS order_id, o.order_number,
                       h.from_status AS detail_from, h.to_status AS detail, u.first_name || ' ' || u.last_name AS user_name
                FROM service_order_status_history h
                JOIN service_orders o ON o.id = h.service_order_id
                LEFT JOIN users u ON u.id = h.changed_by_id
                WHERE TRUE {$techSql}
                UNION ALL
                SELECT 'comment', c.created_at, o.id, o.order_number, NULL, left(c.body, 140), u.first_name || ' ' || u.last_name
                FROM service_order_comments c
                JOIN service_orders o ON o.id = c.service_order_id
                LEFT JOIN users u ON u.id = c.user_id
                WHERE TRUE {$techSql}
                UNION ALL
                SELECT 'work', w.created_at, o.id, o.order_number, w.minutes::text, w.description, u.first_name || ' ' || u.last_name
                FROM work_logs w
                JOIN service_orders o ON o.id = w.service_order_id
                LEFT JOIN users u ON u.id = w.user_id
                WHERE TRUE {$techSql}
             ) activity
             ORDER BY created_at DESC
             LIMIT ?",
            ...[...$techParams, ...$techParams, ...$techParams, $limit],
        )->fetchAll();

        return array_values($rows);
    }

    /**
     * Průměrná doba od přijetí do dokončení (dny) za posledních 90 dní.
     */
    public function getAverageTurnaroundDays(?int $technicianId): ?float
    {
        [$techSql, $techParams] = self::technicianCondition($technicianId);

        $value = $this->db->query(
            "SELECT ROUND(AVG(EXTRACT(EPOCH FROM (completed_at - received_at)) / 86400)::numeric, 1)
             FROM service_orders
             WHERE completed_at >= now() - interval '90 days' {$techSql}",
            ...$techParams,
        )->fetchField();

        return $value === null ? null : (float) $value;
    }

    /**
     * @param literal-string $alias
     * @return array{0: literal-string, 1: list<int>}
     */
    private static function technicianCondition(?int $technicianId, string $alias = ''): array
    {
        if ($technicianId === null) {
            return ['', []];
        }

        return [" AND {$alias}assigned_technician_id = ?", [$technicianId]];
    }
}
