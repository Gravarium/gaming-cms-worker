<?php

declare(strict_types=1);

namespace App\Controller;

use App\Internationalization\LocalePolicy;
use App\Repository\SiteSettingsRepository;
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

        $target = $this->safeLocalTarget((string) $request->request->get('_target', '/'));

        $response = $this->redirect($target);
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
    private function safeLocalTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || preg_match('/[\x00-\x1F\x7F\\\\]/u', $target) === 1) {
            return '/';
        }

        $candidate = $target;
        for ($round = 0; $round < 3; ++$round) {
            if (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//') || str_contains($candidate, '\\')) {
                return '/';
            }
            $parts = parse_url($candidate);
            if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                return '/';
            }

            $decoded = rawurldecode($candidate);
            if ($decoded === $candidate) {
                break;
            }
            if (preg_match('/[\x00-\x1F\x7F\\\\]/u', $decoded) === 1) {
                return '/';
            }
            $candidate = $decoded;
        }

        return $target;
    }
}
