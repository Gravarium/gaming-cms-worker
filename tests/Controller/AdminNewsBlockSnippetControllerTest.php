<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\NewsBlockSnippet;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNewsBlockSnippetControllerTest extends WebTestCase
{
    public function testPersonalLibraryCreatesSearchesIsolatesAndDeletesValidatedBlocks(): void
    {
        $client = static::createClient();
        [$owner, $entry] = $this->editor($client);
        [$other, $otherEntry] = $this->editor($client);
        $client->loginUser($owner);
        $token = $this->token($client, $entry);
        $client->request('POST', '/admin/news-editor/snippets', [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token,
        ], json_encode(['label' => '  Intro sicher  ', 'block' => $this->paragraph('<script>Text</script>')], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $created = $this->json($client);
        self::assertSame('Intro sicher', $created['item']['label']);
        self::assertSame('<script>Text</script>', $created['item']['block']['content'][0]['text']);
        $id = $created['item']['id'];
        self::assertIsInt($id);
        self::assertStringContainsString('no-store', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));

        $client->request('GET', '/admin/news-editor/snippets?q=Intro');
        self::assertResponseIsSuccessful();
        $listed = $this->json($client);
        self::assertSame([$id], array_column($listed['items'], 'id'));
        self::assertSame(50, $listed['capacity']);
        self::assertStringContainsString('private', strtolower((string) $client->getResponse()->headers->get('Cache-Control')));

        $client->loginUser($other);
        $otherToken = $this->token($client, $otherEntry);
        $client->request('GET', '/admin/news-editor/snippets');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client)['items']);
        $client->request('DELETE', '/admin/news-editor/snippets/'.$id, [], [], ['HTTP_X_CSRF_TOKEN' => $otherToken]);
        self::assertResponseStatusCodeSame(404);

        $client->loginUser($owner);
        $token = $this->token($client, $entry);
        $client->request('DELETE', '/admin/news-editor/snippets/'.$id, [], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['deleted' => $id], $this->json($client));
        $client->request('GET', '/admin/news-editor/snippets');
        self::assertSame([], $this->json($client)['items']);
    }

    public function testLibraryRejectsPermissionCsrfInvalidBlocksAndUnboundedInput(): void
    {
        $client = static::createClient();
        [$owner, $entry] = $this->editor($client);
        $client->loginUser($owner);
        $token = $this->token($client, $entry);
        $valid = json_encode(['label' => 'Hinweis', 'block' => $this->paragraph('Text')], JSON_THROW_ON_ERROR);
        $client->request('POST', '/admin/news-editor/snippets', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'wrong'], $valid);
        self::assertResponseStatusCodeSame(403);
        foreach ([
            ['label' => "bad\nname", 'block' => $this->paragraph('Text')],
            ['label' => 'Aktiv', 'block' => ['type' => 'script', 'text' => 'alert(1)']],
            ['label' => 'Link', 'block' => ['type' => 'paragraph', 'content' => [['text' => 'x', 'marks' => ['link'], 'href' => 'javascript:alert(1)']]]],
        ] as $payload) {
            $client->request('POST', '/admin/news-editor/snippets', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode($payload, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('POST', '/admin/news-editor/snippets', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], str_repeat('x', 20001));
        self::assertResponseStatusCodeSame(413);
        $client->request('GET', '/admin/news-editor/snippets?q='.str_repeat('x', 81));
        self::assertResponseStatusCodeSame(422);

        [$reader] = $this->editor($client, [CmsPermission::ACCESS]);
        $client->loginUser($reader);
        $client->request('GET', '/admin/news-editor/snippets');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLibraryBoundsListingAndCapacityPerOwner(): void
    {
        $client = static::createClient();
        [$owner, $entry] = $this->editor($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        for ($index = 1; $index <= 50; $index++) {
            $em->persist(new NewsBlockSnippet($owner, 'Vorlage '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), $this->paragraph('Text '.$index)));
        }
        $em->flush();
        $client->loginUser($owner);
        $token = $this->token($client, $entry);
        $client->request('GET', '/admin/news-editor/snippets?q=Vorlage');
        self::assertResponseIsSuccessful();
        self::assertCount(30, $this->json($client)['items']);
        $client->request('POST', '/admin/news-editor/snippets', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['label' => 'Zu viel', 'block' => $this->paragraph('Text')], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    /** @param list<string> $permissions @return array{User, ContentEntry} */
    private function editor(KernelBrowser $client, array $permissions = [CmsPermission::ACCESS, CmsPermission::CONTENT]): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())->setEmail('snippet-'.$suffix.'@example.test')->setDisplayName('Snippet editor')->setPermissions($permissions)->verifyEmail();
        $entry = (new ContentEntry())->setAuthor($user)->setType(ContentEntry::TYPE_NEWS)->setTitle('Snippet '.$suffix)->setSlug('snippet-'.$suffix)->setBody('Text');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($user); $em->persist($entry); $em->flush();
        return [$user, $entry];
    }

    private function token(KernelBrowser $client, ContentEntry $entry): string
    {
        $crawler = $client->request('GET', '/admin/news-editor/'.$entry->getId());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#news-editor')->attr('data-snippets-csrf');
        self::assertNotNull($token);
        return $token;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['text' => $text, 'marks' => []]]];
    }
}
