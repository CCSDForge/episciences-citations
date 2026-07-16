<?php
// src/Twig/AppExtension.php
namespace App\Twig;

use Twig\Attribute\AsTwigFunction;
use App\Services\Bibtex;
use JsonException;
use Seboettg\CiteProc\Exception\CiteProcException;

class JsonGrobidExtension
{
    public function __construct(private readonly Bibtex $bibtex)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $authors
     * @return array<int, array<string, mixed>>
     */
    #[AsTwigFunction(name: 'getAuthors')]
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
            }
        }
        return $infoAuthor;
    }

    /**
     * @param array<string, mixed> $author
     */
    public function getOrcid(array $author): ?string
    {
        if (array_key_exists("idno", $author)) {
            return $author['idno'];
        }
        return null;
    }

    /**
     * @param array<string> $names
     */
    public function composeNames(array $names): string
    {
        return implode(" ", $names);
    }

    /**
     * @param string|array<string, mixed> $date
     */
    #[AsTwigFunction(name: 'getDateInJson')]
    public function getDateInJson(string|array $date): mixed
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

    /**
     * @param string|array<string> $identifier
     */
    #[AsTwigFunction(name: 'getJournalIdentifier')]
    public function getJournalIdentifier(string|array $identifier): string
    {
        if (is_array($identifier)) {
            return implode('; ', $identifier);
        }
        return $identifier;

    }

    /**
     * @return array<string, mixed>
     */
    #[AsTwigFunction(name: 'prettyReference')]
    public function prettyReference(string $jsonRawReference): array
    {
        try {
            $jsonReference = json_decode($jsonRawReference, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($jsonReference)) {
                return [];
            }

            /** @var array<string, mixed> $reference */
            $reference = $this->unwrapLegacyEncoding($jsonReference);

            return $this->bibtex->getCslRefText($reference);
        } catch (JsonException|CiteProcException) {
            return [];
        }
    }

    /**
     * Unwrap legacy outer-array format created by old JS double-encoding:
     *   [{"raw_reference":"..."}]      → {"raw_reference":"..."}  (array wrapper)
     *   ["{\"raw_reference\":\"...\"}"] → {"raw_reference":"..."}  (array + string wrapper)
     *
     * @param array<int|string, mixed> $jsonReference
     * @return array<int|string, mixed>
     */
    private function unwrapLegacyEncoding(array $jsonReference): array
    {
        if (!array_is_list($jsonReference) || count($jsonReference) !== 1) {
            return $jsonReference;
        }

        $inner = $jsonReference[0];
        if (is_array($inner)) {
            return $inner;
        }

        $decoded = is_string($inner) ? json_decode($inner, true) : null;

        return is_array($decoded) ? $decoded : $jsonReference;
    }
}
