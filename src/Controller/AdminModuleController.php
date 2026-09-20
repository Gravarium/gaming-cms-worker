<?php

declare(strict_types=1);

namespace App\Controller;

use App\ExtensionPackage\ExtensionPackageInventory;
use App\Module\CmsModuleManager;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/modules')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminModuleController extends AbstractController
{
    #[Route('', name: 'app_admin_module_index', methods: ['GET'])]
    public function index(CmsModuleManager $modules, ExtensionPackageInventory $extensions): Response
    {
        return $this->render('admin/modules/index.html.twig', ['modules' => $modules->overview(), 'externalExtensions' => $extensions->all()]);
    }

    #[Route('/{key}/toggle', name: 'app_admin_module_toggle', requirements: ['key' => '[a-z0-9-]+'], methods: ['POST'])]
    public function toggle(string $key, Request $request, CmsModuleManager $modules, AuditLogger $audit, EntityManagerInterface $entityManager): Response
    {
        if (!$this->validToken($request, 'module-toggle-'.$key)) {
            throw $this->createAccessDeniedException();
        }

        try {
            $enable = !$modules->isEnabled($key);
            $modules->setEnabled($key, $enable);
            $audit->record('module.toggle', self::class, null, $enable ? 'CMS-Modul aktiviert.' : 'CMS-Modul deaktiviert.', ['module' => $key]);
            $entityManager->flush();
            $this->addFlash('success', $enable ? 'Modul aktiviert.' : 'Modul deaktiviert.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_module_index');
    }

    #[Route('/{key}/install', name: 'app_admin_module_install', requirements: ['key' => '[a-z0-9-]+'], methods: ['POST'])]
    public function install(string $key, Request $request, CmsModuleManager $modules, AuditLogger $audit, EntityManagerInterface $entityManager): Response
    {
        return $this->lifecycleAction($key, 'install', $request, $modules, $audit, $entityManager);
    }

    #[Route('/{key}/update', name: 'app_admin_module_update', requirements: ['key' => '[a-z0-9-]+'], methods: ['POST'])]
    public function update(string $key, Request $request, CmsModuleManager $modules, AuditLogger $audit, EntityManagerInterface $entityManager): Response
    {
        return $this->lifecycleAction($key, 'update', $request, $modules, $audit, $entityManager);
    }

    #[Route('/{key}/remove', name: 'app_admin_module_remove', requirements: ['key' => '[a-z0-9-]+'], methods: ['POST'])]
    public function remove(string $key, Request $request, CmsModuleManager $modules, AuditLogger $audit, EntityManagerInterface $entityManager): Response
    {
        return $this->lifecycleAction($key, 'remove', $request, $modules, $audit, $entityManager);
    }

    private function lifecycleAction(
        string $key,
        string $action,
        Request $request,
        CmsModuleManager $modules,
        AuditLogger $audit,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->validToken($request, 'module-'.$action.'-'.$key)) {
            throw $this->createAccessDeniedException();
        }

        $labels = [
            'install' => ['Installiert', 'CMS-Modul installiert.'],
            'update' => ['Aktualisiert', 'CMS-Modul aktualisiert.'],
            'remove' => ['Deinstalliert', 'CMS-Modul deinstalliert; Daten wurden beibehalten.'],
        ];

        try {
            $modules->{$action}($key);
            $audit->record('module.'.$action, self::class, null, $labels[$action][1], ['module' => $key, 'dataDeleted' => false]);
            $entityManager->flush();
            $this->addFlash('success', $labels[$action][0].'.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_module_index');
    }

    private function validToken(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, (string) $request->request->get('_token'));
    }
}
