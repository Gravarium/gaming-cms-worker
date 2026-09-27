<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentLayoutMutationBoundaryTest extends WebTestCase
{
    public function testInvalidCsrfCannotChangePageTypeOrDeleteItsLayout(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $userEmail = 'layout-boundary-'.$suffix.'@example.test';
        $slug = 'layout-boundary-'.$suffix;
        $entryId = null;
        $userId = null;
        $layoutContext = null;

        try {
            $user = (new User())
                ->setEmail($userEmail)
                ->setDisplayName('Layout boundary test')
                ->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])
                ->verifyEmail();
            $entry = (new ContentEntry())
                ->setAuthor($user)
                ->setType(ContentEntry::TYPE_PAGE)
                ->setTitle('Layout boundary page '.$suffix)
                ->setSlug($slug)
                ->setBody('Original page content');

            $entityManager->persist($user);
            $entityManager->persist($entry);
            $entityManager->flush();

            $entryId = $this->requireId($entry->getId());
            $userId = $this->requireId($user->getId());
            $layoutContext = 'page-'.$entryId;
            $layoutDocument = [
                'theme' => 'gravarium-fantasy',
                'widgets' => [
                    [
                        'id' => 'preserve-layout-'.$suffix,
                        'type' => 'news.latest',
                        'region' => 'main',
                        'enabled' => true,
                        'config' => ['title' => 'Preserve this layout'],
                    ],
                ],
            ];
            $layout = new PageLayout($layoutContext);
            $layout->replace($layoutDocument);
            $entityManager->persist($layout);
            $entityManager->flush();

            $client->loginUser($user);
            $editPath = '/admin/content/'.$entryId.'/edit';
            $crawler = $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();

            $form = $crawler->selectButton('Speichern')->form();
            $formName = $form->getName();
            self::assertNotSame('', $formName);
            $values = $form->getPhpValues();
            $formValues = $values[$formName] ?? null;
            self::assertIsArray($formValues);
            self::assertArrayHasKey('_token', $formValues);
            $formValues['type'] = ContentEntry::TYPE_NEWS;
            $formValues['_token'] = 'invalid-layout-mutation-token';
            $values[$formName] = $formValues;

            $client->request('POST', $editPath, $values);

            self::assertResponseStatusCodeSame(200);
            self::assertFalse($client->getResponse()->isRedirection());

            $entityManager->clear();
            $persistedEntry = $entityManager->find(ContentEntry::class, $entryId);
            self::assertInstanceOf(ContentEntry::class, $persistedEntry);
            self::assertSame(ContentEntry::TYPE_PAGE, $persistedEntry->getType());

            $persistedLayout = $entityManager->find(PageLayout::class, $layoutContext);
            self::assertInstanceOf(PageLayout::class, $persistedLayout);
            self::assertSame($layoutDocument, $persistedLayout->getDocument());
        } finally {
            $entityManager->clear();

            if ($layoutContext !== null) {
                $layout = $entityManager->find(PageLayout::class, $layoutContext);
                if ($layout instanceof PageLayout) {
                    $entityManager->remove($layout);
                }
            }

            if ($entryId !== null) {
                $persistedEntry = $entityManager->find(ContentEntry::class, $entryId);
                if ($persistedEntry instanceof ContentEntry) {
                    $entityManager->remove($persistedEntry);
                }
            }

            if ($userId !== null) {
                $persistedUser = $entityManager->find(User::class, $userId);
                if ($persistedUser instanceof User) {
                    $entityManager->remove($persistedUser);
                }
            }

            $entityManager->flush();
            $entityManager->clear();
        }
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Persisted fixture has no identifier.');
        }

        return $id;
    }
}
