<?php

declare(strict_types=1);

namespace App\Controller\Social;

use App\Entity\Social\SocialAttachment;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialMessage;
use App\Entity\User;
use App\Form\Social\SocialConversationType;
use App\Form\Social\SocialMessageType;
use App\Form\Social\SocialPrivacySettingsType;
use App\Form\Social\SocialRelationshipType;
use App\Repository\Social\SocialAttachmentRepository;
use App\Repository\Social\SocialConversationRepository;
use App\Repository\Social\SocialMessageRepository;
use App\Repository\Social\SocialRelationshipRepository;
use App\Repository\UserRepository;
use App\Social\SocialAccessPolicy;
use App\Social\SocialDataRightsService;
use App\Social\SocialModuleAvailability;
use App\Social\SocialMessagingService;
use App\Social\SocialModerationService;
use App\Social\SocialRelationshipService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/social')]
#[IsGranted('ROLE_USER')]
final class SocialController extends AbstractController
{
    public function __construct(
        private readonly SocialModuleAvailability $availability,
        private readonly SocialConversationRepository $conversations,
        private readonly SocialMessageRepository $messages,
        private readonly SocialRelationshipRepository $relationships,
        private readonly SocialAttachmentRepository $attachments,
        private readonly SocialAccessPolicy $access,
        private readonly SocialMessagingService $messaging,
        private readonly SocialRelationshipService $relationshipService,
        private readonly SocialModerationService $moderation,
        private readonly SocialDataRightsService $dataRights,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_social_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertEnabled();
        $user = $this->requireUser();

        return $this->render('social/index.html.twig', [
            'conversations' => $this->conversations->forUser($user),
            'relationships' => $this->relationships->forUser($user),
        ]);
    }

    #[Route('/conversations/new', name: 'app_social_conversation_new', methods: ['GET', 'POST'])]
    public function newConversation(Request $request): Response
    {
        $this->assertEnabled();
        $actor = $this->requireUser();
        $form = $this->createForm(SocialConversationType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $recipient = $this->users->find((int) $form->get('recipientId')->getData());
            if (!$recipient instanceof User) {
                $form->addError(new FormError('Mitglied nicht gefunden.'));
            } else {
                try {
                    $title = trim((string) $form->get('title')->getData());
                    $conversation = $title === ''
                        ? $this->messaging->createDirect($actor, $recipient)
                        : $this->messaging->createGroup($actor, $title, [$recipient]);
                    $this->entityManager->flush();

                    return $this->redirectToRoute('app_social_conversation', ['id' => $conversation->getId()]);
                } catch (\DomainException|\InvalidArgumentException|\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        $response = $this->render('social/new.html.twig', ['form' => $form]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/conversations/{id}', name: 'app_social_conversation', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function conversation(int $id): Response
    {
        $this->assertEnabled();
        $actor = $this->requireUser();
        $conversation = $this->conversations->find($id);
        if (!$conversation instanceof SocialConversation) {
            throw $this->createNotFoundException();
        }
        try {
            $messages = $this->messaging->read($actor, $conversation);
            $this->messaging->markRead($actor, $conversation);
            $this->entityManager->flush();
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(SocialMessageType::class, null, [
            'action' => $this->generateUrl('app_social_message_send', ['id' => $id]),
        ]);

        return $this->render('social/conversation.html.twig', [
            'conversation' => $conversation,
            'messages' => array_reverse($messages),
            'messageForm' => $form,
            'actor' => $actor,
        ]);
    }

    #[Route('/conversations/{id}/messages', name: 'app_social_message_send', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function sendMessage(int $id, Request $request): Response
    {
        $this->assertEnabled();
        $actor = $this->requireUser();
        $conversation = $this->conversations->find($id);
        if (!$conversation instanceof SocialConversation) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(SocialMessageType::class, null, [
            'action' => $this->generateUrl('app_social_message_send', ['id' => $id]),
        ])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messaging->send($actor, $conversation, (string) $form->get('body')->getData());
                $this->entityManager->flush();

                return $this->redirectToRoute('app_social_conversation', ['id' => $id]);
            } catch (\DomainException|\InvalidArgumentException|\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        $response = $this->render('social/conversation.html.twig', [
            'conversation' => $conversation,
            'messages' => $this->messaging->read($actor, $conversation),
            'messageForm' => $form,
            'actor' => $actor,
        ]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }

    #[Route('/messages/{id}/report', name: 'app_social_message_report', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function report(int $id, Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-report-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->requireUser();
        $message = $this->messages->find($id);
        if (!$message instanceof SocialMessage) {
            throw $this->createNotFoundException();
        }
        try {
            $this->moderation->report($actor, $message, $request->request->getString('reason'), $request->request->getString('details'));
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Meldung wurde zur Moderation eingereicht.');
        } catch (\DomainException|\InvalidArgumentException|\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_social_conversation', ['id' => $message->getConversation()->getId()]);
    }

    #[Route('/relationships/request', name: 'app_social_relationship_request', methods: ['GET', 'POST'])]
    public function relationshipRequest(Request $request): Response
    {
        $this->assertEnabled();
        $actor = $this->requireUser();
        $form = $this->createForm(SocialRelationshipType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $target = $this->users->find((int) $form->get('targetId')->getData());
            if (!$target instanceof User) {
                $form->addError(new FormError('Mitglied nicht gefunden.'));
            } else {
                try {
                    $this->relationshipService->request($actor, $target, (string) $form->get('type')->getData());
                    $this->entityManager->flush();

                    return $this->redirectToRoute('app_social_index');
                } catch (\DomainException|\InvalidArgumentException|\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        $response = $this->render('social/relationships.html.twig', [
            'form' => $form,
            'relationships' => $this->relationships->forUser($actor),
        ]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/relationships/{id}/{decision}', name: 'app_social_relationship_respond', requirements: ['id' => '\\d+', 'decision' => 'accept|reject'], methods: ['POST'])]
    public function relationshipRespond(int $id, string $decision, Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-relationship-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $relationship = $this->relationships->find($id);
        if ($relationship === null) {
            throw $this->createNotFoundException();
        }
        $this->relationshipService->respond($this->requireUser(), $relationship, $decision === 'accept');
        $this->entityManager->flush();

        return $this->redirectToRoute('app_social_relationship_request');
    }

    #[Route('/users/{id}/block', name: 'app_social_user_block', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function block(int $id, Request $request): Response
    {
        return $this->blockAction($id, $request, true);
    }

    #[Route('/users/{id}/unblock', name: 'app_social_user_unblock', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function unblock(int $id, Request $request): Response
    {
        return $this->blockAction($id, $request, false);
    }

    #[Route('/attachments/{id}', name: 'app_social_attachment', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function attachment(int $id): BinaryFileResponse
    {
        $this->assertEnabled();
        $attachment = $this->attachments->find($id);
        if (!$attachment instanceof SocialAttachment || !$this->access->canAccessAttachment($this->requireUser(), $attachment)) {
            throw $this->createNotFoundException();
        }
        $path = $this->projectDir.'/'.ltrim($attachment->getStorageLocator(), '/');
        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalName());

        return $response;
    }

    #[Route('/privacy', name: 'app_social_privacy', methods: ['GET', 'POST'])]
    public function privacy(Request $request): Response
    {
        $this->assertEnabled();
        $settings = $this->relationshipService->privacy($this->requireUser());
        $form = $this->createForm(SocialPrivacySettingsType::class, $settings)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Privatsphäre-Einstellungen gespeichert.');

            return $this->redirectToRoute('app_social_privacy');
        }

        $response = $this->render('social/privacy.html.twig', ['form' => $form]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/data/export.json', name: 'app_social_export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $this->assertEnabled();
        $response = new JsonResponse($this->dataRights->export($this->requireUser()));
        $response->headers->set('Content-Disposition', 'attachment; filename="social-data-export.json"');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    #[Route('/data/delete', name: 'app_social_delete', methods: ['POST'])]
    public function deleteData(Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-data-delete', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->dataRights->anonymize($this->requireUser(), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'Deine Social-Daten wurden für Löschung und Aufbewahrung markiert.');

        return $this->redirectToRoute('app_social_index');
    }

    private function blockAction(int $id, Request $request, bool $block): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-block-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $target = $this->users->find($id);
        if (!$target instanceof User) {
            throw $this->createNotFoundException();
        }
        if ($block) {
            $this->relationshipService->block($this->requireUser(), $target);
        } else {
            $this->relationshipService->unblock($this->requireUser(), $target);
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('app_social_index');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }
}
