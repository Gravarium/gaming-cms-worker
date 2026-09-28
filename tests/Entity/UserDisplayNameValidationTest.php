<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserDisplayNameValidationTest extends TestCase
{
    public function testDisplayNameMustBeNonBlankAfterUserNormalization(): void
    {
        $user = (new User())->setDisplayName(" \t\n");
        $violations = $this->validator()->validateProperty($user, 'displayName');

        self::assertCount(1, $violations);
        self::assertSame('displayName', $violations[0]->getPropertyPath());
        self::assertInstanceOf(NotBlank::class, $violations[0]->getConstraint());
    }

    public function testDisplayNameAllowsEightyCharactersAndRejectsEightyOne(): void
    {
        $validator = $this->validator();
        $user = (new User())->setDisplayName(str_repeat('é', 80));

        self::assertCount(0, $validator->validateProperty($user, 'displayName'));

        $invalidUser = (new User())->setDisplayName('Player');
        // Check the Symfony length constraint independently from the entity setter guard.
        (new \ReflectionProperty(User::class, 'displayName'))->setValue($invalidUser, str_repeat('é', 81));
        $violations = $validator->validateProperty($invalidUser, 'displayName');

        self::assertCount(1, $violations);
        self::assertSame('displayName', $violations[0]->getPropertyPath());
        self::assertInstanceOf(Length::class, $violations[0]->getConstraint());
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
