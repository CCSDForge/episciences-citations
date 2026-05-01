<?php

namespace App\Command;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Services\Doi;
use App\Services\References;
use App\Services\SemanticScholarImporter;
use App\Services\SolrReferenceEnricher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(name: 'app:get-bibref', description: 'Retrieve the csv and process doi in csv to csl ref', aliases: ['app:get-bibref'], hidden: false)]
class GetBibRefCommand extends Command
{

    public function __construct(
        private readonly Doi                    $doiService,
        private readonly References             $references,
        private readonly SemanticScholarImporter $semanticsScholarImporter,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentRepository     $documentRepository,
        private readonly LoggerInterface        $logger,
        private readonly SolrReferenceEnricher  $solrReferenceEnricher,
    )
    {
        parent::__construct();
    }

    /**
     * @param array<string> $arrayDoiInDb
     * @param array<string> $arrayRefTxt
     * @throws \JsonException
     */
    public function processCslToGetRef(string $csl, array $arrayDoiInDb, array $arrayRefTxt, OutputInterface $output, int $counterRef, int|string $docId): mixed
    {
        $refRetrieved = null;
        if ($csl !== '') {
            $refForDb = $this->doiService->retrieveReferencesFromCsl(json_decode($csl, true, 512, JSON_THROW_ON_ERROR));
            foreach ($refForDb as $refRetrieved) {
                if ((isset($refRetrieved['doi']) && (in_array($refRetrieved['doi'], $arrayDoiInDb, true)))
                    || in_array(serialize($refRetrieved['raw_reference']), $arrayRefTxt, true)) {
                    // outputs a message followed by a "\n"
                    $output->writeln($refRetrieved['raw_reference'] . ' Already in Database');
                    $this->logger->info('SCRIPT CSV => ALREADY IN DB : ' . $refRetrieved['raw_reference']);
                } else {
                    $this->insertRefInDb($refRetrieved, $counterRef, $docId);
                    $output->writeln('New inserted => ' . $refRetrieved['raw_reference']);
                    $this->logger->info('SCRIPT CSV => INSERT IN DB : ' . $refRetrieved['raw_reference']);
                }
            }
            $output->writeln(' ');
        }
        return $refRetrieved;
    }

    /**
     * @return array{0: PaperReferences, 1: int}
     */
    public function insertRefInDb(mixed $refRetrieved, int $counterRef, int|string $docId, string $source = PaperReferences::SOURCE_METADATA_EPI_USER): array
    {
        $user = $this->entityManager->getRepository(UserInformations::class)->find(666);
        if (is_null($user)) {
            $user = new UserInformations();
            $user->setId(666);
            $user->setSurname('Episciences');
            $user->setName('System');
        }
        $reference = $this->solrReferenceEnricher->enrichReference($refRetrieved);
        $ref = new PaperReferences();
        $ref->setReference($reference);
        $ref->setSource($source);
        $ref->setUpdatedAt(new \DateTimeImmutable());
        $ref->setReferenceOrder($counterRef++);
        if (!$this->references->getDocument($docId) instanceof Document) {
            $this->references->createDocumentId($docId);
        }
        $ref->setDocument($this->references->getDocument($docId));
        $ref->setAccepted(0);
        $ref->setUid($user);
        $this->entityManager->persist($ref);
        $this->entityManager->flush();
        return [$ref, $counterRef];
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    public function processCsv(InputInterface $input): array
    {
        $pathCsv = $input->getArgument('csv');
        $csvData = array_map(str_getcsv(...), file($pathCsv));
        // Extract the column names from the first row
        $columnNames = array_map(trim(...), array_shift($csvData));
        // Initialize an empty array to store the processed data
        $globalData = [];
        // Loop through each row of the CSV data
        foreach ($csvData as $row) {
            $rowData = array_combine($columnNames, $row);
            $globalData[$rowData['docid']][] = array_map(trim(...), $rowData);
        }
        return $globalData;
    }
    protected function configure(): void
    {
        $this
            // ...
            ->addArgument('csv', InputArgument::REQUIRED, 'CSV')
            ->addOption(
                'api',
                '-a',
                InputOption::VALUE_OPTIONAL,
                'Optional API before Process references :
            - S2 (semantics scholar)',false)
            ->addOption('output',
                '-o',
                InputOption::VALUE_OPTIONAL,
                'path output in BibTeX the semantics scholar result (use only if S2 option is used)',
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // outputs multiple lines to the console (adding "\n" at the end of each line)
        $output->writeln([
            'START SCRIPT',
            '============',
            '',
        ]);
        $globalData = $this->processCsv($input);
        // creates a new progress bar (50 units)
        $progressBar = new ProgressBar($output, count($globalData));
        $progressBar->start();
        $progressBar->setBarCharacter('<fg=green>=</>');
        $progressBar->setEmptyBarCharacter("<fg=red>|</>");
        $progressBar->setProgressCharacter("<fg=green>></>");
        $progressBar->start();

        // Print the processed data array
        foreach ($globalData as $docId => $allRef) {
            $this->logger->info('==== START SCRIPT CSV ==== ');
            $output->writeln(' SEARCH FOR THIS => ' . $docId);
            $this->logger->info('SCRIPT CSV => SEARCH FOR THIS => DocId : ' . $docId);
            $docExisting = $this->documentRepository->find($docId);
            $referenceAlreadyAccepted = [];
            $arrayDoiInDb = [];
            $arrayRefTxt = [];
            $counterRef = 0;
            if ($docExisting !== null) {
                $reOrdonateCounter = 0;
                foreach ($docExisting->getPaperReferences() as $doc) {
                    $doc->setReferenceOrder($reOrdonateCounter);
                    $referenceAlreadyAccepted[] = $doc->getReference();
                    $this->entityManager->persist($doc);
                    $reOrdonateCounter++;
                    $counterRef++;
                }

                foreach ($referenceAlreadyAccepted as $refDb) {
                    $arrayRefTxt[] = array_key_exists('csl', $refDb) ? serialize($refDb['csl']) : serialize($refDb['raw_reference']);
                    if (isset($refDb['doi'])) {
                        $arrayDoiInDb[] = $refDb['doi'];
                    }
                }
                $this->entityManager->flush();
            }

            foreach ($allRef as $ref) {
                $optionValue = $input->getOption('api');
                if (!is_null($optionValue) && $optionValue !== false && $optionValue === "S2") {
                    $this->semanticsScholarImporter->importByPaperId('DOI:' . $ref['doi'], (int) $docId, $counterRef);
                } else {
                    $csl = $this->doiService->getCsl($ref['doi']);
                    $refRetrieved = $this->processCslToGetRef($csl, $arrayDoiInDb, $arrayRefTxt, $output, $counterRef, $docId);
                }
            }
            $progressBar->advance();
        }
        $output->writeln([
            ' END SCRIPT',
            '=================================',
            '',
        ]);
        $this->logger->info('==== END SCRIPT CSV ==== ');
        return Command::SUCCESS;
    }
}
