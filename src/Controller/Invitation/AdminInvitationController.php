<?php

declare(strict_types=1);

namespace App\Controller\Invitation;

use App\Entity\Invitation\MemberInvitation;
use App\Entity\User;
use App\Form\Invitation\InvitationIssueType;
use App\Invitation\InvitationService;
use App\Profile\ProfileModuleAvailability;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/invitations')]
#[IsGranted(CmsPermission::USERS)]
final class AdminInvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly ProfileModuleAvailability $availability,
    ) {
    }

    #[Route('', name: 'app_admin_invitation_issue', methods: ['GET', 'POST'])]
    public function issue(Request $request): Response
    {
        $this->assertEnabled();
        $actor = $this->requireUser();
        $form = $this->createForm(InvitationIssueType::class)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = $this->invitations->issue(
                    $actor,
                    (string) $form->get('email')->getData(),
                    $form->get('accessRole')->getData(),
                    (int) $form->get('ttlHours')->getData(),
                    new \DateTimeImmutable(),
                );

                $response = $this->render('invitation/issued.html.twig', [
                    'invitation' => $result->invitation,
                    'rawToken' => $result->rawToken,
                ]);
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('Referrer-Policy', 'no-referrer');

                return $response;
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        $response = $this->render('invitation/issue.html.twig', ['form' => $form]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/{id}/revoke', name: 'app_admin_invitation_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(MemberInvitation $invitation, Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('revoke-member-invitation-'.$invitation->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->invitations->revoke($invitation, $this->requireUser(), new \DateTimeImmutable());
        } catch (\DomainException $exception) {
            throw $this->createAccessDeniedException($exception->getMessage());
        }

        $this->addFlash('success', 'Einladung widerrufen.');

        return $this->redirectToRoute('app_admin_invitation_issue');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
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
