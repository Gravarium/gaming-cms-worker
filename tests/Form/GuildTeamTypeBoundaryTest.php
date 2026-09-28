<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildTeam;
use App\Form\GuildTeamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class GuildTeamTypeBoundaryTest extends KernelTestCase
{
    public function testExactEntityNameLimitHasNoFieldErrorAndIsRendered(): void
    {
        $form = $this->submitTeamName(str_repeat('t', 120));

        self::assertTrue($form->isSynchronized());
        self::assertCount(0, $form->get('name')->getErrors());
        self::assertSame(120, $form->createView()->children['name']->vars['attr']['maxlength']);
    }

    public function testNameAboveEntityLimitHasAFieldError(): void
    {
        $form = $this->submitTeamName(str_repeat('t', 121));

        self::assertTrue($form->isSynchronized());
        self::assertCount(1, $form->get('name')->getErrors());
    }

    private function submitTeamName(string $name): FormInterface
    {
        self::bootKernel();
        $container = static::getContainer();
        $formFactory = $container->get('form.factory');
        if (!$formFactory instanceof FormFactoryInterface) {
            throw new \LogicException('The Symfony form factory is unavailable.');
        }

        $entityManager = $container->get('doctrine.orm.entity_manager');
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('The Doctrine entity manager is unavailable.');
        }

        $suffix = bin2hex(random_bytes(8));
        $game = (new Game())
            ->setName('Guild team boundary game '.$suffix)
            ->setSlug('guild-team-boundary-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild team boundary guild '.$suffix)
            ->setSlug('guild-team-boundary-guild-'.$suffix)
            ->setServerName('Boundary server')
            ->setDescription('Boundary test guild');

        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->flush();

        $team = (new GuildTeam())->setGuild($guild);
        $form = $formFactory->create(
            GuildTeamType::class,
            $team,
            ['csrf_protection' => false, 'guild' => $guild],
        );
        $form->submit(['name' => $name, 'members' => []]);

        return $form;
    }
}
