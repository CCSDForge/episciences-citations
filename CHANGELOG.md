# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.2.1

### Fixed
- **Symfony 7.4 deprecation**: Replaced `Request::get()` with `$request->query->get()` in `EpisciencesController` as per Symfony 7.4 guidelines.

### Security
- **API `/api/extract`**: Replaced hostname allowlist with HTTP/HTTPS scheme validation, allowing PDF sources from any public host (e.g. arxiv.org) while still rejecting `file://`, `ftp://`, and other unsafe schemes.

### Changed
- **API `/api/extract`**: The endpoint now returns 401 when the token is not properly set on the server side.

## v1.2.0

### Added
- **API Extraction Endpoint**: Introduced `GET /api/extract` with Bearer token authentication for remote citation extraction.
- **Automatic detection of problematic papers** cited in the references of the papers. This is heavily based on the huge work provided by the Problematic Paper Screener https://dbrech.irit.fr/pls/apex/f?p=9999:1::::::
  For more information: Cabanac, G., Labbé, C., & Magazinov, A. (2022). The ‘Problematic Paper Screener’ automatically selects suspect publications for post-publication (re)assessment.
  Presented at WCRI 2022: 7th World Conference on Research Integrity. arXiv preprint. https://doi.org/10.48550/arXiv.2210.04895
- **Episciences URL Auto-Resolution**: Automatically resolve Episciences article URLs to their corresponding PDF download URLs.
- **UI Enhancements**: Added a screen loader during extraction, "Click to Edit" functionality, and auto-dismissing toasts.
- **Auto-save**: Implemented automatic saving on drag-and-drop reordering and reference editing.
- **JS Test Suite for Legacy Code**: Added a comprehensive test suite for `extract.js`, increasing its coverage from 0% to over 60%, ensuring stability for critical citation extraction features.
- **Robustness in PHP User Management**: Added unit tests to verify that the application gracefully handles incomplete CAS user metadata during autosave operations.
- **Reverse Proxy Support**: Added `SYMFONY_TRUSTED_PROXIES` configuration to support deployments behind reverse proxies.

### Changed
- **Bootstrap Migration**: Migrated the entire frontend from Tailwind CSS to Bootstrap 5.3.8 for improved consistency and component support.
- **Symfony 7.4 Upgrade**: Upgraded the core framework to Symfony 7.4 and resolved associated deprecations.
- **PHP 8.4 Upgrade**: Migrated runtime from PHP 8.3 to PHP 8.4; updated Docker base image (`php:8.4-fpm`), Rector level (`UP_TO_PHP_84`), and enabled native lazy objects for Doctrine proxies.
- **Rebranding**: Rebranded the "Enrich" feature to "Auto-fix" throughout the application for better clarity.
- **PHPUnit bridge version**: Pinned `SYMFONY_PHPUNIT_VERSION` to `12.5` for consistency with the `phpunit/phpunit ^12` Composer dependency.
- **JS Robustness**: Added systematic null checks for DOM elements in `extract.js` to prevent runtime errors and improved script reliability across different pages.
- **PHP Robustness**: Improved `References` service to safely handle missing user identification keys using null coalescing and default values.
- **Test Environment**: Enhanced Jest setup with robust mocks for `Sortable.js` and a polyfill for `HTMLFormElement.prototype.requestSubmit` to ensure better compatibility with JSDOM.

### Fixed
- **Docker DNS Resolution**: Configured Cloudflare nameservers in the `php-fpm` container to resolve intermittent DNS issues during extraction.
- **JSON Encoding**: Resolved JSON double-encoding and `stdClass` conversion issues when saving `PaperReferences`.
- **GROBID Error Handling**: Improved robustness by catching HTTP errors from GROBID and preventing extraction failures.
- **URL Parsing**: Improved article URL detection by stripping `/pdf` and `/download` suffixes.
- **Sass Deprecations**: Silenced Dart Sass 3.0 deprecation warnings from Bootstrap.
- **PHPStan Errors**: Resolved over 120 PHPStan level-6 errors to improve code quality and type safety.

### Removed
- **Doctrine Annotations**: Removed the deprecated `doctrine/annotations` package in favor of native PHP 8 attributes.

### Security
- **SSRF Prevention**: Implemented strict URL validation for extraction sources, blocking percent-encoded IPs and restricting allowed schemes to prevent Server-Side Request Forgery.
- **XSS Fix in Citation UI**: Patched a potential XSS vulnerability in `extract.js` by switching from `innerHTML` to `textContent` when rendering detector badges for citation metadata.
- **Access Control**: Tightened access control for extraction endpoints while maintaining compatibility for allowed external requests.
- **Automation**: Configured CodeQL for automated security analysis.
- **Dependency Fixes**: Fixed security vulnerabilities in dependencies (e.g., `serialize-javascript`).
- **HTTPS Support**: Improved HTTPS support in the Docker environment.

## V1.1.2
### Added
- Script to import bibliographical references from Semantics Scholar from a CSV list
  CSV format is : `doi,docid`
- Script to output bibtex, in order to fix the reference with another software and reimport it as .bib

## V1.1.1
- Script to import bibliographical references from semantics scholar from a csv list

## V1.1

### Added
- Script to import bibliographical references from Crossref for articles previously published
- [23](https://github.com/CCSDForge/episciences-citations/issues/23) Add support to import BibTeX file in the app
- New feature to remove references
- New feature to indicate if a user has changed the citation, if it's edited
- Display informative box if citations is not found, in the page view

- Add custom error page 404 and 403
- Add support url doi.org
- [21](https://github.com/CCSDForge/episciences-citations/issues/21) Add placeholder in modify references input

- Script to retrieve refs from a csv file with the list of doi

### Changed
- [20](https://github.com/CCSDForge/episciences-citations/issues/20) Add some French translations to harmonize
- Display the source in the page 
- improved order management
- API return with CSL
