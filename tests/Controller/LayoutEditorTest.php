<?php

declare(strict_types=1);
namespace App\Tests\Controller;

use App\Entity\PageLayout;
use App\Entity\Category;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LayoutEditorTest extends WebTestCase
{
    private function client(bool $settings = true): KernelBrowser
    {
        $client=static::createClient();$em=$client->getContainer()->get(EntityManagerInterface::class);
        $user=(new User())->setEmail('layout-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('Layout test')->setPermissions($settings?[CmsPermission::ACCESS,CmsPermission::SETTINGS]:[CmsPermission::ACCESS])->verifyEmail();
        $em->persist($user);$em->flush();$client->loginUser($user);return $client;
    }
    private function clearLayout(KernelBrowser $client): void
    {
        $em=$client->getContainer()->get(EntityManagerInterface::class);$record=$em->find(PageLayout::class,'home');if($record!==null){$em->remove($record);$em->flush();}$em->clear();
    }
    public function testUnauthorizedEditorAndWriteAreForbidden(): void
    {
        $client=$this->client(false);$client->request('GET','/admin/layout/home');self::assertResponseStatusCodeSame(403);
        $client->request('POST','/admin/layout/home/save');self::assertResponseStatusCodeSame(403);
    }
    public function testMissingCsrfCannotSaveOrPreview(): void
    {
        $client=$this->client();$client->request('POST','/admin/layout/home/save');self::assertResponseStatusCodeSame(403);
        $client->request('POST','/admin/layout/home/preview');self::assertResponseStatusCodeSame(403);
    }
    public function testPreviewDoesNotSaveAndPublishedTextIsEscapedAndConflictRejected(): void
    {
        $client=$this->client();$this->clearLayout($client);
        try {
            $crawler=$client->request('GET','/admin/layout/home');self::assertResponseIsSuccessful();
            $dir=dirname(__DIR__,2).'/var/layout-preview';if(!is_dir($dir))mkdir($dir,0770,true);
            file_put_contents($dir.'/editor.html',(string)$client->getResponse()->getContent());
            $token=$crawler->filter('[data-layout-editor-token-value]')->attr('data-layout-editor-token-value');
            $doc=$client->getContainer()->get(LayoutValidator::class)->defaults('gravarium-fantasy')->toArray();
            $doc['widgets'][0]['config']['title']='<script>alert("xss")</script>';
            $payload=json_encode(['version'=>0,'document'=>$doc],JSON_THROW_ON_ERROR);
            $client->request('POST','/admin/layout/home/preview',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],$payload);
            self::assertResponseIsSuccessful();self::assertSelectorNotExists('.portal-hero-copy h1 script');
            self::assertStringNotContainsString('<script>alert("xss")</script>', (string)$client->getResponse()->getContent());
            self::assertStringContainsString('&lt;script&gt;', (string)$client->getResponse()->getContent());
            self::assertNull($client->getContainer()->get(EntityManagerInterface::class)->find(PageLayout::class,'home'));
            $client->request('POST','/admin/layout/home/save',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],$payload);self::assertResponseIsSuccessful();
            $client->request('GET','/');self::assertResponseIsSuccessful();self::assertSelectorTextContains('h1','<script>');self::assertSelectorExists('.theme-gravarium-fantasy');
            $client->request('POST','/admin/layout/home/save',[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_CSRF_TOKEN'=>$token],$payload);self::assertResponseStatusCodeSame(409);
        } finally { $this->clearLayout($client); }
    }
    public function testDisabledModuleWidgetIsRetainedAndHidden(): void
    {
        $client=$this->client();$this->clearLayout($client);$em=$client->getContainer()->get(EntityManagerInterface::class);
        try {
            $validator=$client->getContainer()->get(LayoutValidator::class);$doc=$validator->defaults('nebula')->toArray();
            $doc['widgets'][]=['id'=>'video-fixture','type'=>'video.latest','region'=>'right-sidebar','enabled'=>true,'config'=>[]];
            $record=new PageLayout('home');$record->replace($validator->validate($doc)->toArray());$em->persist($record);
            $state=$em->find(CmsModuleState::class,'video')??(new CmsModuleState())->setModuleKey('video')->updateVersion('1.0.0');$state->setEnabled(false);$em->persist($state);$em->flush();
            $client->request('GET','/');self::assertResponseIsSuccessful();self::assertSelectorNotExists('.widget-video-latest');
            $crawler=$client->request('GET','/admin/layout/home');self::assertResponseIsSuccessful();
            $editor=json_decode((string)$crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),true,512,JSON_THROW_ON_ERROR);self::assertSame('video.latest',$editor['document']['widgets'][1]['type']);
        } finally {
            $em=$client->getContainer()->get(EntityManagerInterface::class);$state=$em->find(CmsModuleState::class,'video');if($state!==null)$em->remove($state);$em->flush();$this->clearLayout($client);
        }
    }

    public function testNewsWidgetCanFilterPublishedItemsByCategoryFromLayoutEditor(): void
    {
        $client = $this->client();
        $this->clearLayout($client);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())
            ->setEmail('widget-news-'.$suffix.'@example.test')
            ->setDisplayName('Widget news author')
            ->verifyEmail();
        $categoryAlpha = (new Category())->setName('News Alpha '.$suffix)->setSlug('news-alpha-'.$suffix);
        $categoryBeta = (new Category())->setName('News Beta '.$suffix)->setSlug('news-beta-'.$suffix);
        $entryIds = [];
        $categoryIds = [];
        $authorId = null;

        try {
            $em->persist($author);
            $em->persist($categoryAlpha);
            $em->persist($categoryBeta);
            $em->flush();

            $authorId = $author->getId();
            $categoryAlphaId = (int) $categoryAlpha->getId();
            $categoryBetaId = (int) $categoryBeta->getId();
            $categoryIds = [$categoryAlphaId, $categoryBetaId];
            if ($authorId === null || $categoryAlphaId < 1 || $categoryBetaId < 1) {
                self::fail('The news widget fixture must have persisted category and author IDs.');
            }

            $alphaTitle = 'Alpha published '.$suffix;
            $betaTitle = 'Beta published '.$suffix;
            $draftTitle = 'Alpha draft '.$suffix;
            $publishedAt = new \DateTimeImmutable('-1 hour');
            $createNews = static function (string $title, string $slug, Category $category, string $status) use ($author, $publishedAt): ContentEntry {
                return (new ContentEntry())
                    ->setType(ContentEntry::TYPE_NEWS)
                    ->setTitle($title)
                    ->setSlug($slug)
                    ->setBody('News widget category fixture.')
                    ->setStatus($status)
                    ->setPublishedAt($status === ContentEntry::STATUS_PUBLISHED ? $publishedAt : null)
                    ->setAuthor($author)
                    ->setCategory($category);
            };
            $entries = [
                $createNews($alphaTitle, 'alpha-'.$suffix, $categoryAlpha, ContentEntry::STATUS_PUBLISHED),
                $createNews($betaTitle, 'beta-'.$suffix, $categoryBeta, ContentEntry::STATUS_PUBLISHED),
                $createNews($draftTitle, 'alpha-draft-'.$suffix, $categoryAlpha, ContentEntry::STATUS_DRAFT),
            ];
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();
            foreach ($entries as $entry) {
                if ($entry->getId() !== null) {
                    $entryIds[] = $entry->getId();
                }
            }
            self::assertCount(3, $entryIds);

            $crawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $editor = json_decode((string) $crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($editor);
            $newsCategories = $editor['newsCategories'] ?? null;
            self::assertIsArray($newsCategories);
            self::assertContains(['id' => $categoryAlphaId, 'name' => $categoryAlpha->getDisplayName()], $newsCategories);
            self::assertContains(['id' => $categoryBetaId, 'name' => $categoryBeta->getDisplayName()], $newsCategories);

            $definitions = $editor['widgets'] ?? null;
            self::assertIsArray($definitions);
            $newsSchema = null;
            foreach ($definitions as $definition) {
                if (is_array($definition) && ($definition['key'] ?? null) === 'content.news') {
                    $newsSchema = $definition['schema'] ?? null;
                    break;
                }
            }
            self::assertIsArray($newsSchema);
            self::assertSame(0, $newsSchema['categoryId']['default'] ?? null);

            $validator = $client->getContainer()->get(LayoutValidator::class);
            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'] ?? 'hero';
            $document['widgets'] = [
                ['id' => 'news-alpha-000001', 'type' => 'content.news', 'region' => $region, 'enabled' => true, 'config' => ['count' => 12, 'categoryId' => $categoryAlphaId]],
                ['id' => 'news-beta-000001', 'type' => 'content.news', 'region' => $region, 'enabled' => true, 'config' => ['count' => 12, 'categoryId' => $categoryBetaId]],
                ['id' => 'news-all-000001', 'type' => 'content.news', 'region' => $region, 'enabled' => true, 'config' => ['count' => 12]],
                ['id' => 'news-missing-000001', 'type' => 'content.news', 'region' => $region, 'enabled' => true, 'config' => ['count' => 12, 'categoryId' => 2147483647]],
            ];
            $invalidDocument = $document;
            $invalidDocument['widgets'][0]['config']['categoryId'] = 'not-an-integer';
            $invalidRejected = false;
            try {
                $validator->validate($invalidDocument);
            } catch (\DomainException) {
                $invalidRejected = true;
            }
            self::assertTrue($invalidRejected, 'Malformed category IDs must not be accepted by layout validation.');

            $token = (string) $crawler->filter('[data-layout-editor-token-value]')->attr('data-layout-editor-token-value');
            $payload = json_encode(['version' => 0, 'document' => $document], JSON_THROW_ON_ERROR);
            $client->request('POST', '/admin/layout/home/save', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], $payload);
            self::assertResponseIsSuccessful();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#widget-news-alpha-000001', $alphaTitle);
            self::assertSelectorTextNotContains('#widget-news-alpha-000001', $betaTitle);
            self::assertSelectorTextNotContains('#widget-news-alpha-000001', $draftTitle);
            self::assertSelectorTextContains('#widget-news-beta-000001', $betaTitle);
            self::assertSelectorTextNotContains('#widget-news-beta-000001', $alphaTitle);
            self::assertSelectorTextContains('#widget-news-all-000001', $alphaTitle);
            self::assertSelectorTextContains('#widget-news-all-000001', $betaTitle);
            self::assertSelectorTextNotContains('#widget-news-all-000001', $draftTitle);
            self::assertSelectorTextNotContains('#widget-news-missing-000001', $alphaTitle);
            self::assertSelectorTextNotContains('#widget-news-missing-000001', $betaTitle);
        } finally {
            $this->clearLayout($client);
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            foreach ($entryIds as $id) {
                $entry = $em->find(ContentEntry::class, $id);
                if ($entry !== null) {
                    $em->remove($entry);
                }
            }
            foreach ($categoryIds as $id) {
                $category = $em->find(Category::class, $id);
                if ($category !== null) {
                    $em->remove($category);
                }
            }
            if ($authorId !== null) {
                $author = $em->find(User::class, $authorId);
                if ($author !== null) {
                    $em->remove($author);
                }
            }
            $em->flush();
        }
    }


}
