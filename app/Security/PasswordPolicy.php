<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Minimální požadavky na sílu hesla.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 8;

    /**
     * @return list<string> Seznam nesplněných požadavků (prázdný = heslo vyhovuje)
     */
    public static function validate(#[\SensitiveParameter] string $password): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_LENGTH);
        }
        if (mb_strlen($password) > 128) {
            $errors[] = 'Heslo může mít nejvýše 128 znaků.';
        }
        if (preg_match('/\p{Lu}/u', $password) !== 1 || preg_match('/\p{Ll}/u', $password) !== 1) {
            $errors[] = 'Heslo musí obsahovat malé i velké písmeno.';
        }
        if (preg_match('/\d/', $password) !== 1) {
            $errors[] = 'Heslo musí obsahovat číslici.';
        }

        return $errors;
    }
}
