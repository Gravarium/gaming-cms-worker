<?php

declare(strict_types=1);

namespace App\Controller;

use App\ExternalConnector\ConnectorProviderCatalog;
use App\ExternalConnector\ConnectorTargetPlanner;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/connectors/catalog')]
#[IsGranted('CMS_CONNECTORS_MANAGE')]
final class AdminConnectorCatalogController extends AbstractController
{
    #[Route('', name: 'app_admin_connector_catalog', methods: ['GET'])]
    public function index(ConnectorProviderCatalog $catalog, ExternalConnectorAdapterRegistry $adapters): Response
    {
        $groups = $catalog->grouped();
        $availability = [];
        foreach ($groups as $capability => $providers) {
            foreach ($providers as $provider) {
                $availability[$capability.':'.$provider['key']] = $adapters->has($provider['key'])
                    && $adapters->forProvider($provider['key'])->supports($capability);
            }
        }

        return $this->render('admin/connectors/catalog.html.twig', [
            'groups' => $groups,
            'availability' => $availability,
        ]);
    }

    #[Route('/plan', name: 'app_admin_connector_catalog_plan', methods: ['POST'])]
    public function plan(
        Request $request,
        ConnectorTargetPlanner $planner,
        AuditLogger $audit,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('connector-catalog-plan', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $choices = array_values(array_filter($request->request->all('providers'), 'is_string'));
        $result = $planner->plan($choices);
        if ($result['created'] > 0) {
            $audit->record(
                'connector.catalog.plan',
                self::class,
                null,
                'Externe Ziele aus dem gemeinsamen Anbieter-Katalog vorbereitet.',
                ['count' => $result['created'], 'targetKeys' => $result['keys']],
            );
            $entityManager->flush();
        }

        $this->addFlash('success', sprintf(
            '%d externe Ziele vorbereitet, %d bereits vorhanden. Neue Ziele bleiben deaktiviert.',
            $result['created'],
            $result['skipped'],
        ));

        return $this->redirectToRoute('app_admin_connector_index');
    }
}
