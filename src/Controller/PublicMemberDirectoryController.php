<?php

declare(strict_types=1);

namespace App\Controller;

use App\Profile\ProfileModuleAvailability;
use App\ProfileDirectory\PublicMemberDirectoryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicMemberDirectoryController extends AbstractController
{
    public function __construct(
        private readonly ProfileModuleAvailability $availability,
        private readonly PublicMemberDirectoryQuery $directory,
    ) {
    }

    #[Route('/members', name: 'app_public_member_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }

        $rawPage = $request->query->get('page', '1');
        $page = filter_var($rawPage, FILTER_VALIDATE_INT);
        if (!is_int($page) || $page < 1) {
            throw $this->createNotFoundException('Die angeforderte Verzeichnisseite ist ungültig.');
        }

        try {
            $directory = $this->directory->page($page);
        } catch (\OutOfRangeException $exception) {
            throw $this->createNotFoundException('Diese Seite ist im Mitgliederverzeichnis nicht vorhanden.', $exception);
        }

        return $this->render('public_member_directory/directory.html.twig', $directory);
    }
}
