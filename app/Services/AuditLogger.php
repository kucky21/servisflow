<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Security\Actor;
use Nette\Http\IRequest;
use Nette\Utils\Json;

/**
 * Auditní záznam významných změn (kdo, co, kdy, odkud a jaké hodnoty se změnily).
 * Volá se uvnitř transakce dané operace – při rollbacku zmizí i auditní záznam.
 */
final class AuditLogger
{
    /** Pole, jejichž hodnoty se do auditu nikdy neukládají. */
    private const array SENSITIVE_FIELDS = ['password', 'password_hash', 'passwordHash'];

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly ?IRequest $httpRequest = null,
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function log(string $action, string $entityType, ?int $entityId, array $changes = [], ?Actor $actor = null): void
    {
        $this->repository->insert([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes === [] ? null : Json::encode(self::sanitize($changes)),
            'ip_address' => $this->httpRequest?->getRemoteAddress(),
        ]);
    }

    /**
     * Rozdíl mezi původními a novými hodnotami: ['pole' => ['old' => …, 'new' => …]].
     *
     * @param iterable<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(iterable $before, array $after): array
    {
        $old = [];
        foreach ($before as $key => $value) {
            $old[$key] = $value;
        }

        $diff = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $old[$key] ?? null;
            if (self::normalize($oldValue) !== self::normalize($newValue)) {
                $diff[$key] = ['old' => self::normalize($oldValue), 'new' => self::normalize($newValue)];
            }
        }

        return $diff;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable => (string) $value,
            is_int($value) => (string) $value,
            $value === '' => null,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function sanitize(array $changes): array
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = '***';
            }
        }

        return $changes;
    }
}
