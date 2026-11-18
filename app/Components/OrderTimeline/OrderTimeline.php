<?php

declare(strict_types=1);

namespace App\Components\OrderTimeline;

use App\Model\Enum\OrderPriority;
use App\Model\Enum\OrderStatus;
use Nette\Application\UI\Control;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;

/**
 * Časová osa zakázky: změny stavů + auditní záznamy (úpravy, přiřazení, materiál, dokumenty).
 */
final class OrderTimeline extends Control
{
    private const array ACTION_LABELS = [
        'order.created' => ['Zakázka vytvořena', 'file-plus', 'slate'],
        'order.updated' => ['Úprava údajů zakázky', 'pencil', 'slate'],
        'order.technician_assigned' => ['Změna přiřazeného technika', 'user-check', 'indigo'],
        'order.diagnosis_updated' => ['Aktualizace diagnostiky', 'stethoscope', 'indigo'],
        'order.work_logged' => ['Vykázána práce', 'timer', 'green'],
        'order.work_deleted' => ['Odstraněn výkaz práce', 'timer-off', 'red'],
        'order.material_added' => ['Vydán materiál', 'package-minus', 'blue'],
        'order.material_removed' => ['Materiál vrácen na sklad', 'package-plus', 'teal'],
        'document.generated' => ['Vygenerován dokument', 'file-text', 'slate'],
    ];

    private const array FIELD_LABELS = [
        'priority' => 'Priorita',
        'problem_description' => 'Popis závady',
        'accessories' => 'Příslušenství',
        'diagnosis' => 'Diagnostika',
        'estimated_price' => 'Odhad ceny',
        'due_date' => 'Termín',
        'internal_note' => 'Interní poznámka',
        'hourly_rate' => 'Hodinová sazba',
        'discount_percent' => 'Sleva %',
        'assigned_technician_id' => 'Technik',
        'sku' => 'Položka',
        'quantity' => 'Množství',
        'minutes' => 'Minuty',
        'description' => 'Popis',
        'type' => 'Dokument',
    ];

    /**
     * @param list<ActiveRow> $statusHistory
     * @param list<ActiveRow> $auditEntries
     * @param array<int, string> $userNames
     */
    public function __construct(
        private readonly array $statusHistory,
        private readonly array $auditEntries,
        private readonly array $userNames,
    ) {
    }

    public function render(): void
    {
        $template = $this->getTemplate();
        assert($template instanceof \Nette\Bridges\ApplicationLatte\Template);
        $template->entries = $this->buildEntries();
        $template->render(__DIR__ . '/OrderTimeline.latte');
    }

    /**
     * @return list<array{time: \DateTimeInterface, title: string, icon: string, tone: string, user: ?string, note: ?string, changes: list<array{label: string, old: string, new: string}>, status: ?string}>
     */
    public function buildEntries(): array
    {
        $entries = [];

        foreach ($this->statusHistory as $row) {
            $to = OrderStatus::from((string) $row->to_status);
            $entries[] = [
                'time' => $row->created_at,
                'title' => $row->from_status === null
                    ? 'Zakázka přijata'
                    : sprintf('%s → %s', OrderStatus::from((string) $row->from_status)->label(), $to->label()),
                'icon' => $to->icon(),
                'tone' => $to->color(),
                'user' => $this->userName($row->changed_by_id),
                'note' => $row->note === null ? null : (string) $row->note,
                'changes' => [],
                'status' => $to->value,
            ];
        }

        foreach ($this->auditEntries as $row) {
            $action = (string) $row->action;
            if (!isset(self::ACTION_LABELS[$action]) || $action === 'order.created') {
                continue;
            }
            [$title, $icon, $tone] = self::ACTION_LABELS[$action];
            $entries[] = [
                'time' => $row->created_at,
                'title' => $title,
                'icon' => $icon,
                'tone' => $tone,
                'user' => $this->userName($row->user_id),
                'note' => null,
                'changes' => $this->formatChanges($row->changes === null ? [] : (array) Json::decode((string) $row->changes, forceArrays: true)),
                'status' => null,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $entries;
    }

    /**
     * @param array<string, mixed> $changes
     * @return list<array{label: string, old: string, new: string}>
     */
    private function formatChanges(array $changes): array
    {
        $result = [];
        foreach ($changes as $field => $change) {
            $label = self::FIELD_LABELS[$field] ?? null;
            if ($label === null) {
                continue;
            }
            if (is_array($change) && array_key_exists('new', $change)) {
                $result[] = [
                    'label' => $label,
                    'old' => $this->formatValue($field, $change['old'] ?? null),
                    'new' => $this->formatValue($field, $change['new']),
                ];
            } else {
                $result[] = ['label' => $label, 'old' => '', 'new' => $this->formatValue($field, $change)];
            }
        }

        return $result;
    }

    private function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            'priority' => OrderPriority::tryFrom((string) $value)?->label() ?? (string) $value,
            'assigned_technician_id' => $this->userName($value) ?? '—',
            'minutes' => \App\Core\Latte\AppLatteExtension::hours((int) $value),
            'type' => \App\Model\Enum\DocumentType::tryFrom((string) $value)?->label() ?? (string) $value,
            default => is_scalar($value) ? mb_strimwidth((string) $value, 0, 120, '…') : Json::encode($value),
        };
    }

    private function userName(mixed $userId): ?string
    {
        return $userId === null ? null : ($this->userNames[(int) $userId] ?? 'Smazaný uživatel');
    }
}
