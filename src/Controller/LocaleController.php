<?php

declare(strict_types=1);

namespace App\Controller;

use App\Internationalization\LocalePolicy;
use App\Repository\SiteSettingsRepository;
use App\Security\LocalRedirectTarget;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale_switch', methods: ['POST'])]
    public function switch(
        string $locale,
        Request $request,
        SiteSettingsRepository $settingsRepository,
        LocalePolicy $policy,
    ): Response {
        if (!$this->isCsrfTokenValid('locale-switch', (string) $request->request->get('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $settings = $settingsRepository->current();
        $selected = $policy->choose($locale, $settings->getEnabledLocales(), $settings->getDefaultLocale());
        if ($selected !== $locale) {
            throw $this->createNotFoundException();
        }

        $target = LocalRedirectTarget::normalize((string) $request->request->get('_target', '/'), '/');

        $response = $this->redirect($target ?? '/');
        $response->headers->setCookie(Cookie::create(
            LocalePolicy::COOKIE,
            $selected,
            new \DateTimeImmutable('+1 year'),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
