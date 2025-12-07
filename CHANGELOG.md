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
- Modern language switcher component with dropdown menu
- Stimulus controller for interactive language selection (`language_switcher_controller.js`)
- Script to output BibTeX from Semantic Scholar
- GitHub workflows configuration (CodeQL for security analysis)
- Renovate configuration for automated dependency updates
- Dependabot configuration
- Docker configuration improvements with HTTPS support
- PHP version specification for Symfony server

### Changed
- Replaced simple language links with modern dropdown interface
- Updated README.md with comprehensive documentation in English
- Refactored CORS handling for better performance
- Refactored Composer configuration to avoid conflicts with other Docker projects
- Renamed example `.env` file for clarity
- Updated multiple dependencies:
  - FontAwesome to v6.7.2
  - Tailwind CSS to v3.4.18
  - Webpack to v5.102.1
  - Core-js to v3.47.0
  - Sortable.js to v1.15.6
  - PostCSS to v8.5.6
  - Symfony packages to latest 6.4.x versions
  - And many more dependencies via Renovate

### Fixed
- Security warning from CodeQL analysis
- Deprecation warnings in Symfony and Doctrine
- PHP 8.2+ deprecation: `preg_match()` null parameter
- Prevented public API route from creating unnecessary cookies
- Bumped serialize-javascript from 6.0.1 to 6.0.2 (security fix)

### Improved
- Code quality by removing dead code, unused imports, and redundant PHPDoc
- Translation coverage with additional French and English translations
- CI/CD pipeline with automated security scanning
- Performance optimization through CORS refactoring

### Security
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