<?php
declare(strict_types=1);
namespace App\Repository;
use App\Entity\User;
use LogicException;
use Webauthn\Bundle\Repository\CanRegisterUserEntity;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;
final readonly class PublicKeyCredentialUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface, CanRegisterUserEntity
{
    public function __construct(private UserRepository $users) {}
    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity { return $this->toWebauthnUser($this->users->findOneBy(['email' => mb_strtolower(trim($username)), 'isActive' => true])); }
    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity { return $this->toWebauthnUser($this->users->find($userHandle)); }
    public function saveUserEntity(PublicKeyCredentialUserEntity $userEntity): void
    {
        if (!$this->users->find($userEntity->id) instanceof User) { throw new LogicException('Passkeys können nur für bestehende Benutzerkonten registriert werden.'); }
    }
    private function toWebauthnUser(?User $user): ?PublicKeyCredentialUserEntity
    {
        if (!$user instanceof User || $user->getId() === null) { return null; }
        return PublicKeyCredentialUserEntity::create($user->getUserIdentifier(), (string) $user->getId(), $user->getDisplayName());
    }
}
