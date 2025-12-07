<?php

namespace App\Repository;

use App\Entity\UserInformations;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserInformations>
 *
 * @method UserInformations|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserInformations|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserInformations[]    findAll()
 * @method UserInformations[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserInformationsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserInformations::class);
    }

    public function save(UserInformations $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserInformations $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
