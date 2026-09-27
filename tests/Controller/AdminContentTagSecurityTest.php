<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentTagSecurityTest extends WebTestCase
{
    public function testContentTagRoutesRequireContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'tag-denied', [CmsPermission::VIDEO]);
        $name = 'Denied tag '.bin2hex(random_bytes(4));
        $tag = $this->createTag($client, $name);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/content/tags');
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/content/tags/new');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/content/tags/new', [
                'content_tag' => ['name' => 'Unauthorized tag', 'description' => ''],
            ]);
            self::assertResponseStatusCodeSame(403);

            $tagId = $tag->getId();
            self::assertNotNull($tagId);
            $client->request('POST', '/admin/content/tags/'.$tagId.'/delete');
            self::assertResponseStatusCodeSame(403);

            $stored = $this->findTag($client, $name);
            self::assertInstanceOf(ContentTag::class, $stored);
            self::assertSame($name, $stored->getName());
            self::assertSame([], $this->auditEntries($client, $user));
        } finally {
            $this->cleanup($client, $user, [$name]);
        }
    }

    public function testTagCreateAndDeleteRequireTheirRenderedCsrfTokens(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'tag-csrf', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(4));
        $tagName = 'wcp470 tag '.$suffix;
        $rejectedNames = [
            'wcp470 rejected missing '.$suffix,
            'wcp470 rejected invalid '.$suffix,
        ];

        try {
            $client->loginUser($user);

            foreach ([
                ['mode' => 'missing', 'name' => $rejectedNames[0]],
                ['mode' => 'invalid', 'name' => $rejectedNames[1]],
            ] as $case) {
                $values = $this->renderedFormValues($client, $case['name'], $case['mode']);
                $client->request('POST', '/admin/content/tags/new', $values);

                self::assertResponseIsSuccessful();
                self::assertNull($this->findTag($client, $case['name']));
                self::assertSame([], $this->auditEntries($client, $user));
            }

            $values = $this->renderedFormValues($client, $tagName, 'valid');
            $client->request('POST', '/admin/content/tags/new', $values);
            self::assertResponseRedirects('/admin/content/tags');

            $tag = $this->findTag($client, $tagName);
            self::assertInstanceOf(ContentTag::class, $tag);
            self::assertNotSame('', $tag->getSlug());
            $tagId = $tag->getId();
            self::assertNotNull($tagId);

            $logs = $this->auditEntries($client, $user);
            self::assertCount(1, $logs);
            self::assertSame('content_tag.save', $logs[0]->getAction());

            $deletePath = '/admin/content/tags/'.$tagId.'/delete';
            foreach ([[], ['_token' => 'invalid']] as $parameters) {
                $client->request('POST', $deletePath, $parameters);

                self::assertResponseStatusCodeSame(403);
                self::assertInstanceOf(ContentTag::class, $this->findTag($client, $tagName));
                self::assertCount(1, $this->auditEntries($client, $user));
            }

            $crawler = $client->request('GET', '/admin/content/tags');
            self::assertResponseIsSuccessful();
            $deleteToken = (string) $crawler
                ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $deleteToken);

            $client->request('POST', $deletePath, ['_token' => $deleteToken]);

            self::assertResponseRedirects('/admin/content/tags');
            self::assertNull($this->findTag($client, $tagName));
            $actions = array_map(static fn (AuditLog $log): string => $log->getAction(), $this->auditEntries($client, $user));
            self::assertContains('content_tag.save', $actions);
            self::assertContains('content_tag.delete', $actions);
        } finally {
            $this->cleanup($client, $user, [...$rejectedNames, $tagName]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedFormValues(KernelBrowser $client, string $name, string $csrfMode): array
    {
        $crawler = $client->request('GET', '/admin/content/tags/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Speichern')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);
        $formValues['name'] = $name;

        if ($csrfMode === 'missing') {
            unset($formValues['_token']);
        } elseif ($csrfMode === 'invalid') {
            $formValues['_token'] = 'invalid';
        }

        $values[$formName] = $formValues;

        return $values;
    }

    private function createTag(KernelBrowser $client, string $name): ContentTag
    {
        $tag = (new ContentTag())
            ->setName($name)
            ->setSlug('fixture-'.bin2hex(random_bytes(5)));
        $this->entityManager($client)->persist($tag);
        $this->entityManager($client)->flush();

        return $tag;
    }

    private function findTag(KernelBrowser $client, string $name): ?ContentTag
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $tag = $entityManager->getRepository(ContentTag::class)->findOneBy(['name' => $name]);

        return $tag instanceof ContentTag ? $tag : null;
    }

    /**
     * @return list<AuditLog>
     */
    private function auditEntries(KernelBrowser $client, User $user): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        $entries = $entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]);

        return array_values(array_filter(
            $entries,
            static fn (object $entry): bool => $entry instanceof AuditLog,
        ));
    }

    /**
     * @param list<string> $names
     */
    private function cleanup(KernelBrowser $client, User $user, array $names): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($names as $name) {
            foreach ($entityManager->getRepository(ContentTag::class)->findBy(['name' => $name]) as $tag) {
                if ($tag instanceof ContentTag) {
                    $entityManager->remove($tag);
                }
            }
        }

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]) as $entry) {
                    $entityManager->remove($entry);
                }
                $entityManager->remove($storedUser);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-tag-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content tag security '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
