<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameReleaseFeedsPageController extends AbstractController
{
    public function __construct(private readonly CmsModuleManager $modules)
    {
    }

    #[Route('/games/releases/feeds', name: 'app_game_catalogue_release_feeds', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_catalogue/release_feeds.html.twig');
    }
}
