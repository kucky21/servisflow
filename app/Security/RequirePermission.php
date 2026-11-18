<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Deklarativní kontrola oprávnění na presenteru nebo jeho metodě (action*, render*, handle*).
 *
 *   #[RequirePermission('customer', 'edit')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class RequirePermission
{
    public function __construct(
        public string $resource,
        public string $privilege = 'view',
    ) {
    }
}
