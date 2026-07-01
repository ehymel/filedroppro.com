<?php

namespace App\Repository;

use App\Entity\DropRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DropRequest>
 *
 * @method DropRequest|null find($id, $lockMode = null, $lockVersion = null)
 * @method DropRequest|null findOneBy(array $criteria, array $orderBy = null)
 * @method DropRequest[]    findAll()
 * @method DropRequest[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DropRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DropRequest::class);
    }

    public function save(DropRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DropRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findAllSortedByCreatedAt(): array
    {
        return $this->createQueryBuilder('dropRequest')
            ->addOrderBy('dropRequest.createdAt', 'DESC')
            ->getQuery()->getResult();
    }
}
