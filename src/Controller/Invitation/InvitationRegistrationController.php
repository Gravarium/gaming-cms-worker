<?php

declare(strict_types=1);

namespace App\Controller\Invitation;

use App\Form\Invitation\InvitationRegistrationType;
use App\Invitation\InvitationRegistrationService;
use App\Profile\ProfileModuleAvailability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationRegistrationController extends AbstractController
{
    public function __construct(
        private readonly InvitationRegistrationService $registrations,
        private readonly ProfileModuleAvailability $availability,
    ) {
    }

    #[Route(
        '/invitation/{token}',
        name: 'app_invitation_register',
        requirements: ['token' => '[A-Za-z0-9_-]{20,200}'],
        methods: ['GET', 'POST'],
    )]
    public function register(string $token, Request $request): Response
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_account_home');
        }

        $invitation = $this->registrations->resolve($token, new \DateTimeImmutable());
        if ($invitation === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(InvitationRegistrationType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = $this->registrations->register(
                    $token,
                    (string) $form->get('displayName')->getData(),
                    (string) $form->get('password')->getData(),
                    new \DateTimeImmutable(),
                );
                $this->addFlash(
                    'success',
                    $result->verificationMailQueued
                        ? 'Konto erstellt. Bitte bestätige deine E-Mail-Adresse.'
                        : 'Konto erstellt. Die Bestätigungs-E-Mail konnte nicht versendet werden; bitte wende dich an die Administration.',
                );

                return $this->redirectToRoute('app_login');
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        $response = $this->render('invitation/register.html.twig', [
            'form' => $form,
            'invitation' => $invitation,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }
}
