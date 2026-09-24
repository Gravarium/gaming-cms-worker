<?php

declare(strict_types=1);

namespace App\Controller\VideoDiscovery;

use App\Entity\User;
use App\Entity\VideoDiscovery\VideoHistoryPreference;
use App\Video\Discovery\VideoModuleAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/video-discovery')]
#[IsGranted('ROLE_USER')]
final class VideoHistoryPreferenceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoModuleAvailability $availability,
    ) {
    }

    #[Route('/history-preference', name: 'app_video_discovery_history_preference', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('video-history-preference', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $raw = $request->request->getString('enabled');
        if (!in_array($raw, ['0', '1'], true)) {
            throw new UnprocessableEntityHttpException('History preference must be 0 or 1.');
        }

        $repository = $this->entityManager->getRepository(VideoHistoryPreference::class);
        $preference = $repository->findOneBy(['user' => $user]);
        if (!$preference instanceof VideoHistoryPreference) {
            $preference = new VideoHistoryPreference($user);
            $this->entityManager->persist($preference);
        }

        $preference->setEnabled($raw === '1');
        $this->entityManager->flush();

        return new JsonResponse(['enabled' => $preference->isEnabled()]);
    }
}
