<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Form\GuildCharacterProfileType;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/guild-area')]
#[IsGranted('ROLE_USER')]
final class GuildCharacterProfileController extends AbstractController
{
    #[Route(
        '/{id}/character/{member}/edit',
        name: 'app_guild_character_profile_edit',
        requirements: ['id' => '\\d+', 'member' => '\\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(
        Guild $guild,
        GuildMember $member,
        Request $request,
        EntityManagerInterface $entityManager,
        AuditLogger $audit,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (
            $member->getGuild()?->getId() !== $guild->getId()
            || $member->getUser()?->getId() !== $user->getId()
            || !$member->isActive()
        ) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(GuildCharacterProfileType::class, $member)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $audit->record(
                'guild.character_profile.updated',
                $member,
                $member->getId(),
                'Eigenes Gildencharakterprofil aktualisiert.',
            );
            $entityManager->flush();

            $this->addFlash('success', 'Dein Charakterprofil wurde gespeichert.');
            $response = $this->redirectToRoute('app_guild_portal_show', ['id' => $guild->getId()]);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        $response = $this->render('guild_portal/character_edit.html.twig', [
            'form' => $form,
            'guild' => $guild,
            'character' => $member,
        ]);
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
