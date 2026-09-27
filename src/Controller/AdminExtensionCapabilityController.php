<?php

declare(strict_types=1);

namespace App\Controller;

use App\ExtensionPackage\ExtensionCapabilityAdministration;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/modules/extensions')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminExtensionCapabilityController extends AbstractController
{
    public function __construct(
        private readonly ExtensionCapabilityAdministration $capabilities,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{type}/{key}', name: 'app_admin_extension_capability_review', requirements: ['type' => 'module|theme', 'key' => '[a-z][a-z0-9-]{1,39}'], methods: ['GET'])]
    public function review(string $type, string $key): Response
    {
        try {
            $package = $this->capabilities->review($type, $key);
        } catch (\DomainException|\RuntimeException) {
            throw $this->createNotFoundException('Das signierte Paket ist nicht verfügbar.');
        }

        return $this->privateResponse($this->render('admin/modules/extension_permissions.html.twig', [
            'package' => $package,
        ]));
    }

    #[Route(
        '/{type}/{key}/{capability}/{action}',
        name: 'app_admin_extension_capability_action',
        requirements: [
            'type' => 'module|theme',
            'key' => '[a-z][a-z0-9-]{1,39}',
            'capability' => '[a-z][a-z0-9.]{0,39}',
            'action' => 'grant|revoke',
        ],
        methods: ['POST'],
    )]
    public function change(string $type, string $key, string $capability, string $action, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid($this->tokenId($action, $type, $key, $capability), $token)) {
            throw $this->createAccessDeniedException();
        }

        try {
            $package = $this->capabilities->review($type, $key);
        } catch (\DomainException|\RuntimeException) {
            throw $this->createNotFoundException('Das signierte Paket ist nicht verfügbar.');
        }

        $requested = null;
        foreach ($package['capabilities'] as $candidate) {
            if ($candidate['key'] === $capability) {
                $requested = $candidate;
                break;
            }
        }
        if ($requested === null) {
            throw $this->createNotFoundException('Das Paket hat diese Fähigkeit nicht angefordert.');
        }

        $approved = $action === 'grant';
        if ($requested['approved'] === $approved) {
            $this->addFlash('notice', $approved ? 'Die Fähigkeit war bereits freigegeben.' : 'Die Fähigkeit war bereits gesperrt.');

            return $this->reviewRedirect($type, $key);
        }

        try {
            $this->capabilities->set($type, $key, $capability, $approved);
        } catch (\DomainException|\RuntimeException) {
            $this->addFlash('error', 'Die Fähigkeit konnte nicht sicher geändert werden. Es wurde keine Freigabe bestätigt.');

            return $this->reviewRedirect($type, $key);
        }

        try {
            $this->audit->record(
                $approved ? 'extension.capability.granted' : 'extension.capability.revoked',
                'external-extension:'.$type.':'.$key,
                null,
                $approved ? 'Fähigkeit eines signierten Pakets freigegeben.' : 'Fähigkeit eines signierten Pakets entzogen.',
                ['extensionType' => $type, 'extensionKey' => $key, 'capability' => $capability, 'version' => $package['version']],
            );
            $this->entityManager->flush();
        } catch (\Throwable) {
            try {
                $this->capabilities->set($type, $key, $capability, $requested['approved']);
            } catch (\Throwable $rollbackException) {
                if ($this->entityManager->isOpen()) {
                    $this->entityManager->clear();
                }
                throw new \RuntimeException('Permission state could not be restored after audit failure.', 0, $rollbackException);
            }

            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }
            $this->addFlash('error', 'Die Änderung wurde wegen eines Auditfehlers zurückgenommen. Die vorherigen Freigaben gelten weiterhin.');

            return $this->reviewRedirect($type, $key);
        }

        $this->addFlash('success', $approved ? 'Die angeforderte Fähigkeit wurde freigegeben.' : 'Die Fähigkeit wurde entzogen.');

        return $this->reviewRedirect($type, $key);
    }

    private function reviewRedirect(string $type, string $key): Response
    {
        return $this->privateResponse($this->redirectToRoute('app_admin_extension_capability_review', [
            'type' => $type,
            'key' => $key,
        ]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function tokenId(string $action, string $type, string $key, string $capability): string
    {
        return 'extension-capability-'.$action.'-'.$type.'-'.$key.'-'.$capability;
    }
}
