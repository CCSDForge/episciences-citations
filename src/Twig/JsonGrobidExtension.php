<?php
// src/Twig/AppExtension.php
namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class JsonGrobidExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    public function getFunctions() : array
    {
        return [
            new TwigFunction('getAuthors', [$this, 'getAuthors']),
            new TwigFunction('getDateInJson', [$this, 'getDateInJson']),
            new TwigFunction('getJournalIdentifier', [$this, 'getJournalIdentifier']),
            new TwigFunction('prettyReference', [$this, 'prettyReference']),
        ];
    }

    /**
     * @param array $authors
     * @return array
     */
    public function getAuthors(array $authors): array
    {
        $infoAuthor = [];
        foreach ($authors as $author) {
            if (isset($author['persName']['forename'], $author['persName']['surname'])
                && !is_array($author['persName']['forename']) && !is_array($author['persName']['surname'])) {
                $infoAuthor[] = [
                    "forename" => $author['persName']['forename'],
                    "surname" => $author['persName']['surname'],
                    "orcid" => $this->getOrcid($author)
                ];
            } elseif(isset($author['persName']['forename'], $author['persName']['surname']) && is_array($author['persName']['forename'])) {
                $infoAuthor[] = [
                    "forename" => $this->composeNames($author['persName']['forename']),
                    "surname" => $author['persName']['surname'],
                    "orcid" => $this->getOrcid($author)
                ];
            }
            elseif(isset($author['persName']['forename'], $author['persName']['surname']) && is_array($author['persName']['surname'])) {
                $infoAuthor[] = [
                    "forename" => $author['persName']['forename'],
                    "surname" => $this->composeNames($author['persName']['surname']),
                    "orcid" => $this->getOrcid($author)
                ];
            } elseif(isset($author['persName']['forename'], $author['persName']['surname']) && is_array($author['persName']['surname'])) {
                $infoAuthor[] = [
                    "forename" => $this->composeNames($author['persName']['surname']),
                    "surname" => $this->composeNames($author['persName']['surname']),
                    "orcid" => $this->getOrcid($author)
                ];
            }
        }
        return $infoAuthor;
    }

    /**
     * @param array $author
     * @return string|null
     */
    public function getOrcid(array $author) : ?string {
        if (array_key_exists("idno",$author)){
           return $author['idno'];
        }
        return null;
    }

    /**
     * @param array $names
     * @return string
     */
    public function composeNames(array $names): string {
        return implode(" ",$names);
    }

    public function getDateInJson(string|array $date){
        if (is_array($date)) {
            foreach ($date as $attr) {
                if (array_key_exists('when', $attr)) {
                        return $attr['when'];
                }
            }
        }
        return $date;
    }
    public function getJournalIdentifier(string|array $identifier){
        if (is_array($identifier)){
            return implode('; ',$identifier);
        }
        return $identifier;

    }

    public function prettyReference(string $jsonRawReference): array {
        if ($jsonRawReference !== ""){
            $jsonRawReference = json_decode($jsonRawReference, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($jsonRawReference)){
                foreach ($jsonRawReference as $jsonReference) {
                    return json_decode($jsonReference, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }
        return [];
    }
}