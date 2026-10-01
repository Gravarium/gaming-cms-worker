<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoPlaybackJourney\ViewingSources;
use App\VideoPlayerBranding\BrandingOptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class VideoPlayerBrandingController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly VideoModuleAvailability $module,
        private readonly ViewingSources $sources, private readonly BrandingOptions $branding) {}

    #[Route('/admin/video-branding/videos/{slug}', name: 'app_admin_video_branding_preview',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET', 'POST'])]
    public function preview(string $slug, Request $request): Response
    {
        if (!$this->module->enabled()) { throw $this->createNotFoundException(); }
        $this->denyAccessUnlessGranted('CMS_VIDEO_MANAGE');
        $video = strlen($slug) <= 200 ? $this->em->getRepository(Video::class)->findOneBy(['slug' => $slug]) : null;
        if (!$video instanceof Video || !$this->sources->visible($video, null)) { throw $this->createNotFoundException(); }

        // The preview uses the public source view. It cannot expose a private creator or unpublished video.
        $entries = $this->sources->entries($video, null, $request->getHost());
        $rows = [];
        foreach ($entries as $id => $entry) {
            if ($entry['playback'] === null) { continue; }
            $rows[] = ['id' => (string) $id, 'label' => $entry['label'], 'direct' => in_array($entry['playback']['mode'], ['video', 'hls'], true)];
        }
        $playback = null; $settings = null; $selected = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('video-branding-'.$video->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $selected = $request->request->getString('source');
            if (!isset($entries[$selected])) { throw $this->createNotFoundException(); }
            $playback = $entries[$selected]['playback'];
            if ($playback === null) { throw new UnprocessableEntityHttpException('Diese Quelle ist nicht verfügbar.'); }
            try { $settings = $this->branding->parse($request, $playback['mode']); }
            catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        }
        $response = $this->render('video_player_branding/preview.html.twig', [
            'video' => $video, 'sources' => $rows, 'selected' => $selected,
            'playback' => $playback, 'settings' => $settings,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
