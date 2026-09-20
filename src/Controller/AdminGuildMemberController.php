<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Form\GuildMemberType;
use App\Repository\GuildMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/members')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildMemberController extends AbstractController
{
    public function __construct(private readonly GuildMemberRepository $members, private readonly EntityManagerInterface $entityManager) {}

    #[Route('', name: 'app_admin_guild_member_index', methods: ['GET'])]
    public function index(Guild $guild): Response
    {
        return $this->render('admin/gaming/member/index.html.twig', [
            'guild' => $guild,
            'members' => $this->members->findBy(['guild' => $guild], ['leader' => 'DESC', 'position' => 'ASC', 'characterName' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_guild_member_new', methods: ['GET', 'POST'])]
    public function new(Guild $guild, Request $request): Response { return $this->form($guild, (new GuildMember())->setGuild($guild), $request, 'Mitglied anlegen'); }

    #[Route('/{member}/edit', name: 'app_admin_guild_member_edit', requirements: ['member' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Guild $guild, GuildMember $member, Request $request): Response { $this->ensureGuild($guild, $member); return $this->form($guild, $member, $request, 'Mitglied bearbeiten'); }

    #[Route('/{member}/delete', name: 'app_admin_guild_member_delete', requirements: ['member' => '\d+'], methods: ['POST'])]
    public function delete(Guild $guild, GuildMember $member, Request $request): Response
    {
        $this->ensureGuild($guild, $member);
        if (!$this->isCsrfTokenValid('delete-member-'.$member->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $this->entityManager->remove($member);
        $this->entityManager->flush();
        $this->addFlash('success', 'Das Mitglied wurde gelöscht.');
        return $this->redirectToRoute('app_admin_guild_member_index', ['guild' => $guild->getId()]);
    }

    private function form(Guild $guild, GuildMember $member, Request $request, string $heading): Response
    {
        $form = $this->createForm(GuildMemberType::class, $member, ['guild' => $guild])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($member->getRank() !== null) { $member->setRankName($member->getRank()->getName()); }
            if ($member->getId() === null) { $this->entityManager->persist($member); }
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Mitglied wurde gespeichert.');
            return $this->redirectToRoute('app_admin_guild_member_index', ['guild' => $guild->getId()]);
        }
        return $this->render('admin/gaming/member/form.html.twig', ['form' => $form, 'guild' => $guild, 'heading' => $heading]);
    }

    private function ensureGuild(Guild $guild, GuildMember $member): void
    {
        if ($member->getGuild()?->getId() !== $guild->getId()) { throw $this->createNotFoundException(); }
    }
}
