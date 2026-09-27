<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\MediaAsset;
use App\Entity\Profile\MemberProfile;
use App\Entity\Profile\ProfileDeletionRequest;
use App\Entity\User;
use App\Form\Profile\MemberProfileType;
use App\Profile\ProfileDataRightsService;
use App\Profile\ProfileMediaReferencePolicy;
use App\Profile\ProfileModuleAvailability;
use App\Profile\ProfileVisibilityPolicy;
use App\Repository\Profile\MemberProfileRepository;
use App\Repository\Profile\ProfileDeletionRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MemberProfileController extends AbstractController
{
    public function __construct(
        private readonly ProfileModuleAvailability $availability,
        private readonly MemberProfileRepository $profiles,
        private readonly ProfileDeletionRequestRepository $deletions,
        private readonly ProfileVisibilityPolicy $visibility,
        private readonly ProfileMediaReferencePolicy $media,
        private readonly ProfileDataRightsService $dataRights,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/members/{id}', name: 'app_profile_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, UserRepository $users): Response
    {
        $this->assertEnabled();

        $member = $users->find($id);
        if (!$member instanceof User || !$member->isActive()) {
            throw $this->createNotFoundException();
        }

        $profile = $this->profiles->forUser($member);
        if (!$profile instanceof MemberProfile) {
            throw $this->createNotFoundException();
        }

        $viewer = $this->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;

        return $this->render('profile/show.html.twig', [
            'member' => $member,
            'displayName' => $this->visibility->canView($profile, MemberProfile::FIELD_DISPLAY_NAME, $viewer)
                ? $member->getDisplayName()
                : 'Mitglied',
            'bio' => $this->visibility->canView($profile, MemberProfile::FIELD_BIO, $viewer)
                ? $profile->getBio()
                : null,
            'avatarUrl' => $this->visibility->canView($profile, MemberProfile::FIELD_AVATAR, $viewer)
                ? $this->safeMediaLocation($profile->getAvatar())
                : null,
            'bannerUrl' => $this->visibility->canView($profile, MemberProfile::FIELD_BANNER, $viewer)
                ? $this->safeMediaLocation($profile->getBanner())
                : null,
        ]);
    }

    #[Route('/account/profile', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request): Response
    {
        $this->assertEnabled();
        $user = $this->requireUser();

        $profile = $this->profiles->forUser($user);
        $new = !$profile instanceof MemberProfile;
        if ($new) {
            $profile = new MemberProfile($user);
        }

        $form = $this->createForm(MemberProfileType::class, $profile, [
            'avatar_asset_id' => $profile->getAvatar()?->getId(),
            'banner_asset_id' => $profile->getBanner()?->getId(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $avatarId = $form->get('avatarAssetId')->getData();
                $bannerId = $form->get('bannerAssetId')->getData();
                $profile
                    ->setAvatar($this->media->resolve(is_int($avatarId) ? $avatarId : null))
                    ->setBanner($this->media->resolve(is_int($bannerId) ? $bannerId : null));
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }

            if ($form->isValid()) {
                if ($new) {
                    $this->entityManager->persist($profile);
                }
                $this->entityManager->flush();
                $this->addFlash('success', 'Profil gespeichert.');

                return $this->redirectToRoute('app_profile_edit');
            }
        }

        $response = $this->render('profile/edit.html.twig', [
            'form' => $form,
            'profile' => $profile,
        ]);
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/account/profile/data', name: 'app_profile_data_rights', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dataRights(): Response
    {
        $this->assertEnabled();
        $user = $this->requireUser();
        $deletion = $this->deletions->find($user->getId());

        return $this->render('profile/data_rights.html.twig', [
            'deletion' => $deletion instanceof ProfileDeletionRequest ? $deletion : null,
        ]);
    }

    #[Route('/account/profile/export.json', name: 'app_profile_export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function export(): JsonResponse
    {
        $this->assertEnabled();

        $response = new JsonResponse($this->dataRights->export($this->requireUser()));
        $response->headers->set('Content-Disposition', 'attachment; filename="profile-export.json"');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    #[Route('/account/profile/delete-request', name: 'app_profile_delete_request', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function requestDeletion(Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('profile-delete-request', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->dataRights->requestDeletion($this->requireUser(), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'Kontolöschung vorgemerkt. Die Aufbewahrungsfrist beträgt 30 Tage.');

        return $this->redirectToRoute('app_profile_data_rights');
    }

    #[Route('/account/profile/delete-cancel', name: 'app_profile_delete_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelDeletion(Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('profile-delete-cancel', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->dataRights->cancelDeletion($this->requireUser(), new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'Vorgemerkte Kontolöschung abgebrochen.');

        return $this->redirectToRoute('app_profile_data_rights');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function safeMediaLocation(?MediaAsset $asset): ?string
    {
        $id = $asset?->getId();
        if ($id === null) {
            return null;
        }

        try {
            return $this->media->resolve($id)?->getLocation();
        } catch (\DomainException) {
            return null;
        }
    }
}
