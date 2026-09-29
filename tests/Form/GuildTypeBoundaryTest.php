<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Guild;
use App\Form\GuildType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildTypeBoundaryTest extends KernelTestCase
{
    public function testExactStoredStringLimitsAreAcceptedAndRendered(): void
    {
        $limits = [
            'name' => 120,
            'serverName' => 120,
            'region' => 60,
            'faction' => 80,
            'websiteUrl' => 500,
        ];
        $values = [
            'name' => str_repeat('é', $limits['name']),
            'serverName' => str_repeat('é', $limits['serverName']),
            'region' => str_repeat('é', $limits['region']),
            'faction' => str_repeat('é', $limits['faction']),
            'websiteUrl' => $this->websiteUrlOfLength($limits['websiteUrl']),
        ];

        $form = $this->submitGuild($values);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertTrue($form->get('name')->getConfig()->getRequired());
        self::assertTrue($form->get('serverName')->getConfig()->getRequired());
        self::assertFalse($form->get('region')->getConfig()->getRequired());
        self::assertFalse($form->get('faction')->getConfig()->getRequired());
        self::assertFalse($form->get('websiteUrl')->getConfig()->getRequired());

        $view = $form->createView();
        foreach ($limits as $field => $limit) {
            self::assertSame($limit, $view->children[$field]->vars['attr']['maxlength']);
        }
    }

    public function testOneOverStoredStringLimitsAreRejectedByTheirFields(): void
    {
        $limits = [
            'name' => 120,
            'serverName' => 120,
            'region' => 60,
            'faction' => 80,
            'websiteUrl' => 500,
        ];
        $overLimitValues = [
            'name' => str_repeat('é', $limits['name'] + 1),
            'serverName' => str_repeat('é', $limits['serverName'] + 1),
            'region' => str_repeat('é', $limits['region'] + 1),
            'faction' => str_repeat('é', $limits['faction'] + 1),
            'websiteUrl' => $this->websiteUrlOfLength($limits['websiteUrl'] + 1),
        ];

        foreach ($overLimitValues as $field => $value) {
            $form = $this->submitGuild([$field => $value]);

            self::assertTrue($form->isSynchronized(), sprintf('%s should remain synchronized.', $field));
            self::assertNotCount(
                0,
                $form->get($field)->getErrors(),
                sprintf('%s should reject a value over its stored limit.', $field),
            );
        }
    }

    public function testBlankOptionalRegionFactionAndWebsiteRemainValidAndNormalizeToNull(): void
    {
        $guild = new Guild();
        $form = $this->submitGuild([
            'region' => '',
            'faction' => '',
            'websiteUrl' => '',
        ], $guild);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertNull($guild->getRegion());
        self::assertNull($guild->getFaction());
        self::assertNull($guild->getWebsiteUrl());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function submitGuild(array $overrides, ?Guild $guild = null): FormInterface
    {
        self::bootKernel();
        $formFactory = static::getContainer()->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $guild ??= new Guild();
        $guild->setDescription('Synthetic description');
        $guild->setSlug('guild-form-boundary-' . bin2hex(random_bytes(8)));
        $builder = $formFactory->createBuilder(GuildType::class, $guild, ['csrf_protection' => false]);
        foreach (['game', 'description', 'logoFile', 'logoUrl', 'recruitmentOpen', 'enabled'] as $field) {
            $builder->remove($field);
        }

        $form = $builder->getForm();
        $form->submit(array_replace([
            'name' => 'Synthetic guild',
            'serverName' => 'Synthetic server',
            'region' => '',
            'faction' => '',
            'websiteUrl' => '',
        ], $overrides));

        return $form;
    }

    private function websiteUrlOfLength(int $length): string
    {
        $prefix = 'https://guild.com/';

        return $prefix . str_repeat('x', $length - strlen($prefix));
    }
}
