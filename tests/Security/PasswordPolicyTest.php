<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class PasswordPolicyTest extends TestCase
{
    public function testStrongPasswordSatisfiesSharedPolicy(): void
    {
        $violations = Validation::createValidator()->validate('Strong-Password-42', PasswordPolicy::constraints());
        self::assertCount(0, $violations);
    }

    public function testSharedPolicyRejectsWeakAndOversizedPasswords(): void
    {
        $validator = Validation::createValidator();

        self::assertGreaterThan(0, $validator->validate('onlylowercase12', PasswordPolicy::constraints())->count());
        self::assertGreaterThan(0, $validator->validate(str_repeat('A', 4097).'a1', PasswordPolicy::constraints())->count());
    }

    public function testOptionalAdministrativePasswordMayBeOmitted(): void
    {
        $violations = Validation::createValidator()->validate(null, PasswordPolicy::constraints(false));
        self::assertCount(0, $violations);
    }
}
