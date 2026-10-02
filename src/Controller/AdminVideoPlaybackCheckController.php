<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Entity\VideoWorkspace\VideoSource;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoPlaybackJourney\ViewingSources;
use App\VideoPlayerChoice\PlaybackChoice;
use App\VideoWorkspace\ProviderRegistry;
use App\VideoWorkspace\WorkspaceInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-playback-check')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class AdminVideoPlaybackCheckController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VideoModuleAvailability $module,
        private readonly ViewingSources $viewing, private readonly ProviderRegistry $providers, private readonly PlaybackChoice $choices) {}

    #[Route('', name: 'app_admin_video_playback_check', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->enabled();
        try { $page = WorkspaceInput::page($request); } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        $videos = $this->em->getRepository(Video::class)->findBy([], ['id' => 'DESC'], 21, ($page - 1) * 20);
        return $this->response('video_playback_journey/admin_index.html.twig', ['videos' => array_slice($videos, 0, 20), 'page' => $page, 'more' => count($videos) > 20]);
    }

    #[Route('/videos/{id}', name: 'app_admin_video_playback_check_video', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function video(Video $video, Request $request): Response
    {
        $this->enabled(); $rows = []; $entries = [];
        $original = $this->viewing->original($video, $request->getHost());
        $rows[] = ['id' => 'legacy', 'label' => 'Originalquelle', 'engines' => $this->choices->engines($original),
            'issue' => $original === null ? 'Keine unterstützte Originalquelle. Videodatei zuordnen oder eine passende Anbieterquelle ergänzen.' : null, 'edit' => null];
        if ($original !== null) { $entries['legacy'] = $original; }
        foreach ($this->em->getRepository(VideoSource::class)->findBy(['video' => $video], ['position' => 'ASC', 'id' => 'ASC'], 101) as $source) {
            $resolved = $this->providers->resolve($source->getProvider(), $source->getUrl(), $request->getHost());
            $issue = !$source->isAuthorized() ? 'Quelle nicht freigegeben. Berechtigung in der Quellenverwaltung bestätigen.'
                : (!$source->isEnabled() ? 'Quelle deaktiviert. In der Quellenverwaltung aktivieren.'
                : ($resolved === null ? 'URL oder Anbieterformat nicht unterstützt. Den dokumentierten Link in der Quellenverwaltung prüfen.' : null));
            $id = (string) $source->getId();
            $rows[] = ['id' => $id, 'label' => $source->getLabel(), 'engines' => $issue === null ? $this->choices->engines($resolved) : [], 'issue' => $issue, 'edit' => $source->getId()];
            if ($issue === null && $resolved !== null) { $entries[$id] = $resolved; }
        }
        $playback = null; $engine = null; $options = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('video-check-'.$video->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            $source = $request->request->getString('source');
            if (!isset($entries[$source])) { throw $this->createNotFoundException(); }
            $engine = $request->request->getString('engine');
            try { $options = $this->choices->options($request); $playback = $this->choices->select($entries[$source], $engine, $options); }
            catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        }
        return $this->response('video_playback_journey/admin_video.html.twig', ['video' => $video, 'rows' => $rows,
            'publicVisible' => $this->viewing->publicVideo($video), 'playback' => $playback, 'engine' => $engine, 'options' => $options]);
    }

    private function enabled(): void { if (!$this->module->enabled()) { throw $this->createNotFoundException(); } }
    /** @param array<string,mixed> $data */
    private function response(string $template, array $data): Response
    {
        $response = $this->render($template, $data); $response->headers->set('Cache-Control', 'private, no-store'); return $response;
    }
}
