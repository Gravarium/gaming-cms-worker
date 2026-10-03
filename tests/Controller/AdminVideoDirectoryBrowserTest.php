<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoCategory;
use App\Security\CmsPermission;
use App\Video\AdminVideoBrowser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminVideoDirectoryBrowserTest extends WebTestCase
{
    private ?KernelBrowser $client = null;

    private string $fixtureKey;

    private ?string $userEmail = null;

    /** @var list<string> */
    private array $videoSlugs = [];

    /** @var list<string> */
    private array $categorySlugs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureKey = bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->client instanceof KernelBrowser) {
                $entityManager = $this->entityManager($this->client);
                foreach ($this->videoSlugs as $slug) {
                    $video = $entityManager->getRepository(Video::class)->findOneBy(['slug' => $slug]);
                    if ($video instanceof Video) {
                        $entityManager->remove($video);
                    }
                }
                foreach ($this->categorySlugs as $slug) {
                    $category = $entityManager->getRepository(VideoCategory::class)->findOneBy(['slug' => $slug]);
                    if ($category instanceof VideoCategory) {
                        $entityManager->remove($category);
                    }
                }
                if ($this->userEmail !== null) {
                    $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $this->userEmail]);
                    if ($user instanceof User) {
                        $entityManager->remove($user);
                    }
                }
                $entityManager->flush();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testVideoAdministrationRequiresLoginAndVideoPermission(): void
    {
        $client = $this->createBrowser();
        $client->request('GET', '/admin/videos');
        self::assertResponseRedirects('/login');

        $user = $this->createUser($client, false);
        $client->loginUser($user);
        $client->request('GET', '/admin/videos');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSearchCategoryAndPublicationStateFiltersPreserveEditorContext(): void
    {
        $client = $this->createBrowser();
        $this->loginOperator($client);
        $alpha = $this->createCategory($client, 'alpha');
        $beta = $this->createCategory($client, 'beta');
        $prefix = 'needle'.$this->fixtureKey;

        $published = $this->createVideo($client, 'published', $prefix, $alpha, new \DateTimeImmutable('-1 hour'));
        $scheduled = $this->createVideo($client, 'scheduled', $prefix, $beta, new \DateTimeImmutable('+1 day'));
        $draft = $this->createVideo($client, 'draft', $prefix, $alpha);
        $disabled = $this->createVideo($client, 'disabled', $prefix, $alpha, new \DateTimeImmutable('-1 day'), false);

        $url = '/admin/videos?'.http_build_query([
            'search' => strtoupper($prefix),
            'status' => 'published',
            'category' => $alpha->getId(),
        ]);
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '#video-inventory .video-title');
        self::assertSelectorTextContains('#video-inventory', $published->getTitle());
        self::assertStringNotContainsString($scheduled->getTitle(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));

        $editHref = $crawler->filter('#video-inventory .row-actions a[href*="/edit"]')->attr('href');
        self::assertNotNull($editHref);
        self::assertSame([
            'search' => strtoupper($prefix),
            'status' => 'published',
            'category' => (string) $alpha->getId(),
        ], $this->queryFromUrl($editHref));

        $deleteAction = $crawler->filter('#video-inventory form.video-delete-form')->attr('action');
        self::assertNotNull($deleteAction);
        self::assertSame($this->queryFromUrl($editHref), $this->queryFromUrl($deleteAction));

        $editPage = $client->request('GET', $editHref);
        self::assertResponseIsSuccessful();
        $backHref = $editPage->filter('header a[href^="/admin/videos"]')->attr('href');
        self::assertNotNull($backHref);
        self::assertSame($this->queryFromUrl($editHref), $this->queryFromUrl($backHref));
        $formAction = $editPage->filter('form.content-form')->attr('action');
        self::assertNotNull($formAction);
        self::assertSame($this->queryFromUrl($editHref), $this->queryFromUrl($formAction));

        foreach ([
            'published' => $published,
            'scheduled' => $scheduled,
            'draft' => $draft,
            'disabled' => $disabled,
        ] as $state => $expected) {
            $stateUrl = '/admin/videos?'.http_build_query([
                'search' => $prefix,
                'status' => $state,
                'category' => $expected->getCategory()?->getId(),
            ]);
            $client->request('GET', $stateUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '#video-inventory .video-title');
            self::assertSelectorTextContains('#video-inventory', $expected->getTitle());
        }

        $client->request('GET', '/admin/videos?'.http_build_query(['search' => $prefix, 'category' => $alpha->getId()]));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '#video-inventory .video-title');
        self::assertStringNotContainsString($scheduled->getTitle(), (string) $client->getResponse()->getContent());
    }

    public function testPageCountRoundsUpAndNeverExceedsTheAcceptedPageMaximum(): void
    {
        $cases = [
            [0, 1],
            [1, 1],
            [AdminVideoBrowser::PAGE_SIZE, 1],
            [AdminVideoBrowser::PAGE_SIZE + 1, 2],
            [
                (AdminVideoBrowser::MAX_PAGE - 1) * AdminVideoBrowser::PAGE_SIZE,
                AdminVideoBrowser::MAX_PAGE - 1,
            ],
            [
                AdminVideoBrowser::MAX_PAGE * AdminVideoBrowser::PAGE_SIZE - 1,
                AdminVideoBrowser::MAX_PAGE,
            ],
            [
                AdminVideoBrowser::MAX_PAGE * AdminVideoBrowser::PAGE_SIZE,
                AdminVideoBrowser::MAX_PAGE,
            ],
            [
                AdminVideoBrowser::MAX_PAGE * AdminVideoBrowser::PAGE_SIZE + 1,
                AdminVideoBrowser::MAX_PAGE,
            ],
            [PHP_INT_MAX, AdminVideoBrowser::MAX_PAGE],
        ];

        foreach ($cases as [$total, $expectedPageCount]) {
            self::assertSame($expectedPageCount, AdminVideoBrowser::boundedPageCount($total));
        }
    }

    public function testNegativePageCountsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminVideoBrowser::boundedPageCount(-1);
    }

    public function testVideoPagesAreStableBoundedAndClampPastEndPages(): void
    {
        $client = $this->createBrowser();
        $this->loginOperator($client);

        $videos = [];
        for ($index = 1; $index <= 27; ++$index) {
            $videos[] = $this->createVideo($client, sprintf('page-%02d', $index), 'pager');
        }

        $firstPage = $client->request('GET', '/admin/videos?'.http_build_query(['search' => 'pager']));
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '#video-inventory tbody > tr');
        self::assertSelectorTextContains('#video-inventory', '27 Videos');
        self::assertSelectorTextContains('#video-inventory', 'Seite 1 von 2');
        self::assertSelectorTextContains('#video-inventory', $videos[26]->getTitle());
        self::assertStringNotContainsString($videos[0]->getTitle(), (string) $client->getResponse()->getContent());

        $nextHref = $firstPage->filter('#video-inventory a[aria-label="Nächste Seite"]')->attr('href');
        self::assertNotNull($nextHref);
        self::assertSame(['search' => 'pager', 'page' => '2'], $this->queryFromUrl($nextHref));

        $secondPage = $client->request('GET', $nextHref);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '#video-inventory tbody > tr');
        self::assertSelectorTextContains('#video-inventory', 'Seite 2 von 2');
        self::assertSelectorTextContains('#video-inventory', $videos[0]->getTitle());
        self::assertSelectorTextContains('#video-inventory', $videos[1]->getTitle());
        self::assertStringNotContainsString($videos[2]->getTitle(), (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/videos?'.http_build_query(['search' => 'pager', 'page' => 999]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#video-inventory', 'Seite 2 von 2');
        self::assertSelectorCount(2, '#video-inventory tbody > tr');
    }

    public function testMalformedFiltersReturnPrivateBadRequestsWithoutChangingVideos(): void
    {
        $client = $this->createBrowser();
        $this->loginOperator($client);
        $unknownCategory = 2_147_483_647;
        $invalidQueries = [
            'search%5B%5D=needle',
            'search='.str_repeat('x', 101),
            'search=%FF',
            'search=%00needle',
            'status=unknown',
            'page=0',
            'page=01',
            sprintf('page=%d', AdminVideoBrowser::MAX_PAGE + 1),
            'page%5B%5D=1',
            'category=0',
            'category=01',
            'category=2147483648',
            'category='.$unknownCategory,
            'category%5B%5D=1',
        ];

        foreach ($invalidQueries as $query) {
            $client->request('GET', '/admin/videos?'.$query);
            self::assertResponseStatusCodeSame(400, $query);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        }
    }

    public function testDeleteStillRequiresCsrfAndPreservesValidListContext(): void
    {
        $client = $this->createBrowser();
        $this->loginOperator($client);
        $video = $this->createVideo($client, 'deletion-target', 'delete needle');

        $crawler = $client->request('GET', '/admin/videos?'.http_build_query([
            'search' => 'delete needle',
            'status' => 'draft',
        ]));
        self::assertResponseIsSuccessful();
        $action = $crawler->filter('#video-inventory form.video-delete-form')->attr('action');
        $token = $crawler->filter('#video-inventory form.video-delete-form input[name="_token"]')->attr('value');
        self::assertNotNull($action);
        self::assertNotNull($token);

        $client->request('POST', $action, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Video::class, $this->entityManager($client)->getRepository(Video::class)->findOneBy(['slug' => $video->getSlug()]));

        $client->request('POST', $action, ['_token' => $token]);
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertSame('/admin/videos', parse_url($location, PHP_URL_PATH));
        self::assertSame(['search' => 'delete needle', 'status' => 'draft'], $this->queryFromUrl($location));
        self::assertNull($this->entityManager($client)->getRepository(Video::class)->findOneBy(['slug' => $video->getSlug()]));
    }

    private function createBrowser(): KernelBrowser
    {
        $client = static::createClient();
        $this->client = $client;

        return $client;
    }

    private function loginOperator(KernelBrowser $client): User
    {
        $user = $this->createUser($client, true);
        $client->loginUser($user);

        return $user;
    }

    private function createUser(KernelBrowser $client, bool $videoPermission): User
    {
        $email = 'video-browser-'.$this->fixtureKey.'@example.test';
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName('Video browser test');
        if ($videoPermission) {
            $user->setPermissions([CmsPermission::VIDEO]);
        }
        $this->userEmail = $email;
        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createCategory(KernelBrowser $client, string $label): VideoCategory
    {
        $slug = 'video-browser-'.$this->fixtureKey.'-'.$label;
        $category = (new VideoCategory())
            ->setName('Browser '.$this->fixtureKey.' '.$label)
            ->setSlug($slug);
        $this->categorySlugs[] = $slug;
        $entityManager = $this->entityManager($client);
        $entityManager->persist($category);
        $entityManager->flush();

        return $category;
    }

    private function createVideo(
        KernelBrowser $client,
        string $label,
        string $titlePrefix,
        ?VideoCategory $category = null,
        ?\DateTimeImmutable $publishedAt = null,
        bool $enabled = true,
    ): Video {
        $slug = 'video-browser-'.$this->fixtureKey.'-'.$label;
        $video = (new Video())
            ->setTitle('Admin '.$titlePrefix.' '.$label)
            ->setSlug($slug)
            ->setDescription('Synthetic video browser fixture.')
            ->setSourceType(Video::SOURCE_EXTERNAL)
            ->setSourceUrl('https://videos.example.test/'.$this->fixtureKey.'/'.$label)
            ->setCategory($category)
            ->setPublishedAt($publishedAt)
            ->setEnabled($enabled);
        $this->videoSlugs[] = $slug;
        $entityManager = $this->entityManager($client);
        $entityManager->persist($video);
        $entityManager->flush();

        return $video;
    }

    /**
     * @return array<string, string>
     */
    private function queryFromUrl(string $url): array
    {
        $queryString = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($queryString, $query);

        return $query;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
