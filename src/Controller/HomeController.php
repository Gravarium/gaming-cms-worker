<?php

namespace App\Controller;

use App\Layout\LayoutStore;
use App\Layout\LayoutRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(LayoutStore $layouts, LayoutRenderer $renderer): Response
    {
        return $this->render('home/index.html.twig', [
            'portal' => $renderer->view($layouts->load('home')),
        ]);
    }
}
