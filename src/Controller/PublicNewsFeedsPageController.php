<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsFeedsPageController extends AbstractController
{
    #[Route('/news/feeds', name: 'app_news_feeds', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('content/news_feeds.html.twig');
    }
}
