<?php

declare(strict_types=1);

namespace App\Controller;

use App\Video\Discovery\VideoModuleAvailability;
use App\VideoProviderApi\ProviderCapabilities;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-provider-api')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class VideoProviderApiController extends AbstractController
{
    public function __construct(private readonly VideoModuleAvailability $module, private readonly ProviderCapabilities $capabilities,
        private readonly CsrfTokenManagerInterface $tokens) {}

    #[Route('', name: 'app_video_provider_api_catalogue', methods: ['GET'])]
    public function catalogue(): JsonResponse
    {
        $this->enabled();

        return $this->response([
            'version' => 1,
            'providers' => $this->capabilities->catalogue(),
            'csrf_token' => $this->tokens->getToken('video-provider-api-resolve')->getValue(),
        ]);
    }

    #[Route('/resolve', name: 'app_video_provider_api_resolve', methods: ['POST'])]
    public function resolve(Request $request): JsonResponse
    {
        $this->enabled();
        if (!$this->isCsrfTokenValid('video-provider-api-resolve', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $provider = $request->request->getString('provider');
        $url = $request->request->getString('url');
        $resolved = $this->capabilities->resolve($provider, $url, $request->getHost());
        if ($resolved === null) {
            return $this->response(['error' => 'Nicht unterstützte oder ungültige Anbieter-URL.'], 422);
        }

        return $this->response(['provider' => $provider, 'mode' => $resolved['mode'], 'url' => $resolved['url']]);
    }

    private function enabled(): void
    {
        if (!$this->module->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
