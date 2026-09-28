<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminNotification;
use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use App\Entity\GuildApplication;
use App\Form\GuildApplicationType;
use App\GuildRecruitment\PublicGuildRoleNeedQuery;
use App\Module\CmsModuleManager;
use App\Repository\GuildApplicationQuestionRepository;
use App\Repository\GuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGuildRoleNeedApplicationController extends AbstractController
{
    public function __construct(
        private readonly GuildRepository $guilds,
        private readonly GuildApplicationQuestionRepository $questions,
        private readonly PublicGuildRoleNeedQuery $publicNeeds,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.guild_application')]
        private readonly RateLimiterFactory $applicationLimiter,
    ) {
    }

    #[Route('/gaming/guild/{slug}/apply/role-need', name: 'app_guild_role_need_apply', methods: ['GET', 'POST'])]
    public function __invoke(string $slug, Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $guild = $this->guilds->findPublicBySlug($slug);
        if (!$guild instanceof Guild || !$guild->isRecruitmentOpen()) {
            throw $this->createNotFoundException();
        }

        $need = $this->selectedNeed($guild, $request);
        $questions = $this->questions->enabledForGuild($guild);
        $application = (new GuildApplication())
            ->setGuild($guild)
            ->setCharacterClass($need->getClassKey());
        $form = $this->createForm(GuildApplicationType::class, $application, ['questions' => $questions])
            ->handleRequest($request);

        $rateLimited = false;
        if ($form->isSubmitted()) {
            $key = 'guild-'.$guild->getId().'-'.($request->getClientIp() ?? 'unknown');
            if (!$this->applicationLimiter->create($key)->consume(1)->isAccepted()) {
                $form->addError(new \Symfony\Component\Form\FormError('Zu viele Bewerbungsversuche. Bitte versuche es später erneut.'));
                $rateLimited = true;
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $answers = [[
                'question' => 'Gesuchte Rolle',
                'answer' => $need->getRoleKey().' · '.$need->getClassKey(),
            ]];
            foreach ($questions as $question) {
                $value = $form->get('question_'.$question->getId())->getData();
                $answers[] = [
                    'question' => $question->getLabel(),
                    'answer' => is_bool($value) ? ($value ? 'Ja' : 'Nein') : trim((string) $value),
                ];
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

            return $this->privateResponse($this->redirectToRoute('app_guild_show', ['slug' => $guild->getSlug()]));
        }

        $response = $this->render('guild_role_need_application/apply.html.twig', [
            'guild' => $guild,
            'need' => $need,
            'form' => $form,
        ]);
        if ($rateLimited) {
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->privateResponse($response);
    }

    private function selectedNeed(Guild $guild, Request $request): GuildRoleNeed
    {
        $parameters = $request->query->all();
        $gameSlug = $parameters['game'] ?? null;
        $roleKey = $parameters['role'] ?? null;
        $classKey = $parameters['classKey'] ?? null;

        if (!is_string($gameSlug) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $gameSlug) !== 1
            || !is_string($roleKey) || trim($roleKey) === '' || mb_strlen($roleKey, 'UTF-8') > 40
            || !is_string($classKey) || trim($classKey) === '' || mb_strlen($classKey, 'UTF-8') > 100
        ) {
            throw $this->createNotFoundException();
        }

        foreach ($this->publicNeeds->forGuild($guild) as $need) {
            if ($need->getGame()->getSlug() === $gameSlug
                && $need->getRoleKey() === $roleKey
                && $need->getClassKey() === $classKey
            ) {
                return $need;
            }
        }

        throw $this->createNotFoundException();
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
