<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Client>
 *
 * @method Client|null find($id, $lockMode = null, $lockVersion = null)
 * @method Client|null findOneBy(array $criteria, array $orderBy = null)
 * @method Client[]    findAll()
 * @method Client[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    public function save(Client $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Client $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Returns the Client matching (tenant, clientName), creating it if absent.
     *
     * Safe against concurrent callers (e.g. a client dropping several files at once,
     * each finalizing in a separate parallel request): the insert is a single atomic
     * `INSERT IGNORE` guarded by the uniq_client_tenant_name
     * constraint, so a lost race becomes a no-op rather than a duplicate row or a
     * UniqueConstraintViolationException that would poison the ORM unit of work.
     *
     * The returned entity is managed, so callers may attach documents / access grants
     * and rely on their own flush.
     */
    public function findOrCreate(Tenant $tenant, string $clientName): Client
    {
        $clientName = trim($clientName);

        $existing = $this->findOneBy(['tenant' => $tenant, 'clientName' => $clientName]);
        if ($existing !== null) {
            return $existing;
        }

        $conn = $this->getEntityManager()->getConnection();

        // Runs outside any ORM transaction, so it commits immediately and is visible
        // to concurrent finalizers. On conflict the row already exists — we re-fetch it below.
        $conn->executeStatement(
            'INSERT IGNORE INTO client (id, tenant_id, client_name, created_at)
             VALUES (UNHEX(REPLACE(:id, "-", "")), UNHEX(REPLACE(:tenant_uuid, "-", "")), :name, :createdAt)',
            [
                'id' => Uuid::v4()->toString(),
                'tenant_uuid' => $tenant->id->toString(),
                'name' => $clientName,
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        // Whether this request won or lost the race, the row now exists.
        return $this->findOneBy(['tenant' => $tenant, 'clientName' => $clientName]);
    }

    public function createAlphabeticalUserQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('client')
            ->addOrderBy('client.clientName', 'ASC')
            ;
    }
}
