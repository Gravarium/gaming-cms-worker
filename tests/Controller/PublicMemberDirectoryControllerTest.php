<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Profile\MemberProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicMemberDirectoryControllerTest extends WebTestCase
{
    public function testDirectoryIncludesOnlyActivePublicNamesAndUsesStablePagination(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, true);
        $users = [];
        $suffix = bin2hex(random_bytes(4));
        $expectedNames = [];

        try {
            $em = $this->em($client);
            for ($index = 0; $index < 21; ++$index) {
                $name = sprintf('A WCP-554 Public Member %02d %s', $index, $suffix);
                $expectedNames[] = $name;
                $users[] = $this->createProfile($em, $name, MemberProfile::VISIBILITY_PUBLIC);
            }

            $privateName = 'Private Member '.$suffix;
            $memberOnlyName = 'Members Only '.$suffix;
            $inactiveName = 'Inactive Member '.$suffix;
            $withoutProfileName = 'No Profile '.$suffix;
            $private = $this->createProfile($em, $privateName, MemberProfile::VISIBILITY_PRIVATE);
            $membersOnly = $this->createProfile($em, $memberOnlyName, MemberProfile::VISIBILITY_MEMBERS);
            $inactive = $this->createProfile($em, $inactiveName, MemberProfile::VISIBILITY_PUBLIC, false);
            $withoutProfile = $this->createUser($em, $withoutProfileName);
            array_push($users, $private, $membersOnly, $inactive, $withoutProfile);
            $em->flush();

            $client->request('GET', '/members');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Öffentliche Mitglieder');
            self::assertSelectorTextContains('nav[aria-label="Mitgliederseiten"]', 'Seite 1 von 2');

            $firstPageNames = $client->getCrawler()
                ->filter('.public-member-card a[href^="/members/"]')
                ->each(static fn ($link): string => trim($link->text()));
            self::assertCount(20, $firstPageNames);
            self::assertSame(array_slice($expectedNames, 0, 20), $firstPageNames);

            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($privateName, $html);
            self::assertStringNotContainsString($memberOnlyName, $html);
            self::assertStringNotContainsString($inactiveName, $html);
            self::assertStringNotContainsString($withoutProfileName, $html);
            self::assertStringNotContainsString('Private biography', $html);
            self::assertStringNotContainsString($private->getEmail(), $html);

            $lastPublicUser = $users[20];
            self::assertNotNull($lastPublicUser->getId());
            $client->request('GET', '/members?page=2');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('nav[aria-label="Mitgliederseiten"]', 'Seite 2 von 2');
            self::assertSame(
                [$expectedNames[20]],
                $client->getCrawler()->filter('.public-member-card a[href^="/members/"]')->each(static fn ($link): string => trim($link->text())),
            );
            self::assertSame(
                '/members/'.$lastPublicUser->getId(),
                $client->getCrawler()->filter('.public-member-card a[href^="/members/"]')->attr('href'),
            );
        } finally {
            $this->deleteUsers($client, $users);
            $this->resetUsersState($client);
        }
    }

    public function testEmptyDirectoryHasAnExplicitEmptyState(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, true);
        $users = [];

        try {
            $em = $this->em($client);
            $users[] = $this->createProfile($em, 'Hidden Member '.bin2hex(random_bytes(4)), MemberProfile::VISIBILITY_PRIVATE);
            $em->flush();

            $client->request('GET', '/members');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Zurzeit gibt es keine öffentlichen Mitgliederprofile.');
        } finally {
            $this->deleteUsers($client, $users);
            $this->resetUsersState($client);
        }
    }

    public function testInvalidOutOfRangeAndNonGetDirectoryRequestsAreRejected(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, true);
        $users = [];

        try {
            $em = $this->em($client);
            $users[] = $this->createProfile($em, 'Only Public Member '.bin2hex(random_bytes(4)), MemberProfile::VISIBILITY_PUBLIC);
            $em->flush();

            $client->request('GET', '/members?page=abc');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/members?page=0');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/members?page=2');
            self::assertResponseStatusCodeSame(404);

            $client->request('POST', '/members');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->deleteUsers($client, $users);
            $this->resetUsersState($client);
        }
    }

    public function testUsersModuleDisablesDirectoryAndExistingProfilePage(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, true);
        $users = [];

        try {
            $em = $this->em($client);
            $user = $this->createProfile($em, 'Visible Member '.bin2hex(random_bytes(4)), MemberProfile::VISIBILITY_PUBLIC);
            $em->flush();
            $users[] = $user;
            self::assertNotNull($user->getId());

            $this->setUsersEnabled($client, false);
            $client->request('GET', '/members');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/members/'.$user->getId());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->deleteUsers($client, $users);
            $this->resetUsersState($client);
        }
    }

    private function createProfile(
        EntityManagerInterface $em,
        string $displayName,
        string $visibility,
        bool $active = true,
    ): User {
        $user = $this->createUser($em, $displayName)->setActive($active);
        $profile = (new MemberProfile($user))
            ->setDisplayNameVisibility($visibility)
            ->setBio('Private biography '.bin2hex(random_bytes(3)))
            ->setBioVisibility(MemberProfile::VISIBILITY_PRIVATE);

        $em->persist($profile);

        return $user;
    }

    private function createUser(EntityManagerInterface $em, string $displayName): User
    {
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail('member-directory-'.$suffix.'@example.test')
            ->setDisplayName($displayName)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $em->persist($user);

        return $user;
    }

    /** @param list<User> $users */
    private function deleteUsers(KernelBrowser $client, array $users): void
    {
        $connection = $this->em($client)->getConnection();
        foreach ($users as $user) {
            $id = $user->getId();
            if ($id !== null) {
                $connection->delete('cms_user', ['id' => $id]);
            }
        }
        $this->em($client)->clear();
    }

    private function setUsersEnabled(KernelBrowser $client, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'users');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('users')->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled($enabled);
        $em->flush();
    }

    private function resetUsersState(KernelBrowser $client): void
    {
        $connection = $this->em($client)->getConnection();
        $connection->delete('cms_module_state', ['module_key' => 'users']);
        $this->em($client)->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
