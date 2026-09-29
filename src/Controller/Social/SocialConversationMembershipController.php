<?php

declare(strict_types=1);

namespace App\Controller\Social;

use App\Entity\Social\SocialConversation;
use App\Entity\User;
use App\Form\Social\SocialConversationMemberType;
use App\Repository\Social\SocialConversationRepository;
use App\Social\SocialConversationMembershipService;
use App\Social\SocialModuleAvailability;
use App\Social\SocialRateLimitPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/social/conversations')]
#[IsGranted('ROLE_USER')]
final class SocialConversationMembershipController extends AbstractController
{
    public function __construct(
        private readonly SocialModuleAvailability $availability,
        private readonly SocialConversationRepository $conversations,
        private readonly SocialConversationMembershipService $membership,
    ) {
    }

    #[Route('/{id}/members', name: 'app_social_conversation_members', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function members(int $id, Request $request): Response
    {
        $this->assertEnabled();
        $actor = $this->requireUser();
        $conversation = $this->conversation($id);

        try {
            $participants = $this->membership->activeParticipants($actor, $conversation);
        } catch (AccessDeniedException) {
            throw $this->createNotFoundException();
        }

        $canManage = $this->membership->canManage($actor, $conversation);
        $activeIds = [];
        foreach ($participants as $participant) {
            $memberId = $participant->getUser()->getId();
            if ($memberId !== null) {
                $activeIds[$memberId] = true;
            }
        }

        $choices = $canManage ? $this->membership->recipientChoices($actor) : [];
        $choices = array_filter(
            $choices,
            static fn (int $memberId): bool => !isset($activeIds[$memberId]),
        );
        $maxRecipients = min(
            SocialRateLimitPolicy::MAX_GROUP_PARTICIPANTS - 1,
            max(0, SocialRateLimitPolicy::MAX_GROUP_PARTICIPANTS - count($participants)),
        );
        $form = $this->createForm(SocialConversationMemberType::class, null, [
            'recipient_choices' => $choices,
            'max_recipients' => $maxRecipients,
            'csrf_token_id' => 'social-members-add-'.$id,
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $recipientIds = $form->get('recipientIds')->getData();
            try {
                $this->membership->addMembers($actor, $conversation, is_array($recipientIds) ? array_values($recipientIds) : []);
                $this->addFlash('success', 'Mitglied wurde zur Gruppe hinzugefügt.');

                return $this->privateResponse($this->redirectToRoute('app_social_conversation_members', ['id' => $id]));
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        $response = $this->render('social/conversation_members.html.twig', [
            'conversation' => $conversation,
            'participants' => $participants,
            'actor' => $actor,
            'canManage' => $canManage,
            'form' => $form,
            'hasRecipientChoices' => $choices !== [],
            'maxRecipients' => $maxRecipients,
        ]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->privateResponse($response);
    }

    #[Route('/{id}/members/{participantId}/remove', name: 'app_social_conversation_member_remove', requirements: ['id' => '\\d+', 'participantId' => '\\d+'], methods: ['POST'])]
    public function remove(int $id, int $participantId, Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-members-remove-'.$id.'-'.$participantId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->requireUser();
        $conversation = $this->conversation($id);
        try {
            $this->membership->removeMember($actor, $conversation, $participantId);
            $this->addFlash('success', 'Mitglied wurde aus der Gruppe entfernt.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->privateResponse($this->redirectToRoute('app_social_conversation_members', ['id' => $id]));
    }

    #[Route('/{id}/leave', name: 'app_social_conversation_leave', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function leave(int $id, Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-members-leave-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->requireUser();
        $conversation = $this->conversation($id);
        $transferredOwnership = $this->membership->leave($actor, $conversation);
        if ($transferredOwnership) {
            $this->addFlash('success', 'Du hast die Unterhaltung verlassen. Die Gruppenleitung wurde an das dienstälteste aktive Mitglied übergeben.');
        } else {
            $this->addFlash('success', 'Du hast die Unterhaltung verlassen.');
        }

        return $this->privateResponse($this->redirectToRoute('app_social_index'));
    }

    private function conversation(int $id): SocialConversation
    {
        $conversation = $this->conversations->find($id);
        if (!$conversation instanceof SocialConversation) {
            throw $this->createNotFoundException();
        }

        return $conversation;
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

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
