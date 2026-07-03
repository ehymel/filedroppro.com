<?php

namespace App\Repository;

use App\Entity\DocumentKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentKey>
 *
 * @method DocumentKey|null find($id, $lockMode = null, $lockVersion = null)
 * @method DocumentKey|null findOneBy(array $criteria, array $orderBy = null)
 * @method DocumentKey[]    findAll()
 * @method DocumentKey[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DocumentKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentKey::class);
    }

    public function save(DocumentKey $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DocumentKey $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
