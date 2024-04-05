<?php

namespace App\Services;


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
use RenanBr\BibTexParser\Processor\LatexToUnicodeProcessor;
use Seboettg\CiteProc\CiteProc;
use Seboettg\CiteProc\StyleSheet;

class Bibtex
{
    public CONST REPLACE_CSL_EXCEPTION_STRING = [" (1–)"," (1–,"];

    public function __construct(private Doi $doi,private EntityManagerInterface $entityManager, private LoggerInterface $logger)
    {

    }

    /**
     * @param $bibtexFile
     * @return string[]
     */
    public function convertBibtexToArray($bibtexFile): array
    {
        // Create and configure a Listener
        $listener = new Listener();
        $listener->addProcessor(new TagNameCaseProcessor(CASE_LOWER));
        $listener->addProcessor(new TrimProcessor());
        $listener->addProcessor(new NamesProcessor());
        $listener->addProcessor(new LatexToUnicodeProcessor());
        $parser = new Parser();
        $parser->addListener($listener);
        try {
            $parser->parseFile($bibtexFile);
            $entries = $listener->export();
            $this->logger->info('bibtexImport => ',['entries' => $entries,
                'original File' => file_get_contents($bibtexFile)]);
        } catch (ParserException $exception) {
            // The BibTeX isn't valid
            $this->logger->error('BIBTEX NOT VALID => '. $exception->getMessage(),['file'=> $exception->getFile()]);
            return ["error" => 'BibTeX is not valid'];
        } catch (ExceptionInterface $exception) {
            // Alternatively, you can use this exception to catch all of them at once
            $this->logger->error('EXCEPTION FROM BIBTEX CONVERTER => '. $exception->getMessage(),
                ['file'=> $exception->getFile(), 'error' => $exception->getMessage()]);
            return ["error" => 'Something went wrong with the BibTeX converter. Please check the syntax and the format of your file.'];
        } catch (\ErrorException $exception) {
            $this->logger->error('ERROR FROM BIBTEX CONVERTER => '. $exception->getMessage(),
                ['file'=> $exception->getFile(), 'error' => $exception->getMessage()]);
            return ["error" => 'Something went wrong with the BibTeX converter. Please check the syntax and the format of your file.'];
        }
        return $entries;
    }

    public function generateCSL($entry): array
    {
        $csl = [
            'type' => lcfirst($entry['type']),
            'author' => [],
            'title' => $entry['title'],
            'issued' => [
                'date-parts' => [
                    [$entry['year']]
                ]
            ]
        ];
        foreach ($entry['author'] as $author) {
            $csl['author'][] = array(
                'family' => $author['last'],
                'given' => $author['first']
            );
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
    public function processBibtex($bibtexFile,$userInfo,$docId)
    {
        $allBibFromDocId = $this->entityManager->getRepository(PaperReferences::class)
            ->findBy(['document' => $docId, 'source' => PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT]);
        $countAllRef = count($this->entityManager->getRepository(PaperReferences::class)
            ->findBy(['document' => $docId]));
        if (!empty($allBibFromDocId)){
            foreach ($allBibFromDocId as $bib){
                $this->entityManager->remove($bib);
            }
            $this->entityManager->flush();
        }
        $bibtex = $this->convertBibtexToArray($bibtexFile);
        if (isset($bibtex['error'])) {
            return ['error' => $bibtex['error']];
        }

        foreach ($bibtex as $bibtexInfo) {
            if (array_key_exists('crossref_doi', $bibtexInfo)) {
                $csl = $this->doi->getCsl($bibtexInfo['crossref_doi']);
                $reference = (['csl' => json_decode($csl, true, 512, JSON_THROW_ON_ERROR),
                    'doi' => $bibtexInfo['crossref_doi']]);
            } else {
                $csl = $this->generateCSL($bibtexInfo);
                $reference = (['csl' => $csl]);
            }
            $ref = new PaperReferences();
            $ref->setReference([json_encode($reference)]);
            $ref->setSource(PaperReferences::SOURCE_METADATA_BIBTEX_IMPORT);
            $user = $this->entityManager->getRepository(UserInformations::class)->find($userInfo['UID']);
            if (is_null($user)) {
                $user = new UserInformations();
                $user->setId($userInfo['UID']);
                $user->setSurname($userInfo['FIRSTNAME']);
                $user->setName($userInfo['LASTNAME']);
            }
            $ref->setUid($user);
            $ref->setAccepted(1);
            $ref->setUpdatedAt(new \DateTimeImmutable());
            $ref->setDocument($this->entityManager->getRepository(Document::class)->find($docId));
            $ref->setReferenceOrder($countAllRef++);
            $this->entityManager->persist($ref);
            $this->entityManager->flush();
        }
        return [];
    }

    /**
     * @param $jsonCsl
     * @return false|mixed|string
     * @throws \JsonException
     * @throws \Seboettg\CiteProc\Exception\CiteProcException
     */
    public function getCslRefText($jsonCsl) {
        $jsonReference = json_decode($jsonCsl, true, 512, JSON_THROW_ON_ERROR);
        // Check if 'csl' key is set in $jsonReference
        if (array_key_exists('csl',$jsonReference)) {
            // Extract CSL data and render bibliography
            $jsonArray = [$jsonReference['csl']];
            $jsonArray = json_encode($jsonArray, JSON_THROW_ON_ERROR);
            $style = StyleSheet::loadStyleSheet("apa");
            $citeProc = new CiteProc($style, "en-US");
            $bibliography = $citeProc->render(json_decode($jsonArray), "bibliography");
            // Process raw reference and assign to 'raw_reference' key
            $jsonReference['raw_reference'] = trim(htmlspecialchars_decode(strip_tags($bibliography)));
            $jsonReference['raw_reference'] = str_replace(self::REPLACE_CSL_EXCEPTION_STRING
                ,'',$jsonReference['raw_reference']);
            unset($jsonReference['csl']);
            return json_encode($jsonReference);
        }
        return $jsonCsl;
    }
}
