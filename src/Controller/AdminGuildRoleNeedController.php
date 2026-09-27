<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use App\Form\GuildRoleNeedInput;
use App\Form\GuildRoleNeedType;
use App\Module\CmsModuleManager;
use App\Repository\GuildRoleNeedRepository;
use App\Security\CmsPermission;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/role-needs')]
#[IsGranted(CmsPermission::GAMING)]
final class AdminGuildRoleNeedController extends AbstractController
{
    public function __construct(
        private readonly GuildRoleNeedRepository $needs,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_admin_guild_role_need_index', methods: ['GET'])]
    public function index(Guild $guild): Response
    {
        $this->ensureGamingEnabled();

        return $this->manager($guild);
    }

    #[Route('/new', name: 'app_admin_guild_role_need_create', methods: ['POST'])]
    public function create(Guild $guild, Request $request): Response
    {
        $this->ensureGamingEnabled();
        $game = $guild->getGame();
        if ($game === null) {
            throw $this->createNotFoundException();
        }

        $input = new GuildRoleNeedInput();
        $form = $this->createForm(GuildRoleNeedType::class, $input)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $roleKey = trim($input->roleKey);
            $classKey = trim($input->classKey);
            if ($this->needs->identityExists($guild, $game, $roleKey, $classKey)) {
                $form->get('roleKey')->addError(new FormError('Dieser Rollen- und Klassenbedarf ist bereits angelegt.'));

                return $this->manager($guild, $form->createView(), responseStatus: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $need = (new GuildRoleNeed($guild, $game, $roleKey, $classKey))
                ->setDesiredCount($input->desiredCount)
                ->setActive($input->active);

            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($need): void {
                $entityManager->persist($need);
                $entityManager->flush();
                $identifier = $entityManager->getClassMetadata(GuildRoleNeed::class)->getIdentifierValues($need);
                $this->audit->record('guild_role_need.created', $need, isset($identifier['id']) ? (int) $identifier['id'] : null, 'Rollenbedarf angelegt.', [
                    'guild' => $need->getGuild()->getName(),
                    'role' => $need->getRoleKey(),
                    'class' => $need->getClassKey(),
                    'desired_count' => $need->getDesiredCount(),
                    'active' => $need->isActive(),
                ]);
            });

            $this->addFlash('success', 'Der Rollenbedarf wurde angelegt.');

            return $this->redirectToRoute('app_admin_guild_role_need_index', ['guild' => $guild->getId()]);
        }

        return $this->manager(
            $guild,
            $form->createView(),
            responseStatus: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    #[Route('/{needId}/edit', name: 'app_admin_guild_role_need_edit', requirements: ['needId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Guild $guild, int $needId, Request $request): Response
    {
        $this->ensureGamingEnabled();
        $need = $this->needs->find($needId);
        if (!$need instanceof GuildRoleNeed || $need->getGuild()->getId() !== $guild->getId()) {
            throw $this->createNotFoundException();
        }
        $row = $this->needs->findAdminRow($needId);
        if ($row === null) {
            throw $this->createNotFoundException();
        }

        $input = GuildRoleNeedInput::fromNeed($need);
        $form = $this->createForm(GuildRoleNeedType::class, $input, ['identity_locked' => true])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($need, $needId, $input): void {
                $need->setDesiredCount($input->desiredCount)->setActive($input->active);
                $entityManager->persist($need);
                $this->audit->record('guild_role_need.updated', $need, $needId, 'Rollenbedarf aktualisiert.', [
                    'guild' => $need->getGuild()->getName(),
                    'role' => $need->getRoleKey(),
                    'class' => $need->getClassKey(),
                    'desired_count' => $need->getDesiredCount(),
                    'active' => $need->isActive(),
                ]);
            });

            $this->addFlash('success', 'Der Rollenbedarf wurde gespeichert.');

            return $this->redirectToRoute('app_admin_guild_role_need_index', ['guild' => $guild->getId()]);
        }

        $response = $this->manager($guild, editNeed: $row, editForm: $form->createView());
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/{needId}/delete', name: 'app_admin_guild_role_need_delete', requirements: ['needId' => '\d+'], methods: ['POST'])]
    public function delete(Guild $guild, int $needId, Request $request): Response
    {
        $this->ensureGamingEnabled();
        $need = $this->needs->find($needId);
        if (!$need instanceof GuildRoleNeed || $need->getGuild()->getId() !== $guild->getId()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('delete-guild-role-need-'.$needId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($need, $needId): void {
            $entityManager->remove($need);
            $this->audit->record('guild_role_need.deleted', $need, $needId, 'Rollenbedarf gelöscht.', [
                'guild' => $need->getGuild()->getName(),
                'role' => $need->getRoleKey(),
                'class' => $need->getClassKey(),
            ]);
        });

        $this->addFlash('success', 'Der Rollenbedarf wurde gelöscht.');

        return $this->redirectToRoute('app_admin_guild_role_need_index', ['guild' => $guild->getId()]);
    }

    /** @param array{id:int, roleKey:string, classKey:string, desiredCount:int, active:bool}|null $editNeed */
    private function manager(
        Guild $guild,
        ?FormView $createForm = null,
        ?array $editNeed = null,
        ?FormView $editForm = null,
        int $responseStatus = Response::HTTP_OK,
    ): Response {
        return $this->render('admin/gaming/role_needs.html.twig', [
            'guild' => $guild,
            'needs' => $this->needs->findAdminRows($guild),
            'createForm' => $createForm ?? $this->createForm(GuildRoleNeedType::class, new GuildRoleNeedInput())->createView(),
            'editNeed' => $editNeed,
            'editForm' => $editForm,
        ], new Response(status: $responseStatus));
    }

    private function ensureGamingEnabled(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}
