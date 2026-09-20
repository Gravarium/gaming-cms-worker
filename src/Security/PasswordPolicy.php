<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class PasswordPolicy
{
    /** @return list<Constraint> */
    public static function constraints(bool $required = true): array
    {
        $constraints = [
            new Length(min: 12, max: 4096, minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.'),
            new Regex(pattern: '/\p{Ll}/u', message: 'Das Passwort braucht mindestens einen Kleinbuchstaben.'),
            new Regex(pattern: '/\p{Lu}/u', message: 'Das Passwort braucht mindestens einen Großbuchstaben.'),
            new Regex(pattern: '/\p{N}/u', message: 'Das Passwort braucht mindestens eine Zahl.'),
        ];

        if ($required) {
            array_unshift($constraints, new NotBlank());
        }

        return $constraints;
    }

    private function __construct()
    {
    }
}
