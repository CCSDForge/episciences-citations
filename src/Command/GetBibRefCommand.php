<?php

namespace App\Command;

use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use App\Repository\DocumentRepository;
use App\Services\Bibtex;
use App\Services\Doi;
use App\Services\References;
use App\Services\Semanticsscholar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    public const PREFIX_ARXIV = "10.48550/";
    public function __construct(
        private Doi                    $doiService,
        private References             $references,
        private Semanticsscholar       $semanticsscholar,
        private EntityManagerInterface $entityManager,
        private DocumentRepository     $documentRepository,
        private LoggerInterface        $logger,
        private Bibtex                 $bibtexService,
    )
    {
        parent::__construct();
    }

    /**
     * @param mixed $semanticsRef
     * @param OutputInterface $output
     * @param int $counterRef
     * @param int|string $docId
     * @param array $bibArray
     * @param mixed $outopt
     * @return mixed|string
     * @throws \JsonException
     */
    public function processS2Ref(mixed $semanticsRef, OutputInterface $output, int $counterRef, int|string $docId, array $bibArray, mixed $outopt): mixed
    {
        if ($semanticsRef !== '' && isset($semanticsRef['data'])) {
            foreach ($semanticsRef['data'] as $rSemantics) {
                if ($this->hasDoi($rSemantics)) {
                    $this->insertRefS2fromDoi($output, $rSemantics['citedPaper']['externalIds']['DOI'], $counterRef, $docId);
                    $bibArray[] = $this->getBibtexFromDoi($rSemantics['citedPaper']['externalIds']['DOI']);
                } elseif ($this->hasBibTeX($rSemantics)) {
                    if ($this->hasMandatoryBibtexInfo($rSemantics)) {
                        //case no ID but Bibtex is in
                        $this->insertCslFromBibtexS2($output, $rSemantics['citedPaper']['citationStyles']['bibtex'], $counterRef, $docId);
                        $bibArray[] = $rSemantics['citedPaper']['citationStyles']['bibtex'];
                    } elseif ($this->hasUrlInTitle($rSemantics['citedPaper']['title'])) {
                        //case url in title and nothing else in array get the most info possible
                        $this->InsertFromUrlText($output, $rSemantics['citedPaper'], $counterRef, $docId);
                    }
                } elseif ($this->hasArxiv($rSemantics)) {
                    $this->insertRefFromArXivIdS2($output, $rSemantics['citedPaper']['externalIds'], $counterRef, $docId);
                    $bibArray[] = $this->getBibtexFromDoi($rSemantics['citedPaper']['externalIds']['ArXiv'], 'arxiv');
                }
            }
            $this->writeBibtexToFile($outopt, $docId, $bibArray);
        }
        return $semanticsRef;
    }

    /**
     * @param mixed $rSemantics
     * @return bool
     */
    public function hasMandatoryBibtexInfo(mixed $rSemantics): bool
    {
        return isset($rSemantics['citedPaper']['title'],
            $rSemantics['citedPaper']['year'],
            $rSemantics['citedPaper']['authors'],
            $rSemantics['citedPaper']['citationStyles']['bibtex']);
    }

    /**
     * @param mixed $outopt
     * @param int|string $docId
     * @param array $bibArray
     * @return void
     */
    public function writeBibtexToFile(mixed $outopt, int|string $docId, array $bibArray): void
    {
        if (!is_dir($outopt) && !mkdir($outopt, 0777, true) && !is_dir($outopt)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $outopt));
        }
        if ($outopt !== '') {
            array_map('trim', $bibArray);
            file_put_contents($outopt . $docId . '.bib', implode(',', $bibArray));
        }
    }

    /**
     * @param mixed $rSemantics
     * @return bool
     */
    public function hasArxiv(mixed $rSemantics): bool
    {
        return !isset($rSemantics['citedPaper']['externalIds']['DOI'])
            && isset($rSemantics['citedPaper']['externalIds']['ArXiv']);
    }

    /**
     * @param $title
     * @return bool
     */
    public function hasUrlInTitle($title): bool
    {
        return strpos($title, 'https://') || strpos($title, 'http://');
    }

    /**
     * @param mixed $rSemantics
     * @return bool
     */
    public function hasBibTeX(mixed $rSemantics): bool
    {
        return !isset($rSemantics['citedPaper']['externalIds']) ||
            (!isset($rSemantics['citedPaper']['externalIds']['DOI']) && !isset($rSemantics['citedPaper']['externalIds']['ArXiv']));
    }

    /**
     * @param mixed $rSemantics
     * @return bool
     */
    public function hasDoi(mixed $rSemantics): bool
    {
        return isset($rSemantics['citedPaper']['externalIds']['DOI'])
            && !empty($rSemantics['citedPaper']['externalIds'])
            && $rSemantics['citedPaper']['externalIds']['DOI'] !== '';
    }

    /**
     * @param OutputInterface $output
     * @param $externalIds
     * @param int $counterRef
     * @param int|string $docId
     * @return mixed|string
     * @throws \JsonException
     */
    public function insertRefFromArXivIdS2(OutputInterface $output, $externalIds, int $counterRef, int|string $docId): mixed
    {
        // case arxiv in IDS
        $output->writeln('ArXiv founded => ' . $externalIds['ArXiv']);
        $this->logger->info('SCRIPT CSV => ArXiv founded => ' . $externalIds['ArXiv']);
        $arxivId = $externalIds['ArXiv'];
        // case with have arxiv but not doi -> doi.org with arxiv
        if (!strpos($externalIds['ArXiv'], 'arxiv')) {
            $arxivId = 'arxiv.' . $arxivId;
        }
        $arxivId = self::PREFIX_ARXIV . $arxivId;
        $csl = $this->doiService->getCsl($arxivId);
        if ($csl !== '') {
            $this->logger->info('SCRIPT CSV => CSL From ARXIV ID founded => ' . $csl);
            $newRef = (['csl' => json_decode($csl, true, 512, JSON_THROW_ON_ERROR),
                'doi' => $arxivId]);
            $this->insertRefInDb($newRef, $counterRef, $docId, PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
        } else {
            $this->logger->info('CSL From ARXIV ID/Resource not found => ' . $externalIds['DOI']);
            $output->writeln('SCRIPT CSV => CSL From ARXIV ID/Resource not found =>' . $externalIds['DOI']);
        }
        $this->insertRefInDb($newRef, $counterRef, $docId, PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
        return $csl;
    }

    /**
     * @param OutputInterface $output
     * @param $citedPaper
     * @param int $counterRef
     * @param int|string $docId
     * @return array
     */
    public function InsertFromUrlText(OutputInterface $output, $citedPaper, int $counterRef, int|string $docId): array
    {
        $output->writeln('URL FOUNDED IN TITLE AND NO IDS AND BIBTEX NEITHER, CREATION OF SPECIFIC CSL => ' . $citedPaper['title']);
        $this->logger->info(('SCRIPT CSV => URL FOUNDED IN TITLE AND NO IDS AND BIBTEX NEITHER CREATION OF SPECIFIC CSL => ' . $citedPaper['title']));
        $entry = [
            'title' => $citedPaper['title'] ?? '',
            'type' => $citedPaper['type'] ?? '',
            'author' => $citedPaper['authors'] ?? [],
            'year' => $citedPaper['year'] ?? '',
        ];
        $csl = $this->bibtexService::generateCSL($entry);
        $this->logger->info('SCRIPT CSV => SPECIFIC CSL => ', ['csl' => $csl]);
        $reference = (['csl' => $csl]);
        $this->insertRefInDb($reference, $counterRef, $docId, PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
        return $csl;
    }

    /**
     * @param OutputInterface $output
     * @param $bibtex
     * @param int $counterRef
     * @param int|string $docId
     * @return array
     */
    public function insertCslFromBibtexS2(OutputInterface $output, $bibtex, int $counterRef, int|string $docId): array
    {
        $output->writeln('NO IDS BUT BIBTEX FOUND IN S2 CITED PAPER => ' . $bibtex);
        $this->logger->info('SCRIPT CSV => NO IDS BUT BIBTEX FOUND IN S2 CITED PAPER => ' . $bibtex);
        $bibInfo = $this->bibtexService::convertBibtexToArray($bibtex, false);
        $csl = $this->bibtexService::generateCSL($bibInfo[0]);
        $this->logger->info('SCRIPT CSV => CUSTOM CSL => ', ['csl' => $csl]);
        $reference = (['csl' => $csl]);
        $this->insertRefInDb($reference, $counterRef, $docId, PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
        return array($csl, $reference);
    }

    /**
     * @param int|string $docId
     * @param OutputInterface $output
     * @param $doi
     * @return void
     */
    public function removeAllS2RefFromDb(int|string $docId, OutputInterface $output, $doi): void
    {
        $allSchoFromDocId = $this->entityManager->getRepository(PaperReferences::class)
            ->findBy(['document' => $docId, 'source' => PaperReferences::SOURCE_SEMANTICS_SCHOLAR]);
        $output->writeln('SEARCH CITED PAPER FOR ' . $doi);
        $output->writeln('Remove all S2 Ref => ' . $docId);
        foreach ($allSchoFromDocId as $schoInfo) {
            $this->logger->info('SCRIPT CSV => Remove all S2 Ref => ' . $docId);
            $this->entityManager->remove($schoInfo);
        }
    }


    /**
     * @param OutputInterface $output
     * @param $doi
     * @param int $counterRef
     * @param int|string $docId
     * @return void
     * @throws \JsonException
     */
    public function insertRefS2fromDoi(OutputInterface $output, $doi, int $counterRef, int|string $docId)
    {
        //case Doi in IDS
        $output->writeln('DOI FOUND IN S2 CITED PAPER => ' . $doi);
        $this->logger->info('SCRIPT CSV => DOI FOUND IN S2 CITED PAPER => ' . $doi);
        $csl = $this->doiService->getCsl($doi);
        if ($csl !== '') {
            $this->logger->info('SCRIPT CSV => CSL FOUNDED => ' . $csl);
            $newRef = (['csl' => json_decode($csl, true, 512, JSON_THROW_ON_ERROR),
                'doi' => $doi]);
            $this->insertRefInDb($newRef, $counterRef, $docId, PaperReferences::SOURCE_SEMANTICS_SCHOLAR);
        } else {
            $output->writeln('SCRIPT CSV => CSL/Resource not found => ' . $doi);
            $this->logger->info('SCRIPT CSV => CSL/Resource not found => ' . $doi);
        }
    }

    /**
     * @param string $csl
     * @param array $arrayDoiInDb
     * @param array $arrayRefTxt
     * @param OutputInterface $output
     * @param int $counterRef
     * @param int|string $docId
     * @return mixed
     * @throws \JsonException
     */
    public function processCslToGetRef(string $csl, array $arrayDoiInDb, array $arrayRefTxt, OutputInterface $output, int $counterRef, int|string $docId): mixed
    {
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
     * @param mixed $refRetrieved
     * @param int $counterRef
     * @param int|string $docId
     * @return array
     */
    public function insertRefInDb(mixed $refRetrieved, int $counterRef, int|string $docId, $source = PaperReferences::SOURCE_METADATA_EPI_USER): array
    {
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
        $ref->setSource($source);
        $ref->setUpdatedAt(new \DateTimeImmutable());
        $ref->setReferenceOrder($counterRef++);
        if ($this->references->getDocument($docId) === null) {
            $this->references->createDocumentId($docId);
        }
        $ref->setDocument($this->references->getDocument($docId));
        $ref->setAccepted(0);
        $ref->setUid($user);
        $this->entityManager->persist($ref);
        $this->entityManager->flush();
        return array($ref, $counterRef);
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    public function processCsv(InputInterface $input): array
    {
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
        return $globalData;
    }
    public function getBibtexFromDoi($id, $idType='doi'){
        if ($idType === 'arxiv'){
            $id = $this->processArXivDoi($id);
        }

        return $this->doiService->getBibtex($id);
    }

    public function processArXivDoi($id){
        $arxivId = $id;
        // case with have arxiv but not doi -> doi.org with arxiv
        if (!strpos($arxivId, 'arxiv')) {
            $arxivId = 'arxiv.' . $arxivId;
        }
        return self::PREFIX_ARXIV.$arxivId;
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
                    $referenceAlreadyAccepted[] =
                        json_decode($doc->getReference()[0], true, 512, JSON_THROW_ON_ERROR);
                    $this->entityManager->persist($doc);
                    $reOrdonateCounter++;
                    $counterRef++;
                }

                foreach ($referenceAlreadyAccepted as $refDb) {
                    if (array_key_exists('csl', $refDb)) {
                        $arrayRefTxt[] = serialize($refDb['csl']);
                    } else {
                        $arrayRefTxt[] = serialize($refDb['raw_reference']);
                    }
                    if (isset($refDb['doi'])) {
                        $arrayDoiInDb[] = $refDb['doi'];
                    }
                }
                $this->entityManager->flush();
            }

            foreach ($allRef as $ref) {
                $optionValue = $input->getOption('api');
                if (!is_null($optionValue) && $optionValue !== false && $optionValue === "S2") {
                    $bibArray = [];
                    $outopt = $input->getOption('output');
                    $this->removeAllS2RefFromDb($docId, $output, $ref['doi']);
                    $semanticsRef = $this->semanticsscholar->getRef($ref['doi']);
                    $semanticsRef = json_decode($semanticsRef, true, 512, JSON_THROW_ON_ERROR);
                    $this->processS2Ref($semanticsRef, $output, $counterRef, $docId, $bibArray, $outopt);
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
