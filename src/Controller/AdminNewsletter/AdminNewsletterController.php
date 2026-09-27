<?php

declare(strict_types=1);

namespace App\Controller\AdminNewsletter;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterSubscription;
use App\Entity\User;
use App\Form\Newsletter\NewsletterCampaignType;
use App\Newsletter\NewsletterDispatchService;
use App\Repository\Newsletter\NewsletterCampaignRepository;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/newsletter', name: 'app_admin_notification_newsletter_admin_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminNewsletterController extends AbstractController
{
    public function __construct(
        private readonly NewsletterCampaignRepository $campaigns,
        private readonly NewsletterSubscriptionRepository $subscriptions,
        private readonly NewsletterDispatchService $dispatch,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/newsletter/index.html.twig', [
            'campaigns' => $this->campaigns->findBy([], ['createdAt' => 'DESC'], 100),
            'subscriptions' => $this->subscriptions->findBy([], ['createdAt' => 'DESC'], 100),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->form((new NewsletterCampaign())->setCreatedBy($this->requireUser()), $request, 'Newsletter anlegen');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(NewsletterCampaign $campaign, Request $request): Response
    {
        if ($campaign->isFinal() || $campaign->getStatus() === NewsletterCampaign::STATUS_SENDING) {
            throw new ConflictHttpException('Finale oder laufende Newsletter sind unveränderlich.');
        }

        return $this->form($campaign, $request, 'Newsletter bearbeiten');
    }

    #[Route('/{id}/preview', name: 'preview', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function preview(NewsletterCampaign $campaign): Response
    {
        return $this->render('admin/newsletter/preview.html.twig', ['campaign' => $campaign]);
    }

    #[Route('/{id}/schedule', name: 'schedule', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function schedule(NewsletterCampaign $campaign, Request $request): Response
    {
        $this->assertCsrf($request, 'newsletter-schedule-'.$campaign->getId());
        try {
            $at = new \DateTimeImmutable($request->request->getString('scheduled_at'));
            $campaign->schedule($at, new \DateTimeImmutable());
        } catch (\Throwable) {
            throw new BadRequestHttpException('Ungültiger Planungszeitpunkt.');
        }

        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
    }

    #[Route('/{id}/dispatch', name: 'dispatch', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function dispatchNow(NewsletterCampaign $campaign, Request $request): Response
    {
        $this->assertCsrf($request, 'newsletter-dispatch-'.$campaign->getId());
        $quota = $request->request->getInt('quota', 100);
        if ($quota < 1 || $quota > 1000) {
            throw new BadRequestHttpException('Ungültige Versandquote.');
        }

        $result = $this->dispatch->dispatch($campaign, new \DateTimeImmutable(), $quota);
        $this->addFlash('success', sprintf(
            'Versandlauf: %d gesendet, %d Fehlversuche, %d unterdrückt, %d offen.',
            $result->sent,
            $result->failedAttempts,
            $result->suppressed,
            $result->outstanding,
        ));

        return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
    }

    #[Route('/subscriptions/{id}/suppress', name: 'suppress', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function suppress(NewsletterSubscription $subscription, Request $request): Response
    {
        $this->assertCsrf($request, 'newsletter-suppress-'.$subscription->getId());
        $subscription->suppress('manual_admin', new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
    }

    private function form(NewsletterCampaign $campaign, Request $request, string $heading): Response
    {
        $form = $this->createForm(NewsletterCampaignType::class, $campaign)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($campaign->getId() === null) {
                $this->entityManager->persist($campaign);
            }
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_notification_newsletter_admin_index');
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            $form->addError(new FormError('Newsletter konnte nicht gespeichert werden.'));
        }

        return $this->render('admin/newsletter/form.html.twig', [
            'form' => $form,
            'campaign' => $campaign,
            'heading' => $heading,
        ]);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}
