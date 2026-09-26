<?php

declare(strict_types=1);
namespace App\Widget;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\Guild;
use App\Entity\Video;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\GuildRepository;
use App\Repository\VideoRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class BundledWidgetProvider implements WidgetProvider
{
    public function __construct(private ContentEntryRepository $news, private CategoryRepository $categories, private VideoRepository $videos, private GuildRepository $guilds, private RequestStack $requests) {}
    public function definitions(): array
    {
        return [
            new WidgetDefinition('core.welcome','Begrüßung / Hero','core','widget/welcome.html.twig',[],false,['imageId'=>['label'=>'Hero-Bild aus der Mediathek','type'=>'int','default'=>0,'min'=>0,'max'=>2147483647]]),
            new WidgetDefinition('media.image','Bild / Banner','media','widget/image.html.twig',[],true,['imageId'=>['label'=>'Bild aus der Mediathek','type'=>'int','default'=>0,'min'=>0,'max'=>2147483647]]),
            new WidgetDefinition('core.text','Textblock','core','widget/text.html.twig'),
            new WidgetDefinition('content.news','Aktuelle News','content','widget/news.html.twig',[],true,['categoryId'=>['label'=>'News-Kategorie','type'=>'int','default'=>0,'min'=>0,'max'=>2147483647]]),
            new WidgetDefinition('content.featured','Hervorgehobene News','content','widget/news.html.twig'),
            new WidgetDefinition('video.latest','Aktuelle Videos','video','widget/videos.html.twig'),
            new WidgetDefinition('gaming.guilds','Gilden entdecken','gaming','widget/guilds.html.twig'),
            new WidgetDefinition('gaming.recruitment','Gilden suchen Mitglieder','gaming','widget/guilds.html.twig'),
        ];
    }
    public function data(string $key, array $config): array
    {
        $request = $this->requests->getCurrentRequest();
        $categoryId = 0;
        if ($key === 'content.news' && array_key_exists('categoryId', $config)) {
            $value = $config['categoryId'];
            if (!is_int($value) || $value < 0 || $value > 2147483647) {
                return ['items' => []];
            }
            $categoryId = $value;
        }
        $cacheKey = '_cms_widget_data_'.$key.($key === 'content.news' ? '_'.$categoryId : '');
        $cached = $request?->attributes->get($cacheKey);
        if (!is_array($cached)) {
            $cached = match ($key) {
                'content.news'=>$this->publishedNews($categoryId),
                'content.featured'=>$this->news->findFeaturedNews(12),
                'video.latest'=>$this->publicVideos(),
                'gaming.guilds'=>$this->publicGuilds(false),
                'gaming.recruitment'=>$this->publicGuilds(true),
                default=>[],
            };
            $request?->attributes->set($cacheKey,$cached);
        }
        $count = $config['count'] ?? 6;
        return ['items'=>array_slice($cached,0,is_int($count)?$count:6)];
    }
    /** @return list<ContentEntry> */
    private function publishedNews(int $categoryId): array
    {
        if ($categoryId === 0) {
            return $this->news->findPublishedNews(12);
        }

        $category = $this->categories->find($categoryId);
        if (!$category instanceof Category) {
            return [];
        }

        return $this->news->findPublishedNews(12, 0, $category);
    }

    /** @return list<Video> */
    private function publicVideos(): array
    {
        return $this->videos->createQueryBuilder('v')->andWhere('v.enabled = true')->andWhere('v.publishedAt <= :now')->setParameter('now',new \DateTimeImmutable())->orderBy('v.publishedAt','DESC')->addOrderBy('v.id','DESC')->setMaxResults(12)->getQuery()->getResult();
    }
    /** @return list<Guild> */
    private function publicGuilds(bool $recruitment): array
    {
        $q=$this->guilds->createQueryBuilder('g')->addSelect('game')->join('g.game','game')->andWhere('g.enabled = true')->andWhere('game.enabled = true')->orderBy('g.name','ASC')->addOrderBy('g.id','ASC')->setMaxResults(12);
        if ($recruitment) $q->andWhere('g.recruitmentOpen = true');
        return $q->getQuery()->getResult();
    }
}
