<?php
namespace App\Services;
use App\Entity\PaperReferences;
use App\Repository\PaperReferencesRepository;
use Doctrine\ORM\EntityManagerInterface;

class References {

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function validateChoicesReferencesByUser(array $form) : int
    {
        $row = 0;
        if (array_key_exists("choice",$form)){

            foreach ($form['choice'] as $choiceRef) {
                $ref = $this->entityManager->getRepository(PaperReferences::class)->findOneBy(['id' => $choiceRef]);
                if (!is_null($ref) && $ref->getSourceId() !== PaperReferences::SOURCE_METADATA_EPI_USER) {
                    $ref->setSourceId(PaperReferences::SOURCE_METADATA_EPI_USER);
                    $ref->setUpdatedAt(new \DateTimeImmutable());
                    $this->entityManager->flush();
                    ++$row;
                }
            }
        }
        return $row;
    }
}