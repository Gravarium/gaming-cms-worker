<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Module\CmsModuleManager;
use App\Repository\Newsletter\NewsletterDeliveryRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/newsletter', name: 'app_admin_notification_newsletter_lifecycle_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminNewsletterCampaignLifecycleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NewsletterDeliveryRepository $deliveries,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/{id}/cancel', name: 'cancel', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $this->assertCsrf($request, 'newsletter-cancel-'.$id);
        $campaign = $this->campaign($id);

        $this->entityManager->getConnection()->transactional(function () use ($campaign): void {
            $this->entityManager->refresh($campaign, LockMode::PESSIMISTIC_WRITE);
            if (!in_array($campaign->getStatus(), [NewsletterCampaign::STATUS_DRAFT, NewsletterCampaign::STATUS_SCHEDULED], true)) {
                throw new ConflictHttpException('Nur Entwürfe und geplante Kampagnen können abgesagt werden.');
            }

            $campaign->cancel(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->entityManager->flush();
        });

        $this->addFlash('success', 'Die Kampagne wurde abgesagt.');

        return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
    }

    #[Route('/{id}/reschedule', name: 'reschedule', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reschedule(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $this->assertCsrf($request, 'newsletter-reschedule-'.$id);
        $at = $this->scheduledAt($request);
        $campaign = $this->campaign($id);

        $this->entityManager->getConnection()->transactional(function () use ($campaign, $at): void {
            $this->entityManager->refresh($campaign, LockMode::PESSIMISTIC_WRITE);
            if ($campaign->getStatus() !== NewsletterCampaign::STATUS_SCHEDULED) {
                throw new ConflictHttpException('Nur geplante Kampagnen können umgeplant werden.');
            }
            try {
                $campaign->schedule($at, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            } catch (\DomainException) {
                throw new BadRequestHttpException('Der neue Zeitpunkt muss in der Zukunft liegen.');
            }
            $this->entityManager->flush();
        });

        $this->addFlash('success', 'Der Versandzeitpunkt wurde aktualisiert.');

        return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
    }

    #[Route('/{id}/retry', name: 'retry', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function retry(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $this->assertCsrf($request, 'newsletter-retry-'.$id);
        $at = $this->scheduledAt($request);
        $campaign = $this->campaign($id);

        $this->entityManager->getConnection()->transactional(function () use ($campaign, $at): void {
            $this->entityManager->refresh($campaign, LockMode::PESSIMISTIC_WRITE);
            if ($campaign->getStatus() !== NewsletterCampaign::STATUS_FAILED || $this->deliveries->countOutstanding($campaign) !== 0) {
                throw new ConflictHttpException('Nur abgeschlossene fehlgeschlagene Kampagnen können erneut geplant werden.');
            }

            $failed = $this->deliveries->failedFor($campaign);
            if ($failed === []) {
                throw new ConflictHttpException('Es gibt keine fehlgeschlagenen Zustellungen für einen neuen Versuch.');
            }

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            try {
                $campaign->schedule($at, $now);
            } catch (\DomainException) {
                throw new BadRequestHttpException('Der neue Zeitpunkt muss in der Zukunft liegen.');
            }

            foreach ($failed as $delivery) {
                $delivery->requeueFailed($now);
            }
            $this->entityManager->flush();
        });

        $this->addFlash('success', 'Nur die fehlgeschlagenen Zustellungen wurden erneut eingeplant. Einwilligungen werden vor dem Versand erneut geprüft.');

        return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
    }

    private function campaign(int $id): NewsletterCampaign
    {
        $campaign = $this->entityManager->find(NewsletterCampaign::class, $id);
        if (!$campaign instanceof NewsletterCampaign) {
            throw $this->createNotFoundException();
        }

        return $campaign;
    }

    private function scheduledAt(Request $request): \DateTimeImmutable
    {
        $raw = $request->request->getString('scheduled_at');
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/D', $raw) !== 1) {
            throw new BadRequestHttpException('Bitte einen gültigen UTC-Zeitpunkt angeben.');
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $raw, new \DateTimeZone('UTC'));
        if (!$at instanceof \DateTimeImmutable || $at->format('Y-m-d\\TH:i') !== $raw) {
            throw new BadRequestHttpException('Bitte einen gültigen UTC-Zeitpunkt angeben.');
        }

        return $at;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('notifications')) {
            throw $this->createNotFoundException();
        }
    }
}
