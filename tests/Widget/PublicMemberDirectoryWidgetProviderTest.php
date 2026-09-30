<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Profile\MemberProfile;
use App\Entity\User;
use App\Widget\PublicMemberDirectoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicMemberDirectoryWidgetProviderTest extends WebTestCase
{
    public function testWidgetIsDiscoveredAndShowsOnlyPublicNamesWithBoundedCount(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, true);
        $users = [];
        $suffix = bin2hex(random_bytes(4));
        $expectedNames = [];

        try {
            $em = $this->em($client);
            for ($index = 0; $index < 15; ++$index) {
                $name = $index === 0
                    ? 'A WCP-554 <script>member</script> '.$suffix
                    : sprintf('A WCP-554 Public Member %02d %s', $index, $suffix);
                $expectedNames[] = $name;
                $users[] = $this->createProfile($em, $name, MemberProfile::VISIBILITY_PUBLIC);
            }

            $privateName = 'Private Widget Member '.$suffix;
            $membersOnlyName = 'Members Only Widget Member '.$suffix;
            $inactiveName = 'Inactive Widget Member '.$suffix;
            $private = $this->createProfile($em, $privateName, MemberProfile::VISIBILITY_PRIVATE);
            $membersOnly = $this->createProfile($em, $membersOnlyName, MemberProfile::VISIBILITY_MEMBERS);
            $inactive = $this->createProfile($em, $inactiveName, MemberProfile::VISIBILITY_PUBLIC, false);
            array_push($users, $private, $membersOnly, $inactive);
            $em->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(PublicMemberDirectoryWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('users', $definition->module);
            self::assertTrue($registry->available($definition->key));

            $data = $registry->data($definition->key, ['count' => 999]);
            self::assertSame(['members'], array_keys($data));
            $members = $data['members'] ?? null;
            self::assertIsArray($members);
            self::assertCount(12, $members);

            $html = $client->getContainer()->get(Environment::class)->render($definition->template, $data);
            self::assertStringContainsString('&lt;script&gt;member&lt;/script&gt;', $html);
            self::assertStringNotContainsString('<script>member</script>', $html);
            self::assertStringNotContainsString($privateName, $html);
            self::assertStringNotContainsString($membersOnlyName, $html);
            self::assertStringNotContainsString($inactiveName, $html);
            self::assertStringNotContainsString($private->getEmail(), $html);
            self::assertStringNotContainsString('Private biography', $html);
            self::assertStringContainsString('href="/members"', $html);

            $sortedNames = $expectedNames;
            sort($sortedNames, SORT_STRING);
            $renderedNames = array_map(
                static fn (array $member): string => $member['displayName'],
                $members,
            );
            self::assertSame(array_slice($sortedNames, 0, 12), $renderedNames);
        } finally {
            $this->deleteUsers($client, $users);
            $this->resetUsersState($client);
        }
    }

    public function testDisabledUsersModuleSuppressesWidgetAndWidgetData(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(PublicMemberDirectoryWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicMemberDirectoryWidgetProvider::KEY, ['count' => 6]));
            self::assertNotContains(
                PublicMemberDirectoryWidgetProvider::KEY,
                array_map(
                    static fn (WidgetDefinition $definition): string => $definition->key,
                    $registry->availableDefinitions(),
                ),
            );
        } finally {
            $this->resetUsersState($client);
        }
    }

    public function testTemplateShowsItsEmptyState(): void
    {
        $client = static::createClient();
        $this->setUsersEnabled($client, true);

        try {
            $definition = $client->getContainer()
                ->get(WidgetRegistry::class)
                ->get(PublicMemberDirectoryWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);

            $html = $client->getContainer()->get(Environment::class)->render(
                $definition->template,
                ['members' => []],
            );
            self::assertStringContainsString('Zurzeit gibt es keine öffentlichen Mitgliederprofile.', $html);
            self::assertStringContainsString('Alle öffentlichen Mitglieder', $html);
        } finally {
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
            ->setEmail('member-widget-'.$suffix.'@example.test')
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
