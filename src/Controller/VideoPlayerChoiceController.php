<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoWorkspace\VideoSource;
use App\Service\VideoEmbedResolver;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoPlayerChoice\IntegrationCatalogue;
use App\VideoPlayerChoice\PlaybackChoice;
use App\VideoWorkspace\PlaybackAccess;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\WorkspaceInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-players')]
final class VideoPlayerChoiceController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VideoModuleAvailability $module,
        private readonly PlaybackAccess $access, private readonly ProviderRegistry $providers,
        private readonly VideoEmbedResolver $legacy, private readonly PlaybackChoice $choices) {}

    #[Route('', name: 'app_video_player_choice', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->enabled();
        try { $page = WorkspaceInput::page($request); } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        $candidates = $this->em->getRepository(Video::class)->createQueryBuilder('v')->where('v.enabled = :enabled AND v.publishedAt <= :now')
            ->setParameter('enabled', true)->setParameter('now', new \DateTimeImmutable())->orderBy('v.id', 'DESC')
            ->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();
        $viewer = $this->viewer(); $videos = [];
        foreach ($candidates as $video) { if ($video instanceof Video && $this->access->video($video, $viewer)) { $videos[] = $video; } }
        $streams = array_values(array_filter($this->em->getRepository(VideoSource::class)->findBy(['video' => null, 'enabled' => true, 'authorized' => true], ['id' => 'DESC'], 20, ($page - 1) * 20), fn (VideoSource $s): bool => $this->access->source($s, $viewer)));
        return $this->privateResponse($this->render('video_player_choice/index.html.twig', compact('videos', 'streams', 'page')));
    }

    #[Route('/integrations', name: 'app_video_player_integrations', methods: ['GET'])]
    public function integrations(IntegrationCatalogue $catalogue): Response
    {
        $this->enabled(); $this->denyAccessUnlessGranted('CMS_VIDEO_MANAGE');
        return $this->privateResponse($this->render('video_player_choice/integrations.html.twig', ['integrations' => $catalogue->entries()]));
    }

    #[Route('/videos/{slug}', name: 'app_video_player_choice_video', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function video(string $slug, Request $request): Response
    {
        $this->enabled();
        $video = strlen($slug) <= 200 ? $this->em->getRepository(Video::class)->findOneBy(['slug' => $slug]) : null;
        if (!$video instanceof Video || !$this->access->video($video, $this->viewer())) { throw $this->createNotFoundException(); }
        $sources = array_values(array_filter($this->em->getRepository(VideoSource::class)->findBy(['video' => $video, 'enabled' => true, 'authorized' => true], ['position' => 'ASC', 'id' => 'ASC'], 100), fn (VideoSource $s): bool => $this->access->source($s, $this->viewer())));
        $entries = [];
        $original = $this->legacy->resolve($video, $request->getHost());
        if ($video->getSourceType() === Video::SOURCE_EXTERNAL && $video->getSourceUrl() !== null) {
            foreach (['hls', 'mp4', 'webm'] as $format) {
                $direct = $this->providers->resolve($format, $video->getSourceUrl(), $request->getHost());
                if ($direct !== null) { $original = $direct; break; }
            }
        }
        if ($original !== null) { $entries['legacy'] = ['label' => 'Originalquelle', 'playback' => $original]; }
        foreach ($sources as $source) {
            $entries[(string) $source->getId()] = ['label' => $source->getLabel(), 'playback' => $this->providers->resolve($source->getProvider(), $source->getUrl(), $request->getHost())];
        }
        return $this->play($request, 'video-'.$video->getId(), $video->getTitle(), $entries, $video);
    }

    #[Route('/live/{id}', name: 'app_video_player_choice_live', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function live(VideoSource $source, Request $request): Response
    {
        $this->enabled();
        if ($source->getVideo() !== null || !$this->access->source($source, $this->viewer())) { throw $this->createNotFoundException(); }
        $entries = [(string) $source->getId() => ['label' => $source->getLabel(), 'playback' => $this->providers->resolve($source->getProvider(), $source->getUrl(), $request->getHost())]];
        return $this->play($request, 'live-'.$source->getId(), $source->getLabel(), $entries, null);
    }

    /** @param array<int|string,array{label:string,playback:array{mode:string,url:string}|null}> $entries */
    private function play(Request $request, string $identity, string $title, array $entries, ?Video $video): Response
    {
        $playback = null; $engine = null; $selected = null; $options = null; $rows = [];
        foreach ($entries as $id => $entry) { $rows[] = ['id' => (string) $id, 'label' => $entry['label'], 'engines' => $this->choices->engines($entry['playback'])]; }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('player-choice-'.$identity, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            $selected = $request->request->getString('source');
            if (!isset($entries[$selected])) { throw $this->createNotFoundException(); }
            $candidate = $entries[$selected]['playback'];
            if ($candidate === null) { throw new UnprocessableEntityHttpException('Diese Quelle ist momentan nicht verfügbar.'); }
            $engine = $request->request->getString('engine');
            try {
                $options = $this->choices->options($request); $playback = $this->choices->select($candidate, $engine, $options);
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        }
        return $this->privateResponse($this->render('video_player_choice/playback.html.twig', ['title' => $title, 'identity' => $identity,
            'sources' => $rows, 'selected' => $selected, 'playback' => $playback, 'engine' => $engine, 'options' => $options, 'video' => $video]));
    }
    private function viewer(): ?User { $user = $this->getUser(); return $user instanceof User && $user->isActive() && !$user->isLocked() ? $user : null; }
    private function enabled(): void { if (!$this->module->enabled()) { throw $this->createNotFoundException(); } }
    private function privateResponse(Response $response): Response { $response->headers->set('Cache-Control', 'private, no-store'); return $response; }
}
