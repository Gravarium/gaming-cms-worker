<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\CredentialRecord;
use App\Entity\User;
use App\Repository\CredentialRecordRepository;
use App\Repository\PublicKeyCredentialUserEntityRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/account/passkeys')]
#[IsGranted('ROLE_USER')]
final class PasskeyController extends AbstractController
{
    public function __construct(private readonly CredentialRecordRepository $credentials, private readonly PublicKeyCredentialUserEntityRepository $users, private readonly EntityManagerInterface $entityManager, private readonly AuditLogger $audit) {}
    #[Route('', name: 'app_account_passkeys', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser(); $userEntity = $this->users->findOneByUserHandle((string) $user->getId());
        return $this->render('account/passkeys.html.twig', ['passkeys' => $userEntity === null ? [] : $this->credentials->findAllForUserEntity($userEntity)]);
    }
    #[Route('/{id}/rename', name: 'app_account_passkey_rename', methods: ['POST'])]
    public function rename(string $id, Request $request): Response
    {
        $credential = $this->ownedCredential($id);
        if (!$this->isCsrfTokenValid('passkey-rename-'.$id, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $name = trim((string) $request->request->get('name'));
        if ($name === '') { $this->addFlash('error', 'Bitte gib dem Passkey einen Namen.'); }
        else { $credential->rename($name); $this->audit->record('security.passkey.renamed', $this->currentUser(), null, 'Passkey umbenannt.', ['credentialId' => $id]); $this->entityManager->flush(); $this->addFlash('success', 'Der Passkey wurde umbenannt.'); }
        return $this->redirectToRoute('app_account_passkeys');
    }
    #[Route('/{id}/delete', name: 'app_account_passkey_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $credential = $this->ownedCredential($id);
        if (!$this->isCsrfTokenValid('passkey-delete-'.$id, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $this->audit->record('security.passkey.deleted', $this->currentUser(), null, 'Passkey gelöscht.', ['credentialId' => $id]); $this->entityManager->flush();
        $this->credentials->remove($credential); $this->addFlash('success', 'Der Passkey wurde gelöscht.');
        return $this->redirectToRoute('app_account_passkeys');
    }
    private function ownedCredential(string $id): CredentialRecord
    {
        $credential = $this->credentials->find($id); $user = $this->currentUser();
        if (!$credential instanceof CredentialRecord || $credential->userHandle !== (string) $user->getId()) { throw $this->createNotFoundException(); }
        return $credential;
    }
    private function currentUser(): User
    {
        $user = $this->getUser(); if (!$user instanceof User || $user->getId() === null) { throw $this->createAccessDeniedException(); } return $user;
    }
}
