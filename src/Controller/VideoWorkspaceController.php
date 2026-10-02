<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoWorkspace\VideoSource;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoWorkspace\PlaybackAccess;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\WorkspaceInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-workspace')]
final class VideoWorkspaceController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VideoModuleAvailability $module, private readonly PlaybackAccess $access, private readonly ProviderRegistry $providers, private readonly \App\Service\VideoEmbedResolver $legacy) {}

    #[Route('', name: 'app_video_workspace', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->enabled();
        try { $page = WorkspaceInput::page($request); } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        $viewer = $this->viewer();
        $candidates = $this->em->getRepository(Video::class)->createQueryBuilder('v')->where('v.enabled = :enabled AND v.publishedAt <= :now')->setParameter('enabled', true)->setParameter('now', new \DateTimeImmutable())->orderBy('v.id', 'DESC')->setFirstResult(($page - 1) * 20)->setMaxResults(20)->getQuery()->getResult();
        $videos = [];
        foreach ($candidates as $video) { if ($video instanceof Video && $this->access->video($video, $viewer)) { $videos[] = $video; } }
        $streams = array_values(array_filter($this->em->getRepository(VideoSource::class)->findBy(['video' => null, 'enabled' => true, 'authorized' => true], ['id' => 'DESC'], 20, ($page - 1) * 20), fn (VideoSource $source): bool => $this->access->source($source, $viewer)));
        return $this->privateResponse($this->render('video_workspace/public.html.twig', compact('videos', 'streams', 'page')));
    }
    #[Route('/videos/{slug}', name: 'app_video_workspace_video', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function video(string $slug, Request $request): Response
    {
        $this->enabled(); $viewer = $this->viewer();
        $video = strlen($slug) <= 200 ? $this->em->getRepository(Video::class)->findOneBy(['slug' => $slug]) : null;
        if (!$video instanceof Video || !$this->access->video($video, $viewer)) { throw $this->createNotFoundException(); }
        $sources = array_values(array_filter($this->em->getRepository(VideoSource::class)->findBy(['video' => $video, 'enabled' => true, 'authorized' => true], ['position' => 'ASC', 'id' => 'ASC'], 100), fn (VideoSource $source): bool => $this->access->source($source, $viewer)));
        $playback = null; $selected = null;
        if ($request->isMethod('POST')) {
            $raw = $request->request->getString('source');
            if ($raw === 'legacy') {
                if (!$this->isCsrfTokenValid('workspace-legacy-'.$video->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
                $playback = $this->legacy->resolve($video, $request->getHost());
                if ($video->getSourceType() === Video::SOURCE_EXTERNAL && $video->getSourceUrl() !== null) {
                    foreach (['hls', 'mp4', 'webm'] as $format) { $direct = $this->providers->resolve($format, $video->getSourceUrl(), $request->getHost()); if ($direct !== null) { $playback = $direct; break; } }
                }
            } else {
            if (preg_match('/\A[1-9][0-9]{0,8}\z/', $raw) !== 1) { throw $this->createNotFoundException(); }
            foreach ($sources as $source) { if ($source->getId() === (int) $raw) { $selected = $source; break; } }
            if (!$selected instanceof VideoSource) { throw $this->createNotFoundException(); }
            $this->consent($request, $selected); $playback = $this->providers->resolve($selected->getProvider(), $selected->getUrl(), $request->getHost());
            }
        }
        return $this->privateResponse($this->render('video_workspace/playback.html.twig', ['video' => $video, 'sources' => $sources, 'selected' => $selected, 'playback' => $playback, 'title' => $video->getTitle()]));
    }
    #[Route('/live/{id}', name: 'app_video_workspace_live', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function live(VideoSource $source, Request $request): Response
    {
        $this->enabled();
        if ($source->getVideo() !== null || !$this->access->source($source, $this->viewer())) { throw $this->createNotFoundException(); }
        $playback = null;
        if ($request->isMethod('POST')) { $this->consent($request, $source); $playback = $this->providers->resolve($source->getProvider(), $source->getUrl(), $request->getHost()); }
        return $this->privateResponse($this->render('video_workspace/playback.html.twig', ['video' => null, 'sources' => [$source], 'selected' => $request->isMethod('POST') ? $source : null, 'playback' => $playback, 'title' => $source->getLabel()]));
    }
    private function consent(Request $request, VideoSource $source): void { if (!$this->isCsrfTokenValid('workspace-play-'.$source->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); } }
    private function viewer(): ?User { $user = $this->getUser(); return $user instanceof User && $user->isActive() && !$user->isLocked() ? $user : null; }
    private function enabled(): void { if (!$this->module->enabled()) { throw $this->createNotFoundException(); } }
    private function privateResponse(Response $response): Response { $response->headers->set('Cache-Control', 'private, no-store'); return $response; }
}
