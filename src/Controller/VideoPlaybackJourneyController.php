<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoPlaylist;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoPlaybackJourney\PlaylistViewing;
use App\VideoPlaybackJourney\ViewingSources;
use App\VideoPlayerChoice\PlaybackChoice;
use App\VideoWorkspace\WorkspaceInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-viewing')]
final class VideoPlaybackJourneyController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VideoModuleAvailability $module,
        private readonly ViewingSources $sources, private readonly PlaylistViewing $playlists, private readonly PlaybackChoice $choices) {}

    #[Route('', name: 'app_video_viewing', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->enabled();
        try { $page = WorkspaceInput::page($request); } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        $lists = $this->em->getRepository(VideoPlaylist::class)->findBy(['enabled' => true], ['id' => 'ASC'], 21, ($page - 1) * 20);
        return $this->response('video_playback_journey/index.html.twig', ['playlists' => array_slice($lists, 0, 20), 'page' => $page, 'more' => count($lists) > 20]);
    }

    #[Route('/videos/{slug}', name: 'app_video_viewing_video', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function video(string $slug, Request $request): Response
    {
        $this->enabled();
        return $this->play($this->findVideo($slug), $request, null);
    }

    #[Route('/playlists/{slug}', name: 'app_video_viewing_playlist', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function playlist(string $slug, Request $request): Response
    {
        $this->enabled();
        $playlist = strlen($slug) <= 180 ? $this->em->getRepository(VideoPlaylist::class)->findOneBy(['slug' => $slug, 'enabled' => true]) : null;
        if (!$playlist instanceof VideoPlaylist) { throw $this->createNotFoundException(); }
        $videoSlug = $request->isMethod('POST') ? $request->request->getString('video') : $request->query->getString('video');
        $video = $videoSlug === '' ? $this->playlists->adjacent($playlist, 0, $this->viewer()) : $this->findVideo($videoSlug);
        if ($video !== null && !$this->playlists->contains($playlist, $video)) { throw $this->createNotFoundException(); }
        if ($video === null) {
            return $this->response('video_playback_journey/empty.html.twig', ['playlist' => $playlist]);
        }
        return $this->play($video, $request, $playlist);
    }

    private function play(Video $video, Request $request, ?VideoPlaylist $playlist): Response
    {
        if (!$this->sources->visible($video, $this->viewer())) { throw $this->createNotFoundException(); }
        $entries = $this->sources->entries($video, $this->viewer(), $request->getHost());
        $token = $playlist === null ? 'viewing-video-'.$video->getId() : 'viewing-playlist-'.$playlist->getId();
        $playback = null; $engine = null; $options = null; $selected = null; $continuous = false; $resumeKey = '';
        $message = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $continuous = WorkspaceInput::flag($request, 'continuous');
                $options = $this->choices->options($request);
                $selected = $request->request->getString('source'); $engine = $request->request->getString('engine', 'native');
                if ($selected === 'auto') {
                    if (!$continuous || $playlist === null || !in_array($engine, ['native', 'videojs'], true)) { throw new \InvalidArgumentException('Bitte eine Quelle auswählen.'); }
                    $selected = null;
                    foreach ($entries as $id => $entry) {
                        if ($entry['playback'] !== null && in_array($entry['playback']['mode'], ['video', 'hls'], true)) { $selected = (string) $id; break; }
                    }
                    if ($selected === null) { $message = 'Für dieses Video gibt es keinen direkten Player. Bitte die Anbieterquelle ausdrücklich auswählen.'; }
                }
                if ($selected !== null) {
                    if (!isset($entries[$selected])) { throw $this->createNotFoundException(); }
                    $entry = $entries[$selected];
                    if ($entry['playback'] === null) { throw new \InvalidArgumentException('Quelle nicht nutzbar. Bitte eine andere Quelle wählen oder die Verwaltung informieren.'); }
                    $playback = $this->choices->select($entry['playback'], $engine, $options);
                    if ($entry['resumable'] && in_array($playback['mode'], ['video', 'hls'], true)) {
                        $resumeKey = hash('sha256', 'viewing:'.$video->getId().':'.$selected.':'.$entry['playback']['url']);
                    }
                }
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        }
        $rows = [];
        foreach ($entries as $id => $entry) { $rows[] = ['id' => (string) $id, 'label' => $entry['label'], 'engines' => $this->choices->engines($entry['playback'])]; }
        return $this->response('video_playback_journey/play.html.twig', ['video' => $video, 'playlist' => $playlist, 'sources' => $rows,
            'token' => $token, 'playback' => $playback, 'engine' => $engine, 'options' => $options, 'selected' => $selected,
            'continuous' => $continuous, 'resumeKey' => $resumeKey, 'message' => $message,
            'previous' => $playlist === null ? null : $this->playlists->adjacent($playlist, $video->getId() ?? 0, $this->viewer(), true),
            'next' => $playlist === null ? null : $this->playlists->adjacent($playlist, $video->getId() ?? 0, $this->viewer())]);
    }

    private function findVideo(string $slug): Video
    {
        $video = strlen($slug) <= 200 ? $this->em->getRepository(Video::class)->findOneBy(['slug' => $slug]) : null;
        if (!$video instanceof Video) { throw $this->createNotFoundException(); }
        return $video;
    }
    private function viewer(): ?User { $user = $this->getUser(); return $user instanceof User && $user->isActive() && !$user->isLocked() ? $user : null; }
    private function enabled(): void { if (!$this->module->enabled()) { throw $this->createNotFoundException(); } }
    /** @param array<string,mixed> $data */
    private function response(string $template, array $data): Response
    {
        $response = $this->render($template, $data); $response->headers->set('Cache-Control', 'private, no-store'); return $response;
    }
}
