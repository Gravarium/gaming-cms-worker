<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Service\VideoEmbedResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/videos')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class AdminVideoPreviewController extends AbstractController
{
    public function __construct(private readonly VideoEmbedResolver $embedResolver)
    {
    }

    #[Route('/{id}/preview', name: 'app_admin_video_preview', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function preview(Video $video, Request $request): Response
    {
        $response = $this->render('admin/video/preview.html.twig', [
            'video' => $video,
            'player' => $this->embedResolver->resolve($video, $request->getHost()),
            'isPublic' => $video->isPublished(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
