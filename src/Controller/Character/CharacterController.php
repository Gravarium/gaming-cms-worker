<?php

declare(strict_types=1);

namespace App\Controller\Character;

use App\Entity\GameCharacter\CharacterProfile;
use App\Entity\User;
use App\Gaming\Character\CharacterVisibilityPolicy;
use App\Module\CmsModuleManager;
use App\Repository\GameCharacter\CharacterProfileRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gaming/characters')]
final class CharacterController extends AbstractController
{
    public function __construct(
        private readonly CharacterProfileRepository $profiles,
        private readonly CharacterVisibilityPolicy $visibility,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_character_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();

        return $this->render('character/index.html.twig', [
            'profiles' => $this->profiles->publicProfiles(),
        ]);
    }

    #[Route('/{id}', name: 'app_character_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $this->assertAvailable();
        $profile = $this->profiles->find($id);
        if (!$profile instanceof CharacterProfile) {
            throw $this->createNotFoundException();
        }

        $viewer = $this->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;
        if (!$this->visibility->canViewProfile($profile, $viewer)) {
            throw $this->createNotFoundException();
        }

        return $this->render('character/show.html.twig', [
            'profile' => $profile,
            'fields' => $this->visibility->visibleFields($profile, $viewer),
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}
