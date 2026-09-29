<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminNotification;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\User;
use App\Form\GuildApplicationType;
use App\Layout\Module\ModuleLayoutComposer;
use App\Repository\SiteSettingsRepository;
use App\Theme\Module\ModuleThemeCompositionRegistry;
use App\Widget\Module\ModuleWidgetGuildPresenceQuery;
use App\Widget\Module\ModuleWidgetRegistry;
use App\Widget\Module\ModuleWidgetViewer;
use App\Repository\GameRepository;
use App\Repository\GuildApplicationQuestionRepository;
use App\Repository\GuildMemberRepository;
use App\Repository\GuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class GamingController extends AbstractController
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly GuildRepository $guilds,
        private readonly GuildMemberRepository $members,
        private readonly GuildApplicationQuestionRepository $questions,
        private readonly SiteSettingsRepository $siteSettings,
        private readonly ModuleThemeCompositionRegistry $compositions,
        private readonly ModuleLayoutComposer $moduleLayouts,
        private readonly ModuleWidgetRegistry $moduleWidgets,
        private readonly ModuleWidgetGuildPresenceQuery $modulePresence,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.guild_application')]
        private readonly RateLimiterFactory $applicationLimiter,
    ) {}

    #[Route('/gaming', name: 'app_gaming_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query->all();
        $requestedGameSlug = $query['game'] ?? null;
        $selectedGame = null;
        $invalidGameFilter = false;

        if ($requestedGameSlug === null || $requestedGameSlug === '') {
            $guilds = $this->guilds->findPublicGuilds();
        } elseif (
            !is_string($requestedGameSlug)
            || strlen($requestedGameSlug) > 140
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $requestedGameSlug) !== 1
        ) {
            $guilds = [];
            $invalidGameFilter = true;
        } else {
            $selectedGame = $this->games->findOneBy(['slug' => $requestedGameSlug, 'enabled' => true]);
            if ($selectedGame === null) {
                $guilds = [];
                $invalidGameFilter = true;
            } else {
                $guilds = $this->guilds->findPublicGuilds($selectedGame);
            }
        }

        $viewer = ModuleWidgetViewer::anonymous();
        $moduleView = null;
        if ($selectedGame === null && !$invalidGameFilter) {
            $user = $this->getUser();
            $userId = $user instanceof User ? $user->getId() : null;
            if ($user instanceof User && $userId !== null && $user->isActive() && !$user->isLocked()) {
                $viewer = new ModuleWidgetViewer(
                    authenticated: true,
                    userId: $userId,
                    guildIds: $this->modulePresence->guildIdsForUser($userId),
                );
            }

            $composition = $this->compositions->get($this->siteSettings->current()->getThemeKey());
            $widgetKeys = array_values(array_unique(array_merge($composition->contentWidgets, $composition->sidebarWidgets)));
            $moduleView = $this->moduleLayouts->compose(
                $composition,
                $this->moduleWidgets->renderAll($viewer, $widgetKeys),
            );
        }

        $response = $this->render('gaming/index.html.twig', [
            'games' => $this->games->findEnabled(),
            'guilds' => $guilds,
            'selectedGame' => $selectedGame,
            'invalidGameFilter' => $invalidGameFilter,
            'moduleView' => $moduleView,
        ]);
        if ($viewer->authenticated) {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }

    #[Route('/gaming/guild/{slug}', name: 'app_guild_show', methods: ['GET'])]
    public function showGuild(string $slug): Response
    {
        $guild = $this->publicGuild($slug);
        return $this->render('gaming/show.html.twig', ['guild' => $guild, 'members' => $this->members->activeForGuild($guild)]);
    }

    #[Route('/gaming/guild/{slug}/apply', name: 'app_guild_apply', methods: ['GET', 'POST'])]
    public function apply(string $slug, Request $request): Response
    {
        $guild = $this->publicGuild($slug);
        if (!$guild->isRecruitmentOpen()) { throw $this->createNotFoundException(); }

        $questions = $this->questions->enabledForGuild($guild);
        $application = (new GuildApplication())->setGuild($guild);
        $form = $this->createForm(GuildApplicationType::class, $application, ['questions' => $questions])->handleRequest($request);
        $rateLimited = false;
        if ($form->isSubmitted()) {
            $key = 'guild-'.$guild->getId().'-'.($request->getClientIp() ?? 'unknown');
            if (!$this->applicationLimiter->create($key)->consume(1)->isAccepted()) {
                $form->addError(new \Symfony\Component\Form\FormError('Zu viele Bewerbungsversuche. Bitte versuche es später erneut.'));
                $rateLimited = true;
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $answers = [];
            foreach ($questions as $question) {
                $value = $form->get('question_'.$question->getId())->getData();
                $answers[] = ['question' => $question->getLabel(), 'answer' => is_bool($value) ? ($value ? 'Ja' : 'Nein') : trim((string) $value)];
            }
            $application->setAnswers($answers);
            $notification = (new AdminNotification())
                ->setType('guild_application')
                ->setTitle('Neue Bewerbung für '.$guild->getName())
                ->setMessage($application->getApplicantName().' bewirbt sich mit '.$application->getCharacterName().'.')
                ->setLink('/admin/gaming/applications');
            $this->entityManager->persist($application);
            $this->entityManager->persist($notification);
            $this->entityManager->flush();
            $this->addFlash('success', 'Deine Bewerbung wurde gesendet.');
            return $this->redirectToRoute('app_guild_show', ['slug' => $guild->getSlug()]);
        }

        $response = $this->render('gaming/apply.html.twig', ['guild' => $guild, 'form' => $form]);
        if ($rateLimited) {
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    private function publicGuild(string $slug): Guild
    {
        $guild = $this->guilds->findPublicBySlug($slug);
        if ($guild === null) { throw $this->createNotFoundException(); }
        return $guild;
    }
}
