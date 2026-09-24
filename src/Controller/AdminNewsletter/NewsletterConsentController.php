<?php

declare(strict_types=1);

namespace App\Controller\AdminNewsletter;

use App\Newsletter\NewsletterConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/newsletter', name: 'app_admin_notification_newsletter_public_')]
final class NewsletterConsentController extends AbstractController
{
    public function __construct(private readonly NewsletterConsentService $consent) {}

    #[Route('/confirm/{id}/{token}', name: 'confirm', requirements: ['id' => '\\d+', 'token' => '[A-Za-z0-9_-]{20,120}'], methods: ['GET'])]
    public function confirm(int $id, string $token): Response
    {
        try {
            $this->consent->confirm($id, $token, new \DateTimeImmutable());
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }

        return new Response('Newsletter-Anmeldung bestätigt.');
    }

    #[Route('/unsubscribe/{id}/{token}', name: 'unsubscribe', requirements: ['id' => '\\d+', 'token' => '[A-Za-z0-9_-]{20,120}'], methods: ['GET'])]
    public function unsubscribe(int $id, string $token): Response
    {
        try {
            $this->consent->unsubscribeByToken($id, $token, new \DateTimeImmutable());
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }

        return new Response('Newsletter abbestellt.');
    }
}
