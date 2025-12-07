<?php

namespace App\Repository;

use App\Entity\PaperReferences;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaperReferences>
 *
 * @method PaperReferences|null find($id, $lockMode = null, $lockVersion = null)
 * @method PaperReferences|null findOneBy(array $criteria, array $orderBy = null)
 * @method PaperReferences[]    findAll()
 * @method PaperReferences[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaperReferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaperReferences::class);
    }

    public function save(PaperReferences $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PaperReferences $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
