<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ConnectorHealthReport;
use App\Repository\ExternalConnectorTargetRepository;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/connectors/health/report')]
#[IsGranted(CmsPermission::CONNECTORS)]
final class AdminConnectorHealthReportController extends AbstractController
{
    #[Route('', name: 'app_admin_connector_health_report', methods: ['GET'])]
    public function report(Request $request, ExternalConnectorTargetRepository $targets, ConnectorHealthReport $healthReport): JsonResponse
    {
        $parameters = $request->query->all();
        $capability = $this->filter($parameters, 'capability', ['all', ...ExternalConnectorTarget::CAPABILITIES]);
        if ($capability instanceof JsonResponse) {
            return $capability;
        }

        $status = $this->filter($parameters, 'status', ['all', ConnectorHealthReport::STATUS_HEALTHY, ConnectorHealthReport::STATUS_FAILED, ConnectorHealthReport::STATUS_PENDING]);
        if ($status instanceof JsonResponse) {
            return $status;
        }

        $rows = $healthReport->rows(
            $targets->ordered(),
            $capability === 'all' ? null : $capability,
            $status === 'all' ? null : $status,
        );
        $counts = [
            ConnectorHealthReport::STATUS_HEALTHY => 0,
            ConnectorHealthReport::STATUS_FAILED => 0,
            ConnectorHealthReport::STATUS_PENDING => 0,
        ];
        foreach ($rows as $row) {
            ++$counts[$row['status']];
        }

        $response = new JsonResponse([
            'filters' => [
                'capability' => $capability,
                'status' => $status,
            ],
            'counts' => $counts + ['total' => count($rows)],
            'targets' => $rows,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param list<string> $allowed
     */
    private function filter(array $parameters, string $name, array $allowed): string|JsonResponse
    {
        $value = $parameters[$name] ?? 'all';
        if (!is_string($value)) {
            return $this->invalidFilter($name, $allowed);
        }

        $value = strtolower(trim($value));
        if ($value === '') {
            $value = 'all';
        }
        if (!in_array($value, $allowed, true)) {
            return $this->invalidFilter($name, $allowed);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function invalidFilter(string $name, array $allowed): JsonResponse
    {
        $response = new JsonResponse([
            'error' => sprintf('Ungültiger %s-Filter. Erlaubt sind: %s.', $name, implode(', ', $allowed)),
            'parameter' => $name,
            'allowed' => $allowed,
        ], Response::HTTP_BAD_REQUEST);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
