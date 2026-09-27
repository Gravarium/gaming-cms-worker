<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGuildRoleNeedDisplayTest extends WebTestCase
{
    public function testApplicationPageShowsOnlyActivePositiveRoleNeeds(): void
    {
        $client = static::createClient();
        $guild = $this->guild($client, 'open');
        $this->need($client, $guild, 'Heiler', 'Priester', 2, true);
        $this->need($client, $guild, 'Tank', 'Krieger', 0, true);
        $this->need($client, $guild, 'Schaden', 'Jäger', 3, false);
        $this->em($client)->flush();

        $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/apply');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Heiler');
        self::assertSelectorTextContains('body', 'Priester');
        self::assertSelectorTextContains('body', '2 gesucht');
        self::assertSelectorTextNotContains('body', 'Krieger');
        self::assertSelectorTextNotContains('body', 'Jäger');
    }

    public function testClosedRecruitmentDoesNotRenderApplicationOrNeeds(): void
    {
        $client = static::createClient();
        $guild = $this->guild($client, 'closed');
        $this->need($client, $guild, 'Heiler', 'Priester', 2, true);
        $this->em($client)->flush();
        $guild->setRecruitmentOpen(false);
        $this->em($client)->flush();

        $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/apply');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDisabledGuildAndGameDoNotExposeApplicationNeeds(): void
    {
        foreach (['guild', 'game'] as $disabled) {
            $client = static::createClient();
            $guild = $this->guild($client, 'disabled-'.$disabled);
            $this->need($client, $guild, 'Heiler', 'Priester', 2, true);
            if ($disabled === 'guild') {
                $guild->setEnabled(false);
            } else {
                $game = $guild->getGame();
                self::assertInstanceOf(Game::class, $game);
                $game->setEnabled(false);
            }
            $this->em($client)->flush();

            $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/apply');

            self::assertResponseStatusCodeSame(404);
            self::assertSelectorTextNotContains('body', 'Priester');
        }
    }

    public function testDisabledGamingModuleHidesPublicRecruitmentPage(): void
    {
        $client = static::createClient();
        $guild = $this->guild($client, 'module-off');
        $this->need($client, $guild, 'Heiler', 'Priester', 2, true);
        $this->em($client)->flush();
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        $wasExisting = $state instanceof CmsModuleState;
        $previouslyEnabled = $wasExisting ? $state->isEnabled() : true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/apply');
            self::assertResponseStatusCodeSame(404);
            self::assertSelectorTextNotContains('body', 'Priester');
        } finally {
            if ($wasExisting) {
                $state->setEnabled($previouslyEnabled);
                $em->persist($state);
            } else {
                $em->remove($state);
            }
            $em->flush();
        }
    }

    private function guild(KernelBrowser $client, string $label): Guild
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild '.$suffix)
            ->setSlug('guild-'.$label.'-'.$suffix)
            ->setServerName('Server')
            ->setDescription('Guild recruitment test')
            ->setRecruitmentOpen(true);
        $this->em($client)->persist($game);
        $this->em($client)->persist($guild);
        $this->em($client)->flush();

        return $guild;
    }

    private function need(KernelBrowser $client, Guild $guild, string $role, string $class, int $count, bool $active): void
    {
        $game = $guild->getGame();
        self::assertInstanceOf(Game::class, $game);
        $need = (new GuildRoleNeed($guild, $game, $role, $class))
            ->setDesiredCount($count)
            ->setActive($active);
        $this->em($client)->persist($need);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
