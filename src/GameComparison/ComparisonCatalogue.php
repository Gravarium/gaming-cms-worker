<?php

declare(strict_types=1);

namespace App\GameComparison;

use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameRelease;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ComparisonCatalogue
{
    public function __construct(
        private GameCatalogueEntryRepository $entries,
        private EntityManagerInterface $em,
    ) {}

    /** @return list<array{entry: GameCatalogueEntry, releases: list<GameRelease>}> */
    public function compare(string $selection): array
    {
        if (strlen($selection) > 422 || !mb_check_encoding($selection, 'UTF-8')) {
            throw new \InvalidArgumentException('Ungültige Spieleauswahl.');
        }

        $slugs = array_map('trim', explode(',', $selection));
        if (count($slugs) < 2 || count($slugs) > 3 || count(array_unique($slugs)) !== count($slugs)) {
            throw new \InvalidArgumentException('Bitte zwei oder drei unterschiedliche Spiele wählen.');
        }

        $result = [];
        foreach ($slugs as $slug) {
            if (strlen($slug) > 140 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
                throw new \InvalidArgumentException('Ungültiger Spiel-Slug.');
            }

            $entry = $this->entries->publicBySlug($slug);
            if (!$entry instanceof GameCatalogueEntry) {
                throw new \InvalidArgumentException('Spiel nicht öffentlich verfügbar.');
            }

            /** @var list<GameRelease> $releases */
            $releases = $this->em->getRepository(GameRelease::class)->createQueryBuilder('release')
                ->andWhere('release.entry = :entry')
                ->andWhere('release.status != :cancelled')
                ->setParameter('entry', $entry)
                ->setParameter('cancelled', 'cancelled')
                ->orderBy('release.releaseAt', 'DESC')
                ->addOrderBy('release.id', 'DESC')
                ->setMaxResults(5)
                ->getQuery()->getResult();

            $result[] = ['entry' => $entry, 'releases' => $releases];
        }

        return $result;
    }
}
