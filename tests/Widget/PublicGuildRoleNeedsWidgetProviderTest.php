<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use App\Module\CmsModuleManager;
use App\Widget\GuildRoleNeeds\PublicGuildRoleNeedsWidgetQuery;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PublicGuildRoleNeedsWidgetProviderTest extends WebTestCase
{
    private const WIDGET_KEY = 'gaming.public-guild-role-needs';
    private const MODULE_KEYS = ['content', 'gaming'];
    private const CACHE_KEY = '_cms_widget_data_gaming.public-guild-role-needs';

    public function testWidgetShowsOnlyPublicPositiveNeedsAndEscapesOutput(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $game = $this->game($em, $suffix);
            $fixtures[] = $game;
            $guild = $this->guild($em, $game, $suffix, true);
            $fixtures[] = $guild;

            $public = $this->need($em, $fixtures, $guild, $game, 'frontline-'.$suffix, '<script>'.$suffix, 2);
            $this->need($em, $fixtures, $guild, $game, 'inactive-'.$suffix, 'scout-'.$suffix, 4)->setActive(false);
            $this->need($em, $fixtures, $guild, $game, 'empty-'.$suffix, 'healer-'.$suffix, 0);

            $closedGuild = $this->guild($em, $game, $suffix.'-closed', false);
            $fixtures[] = $closedGuild;
            $this->need($em, $fixtures, $closedGuild, $game, 'closed-'.$suffix, 'tank-'.$suffix, 3);

            $disabledGame = $this->game($em, $suffix.'-disabled-game')->setEnabled(false);
            $fixtures[] = $disabledGame;
            $disabledGameGuild = $this->guild($em, $disabledGame, $suffix.'-disabled-game', true);
            $fixtures[] = $disabledGameGuild;
            $this->need($em, $fixtures, $disabledGameGuild, $disabledGame, 'disabled-'.$suffix, 'mage-'.$suffix, 2);

            $disabledGuild = $this->guild($em, $game, $suffix.'-disabled-guild', true)->setEnabled(false);
            $fixtures[] = $disabledGuild;
            $this->need($em, $fixtures, $disabledGuild, $game, 'hidden-'.$suffix, 'rogue-'.$suffix, 2);

            $em->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertNotNull($definition);
            self::assertSame('gaming', $definition->module);
            self::assertTrue($registry->available(self::WIDGET_KEY));

            $data = $registry->data(self::WIDGET_KEY, ['count' => 6]);
            self::assertSame(['needs'], array_keys($data));
            $rows = $data['needs'] ?? null;
            self::assertIsArray($rows);

            $identities = array_map(
                static fn (mixed $row): string => is_array($row) && ($row['need'] ?? null) instanceof GuildRoleNeed
                    ? $row['need']->getRoleKey().'|'.$row['need']->getClassKey()
                    : '',
                $rows,
            );
            self::assertContains($public->getRoleKey().'|'.$public->getClassKey(), $identities);
            self::assertNotContains('inactive-'.$suffix.'|scout-'.$suffix, $identities);
            self::assertNotContains('empty-'.$suffix.'|healer-'.$suffix, $identities);
            self::assertNotContains('closed-'.$suffix.'|tank-'.$suffix, $identities);
            self::assertNotContains('disabled-'.$suffix.'|mage-'.$suffix, $identities);
            self::assertNotContains('hidden-'.$suffix.'|rogue-'.$suffix, $identities);

            $html = $client->getContainer()->get(Environment::class)->render($definition->template, $data);
            self::assertStringContainsString($guild->getName(), $html);
            self::assertStringContainsString($public->getRoleKey(), $html);
            self::assertStringContainsString('&lt;script&gt;'.$suffix, $html);
            self::assertStringNotContainsString('<script>'.$suffix, $html);
            self::assertStringContainsString('/gaming/guild/'.$guild->getSlug().'/apply', $html);
            self::assertStringNotContainsString('Applicant', $html);
            self::assertStringNotContainsString('Bewerbung', $html);
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->resetModuleStates($client);
        }
    }

    public function testQueryAndWidgetClampToTwelveWithStableOrder(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $game = $this->game($em, $suffix);
            $fixtures[] = $game;
            $guild = $this->guild($em, $game, $suffix, true);
            $fixtures[] = $guild;
            for ($i = 0; $i < 15; ++$i) {
                $number = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $this->need($em, $fixtures, $guild, $game, 'role-'.$number, 'class-'.$number, 1);
            }
            $em->flush();

            $query = $client->getContainer()->get(PublicGuildRoleNeedsWidgetQuery::class);
            $first = $query->upcoming(999);
            $second = $query->upcoming(999);
            self::assertCount(12, $first);
            self::assertSame($this->identities($first), $this->identities($second));

            $data = $client->getContainer()->get(WidgetRegistry::class)->data(self::WIDGET_KEY, ['count' => 999]);
            $rows = $data['needs'] ?? null;
            self::assertIsArray($rows);
            self::assertCount(12, $rows);
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->resetModuleStates($client);
        }
    }

    public function testWidgetRendersEmptyStateForEmptyQueryResult(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->enableGaming($client);

        $requestStack = $client->getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->attributes->set(self::CACHE_KEY, []);
        $requestStack->push($request);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertNotNull($definition);
            $data = $registry->data(self::WIDGET_KEY, ['count' => 6]);
            self::assertSame(['needs' => []], $data);

            $html = $client->getContainer()->get(Environment::class)->render($definition->template, $data);
            self::assertStringContainsString('Zurzeit gibt es keine öffentlichen offenen Rollenbedarfe.', $html);
        } finally {
            $requestStack->pop();
            $this->resetModuleStates($client);
        }
    }

    public function testDisabledGamingSuppressesWidgetAndItsData(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->moduleState($client, 'content', true);
        $this->moduleState($client, 'gaming', false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(self::WIDGET_KEY));
            self::assertSame([], $registry->data(self::WIDGET_KEY, ['count' => 6]));
            self::assertNotContains(
                self::WIDGET_KEY,
                array_map(
                    static fn (WidgetDefinition $definition): string => $definition->key,
                    $registry->availableDefinitions(),
                ),
            );
        } finally {
            $this->resetModuleStates($client);
        }
    }

    /** @return list<string> */
    private function identities(array $rows): array
    {
        return array_map(
            static fn (array $row): string => $row['guild']->getName().'|'.$row['need']->getRoleKey().'|'.$row['need']->getClassKey(),
            $rows,
        );
    }

    private function enableGaming(KernelBrowser $client): void
    {
        $this->moduleState($client, 'content', true);
        $this->moduleState($client, 'gaming', true);
        self::assertTrue($client->getContainer()->get(CmsModuleManager::class)->isEnabled('gaming'));
    }

    private function moduleState(KernelBrowser $client, string $key, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, $key);
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled($enabled);
        $em->flush();
    }

    private function resetModuleStates(KernelBrowser $client): void
    {
        $em = $this->em($client);
        foreach (self::MODULE_KEYS as $key) {
            $state = $em->find(CmsModuleState::class, $key);
            if ($state instanceof CmsModuleState) {
                $em->remove($state);
            }
        }
        $em->flush();
        $em->clear();
    }

    /**
     * @param list<object> $fixtures
     */
    private function need(
        EntityManagerInterface $em,
        array &$fixtures,
        Guild $guild,
        Game $game,
        string $role,
        string $class,
        int $count,
    ): GuildRoleNeed {
        $need = (new GuildRoleNeed($guild, $game, $role, $class))->setDesiredCount($count);
        $em->persist($need);
        $fixtures[] = $need;

        return $need;
    }

    private function game(EntityManagerInterface $em, string $suffix): Game
    {
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $em->persist($game);

        return $game;
    }

    private function guild(EntityManagerInterface $em, Game $game, string $suffix, bool $recruitmentOpen): Guild
    {
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild '.$suffix)
            ->setSlug('guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Public recruitment need fixture')
            ->setRecruitmentOpen($recruitmentOpen);
        $em->persist($guild);

        return $guild;
    }

    /**
     * @param list<object> $fixtures
     */
    private function cleanupFixtures(EntityManagerInterface $em, array $fixtures): void
    {
        foreach (array_reverse($fixtures) as $fixture) {
            $em->remove($fixture);
        }
        if ($fixtures !== []) {
            $em->flush();
        }
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
