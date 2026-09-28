<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Form\GuildMemberType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildMemberTypeBoundaryTest extends KernelTestCase
{
    public function testExactStoredNameLimitsAreAcceptedAndRendered(): void
    {
        $limits = [
            'characterName' => 120,
            'playerName' => 120,
            'rankName' => 100,
            'characterClass' => 100,
        ];
        $values = [];
        foreach ($limits as $field => $limit) {
            $values[$field] = str_repeat('é', $limit);
        }

        $form = $this->submitGuildMember($values);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        $view = $form->createView();
        foreach ($limits as $field => $limit) {
            self::assertSame($limit, $view->children[$field]->vars['attr']['maxlength']);
        }
    }

    public function testOneOverStoredNameLimitsAreRejectedByTheirFields(): void
    {
        $limits = [
            'characterName' => 120,
            'playerName' => 120,
            'rankName' => 100,
            'characterClass' => 100,
        ];

        foreach ($limits as $field => $limit) {
            $form = $this->submitGuildMember([$field => str_repeat('é', $limit + 1)]);

            self::assertTrue($form->isSynchronized(), sprintf('%s should remain synchronized.', $field));
            self::assertNotCount(
                0,
                $form->get($field)->getErrors(),
                sprintf('%s should reject a value over its stored limit.', $field),
            );
        }
    }

    public function testBlankOptionalPlayerAndClassRemainValidAndNormalizeToNull(): void
    {
        $member = new GuildMember();
        $form = $this->submitGuildMember(['playerName' => '', 'characterClass' => ''], $member);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertNull($member->getPlayerName());
        self::assertNull($member->getCharacterClass());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitGuildMember(array $overrides, ?GuildMember $member = null): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $builder = $formFactory->createBuilder(GuildMemberType::class, $member ?? new GuildMember(), [
            'guild' => new Guild(),
            'csrf_protection' => false,
        ]);
        $builder->remove('user');
        $builder->remove('rank');
        $form = $builder->getForm();
        $form->submit(array_replace([
            'characterName' => 'Synthetic character',
            'playerName' => '',
            'rankName' => '',
            'characterClass' => '',
            'characterLevel' => '',
            'position' => 0,
            'leader' => false,
            'active' => true,
        ], $overrides));

        return $form;
    }
}
