<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ContentTagRepository;
use App\Repository\SiteSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentTagDirectoryController extends AbstractController
{
    public function __construct(
        private readonly ContentTagRepository $tags,
        private readonly SiteSettingsRepository $siteSettings,
    )
    {
    }

    #[Route('/news/tags', name: 'app_news_tag_directory', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('content_tag/index.html.twig', [
            'tags' => $this->tags->findBy([], ['name' => 'ASC', 'id' => 'ASC']),
            'site' => $this->siteSettings->current(),
        ]);
    }
}
