<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Form\AccessRoleType;
use App\Repository\AccessRoleRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/access-roles')]
#[IsGranted('CMS_USERS_MANAGE')]
final class AdminAccessRoleController extends AbstractController
{
    public function __construct(
        private readonly AccessRoleRepository $roles,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {}

    #[Route('', name: 'app_admin_access_role_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/access_role/index.html.twig', ['roles' => $this->roles->findBy([], ['name' => 'ASC'])]);
    }

    #[Route('/new', name: 'app_admin_access_role_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $role = new AccessRole();
        return $this->handle($role, $request, 'Rolle anlegen');
    }

    #[Route('/{id}/edit', name: 'app_admin_access_role_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(AccessRole $role, Request $request): Response
    {
        return $this->handle($role, $request, 'Rolle bearbeiten');
    }

    #[Route('/{id}/delete', name: 'app_admin_access_role_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(AccessRole $role, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-access-role-'.$role->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        if ($role->isSystemRole() || !$role->getUsers()->isEmpty()) {
            $this->addFlash('error', 'Systemrollen und zugewiesene Rollen können nicht gelöscht werden.');
        } else {
            $this->audit->record('access_role.deleted', $role, $role->getId(), 'Berechtigungsrolle gelöscht.', ['key' => $role->getKey()]);
            $this->entityManager->remove($role);
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Rolle wurde gelöscht.');
        }

        return $this->redirectToRoute('app_admin_access_role_index');
    }

    private function handle(AccessRole $role, Request $request, string $heading): Response
    {
        $new = $role->getId() === null;
        $oldKey = $role->getKey();
        $oldPermissions = $role->getPermissions();
        $oldActive = $role->isActive();
        $actor = $this->getUser();
        $assignedToActor = !$new && $actor instanceof User && $actor->getAccessRoles()->contains($role);
        $form = $this->createForm(AccessRoleType::class, $role, ['key_locked' => !$new])->handleRequest($request);
        if ($form->isSubmitted() && $assignedToActor && ($oldPermissions !== $role->getPermissions() || $oldActive !== $role->isActive())) {
            $role->setPermissions($oldPermissions)->setActive($oldActive);
            $form->get('permissions')->addError(new FormError('Du kannst Rechte oder Status einer dir selbst zugewiesenen Rolle nicht ändern.'));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$new) { $role->setKey($oldKey); }
            if ($new && $this->roles->findOneBy(['key' => $role->getKey()]) !== null) {
                $form->get('key')->addError(new FormError('Dieser Schlüssel wird bereits verwendet.'));
            } else {
                if ($new) { $this->entityManager->persist($role); }
                $this->audit->record($new ? 'access_role.created' : 'access_role.updated', $role, $role->getId(), $new ? 'Berechtigungsrolle angelegt.' : 'Berechtigungsrolle aktualisiert.', ['key' => $role->getKey(), 'permissions' => $role->getPermissions()]);
                $this->entityManager->flush();
                $this->addFlash('success', 'Die Rolle wurde gespeichert.');
                return $this->redirectToRoute('app_admin_access_role_index');
            }
        }

        return $this->render('admin/access_role/form.html.twig', ['form' => $form, 'heading' => $heading, 'role' => $role]);
    }
}
