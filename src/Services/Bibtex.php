<?php

namespace App\Services;


use Seboettg\CiteProc\Exception\CiteProcException;
use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Entity\UserInformations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RenanBr\BibTexParser\Exception\ExceptionInterface;
use RenanBr\BibTexParser\Exception\ParserException;
use RenanBr\BibTexParser\Listener;
use RenanBr\BibTexParser\Parser;
use RenanBr\BibTexParser\Processor\NamesProcessor;
use RenanBr\BibTexParser\Processor\TagNameCaseProcessor;
use RenanBr\BibTexParser\Processor\TrimProcessor;
use Seboettg\CiteProc\CiteProc;
use Seboettg\CiteProc\StyleSheet;

class Bibtex
{
    public const REPLACE_CSL_EXCEPTION_STRING = [" (1–)"," (1–,"];
    private static LoggerInterface $loggerSingleton;
    public function __construct(
        private readonly Doi $doi,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly SolrReferenceEnricher $solrReferenceEnricher
    )
    {
        $this->initStatic();
    }

    /**
     * @param $bibtexFile
     * @return string[]
     */
    public static function convertBibtexToArray($bibtexFile, $isFile = true): array
    {
        // Create and configure a Listener
        $listener = new Listener();
        $listener->addProcessor(new TagNameCaseProcessor(CASE_LOWER));
        $listener->addProcessor(new TrimProcessor());
        $listener->addProcessor(new NamesProcessor());
        $parser = new Parser();
        $parser->addListener($listener);
        try {
            static::logger();
            $bibtexLog = ($isFile) ? file_get_contents($bibtexFile) : $bibtexFile;
            ($isFile) ? $parser->parseFile($bibtexFile) : $parser->parseString($bibtexFile) ;
            $entries = $listener->export();
            self::logger()->info('bibtexImport => ', ['entries' => $entries,
                'original File' => $bibtexLog
            ]);
        } catch (ParserException $exception) {
            // The BibTeX isn't valid
            self::logger()->error('BIBTEX NOT VALID => '. $exception->getMessage(),['file'=> $exception->getFile()]);
            return ["error" => 'BibTeX is not valid'];
        } catch (ExceptionInterface $exception) {
            // Alternatively, you can use this exception to catch all of them at once
            self::logger()->error('EXCEPTION FROM BIBTEX CONVERTER => '. $exception->getMessage(),
                ['file'=> $exception->getFile(), 'error' => $exception->getMessage()]);
            return ["error" => 'Something went wrong with the BibTeX converter. Please check the syntax and the format of your file.'];
        } catch (\ErrorException $exception) {
            self::logger()->error('ERROR FROM BIBTEX CONVERTER => '. $exception->getMessage(),
                ['file'=> $exception->getFile(), 'error' => $exception->getMessage()]);
            return ["error" => 'Something went wrong with the BibTeX converter. Please check the syntax and the format of your file.'];
        }
        return $entries;
    }
    public function initStatic(): void
    {
        self::$loggerSingleton = $this->logger;
    }
    public static function logger(): LoggerInterface
    {
        return self::$loggerSingleton;
    }
    public static function generateCSL(array $entry): array
    {
        $csl = [
            'type' => lcfirst((string) ($entry['type'] ?? 'misc')),
            'author' => [],
            'title' => $entry['title'] ?? '',
            'issued' => [
                'date-parts' => [
                    [$entry['year'] ?? '']
                ]
            ]
        ];
        foreach ($entry['author'] ?? [] as $author) {
            $csl['author'][] = [
                'family' => $author['last'] ?? '',
                'given' => $author['first'] ?? ''
            ];
        }
        if (isset($entry['publisher'])){
            $csl['publisher'] = $entry['publisher'];
        }
        if ($entry['type'] === 'article') {
            if (isset($entry['journal'])){
                $csl['container-title'] = $entry['journal'];
            }
            if (isset($entry['volume'])){
                $csl['volume'] = $entry['volume'];
            }
            if (isset($csl['issue'])){
                $csl['issue'] = $entry['number'];
            }
            if (isset($entry['pages'])){
                $csl['page'] = $entry['pages'];
            }

        }
        if (isset($entry['address'])) {
            $csl['publisher-place'] = $entry['address'];
        }
        if (isset($entry['isbn'])) {
            $csl['ISBN'] = $entry['isbn'];
        }
        return $csl;
    }

    /**
     * @param $bibtexFile
     * @param $userInfo
     * @param $docId
     * @return array|string[]
     * @throws \JsonException
     */
    public function processBibtex($bibtexFile,array $userInfo,$docId): array
    {
        $allBibFromDocId = $this->entityManager->getRepository(PaperReferences::class)
            ->findBy(['document' => $docId, 'source' => PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT]);
        $countAllRef = count($this->entityManager->getRepository(PaperReferences::class)
            ->findBy(['document' => $docId]));
        if ($allBibFromDocId !== []){
            foreach ($allBibFromDocId as $bib){
                $this->entityManager->remove($bib);
            }
            $this->entityManager->flush();
        }
        $bibtex = self::convertBibtexToArray($bibtexFile);
        if (isset($bibtex['error'])) {
            return ['error' => $bibtex['error']];
        }

        $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
        if (is_null($user)) {
            $user = new UserInformations();
            $user->setId($userInfo['UID']);
            $user->setSurname($userInfo['FIRSTNAME']);
            $user->setName($userInfo['LASTNAME']);
        }
        $document = $this->entityManager->getRepository(Document::class)->find($docId);
        $references = [];
        foreach ($bibtex as $bibtexInfo) {
            if (array_key_exists('crossref_doi', $bibtexInfo)) {
                $csl = $this->doi->getCsl($bibtexInfo['crossref_doi']);
                $references[] = ['csl' => json_decode($csl, true, 512, JSON_THROW_ON_ERROR),
                    'doi' => $bibtexInfo['crossref_doi']];
            } else {
                $references[] = ['csl' => self::generateCSL($bibtexInfo)];
            }
        }
        foreach ($this->solrReferenceEnricher->enrichReferences($references) as $reference) {
            $ref = new PaperReferences();
            $ref->setReference($reference);
            $ref->setSource(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT);
            $ref->setUid($user);
            $ref->setAccepted(1);
            $ref->setUpdatedAt(new \DateTimeImmutable());
            $ref->setDocument($document);
            $ref->setReferenceOrder($countAllRef++);
            $this->entityManager->persist($ref);
        }
        $this->entityManager->flush();
        return [];
    }

    /**
     * @throws CiteProcException
     * @throws \JsonException
     */
    public function getCslRefText(array $refData): array
    {
        if (array_key_exists('csl', $refData)) {
            $jsonArray = json_encode([$refData['csl']], JSON_THROW_ON_ERROR);
            $style = StyleSheet::loadStyleSheet("apa");
            $citeProc = new CiteProc($style, "en-US");
            $bibliography = $citeProc->render(json_decode($jsonArray, false, 512, JSON_THROW_ON_ERROR), "bibliography");
            $refData['raw_reference'] = trim(htmlspecialchars_decode(strip_tags($bibliography)));
            $refData['raw_reference'] = str_replace(self::REPLACE_CSL_EXCEPTION_STRING, '', $refData['raw_reference']);
            unset($refData['csl']);
        }
        return $refData;
    }
}
