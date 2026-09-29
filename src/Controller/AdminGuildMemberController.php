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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/members')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildMemberController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_QUERY_LENGTH = 120;

    public function __construct(private readonly GuildMemberRepository $members, private readonly EntityManagerInterface $entityManager) {}

    #[Route('', name: 'app_admin_guild_member_index', methods: ['GET'])]
    public function index(Guild $guild, Request $request): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        $rawStatus = $parameters['status'] ?? '';
        $rawPage = $parameters['page'] ?? '1';

        if (
            !is_string($rawQuery)
            || !mb_check_encoding($rawQuery, 'UTF-8')
            || mb_strlen($rawQuery) > self::MAX_QUERY_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $rawQuery) === 1
            || !is_string($rawStatus)
            || !in_array($rawStatus, ['', 'active', 'inactive'], true)
            || !is_string($rawPage)
            || preg_match('/^[1-9][0-9]{0,8}$/D', $rawPage) !== 1
        ) {
            throw new BadRequestHttpException('Ungültige Mitgliedersuche.');
        }

        $query = trim($rawQuery);
        $status = $rawStatus === '' ? null : $rawStatus;
        $active = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
        $total = $this->members->countForAdminGuild($guild, $query, $active);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $currentPage = min((int) $rawPage, $pageCount);
        $members = $this->members->findForAdminGuild(
            $guild,
            $query,
            $active,
            self::PAGE_SIZE,
            ($currentPage - 1) * self::PAGE_SIZE,
        );

        $response = $this->render('admin/gaming/member/index.html.twig', [
            'guild' => $guild,
            'members' => $members,
            'memberQuery' => $query,
            'memberStatus' => $status,
            'memberTotal' => $total,
            'memberCurrentPage' => $currentPage,
            'memberPageCount' => $pageCount,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
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
