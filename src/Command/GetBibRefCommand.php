<?php

namespace App\Command;

use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Services\Doi;
use App\Services\References;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:get-bibref',
    description: 'Retrieve the csv and process doi in csv to csl ref',
    hidden: false,
    aliases: ['app:get-bibref']
)]
class GetBibRefCommand extends Command
{


    public function __construct(
        private Doi                    $doiService,
        private References             $references,
        private EntityManagerInterface $entityManager,
        private DocumentRepository     $documentRepository,
        private LoggerInterface        $logger
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // ...
            ->addArgument('csv', InputArgument::REQUIRED, 'CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln([
            'START SCRIPT',
            '============',
            '',
        ]);
        $pathCsv = $input->getArgument('csv');
        $csvData = array_map('str_getcsv', file($pathCsv));
        // Extract the column names from the first row
        $columnNames = array_map('trim', array_shift($csvData));
        // Initialize an empty array to store the processed data
        $globalData = [];
        // Loop through each row of the CSV data
        foreach ($csvData as $row) {
            $rowData = array_combine($columnNames, $row);
            $globalData[$rowData['docid']][] = array_map('trim', $rowData);
        }
        // Print the processed data array
        foreach ($globalData as $docId => $allRef) {
            $this->logger->info('==== START SCRIPT CSV ==== ');
            $output->writeln('SEARCH FOR THIS => '.$docId);
            $this->logger->info('SCRIPT CSV => SEARCH FOR THIS => DocId : '.$docId);
            $docExisting = $this->documentRepository->find($docId);
            $referenceAlreadyAccepted = [];
            $arrayDoiInDb = [];
            $arrayRefTxt = [];
            $counterRef = 0;
            if ($docExisting !== null) {
                $reOrdonateCounter = 0;
                foreach ($docExisting->getPaperReferences() as $doc) {
                    $doc->setReferenceOrder($reOrdonateCounter);
                    $referenceAlreadyAccepted[] =
                        json_decode($doc->getReference()[0], true, 512, JSON_THROW_ON_ERROR);
                    $this->entityManager->persist($doc);
                    $reOrdonateCounter++;
                    $counterRef++;
                }
                foreach ($referenceAlreadyAccepted as $refDb) {
                    $arrayRefTxt[] = serialize($refDb['raw_reference']);
                    if (isset($refDb['doi'])) {
                        $arrayDoiInDb[] = $refDb['doi'];
                    }
                }
                $this->entityManager->flush();
            }
            foreach ($allRef as $ref) {
                $csl = $this->doiService->getCsl($ref['doi']);
                if ($csl !== '') {
                    $refForDb = $this->doiService->retrieveReferencesFromCsl(json_decode($csl, true, 512, JSON_THROW_ON_ERROR));
                    foreach ($refForDb as $refRetrieved) {
                        if ((isset($refRetrieved['doi']) && (in_array($refRetrieved['doi'], $arrayDoiInDb, true)))
                            || in_array(serialize($refRetrieved['raw_reference']), $arrayRefTxt, true)) {
                            // outputs a message followed by a "\n"
                            $output->writeln($refRetrieved['raw_reference']. ' Already in Database');
                            $this->logger->info('SCRIPT CSV => ALREADY IN DB : '.$refRetrieved['raw_reference']);
                        } else {
                            $user = $this->entityManager->getRepository(UserInformations::class)->find(666);
                            if (is_null($user)) {
                                $user = new UserInformations();
                                $user->setId(666);
                                $user->setSurname('Episciences');
                                $user->setName('System');
                            }
                            $reference = $refRetrieved;
                            $ref = new PaperReferences();
                            $ref->setReference([json_encode($reference)]);
                            $ref->setSource(PaperReferences::SOURCE_METADATA_EPI_USER);
                            $ref->setUpdatedAt(new \DateTimeImmutable());
                            $ref->setReferenceOrder($counterRef++);
                            if ($this->references->getDocument($docId) === null) {
                                $this->references->createDocumentId($docId);
                            }
                            $ref->setDocument($this->references->getDocument($docId));
                            $ref->setAccepted(1);
                            $ref->setUid($user);
                            $this->entityManager->persist($ref);
                            $this->entityManager->flush();
                            $output->writeln('New inserted => '. $refRetrieved['raw_reference']);
                            $this->logger->info('SCRIPT CSV => INSERT IN DB : '.$refRetrieved['raw_reference']);
                        }
                    }
                    $output->writeln(' ');
                }
            }

        }
        $output->writeln([
            'END SCRIPT',
            '============',
            '',
        ]);
        $this->logger->info('==== END SCRIPT CSV ==== ');
        return Command::SUCCESS;
    }
}
