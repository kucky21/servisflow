<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Základ všech doménových výjimek. Zpráva je určena k zobrazení uživateli (česky).
 */
class DomainException extends \RuntimeException
{
}
