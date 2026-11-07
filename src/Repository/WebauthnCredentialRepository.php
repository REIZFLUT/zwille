<?php

declare(strict_types=1);

namespace App\Repository;

use App\Audit\AuditLogger;
use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Credential storage for the WebAuthn bundle. New registrations arrive as plain
 * CredentialRecord objects and are turned into WebauthnCredential entities here;
 * after a login the bundle hands back the loaded entity with the new counter.
 *
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements CredentialRecordRepositoryInterface, CanSaveCredentialRecord
{
    public function __construct(ManagerRegistry $registry, private readonly UserRepository $users, private readonly AuditLogger $audit)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return $this->findBy(['userHandle' => $publicKeyCredentialUserEntity->id]);
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        return $this->createQueryBuilder('c')
            ->where('c.publicKeyCredentialId = :id')
            ->setParameter('id', $publicKeyCredentialId, 'base64')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        if ($credentialRecord instanceof WebauthnCredential) {
            $credentialRecord->markUsed();
            $this->getEntityManager()->flush();

            return;
        }

        $user = $this->users->findOneByWebauthnHandle($credentialRecord->userHandle)
            ?? throw new \RuntimeException('No user for this WebAuthn user handle.');

        $credential = WebauthnCredential::fromRecord(
            $credentialRecord,
            $user,
            'Passkey '.(\count($this->findByUser($user)) + 1),
        );
        $this->getEntityManager()->persist($credential);
        $this->getEntityManager()->flush();
        // A new passkey has no event of its own in the WebAuthn bundle, so this real
        // persistence point is where the registration is recorded.
        $this->audit->record(AuditLogger::PASSKEY_ADDED, ['name' => $credential->getName()], actor: $user, targetType: 'user', targetId: $user->getId());
    }

    /** @return list<WebauthnCredential> */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC']);
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
