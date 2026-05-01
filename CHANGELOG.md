# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!-- 
## Unreleased
### Fixed
### Added
### Changed
### Deprecated
### Removed
### Security
-->

## Unreleased

### Added
- **JS Test Suite for Legacy Code**: Added a comprehensive test suite for `extract.js`, increasing its coverage from 0% to over 60%, ensuring stability for critical citation extraction features.
- **Robustness in PHP User Management**: Added unit tests to verify that the application gracefully handles incomplete CAS user metadata during autosave operations.

### Changed
- **JS Robustness**: Added systematic null checks for DOM elements in `extract.js` to prevent runtime errors and improved script reliability across different pages.
- **PHP Robustness**: Improved `References` service to safely handle missing user identification keys using null coalescing and default values.
- **Test Environment**: Enhanced Jest setup with robust mocks for `Sortable.js` and a polyfill for `HTMLFormElement.prototype.requestSubmit` to ensure better compatibility with JSDOM.

### Security
- **XSS Fix in Citation UI**: Patched a potential XSS vulnerability in `extract.js` by switching from `innerHTML` to `textContent` when rendering detector badges for citation metadata.
- Configured CodeQL for automated security analysis
- Fixed security vulnerabilities in dependencies (serialize-javascript)
- Improved HTTPS support in Docker environment

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