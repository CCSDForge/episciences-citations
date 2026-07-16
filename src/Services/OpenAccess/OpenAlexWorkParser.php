<?php

declare(strict_types=1);

namespace App\Services\OpenAccess;

/**
 * Parses an OpenAlex "work" payload into open-access location info, porting the
 * priority algorithm from the reference implementation: 1. best_oa_location,
 * 2. primary_location if is_oa, 3. first is_oa entry in locations.
 *
 * Extracted from OpenAlexResolver to keep both classes under Sonar's
 * 20-method-per-class limit (S1448); this half is pure payload parsing with no
 * HTTP or cache dependency.
 */
class OpenAlexWorkParser
{
    /**
     * @param array<string, mixed> $work
     */
    public function extractOaInfo(array $work): ?OpenAccessResult
    {
        $primary = is_array($work['primary_location'] ?? null) ? $work['primary_location'] : null;
        $bestOa = is_array($work['best_oa_location'] ?? null) ? $work['best_oa_location'] : null;
        $locations = is_array($work['locations'] ?? null) ? $work['locations'] : [];

        $info = $this->resolveBestOaInfo($primary, $locations, $bestOa);
        if ($info === null || $info['oa_link'] === '') {
            return null;
        }

        return new OpenAccessResult($info['oa_link'], $info['source_title']);
    }

    public function normalizeDoi(mixed $doi): ?string
    {
        if (!is_string($doi) || trim($doi) === '') {
            return null;
        }

        $doi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', trim($doi)) ?? $doi;

        return strtolower(rawurldecode($doi));
    }

    /**
     * @param array<string, mixed>|null $primary
     * @param array<int, array<string, mixed>> $locations
     * @param array<string, mixed>|null $bestOa
     * @return array{source_title: string, oa_link: string}|null
     */
    private function resolveBestOaInfo(?array $primary, array $locations, ?array $bestOa): ?array
    {
        $fromBestOa = $this->extractLocationOaInfo($bestOa);
        if ($fromBestOa !== null) {
            return $fromBestOa;
        }

        if (($primary['is_oa'] ?? false) === true) {
            $fromPrimary = $this->extractLocationOaInfo($primary);
            if ($fromPrimary !== null) {
                return $fromPrimary;
            }
        }

        return $this->findLocationOaInfo($locations) ?? $this->findFirstAlternativeLocation($locations);
    }

    /**
     * @param array<string, mixed>|null $source
     * @return array{source_title: string, oa_link: string}|null
     */
    private function extractLocationOaInfo(?array $source): ?array
    {
        if ($source === null || !is_array($source['source'] ?? null)) {
            return null;
        }

        return [
            'source_title' => (string) ($source['source']['display_name'] ?? ''),
            'oa_link' => (string) ($source['landing_page_url'] ?? ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     * @return array{source_title: string, oa_link: string}|null
     */
    private function findLocationOaInfo(array $locations): ?array
    {
        foreach ($locations as $location) {
            if (($location['is_oa'] ?? false) !== true || !is_array($location['source'] ?? null)) {
                continue;
            }

            return [
                'source_title' => $this->resolveSourceTitle($location, $locations),
                'oa_link' => (string) ($location['landing_page_url'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $location
     * @param array<int, array<string, mixed>> $locations
     */
    private function resolveSourceTitle(array $location, array $locations): string
    {
        if ((string) ($location['source']['type'] ?? '') === 'journal') {
            return (string) ($location['source']['display_name'] ?? '');
        }

        $journalName = $this->findJournalNameInLocations($locations);

        return $journalName !== '' ? $journalName : (string) ($location['source']['display_name'] ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     */
    private function findJournalNameInLocations(array $locations): string
    {
        foreach ($locations as $location) {
            if (!is_array($location['source'] ?? null)) {
                continue;
            }

            $isJournal = (string) ($location['source']['type'] ?? '') === 'journal';
            $isAcceptedPublishedVersion = ($location['version'] ?? null) === 'publishedVersion'
                && ($location['is_accepted'] ?? false) === true
                && ($location['is_published'] ?? false) === true;

            if ($isJournal || $isAcceptedPublishedVersion) {
                return (string) ($location['source']['display_name'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     * @return array{source_title: string, oa_link: string}|null
     */
    private function findFirstAlternativeLocation(array $locations): ?array
    {
        foreach ($locations as $location) {
            if (!is_array($location['source'] ?? null)) {
                continue;
            }

            $oaLink = ($location['is_oa'] ?? false) === true
                ? (string) ($location['source']['landing_page_url'] ?? '')
                : '';

            return ['source_title' => $this->resolveSourceTitle($location, $locations), 'oa_link' => $oaLink];
        }

        return null;
    }
}
