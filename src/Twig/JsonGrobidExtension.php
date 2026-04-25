<?php
// src/Twig/AppExtension.php
namespace App\Twig;

use App\Services\Bibtex;
use JsonException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Seboettg\CiteProc\Exception\CiteProcException;
use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\CiteProc;

class JsonGrobidExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('getAuthors', $this->getAuthors(...)),
            new TwigFunction('getDateInJson', $this->getDateInJson(...)),
            new TwigFunction('getJournalIdentifier', $this->getJournalIdentifier(...)),
            new TwigFunction('prettyReference', $this->prettyReference(...)),
        ];
    }

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
            } elseif (isset($author['persName']['forename'], $author['persName']['surname']) && is_array($author['persName']['forename'])) {
                $infoAuthor[] = [
                    "forename" => $this->composeNames($author['persName']['forename']),
                    "surname" => $author['persName']['surname'],
                    "orcid" => $this->getOrcid($author)
                ];
            } elseif (isset($author['persName']['forename'], $author['persName']['surname']) && is_array($author['persName']['surname'])) {
                $infoAuthor[] = [
                    "forename" => $author['persName']['forename'],
                    "surname" => $this->composeNames($author['persName']['surname']),
                    "orcid" => $this->getOrcid($author)
                ];
            } elseif (isset($author['persName']['forename'], $author['persName']['surname']) && is_array($author['persName']['surname'])) {
                $infoAuthor[] = [
                    "forename" => $this->composeNames($author['persName']['surname']),
                    "surname" => $this->composeNames($author['persName']['surname']),
                    "orcid" => $this->getOrcid($author)
                ];
            }
        }
        return $infoAuthor;
    }

    public function getOrcid(array $author): ?string
    {
        if (array_key_exists("idno", $author)) {
            return $author['idno'];
        }
        return null;
    }

    public function composeNames(array $names): string
    {
        return implode(" ", $names);
    }

    public function getDateInJson(string|array $date)
    {
        if (is_array($date)) {
            foreach ($date as $attr) {
                if (array_key_exists('when', $attr)) {
                    return $attr['when'];
                }
            }
        }
        return $date;
    }

    public function getJournalIdentifier(string|array $identifier): string
    {
        if (is_array($identifier)) {
            return implode('; ', $identifier);
        }
        return $identifier;

    }

    public function prettyReference(string $jsonRawReference): array
    {
        $jsonReference = [];
        if ($jsonRawReference !== '' && $jsonRawReference !== '0') {
            try {
                $jsonReference = json_decode($jsonRawReference, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($jsonReference)) {
                    return [];
                }

                if (isset($jsonReference['csl'])) {
                    $jsonArray = json_encode([$jsonReference['csl']], JSON_THROW_ON_ERROR);
                    $style = StyleSheet::loadStyleSheet("apa");
                    $citeProc = new CiteProc($style, "en-US");
                    $bibliography = $citeProc->render(json_decode($jsonArray, false, 512, JSON_THROW_ON_ERROR));
                    $jsonReference['raw_reference'] = trim(htmlspecialchars_decode(strip_tags($bibliography)));
                    $jsonReference['raw_reference'] = str_replace(
                        Bibtex::REPLACE_CSL_EXCEPTION_STRING,
                        '',
                        $jsonReference['raw_reference']
                    );
                    unset($jsonReference['csl']);
                    $jsonReference['forbiddenModify'] = 1;
                }
            } catch (JsonException|CiteProcException) {
                return [];
            }
        }
        return $jsonReference;
    }
}