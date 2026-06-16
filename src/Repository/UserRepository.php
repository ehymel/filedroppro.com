<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\Exception\InvalidDataException;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function createAlphabeticalUsernameQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('user')
            ->addOrderBy('user.username', 'ASC')
            ;
    }

    public function createAlphabeticalUserQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('user')
            ->addOrderBy('user.lastName', 'ASC')
            ->addOrderBy('user.firstName', 'ASC')
            ;
    }

    public function findOneByEmailOrUsername($username, $email): ?User
    {
        $q = $this->createQueryBuilder('user')
            ->where('user.username = :username')
            ->orWhere('user.email = :email')
            ->setParameter('username', $username)
            ->setParameter('email', $email)
            ->getQuery()
            ;

        return $q->getOneOrNullResult();
    }

    /**
     * @throws InvalidDataException
     */
    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        /** @var ?User $user */
        $user = $this->findOneBy(['username' => $username]);

        return $this->getUserEntity($user);
    }

    /**
     * @throws InvalidDataException
     */
    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        /** @var ?User $user */
        $user = $this->findOneBy(['username' => $userHandle]);

        return $this->getUserEntity($user);
    }

    /**
     * Converts a Symfony User (if any) into a Webauthn User Entity
     * @throws InvalidDataException
     */
    private function getUserEntity(?User $user): ?PublicKeyCredentialUserEntity
    {
        if ($user === null) {
            return null;
        }

        return new PublicKeyCredentialUserEntity(
            $user->getUserIdentifier(),
            $user->getUserIdentifier(),
            $user->getUserIdentifier(),
            null
        );
    }
}
