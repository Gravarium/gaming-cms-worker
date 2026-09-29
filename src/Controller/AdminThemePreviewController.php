<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SiteSettingsRepository;
use App\Theme\ThemeRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings/theme-preview')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminThemePreviewController extends AbstractController
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly SiteSettingsRepository $settingsRepository,
    ) {
    }

    #[Route('', name: 'app_admin_settings_theme_preview', methods: ['GET'])]
    public function index(): Response
    {
        $settings = $this->settingsRepository->current();
        $activeThemeKey = $this->themes->get($settings->getThemeKey())->key;
        $themeCards = [];

        foreach ($this->themes->choices() as $label => $key) {
            $definition = $this->themes->get($key);
            $themeCards[] = [
                'definition' => $definition,
                'label' => $label,
                'active' => $definition->key === $activeThemeKey,
            ];
        }

        $response = $this->render('admin/settings/theme_preview.html.twig', [
            'settings' => $settings,
            'themes' => $themeCards,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}