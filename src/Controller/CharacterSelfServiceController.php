<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
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

#[Route('/account/characters', name: 'app_character_owner_')]
#[IsGranted('ROLE_USER')]
final class CharacterSelfServiceController extends AbstractController
{
    public function __construct(
        private readonly CharacterAccountRepository $accounts,
        private readonly CharacterProfileRepository $profiles,
        private readonly GameRepository $games,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();
        $owner = $this->owner();

        return $this->privateResponse($this->render('account/characters/index.html.twig', [
            'accounts' => $this->accounts->activeForOwner($owner),
            'profiles' => $this->profiles->activeForOwner($owner),
        ]));
    }

    #[Route('/accounts/new', name: 'account_new', methods: ['GET', 'POST'])]
    public function newAccount(Request $request): Response
    {
        $this->assertAvailable();
        $owner = $this->owner();
        $error = null;
        $games = $this->games->findBy(['enabled' => true], ['name' => 'ASC']);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'character-owner-account-new');
            $game = $this->games->find($request->request->getInt('game_id'));
            $key = trim($request->request->getString('account_key'));
            $name = trim($request->request->getString('display_name'));
            if (!$game instanceof Game || !$game->isEnabled()) {
                $error = 'Wähle ein verfügbares Spiel aus.';
            } elseif ($request->request->getString('consent') !== '1') {
                $error = 'Bitte bestätige die Speicherung deines Charakteraccounts.';
            } elseif ($this->accounts->findOneBy(['owner' => $owner, 'game' => $game, 'accountKey' => $key]) !== null) {
                $error = 'Dieser Account-Schlüssel wird für das Spiel bereits verwendet.';
            } else {
                try {
                    $account = (new CharacterAccount($owner, $game, $key, $name))->grantConsent();
                    $this->entityManager->persist($account);
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Dein Charakteraccount wurde angelegt.');

                    return $this->privateResponse($this->redirectToRoute('app_character_owner_index'));
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->privateResponse($this->render('account/characters/account_form.html.twig', [
            'account' => null,
            'games' => $games,
            'error' => $error,
            'values' => ['game_id' => $request->request->getInt('game_id'), 'display_name' => $request->request->getString('display_name')],
        ], $error === null ? new Response() : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    #[Route('/accounts/{id}/edit', name: 'account_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function editAccount(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $account = $this->account($this->owner(), $id);
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'character-owner-account-edit-'.$id);
            try {
                $account->setDisplayName($request->request->getString('display_name'));
                $this->entityManager->flush();
                $this->addFlash('success', 'Der Accountname wurde aktualisiert.');

                return $this->privateResponse($this->redirectToRoute('app_character_owner_index'));
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->privateResponse($this->render('account/characters/account_form.html.twig', [
            'account' => $account,
            'games' => [],
            'error' => $error,
            'values' => ['display_name' => $request->isMethod('POST') ? $request->request->getString('display_name') : $account->getDisplayName()],
        ], $error === null ? new Response() : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    #[Route('/accounts/{id}/delete', name: 'account_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteAccount(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $owner = $this->owner();
        $account = $this->account($owner, $id);
        $this->assertCsrf($request, 'character-owner-account-delete-'.$id);
        $account->revokeConsent()->markDeleted();
        $this->entityManager->flush();
        $this->addFlash('success', 'Der Charakteraccount und seine Profile sind nicht mehr sichtbar.');

        return $this->privateResponse($this->redirectToRoute('app_character_owner_index'));
    }

    #[Route('/profiles/new', name: 'profile_new', methods: ['GET', 'POST'])]
    public function newProfile(Request $request): Response
    {
        $this->assertAvailable();
        $owner = $this->owner();
        $accounts = array_values(array_filter(
            $this->accounts->activeForOwner($owner),
            static fn (CharacterAccount $account): bool => $account->getGame()->isEnabled(),
        ));
        $error = null;
        $values = $this->profileValues($request);

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'character-owner-profile-new');
            $account = null;
            foreach ($accounts as $candidate) {
                if ($candidate->getId() === $request->request->getInt('account_id')) {
                    $account = $candidate;
                    break;
                }
            }
            if (!$account instanceof CharacterAccount) {
                $error = 'Wähle einen eigenen aktiven Charakteraccount aus.';
            } else {
                try {
                    $this->validateProfileValues($values);
                    $profile = new CharacterProfile($account, $values['name']);
                    $this->applyProfileValues($profile, $values);
                    $this->entityManager->persist($profile);
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Das private Charakterprofil wurde angelegt.');

                    return $this->privateResponse($this->redirectToRoute('app_character_owner_index'));
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->profileForm(null, $accounts, $values, $error);
    }

    #[Route('/profiles/{id}/edit', name: 'profile_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function editProfile(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $owner = $this->owner();
        $profile = $this->profile($owner, $id);
        if (!$profile->isManual()) {
            throw $this->createNotFoundException();
        }

        $error = null;
        $values = $request->isMethod('POST') ? $this->profileValues($request) : [
            'name' => $profile->getName(),
            'server' => $profile->getServer() ?? '',
            'region' => $profile->getRegion() ?? '',
            'character_class' => $profile->getCharacterClass() ?? '',
            'role' => $profile->getRole() ?? '',
            'level' => $profile->getLevel() === null ? '' : (string) $profile->getLevel(),
        ];
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'character-owner-profile-edit-'.$id);
            try {
                $this->validateProfileValues($values);
                $this->applyProfileValues($profile, $values);
                $this->entityManager->flush();
                $this->addFlash('success', 'Das Charakterprofil wurde aktualisiert.');

                return $this->privateResponse($this->redirectToRoute('app_character_owner_index'));
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->profileForm($profile, [], $values, $error);
    }

    #[Route('/profiles/{id}/delete', name: 'profile_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function deleteProfile(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $profile = $this->profile($this->owner(), $id);
        $this->assertCsrf($request, 'character-owner-profile-delete-'.$id);
        $profile->markDeleted();
        $this->entityManager->flush();
        $this->addFlash('success', 'Das Charakterprofil wurde stillgelegt.');

        return $this->privateResponse($this->redirectToRoute('app_character_owner_index'));
    }

    private function account(User $owner, int $id): CharacterAccount
    {
        $account = $this->accounts->find($id);
        if (!$account instanceof CharacterAccount || $account->isDeleted() || !$account->isOwnedBy($owner) || !$account->getGame()->isEnabled()) {
            throw $this->createNotFoundException();
        }

        return $account;
    }

    private function profile(User $owner, int $id): CharacterProfile
    {
        $profile = $this->profiles->findActiveForOwner($owner, $id);
        if (!$profile instanceof CharacterProfile || !$profile->getGame()->isEnabled()) {
            throw $this->createNotFoundException();
        }

        return $profile;
    }

    /** @return array{name: string, server: string, region: string, character_class: string, role: string, level: string} */
    private function profileValues(Request $request): array
    {
        return [
            'name' => trim($request->request->getString('name')),
            'server' => trim($request->request->getString('server')),
            'region' => trim($request->request->getString('region')),
            'character_class' => trim($request->request->getString('character_class')),
            'role' => trim($request->request->getString('role')),
            'level' => trim($request->request->getString('level')),
        ];
    }

    /** @param array{name: string, server: string, region: string, character_class: string, role: string, level: string} $values */
    private function validateProfileValues(array $values): void
    {
        foreach (['name' => 160, 'server' => 120, 'region' => 80, 'character_class' => 100, 'role' => 80] as $field => $limit) {
            if (mb_strlen($values[$field]) > $limit || ($field === 'name' && $values[$field] === '')) {
                throw new \InvalidArgumentException('Name und Charakterfelder müssen ausgefüllt beziehungsweise innerhalb ihrer Längenbegrenzung bleiben.');
            }
        }
        if ($values['level'] !== '' && (preg_match('/^[1-9][0-9]{0,4}$/D', $values['level']) !== 1 || (int) $values['level'] > 10000)) {
            throw new \InvalidArgumentException('Die Stufe muss zwischen 1 und 10000 liegen.');
        }
    }

    /** @param array{name: string, server: string, region: string, character_class: string, role: string, level: string} $values */
    private function applyProfileValues(CharacterProfile $profile, array $values): void
    {
        $profile->setName($values['name'])
            ->setServer($values['server'] === '' ? null : $values['server'])
            ->setRegion($values['region'] === '' ? null : $values['region'])
            ->setCharacterClass($values['character_class'] === '' ? null : $values['character_class'])
            ->setRole($values['role'] === '' ? null : $values['role'])
            ->setLevel($values['level'] === '' ? null : (int) $values['level']);
    }

    /** @param list<CharacterAccount> $accounts
     *  @param array{name: string, server: string, region: string, character_class: string, role: string, level: string} $values
     */
    private function profileForm(?CharacterProfile $profile, array $accounts, array $values, ?string $error): Response
    {
        return $this->privateResponse($this->render('account/characters/profile_form.html.twig', [
            'profile' => $profile,
            'accounts' => $accounts,
            'values' => $values,
            'error' => $error,
        ], $error === null ? new Response() : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
