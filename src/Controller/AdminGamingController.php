<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\ModuleStorageSetting;
use App\Form\GameType;
use App\Form\GuildType;
use App\Repository\GameRepository;
use App\Repository\GuildApplicationRepository;
use App\Repository\GuildRepository;
use App\Service\MediaStorageManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/gaming')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGamingController extends AbstractController
{
    private const MODULE_KEY = 'gaming';

    public function __construct(
        private readonly GameRepository $games,
        private readonly GuildRepository $guilds,
        private readonly GuildApplicationRepository $applications,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly MediaStorageManager $mediaStorage,
    ) {
    }

    #[Route('', name: 'app_admin_gaming_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/gaming/index.html.twig', [
            'games' => $this->games->findBy([], ['name' => 'ASC']),
            'guilds' => $this->guilds->findBy([], ['name' => 'ASC']),
            'pendingApplications' => $this->applications->count(['status' => 'pending']),
            'storageMode' => $this->mediaStorage->modeFor(self::MODULE_KEY),
        ]);
    }

    #[Route('/game/new', name: 'app_admin_game_new', methods: ['GET', 'POST'])]
    public function newGame(Request $request): Response
    {
        return $this->gameForm(new Game(), $request, 'Spiel anlegen');
    }

    #[Route('/game/{id}/edit', name: 'app_admin_game_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editGame(Game $game, Request $request): Response
    {
        return $this->gameForm($game, $request, 'Spiel bearbeiten');
    }

    #[Route('/game/{id}/delete', name: 'app_admin_game_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteGame(Game $game, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-game-'.$game->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->entityManager->remove($game);
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Spiel wurde gelöscht.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'Das Spiel kann erst gelöscht werden, wenn keine Gilde mehr zugeordnet ist.');
        }

        return $this->redirectToRoute('app_admin_gaming_index');
    }

    #[Route('/guild/new', name: 'app_admin_guild_new', methods: ['GET', 'POST'])]
    public function newGuild(Request $request): Response
    {
        return $this->guildForm(new Guild(), $request, 'Gilde oder Clan anlegen');
    }

    #[Route('/guild/{id}/edit', name: 'app_admin_guild_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editGuild(Guild $guild, Request $request): Response
    {
        return $this->guildForm($guild, $request, 'Gilde oder Clan bearbeiten');
    }

    #[Route('/guild/{id}/delete', name: 'app_admin_guild_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteGuild(Guild $guild, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-guild-'.$guild->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->entityManager->remove($guild);
        $this->entityManager->flush();
        $this->addFlash('success', 'Die Gilde wurde gelöscht.');

        return $this->redirectToRoute('app_admin_gaming_index');
    }

    private function gameForm(Game $game, Request $request, string $heading): Response
    {
        $form = $this->createForm(GameType::class, $game)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $game->setSlug($this->uniqueSlug($game->getName(), $game->getId(), true));
            if ($game->getId() === null) {
                $this->entityManager->persist($game);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Spiel wurde gespeichert.');

            return $this->redirectToRoute('app_admin_gaming_index');
        }

        return $this->render('admin/gaming/form.html.twig', ['form' => $form, 'heading' => $heading]);
    }

    private function guildForm(Guild $guild, Request $request, string $heading): Response
    {
        if ($this->games->count([]) === 0) {
            $this->addFlash('error', 'Lege zuerst mindestens ein Spiel an.');

            return $this->redirectToRoute('app_admin_game_new');
        }

        $form = $this->createForm(GuildType::class, $guild)->handleRequest($request);
        $mode = $this->mediaStorage->modeFor(self::MODULE_KEY);
        $externalReady = $this->mediaStorage->externalUploadReady();

        if ($form->isSubmitted()) {
            $logoFile = $form->get('logoFile')->getData();
            $logoUrl = trim((string) $form->get('logoUrl')->getData());
            if ($mode === ModuleStorageSetting::MODE_INTERNAL && $logoUrl !== '') {
                $form->get('logoUrl')->addError(new FormError('Dieses Modul speichert intern. Bitte das Logo hochladen.'));
            }
            if ($mode === ModuleStorageSetting::MODE_EXTERNAL && !$externalReady && $logoFile instanceof UploadedFile) {
                $form->get('logoFile')->addError(new FormError('Der automatische externe Upload ist noch nicht konfiguriert. Verwende vorläufig eine externe URL.'));
            }

            if ($form->isValid()) {
                try {
                    $guild->setSlug($this->uniqueSlug($guild->getName(), $guild->getId(), false));
                    if ($logoFile instanceof UploadedFile) {
                        $guild->setLogo($this->mediaStorage->storeUpload($logoFile, self::MODULE_KEY, $guild->getName()));
                    } elseif ($logoUrl !== '') {
                        $guild->setLogo($this->mediaStorage->storeExternal($logoUrl, self::MODULE_KEY, $guild->getName()));
                    }
                    if ($guild->getId() === null) {
                        $this->entityManager->persist($guild);
                    }
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Die Gilde wurde gespeichert.');

                    return $this->redirectToRoute('app_admin_gaming_index');
                } catch (\DomainException|\RuntimeException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('admin/gaming/form.html.twig', [
            'form' => $form,
            'heading' => $heading,
            'guild' => $guild,
            'storageMode' => $mode,
            'externalStorageReady' => $externalReady,
        ]);
    }

    private function uniqueSlug(string $name, ?int $exceptId, bool $game): string
    {
        $base = mb_strtolower($this->slugger->slug($name)->toString()) ?: ($game ? 'spiel' : 'gilde');
        $slug = $base;
        $number = 2;
        $exists = fn (string $candidate): bool => $game
            ? $this->games->slugExists($candidate, $exceptId)
            : $this->guilds->slugExists($candidate, $exceptId);
        while ($exists($slug)) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }
}
