<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\User;
use App\EventSubscriber\GuildEventSignupWidgetCacheSubscriber;
use App\Widget\MyGuildEventSignupsWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;

final class MyGuildEventSignupsWidgetProviderTest extends WebTestCase
{
    private const MODULE_KEYS = ['content', 'gaming'];

    public function testWidgetShowsOnlyAuthenticatedUsersOwnUpcomingSignupsAndMarksResponsePrivate(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $otherUser = $this->user($em, $suffix.'-other');
            $game = $this->game($em, $suffix);
            $guild = $this->guild($em, $game, $suffix);
            $ownMember = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Widget owner '.$suffix);
            $otherMember = (new GuildMember())->setGuild($guild)->setUser($otherUser)->setCharacterName('Widget other '.$suffix);
            $ownEvent = $this->event($guild, 'Widget own event '.$suffix);
            $otherEvent = $this->event($guild, 'Widget foreign event '.$suffix);
            $ownSignup = (new GuildEventSignup())->setEvent($ownEvent)->setMember($ownMember)->setUser($user);
            $otherSignup = (new GuildEventSignup())->setEvent($otherEvent)->setMember($otherMember)->setUser($otherUser);
            $fixtures = [$user, $otherUser, $game, $guild, $ownMember, $otherMember, $ownEvent, $otherEvent, $ownSignup, $otherSignup];
            foreach ($fixtures as $fixture) {
                $em->persist($fixture);
            }
            $em->flush();
            $client->loginUser($user);
            $client->request('GET', '/guild-area/event-signups');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(MyGuildEventSignupsWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertTrue($registry->available(MyGuildEventSignupsWidgetProvider::KEY));

            $request = $client->getRequest();
            self::assertInstanceOf(Request::class, $request);
            $requestStack = $container->get(RequestStack::class);
            $requestStack->push($request);
            try {
                $data = $registry->data(MyGuildEventSignupsWidgetProvider::KEY, []);
                self::assertCount(1, $data['signups'] ?? []);
                self::assertTrue($request->attributes->get(MyGuildEventSignupsWidgetProvider::PRIVATE_RESPONSE_KEY));

                $html = $container->get(Environment::class)->render($definition->template, $data);
                self::assertStringContainsString('Widget own event '.$suffix, $html);
                self::assertStringContainsString('Widget owner '.$suffix, $html);
                self::assertStringNotContainsString('Widget foreign event '.$suffix, $html);
                self::assertStringNotContainsString('Widget other '.$suffix, $html);

                $response = new Response();
                $event = new ResponseEvent(
                    $container->get(KernelInterface::class),
                    $request,
                    HttpKernelInterface::MAIN_REQUEST,
                    $response,
                );
                $container->get(GuildEventSignupWidgetCacheSubscriber::class)->onKernelResponse($event);
                $this->assertPrivateHeaders($response);
            } finally {
                $requestStack->pop();
            }
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testGuestWidgetHasNoPersonalizedOutputAndAuthenticatedEmptyWidgetIsPrivate(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);

        $container = $client->getContainer();
        $registry = $container->get(WidgetRegistry::class);
        $definition = $registry->get(MyGuildEventSignupsWidgetProvider::KEY);
        self::assertInstanceOf(WidgetDefinition::class, $definition);

        $guestRequest = new Request();
        $requestStack = $container->get(RequestStack::class);
        $requestStack->push($guestRequest);
        try {
            $guestData = $registry->data(MyGuildEventSignupsWidgetProvider::KEY, []);
            self::assertSame(['signups' => []], $guestData);
            self::assertFalse($guestRequest->attributes->has(MyGuildEventSignupsWidgetProvider::PRIVATE_RESPONSE_KEY));
            $guestHtml = $container->get(Environment::class)->render($definition->template, $guestData);
            self::assertStringContainsString('Keine kommenden Gildentermine angemeldet.', $guestHtml);
        } finally {
            $requestStack->pop();
        }

        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $fixtures[] = $user;
            $em->flush();
            $client->loginUser($user);
            $client->request('GET', '/guild-area/event-signups');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(MyGuildEventSignupsWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            $requestStack = $container->get(RequestStack::class);
            $authenticatedRequest = $client->getRequest();
            self::assertInstanceOf(Request::class, $authenticatedRequest);
            $requestStack->push($authenticatedRequest);
            try {
                $data = $registry->data(MyGuildEventSignupsWidgetProvider::KEY, []);
                self::assertSame(['signups' => []], $data);
                self::assertTrue($authenticatedRequest->attributes->get(MyGuildEventSignupsWidgetProvider::PRIVATE_RESPONSE_KEY));

                $response = new Response();
                $event = new ResponseEvent(
                    $container->get(KernelInterface::class),
                    $authenticatedRequest,
                    HttpKernelInterface::MAIN_REQUEST,
                    $response,
                );
                $container->get(GuildEventSignupWidgetCacheSubscriber::class)->onKernelResponse($event);
                $this->assertPrivateHeaders($response);
            } finally {
                $requestStack->pop();
            }
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingSuppressesWidgetAndItsData(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);
        $this->setModuleState($client, 'gaming', false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(MyGuildEventSignupsWidgetProvider::KEY));
            self::assertSame([], $registry->data(MyGuildEventSignupsWidgetProvider::KEY, []));
            self::assertNotContains(
                MyGuildEventSignupsWidgetProvider::KEY,
                array_map(
                    static fn (WidgetDefinition $definition): string => $definition->key,
                    $registry->availableDefinitions(),
                ),
            );
        } finally {
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    /** @return array{content: bool|null, gaming: bool|null} */
    private function moduleSnapshot(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $snapshot = ['content' => null, 'gaming' => null];
        foreach (self::MODULE_KEYS as $key) {
            $state = $em->find(CmsModuleState::class, $key);
            $snapshot[$key] = $state?->isEnabled();
        }

        return $snapshot;
    }

    private function enableGaming(KernelBrowser $client): void
    {
        $this->setModuleState($client, 'content', true);
        $this->setModuleState($client, 'gaming', true);
    }

    private function setModuleState(KernelBrowser $client, string $key, bool $enabled): void
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

    /** @param array{content: bool|null, gaming: bool|null} $snapshot */
    private function restoreModuleSnapshot(KernelBrowser $client, array $snapshot): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($snapshot as $key => $previous) {
            $state = $em->find(CmsModuleState::class, $key);
            if ($previous === null) {
                if ($state instanceof CmsModuleState) {
                    $em->remove($state);
                }
            } elseif ($state instanceof CmsModuleState) {
                $state->setEnabled($previous);
            } else {
                $em->persist((new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0')->setEnabled($previous));
            }
        }
        $em->flush();
        $em->clear();
    }

    /** @param list<object> $fixtures */
    private function cleanupFixtures(EntityManagerInterface $em, array $fixtures): void
    {
        $em->clear();
        foreach (array_reverse($fixtures) as $fixture) {
            $id = $this->fixtureId($fixture);
            if ($id === null) {
                continue;
            }
            $managed = $em->find($fixture::class, $id);
            if (is_object($managed)) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function fixtureId(object $fixture): ?int
    {
        if ($fixture instanceof GuildEventSignup || $fixture instanceof GuildEvent || $fixture instanceof GuildMember
            || $fixture instanceof Guild || $fixture instanceof Game || $fixture instanceof User) {
            return $fixture->getId();
        }

        return null;
    }

    private function user(EntityManagerInterface $em, string $suffix): User
    {
        $user = (new User())
            ->setEmail('event-signups-widget-'.$suffix.'@example.test')
            ->setDisplayName('Event signup widget '.$suffix)
            ->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function game(EntityManagerInterface $em, string $suffix): Game
    {
        $game = (new Game())->setName('Widget game '.$suffix)->setSlug('widget-game-'.$suffix);
        $em->persist($game);

        return $game;
    }

    private function guild(EntityManagerInterface $em, Game $game, string $suffix): Guild
    {
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Widget guild '.$suffix)
            ->setSlug('widget-guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Event signup widget test');
        $em->persist($guild);

        return $guild;
    }

    private function event(Guild $guild, string $title): GuildEvent
    {
        return (new GuildEvent())
            ->setGuild($guild)
            ->setTitle($title)
            ->setDescription('Upcoming guild event')
            ->setStartsAt(new \DateTimeImmutable('+2 days'));
    }

    private function assertPrivateHeaders(Response $response): void
    {
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        self::assertMatchesRegularExpression('/(?:^|,\s*)private(?:,|$)/', $cacheControl);
        self::assertMatchesRegularExpression('/(?:^|,\s*)no-store(?:,|$)/', $cacheControl);
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
