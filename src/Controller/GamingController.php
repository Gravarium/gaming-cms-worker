<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminNotification;
use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Form\GuildApplicationType;
use App\Repository\GameRepository;
use App\Repository\GuildApplicationQuestionRepository;
use App\Repository\GuildMemberRepository;
use App\Repository\GuildRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GamingController extends AbstractController
{
    public function __construct(
        private readonly GameRepository $games,
        private readonly GuildRepository $guilds,
        private readonly GuildMemberRepository $members,
        private readonly GuildApplicationQuestionRepository $questions,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/gaming', name: 'app_gaming_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('gaming/index.html.twig', ['games' => $this->games->findEnabled(), 'guilds' => $this->guilds->findPublicGuilds()]);
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

        return $this->render('gaming/apply.html.twig', ['guild' => $guild, 'form' => $form]);
    }

    private function publicGuild(string $slug): Guild
    {
        $guild = $this->guilds->findPublicBySlug($slug);
        if ($guild === null) { throw $this->createNotFoundException(); }
        return $guild;
    }
}
