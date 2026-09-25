<?php

declare(strict_types=1);

namespace App\Controller\AdminCharacter;

use App\Entity\GameCharacter\CharacterAccount;
use App\Entity\GameCharacter\CharacterProfile;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Repository\GameCharacter\CharacterAccountRepository;
use App\Repository\GameCharacter\CharacterProfileRepository;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/characters')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCharacterController extends AbstractController
{
    public function __construct(
        private readonly CharacterAccountRepository $accounts,
        private readonly CharacterProfileRepository $profiles,
        private readonly GameRepository $games,
        private readonly EntityManagerInterface $entityManager,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_admin_character_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();

        return $this->render('admin/character/index.html.twig', [
            'accounts' => $this->accounts->findBy(['deletedAt' => null], ['displayName' => 'ASC']),
            'profiles' => $this->profiles->findBy(['deletedAt' => null], ['name' => 'ASC']),
        ]);
    }

    #[Route('/account/new', name: 'app_admin_character_account_new', methods: ['GET', 'POST'])]
    public function newAccount(Request $request): Response
    {
        $this->assertAvailable();
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $games = $this->games->findBy(['enabled' => true], ['name' => 'ASC']);
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('character-account-new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $game = $this->games->find($request->request->getInt('game_id'));
            $key = trim($request->request->getString('account_key'));
            $name = trim($request->request->getString('display_name'));
            if (!$game || !$game->isEnabled() || $key === '' || $name === '') {
                $error = 'Spiel, Account-Schlüssel und Anzeigename sind erforderlich.';
            } else {
                try {
                    $account = new CharacterAccount($actor, $game, $key, $name);
                    $account->grantConsent();
                    $this->entityManager->persist($account);
                    $this->entityManager->flush();

                    return $this->redirectToRoute('app_admin_character_index');
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->render('admin/character/account_new.html.twig', ['games' => $games, 'error' => $error]);
    }

    #[Route('/profile/new', name: 'app_admin_character_profile_new', methods: ['GET', 'POST'])]
    public function newProfile(Request $request): Response
    {
        $this->assertAvailable();
        $accounts = $this->accounts->findBy(['deletedAt' => null], ['displayName' => 'ASC']);
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('character-profile-new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $account = $this->accounts->find($request->request->getInt('account_id'));
            $name = trim($request->request->getString('name'));
            if (!$account instanceof CharacterAccount || $account->isDeleted() || $name === '') {
                $error = 'Account und Charaktername sind erforderlich.';
            } else {
                try {
                    $profile = (new CharacterProfile($account, $name))
                        ->setServer($request->request->getString('server') ?: null)
                        ->setRegion($request->request->getString('region') ?: null)
                        ->setCharacterClass($request->request->getString('character_class') ?: null)
                        ->setRole($request->request->getString('role') ?: null);
                    $level = $request->request->getInt('level');
                    if ($level > 0) {
                        $profile->setLevel($level);
                    }
                    $this->entityManager->persist($profile);
                    $this->entityManager->flush();

                    return $this->redirectToRoute('app_admin_character_index');
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->render('admin/character/profile_new.html.twig', ['accounts' => $accounts, 'error' => $error]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}
