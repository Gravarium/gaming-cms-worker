<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Form\GuildEventType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildEventTypeBoundaryTest extends KernelTestCase
{
    public function testExactStoredTextLimitsAreAcceptedAndRendered(): void
    {
        $limits = [
            'title' => 180,
            'location' => 140,
        ];
        $values = [];
        foreach ($limits as $field => $limit) {
            $values[$field] = str_repeat('é', $limit);
        }

        $form = $this->submitGuildEvent($values);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        $view = $form->createView();
        foreach ($limits as $field => $limit) {
            self::assertSame($limit, $view->children[$field]->vars['attr']['maxlength']);
        }
    }

    public function testOneOverStoredTextLimitsAreRejectedByTheirFields(): void
    {
        $limits = [
            'title' => 180,
            'location' => 140,
        ];

        foreach ($limits as $field => $limit) {
            $form = $this->submitGuildEvent([$field => str_repeat('é', $limit + 1)]);

            self::assertTrue($form->isSynchronized(), sprintf('%s should remain synchronized.', $field));
            self::assertNotCount(
                0,
                $form->get($field)->getErrors(),
                sprintf('%s should reject a value over its stored limit.', $field),
            );
        }
    }

    public function testBlankOptionalLocationRemainsValidAndNormalizesToNull(): void
    {
        $event = new GuildEvent();
        $form = $this->submitGuildEvent(['location' => ''], $event);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertNull($event->getLocation());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitGuildEvent(array $overrides, ?GuildEvent $event = null): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $builder = $formFactory->createBuilder(GuildEventType::class, $event ?? new GuildEvent(), [
            'guild' => new Guild(),
            'csrf_protection' => false,
        ]);
        foreach (['team', 'type', 'description', 'startsAt', 'endsAt', 'maxParticipants', 'status'] as $field) {
            $builder->remove($field);
        }

        $form = $builder->getForm();
        $form->submit(array_replace([
            'title' => 'Synthetic event',
            'location' => '',
        ], $overrides));

        return $form;
    }
}
