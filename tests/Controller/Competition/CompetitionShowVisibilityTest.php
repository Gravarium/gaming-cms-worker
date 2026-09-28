<?php

declare(strict_types=1);

namespace App\Tests\Controller\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompetitionShowVisibilityTest extends WebTestCase
{
    private ?KernelBrowser $fixtureClient = null;

    /** @var list<string> */
    private array $competitionSlugs = [];

    /** @var list<string> */
    private array $gameSlugs = [];

    public function testCompetitionDetailsFailClosedForPrivateDraftAndDisabledGameResources(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);

        $client->request('GET', '/competitions/'.$fixture['publicId']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', $fixture['publicName']);

        foreach ($fixture['hiddenIds'] as $id) {
            $client->request('GET', '/competitions/'.$id);
            self::assertResponseStatusCodeSame(404);
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureClient !== null) {
                $entityManager = $this->fixtureClient->getContainer()->get(EntityManagerInterface::class);
                foreach ($this->competitionSlugs as $slug) {
                    $competition = $entityManager->getRepository(Competition::class)->findOneBy(['slug' => $slug]);
                    if ($competition instanceof Competition) {
                        $entityManager->remove($competition);
                    }
                }
                foreach ($this->gameSlugs as $slug) {
                    $game = $entityManager->getRepository(Game::class)->findOneBy(['slug' => $slug]);
                    if ($game instanceof Game) {
                        $entityManager->remove($game);
                    }
                }
                $entityManager->flush();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return array{publicId: int, publicName: string, hiddenIds: list<int>}
     */
    private function fixture(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $this->fixtureClient = $client;
        $this->competitionSlugs = ['public-competition-'.$suffix, 'private-competition-'.$suffix, 'draft-competition-'.$suffix, 'disabled-game-competition-'.$suffix];
        $this->gameSlugs = ['visible-competition-game-'.$suffix, 'disabled-competition-game-'.$suffix];
        $publicGame = (new Game())
            ->setName('Visible Competition Game '.$suffix)
            ->setSlug('visible-competition-game-'.$suffix)
            ->setEnabled(true);
        $disabledGame = (new Game())
            ->setName('Disabled Competition Game '.$suffix)
            ->setSlug('disabled-competition-game-'.$suffix)
            ->setEnabled(true);

        $publicName = 'Public Competition '.$suffix;
        $public = (new Competition())
            ->setGame($publicGame)
            ->setName($publicName)
            ->setSlug('public-competition-'.$suffix)
            ->open();
        $private = (new Competition())
            ->setGame($publicGame)
            ->setName('Private Competition '.$suffix)
            ->setSlug('private-competition-'.$suffix)
            ->setVisibility(Competition::VISIBILITY_PRIVATE)
            ->open();
        $draft = (new Competition())
            ->setGame($publicGame)
            ->setName('Draft Competition '.$suffix)
            ->setSlug('draft-competition-'.$suffix);
        $disabled = (new Competition())
            ->setGame($disabledGame)
            ->setName('Disabled Game Competition '.$suffix)
            ->setSlug('disabled-game-competition-'.$suffix)
            ->open();
        $disabledGame->setEnabled(false);

        foreach ([$publicGame, $disabledGame, $public, $private, $draft, $disabled] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $publicId = $public->getId();
        $privateId = $private->getId();
        $draftId = $draft->getId();
        $disabledId = $disabled->getId();
        if ($publicId === null || $privateId === null || $draftId === null || $disabledId === null) {
            throw new \LogicException('Competition visibility fixture did not receive database identifiers.');
        }

        return [
            'publicId' => $publicId,
            'publicName' => $publicName,
            'hiddenIds' => [$privateId, $draftId, $disabledId],
        ];
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
