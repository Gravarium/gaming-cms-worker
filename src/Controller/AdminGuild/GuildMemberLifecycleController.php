<?php

declare(strict_types=1);

namespace App\Controller\AdminGuild;

use App\Entity\Guild;
use App\Entity\Guild\GuildMemberLifecycleEvent;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/lifecycle')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class GuildMemberLifecycleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/{member}/{action}', name: 'app_admin_guild_member_lifecycle', requirements: ['member' => '\d+', 'action' => 'absence|return'], methods: ['POST'])]
    public function record(Guild $guild, GuildMember $member, string $action, Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
        if ($member->getGuild() !== $guild) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('guild-lifecycle-'.$member->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $reason = trim($request->request->getString('reason'));
        if ($reason === '') {
            throw $this->createNotFoundException('A lifecycle reason is required.');
        }

        $event = new GuildMemberLifecycleEvent(
            $guild,
            $member,
            $actor,
            $action,
            $reason,
            $action === 'absence' ? new \DateTimeImmutable() : null,
        );
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_admin_guild_member_index', ['guild' => $guild->getId()]);
    }
}
