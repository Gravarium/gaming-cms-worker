<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Game;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminCompetitionScheduleTest extends WebTestCase
{
    public function testSchedulingKeepsSecurityGatesAndPersistsUtc(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previous = $this->setGaming($client, true);
        $entities = [];

        try {
            $em = $this->em($client);
            $suffix = bin2hex(random_bytes(5));
            $game = (new Game())->setName('Schedule Game '.$suffix)->setSlug('schedule-game-'.$suffix);
            $manager = (new User())->setEmail('schedule-manager-'.$suffix.'@example.test')->setDisplayName('Schedule manager')->setPermissions([CmsPermission::ACCESS, CmsPermission::GAMING])->verifyEmail();
            $entities = [$game, $manager];
            foreach ($entities as $entity) {
                $em->persist($entity);
            }
            $em->flush();

            $competition = (new Competition())->setGame($game)->setName('Schedule Cup '.$suffix)->setSlug('schedule-cup-'.$suffix)->open();
            $otherCompetition = (new Competition())->setGame($game)->setName('Other Schedule Cup '.$suffix)->setSlug('other-schedule-cup-'.$suffix)->open();
            $match = (new CompetitionMatch())->setCompetition($competition);
            foreach ([$competition, $otherCompetition, $match] as $entity) {
                $em->persist($entity);
                $entities[] = $entity;
            }
            $em->flush();

            $url = '/admin/gaming/competitions/'.$competition->getId().'/match/'.$match->getId().'/schedule';
            $client->request('POST', $url, ['scheduled_at' => 'tomorrow']);
            self::assertResponseRedirects('/login');

            $client->loginUser($manager);
            $client->request('GET', '/admin/gaming/competitions');
            self::assertResponseIsSuccessful();
            $token = $this->csrfToken($client, 'competition-schedule-'.$match->getId());

            $client->request('POST', $url, ['scheduled_at' => '2030-10-01T12:30', '_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', '/admin/gaming/competitions/'.$otherCompetition->getId().'/match/'.$match->getId().'/schedule', [
                'scheduled_at' => '2030-10-01T12:30', '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $url, ['scheduled_at' => 'tomorrow', '_token' => $token]);
            self::assertResponseStatusCodeSame(400);
            self::assertNull($this->storedMatch($client, $match)->getScheduledAt());

            $future = new \DateTimeImmutable('+5 days', new \DateTimeZone('UTC'));
            $raw = $future->setTimezone(new \DateTimeZone('+02:00'))->format('Y-m-d\TH:iP');
            $expected = (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
            $client->request('POST', $url, ['scheduled_at' => $raw, '_token' => $token]);
            self::assertResponseRedirects('/admin/gaming/competitions');
            self::assertSame($expected, $this->storedMatch($client, $match)->getScheduledAt()?->format(\DateTimeInterface::ATOM));

            $this->setGaming($client, false);
            $client->request('POST', $url, ['scheduled_at' => $raw, '_token' => $token]);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->remove($client, $entities);
            $this->restoreGaming($client, $previous);
        }
    }

    private function storedMatch(KernelBrowser $client, CompetitionMatch $match): CompetitionMatch
    {
        $em = $this->em($client);
        $em->clear();
        $stored = $em->find(CompetitionMatch::class, $match->getId());
        self::assertInstanceOf(CompetitionMatch::class, $stored);

        return $stored;
    }

    private function setGaming(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state?->isEnabled();
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreGaming(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        if ($state instanceof CmsModuleState) {
            if ($previous === null) {
                $em->remove($state);
            } else {
                $state->setEnabled($previous);
            }
            $em->flush();
            $em->clear();
        }
    }

    /** @param list<object> $entities */
    private function remove(KernelBrowser $client, array $entities): void
    {
        $em = $this->em($client);
        foreach (array_reverse($entities) as $entity) {
            $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
            if ($ids === [] || in_array(null, $ids, true)) {
                continue;
            }
            $id = count($ids) === 1 ? array_values($ids)[0] : $ids;
            $managed = $em->find($entity::class, $id);
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $request = $client->getRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            throw new \RuntimeException('A current request session is required to create a CSRF token.');
        }

        $requestStack = $client->getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }
}
