# Episciences Citations Manager

![GPL](https://img.shields.io/github/license/CCSDForge/episciences-citations)
![Language](https://img.shields.io/github/languages/top/CCSDForge/episciences-citations)
![Symfony](https://img.shields.io/badge/Symfony-7.4-black)
![PHP](https://img.shields.io/badge/PHP-8.3%20%2F%208.4-777BB4)

[![Tests](https://github.com/CCSDForge/episciences-citations/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/CCSDForge/episciences-citations/actions/workflows/tests.yml)
[![Lint](https://github.com/CCSDForge/episciences-citations/actions/workflows/lint.yml/badge.svg?branch=main)](https://github.com/CCSDForge/episciences-citations/actions/workflows/lint.yml)
[![codecov](https://codecov.io/gh/CCSDForge/episciences-citations/branch/main/graph/badge.svg)](https://codecov.io/gh/CCSDForge/episciences-citations)

A modern web application for managing and visualizing citations from [Episciences](https://www.episciences.org/) publications. The software enables automated extraction, processing, and management of bibliographic references from scientific documents.

Developed by the [Center for Direct Scientific Communication (CCSD)](https://www.ccsd.cnrs.fr/en/).

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Docker Setup (Recommended)](#docker-setup-recommended)
  - [Manual Setup](#manual-setup)
- [Configuration](#configuration)
- [Development](#development)
- [Project Structure](#project-structure)
- [API Documentation](#api-documentation)
- [Testing](#testing)
- [Deployment](#deployment)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Automated Citation Extraction**: Extract bibliographic references from PDF documents using GROBID
- **Multiple Import Sources**: Support for manual entry, BibTeX import, and Semantic Scholar integration
- **Reference Management**: Add, edit, validate, and organize citations
- **Multi-language Support**: Interface available in English and French
- **Export Capabilities**: Export citations in various formats
- **User Authentication**: Secure CAS-based authentication system
- **Modern UI**: Responsive interface built with Bootstrap 5

## Tech Stack

**Backend:**
- PHP 8.3 or 8.4
- Symfony 7.4
- Doctrine ORM 3.0
- MySQL/MariaDB

**Analysis & Modernization:**
- PHPStan 2.1 (Static Analysis)
- Rector 2.4 (Automated Upgrades)

**Frontend:**
- Bootstrap 5.3.8
- Stimulus (Hotwired)
- Webpack Encore
- FontAwesome 7.1.0

**External Services:**
- GROBID (for PDF citation extraction)
- Semantic Scholar API
- CAS Authentication

## Requirements

- PHP 8.3 or 8.4
- Composer
- Node.js 16+ and npm/yarn
- MySQL 5.7+ or MariaDB 10.3+
- Docker & Docker Compose (for containerized setup)

## Installation

### Docker Setup (Recommended)

1. **Clone the repository**
   ```bash
   git clone https://github.com/CCSDForge/episciences-citations.git
   cd episciences-citations
   ```

2. **Configure environment**
   ```bash
   cp .env .env.local
   # Edit .env.local with your configuration
   ```

3. **Start Docker containers**
   ```bash
   make up
   ```

4. **Install dependencies**
   ```bash
   make composer-install
   make npm-install
   ```

5. **Run database migrations**
   ```bash
   make db-test-migrate # for test environment
   # or manually for dev
   docker exec epi-citations-php-fpm php bin/console doctrine:migrations:migrate
   ```

6. **Build frontend assets**
   ```bash
   make npm-build
   ```

7. **Access the application**
   - Application: https://localhost (or your configured domain)
   - Assets are automatically compiled in Docker environment

### Manual Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/CCSDForge/episciences-citations.git
   cd episciences-citations
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install JavaScript dependencies**
   ```bash
   npm install
   ```

4. **Configure environment**
   ```bash
   cp .env .env.local
   ```

   Edit `.env.local` and configure:
   - `DATABASE_URL`: Your database connection string
   - `APP_ENV`: `dev` or `prod`
   - `APP_SECRET`: Generate a secure random string
   - External service URLs (GROBID, Semantic Scholar, etc.)

5. **Create database and run migrations**
   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   ```

6. **Build assets**
   ```bash
   npm run dev    # Development mode
   # or
   npm run build  # Production mode
   ```

7. **Start development server**
   ```bash
   symfony server:start
   # or
   php -S localhost:8000 -t public/
   ```

## Configuration

### Environment Variables

Key configuration variables in `.env.local`:

```env
# Application
APP_ENV=dev
APP_SECRET=your-secret-key-here

# Database
DATABASE_URL="mysql://user:password@127.0.0.1:3306/episciences_citations?serverVersion=8.0"

# External Services
GROBID_URL=http://grobid-server:8070
SEMANTIC_SCHOLAR_API_URL=https://api.semanticscholar.org/
API_S2_KEY=your-semantic-scholar-api-key-here

# CAS Authentication
CAS_SERVER_URL=your-cas-server-url
CAS_SERVICE_URL=your-service-url
```

### Apache/Nginx Configuration

For production deployment, configure your web server to point to the `public/` directory.

**Apache VirtualHost example:**
```apache
<VirtualHost *:80>
    ServerName episciences-citations.example.com
    DocumentRoot /path/to/episciences-citations/public

    <Directory /path/to/episciences-citations/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Development

### Makefile

The project uses a `Makefile` to simplify common tasks. It is recommended to use these commands to ensure consistency between development environments.

```bash
make help # Display all available commands
```

### Available Commands

```bash
# Frontend development
npm run dev          # Build assets for development
npm run watch        # Watch and rebuild on changes
npm run build        # Build assets for production

# Backend development
php bin/console cache:clear              # Clear cache
php bin/console doctrine:migrations:diff # Generate new migration
php bin/console doctrine:schema:validate # Validate database schema

# Code quality
composer install --dev                   # Install dev dependencies
vendor/bin/phpunit                       # Run tests
make rector-dry                          # Preview PHP migration changes
make rector                              # Apply PHP migration changes
```

### Coding Standards

- **PHP**: Follow Symfony coding standards (PSR-12)
- **JavaScript**: ES6+ syntax with Stimulus controllers
- **CSS**: Bootstrap utility classes with custom SCSS components

## Project Structure

```
episciences-citations/
├── assets/                 # Frontend assets
│   ├── controllers/       # Stimulus controllers
│   ├── js/               # JavaScript files
│   └── styles/           # CSS/Bootstrap/Sass files
├── config/               # Symfony configuration
├── docker/              # Docker configuration files
├── migrations/          # Database migrations
├── public/             # Web server document root
├── src/
│   ├── Command/       # Console commands
│   ├── Controller/    # HTTP controllers
│   ├── Entity/        # Doctrine entities
│   ├── Repository/    # Doctrine repositories
│   ├── Service/       # Business logic services
│   └── Twig/         # Twig extensions
├── templates/         # Twig templates
├── tests/            # PHPUnit tests
├── translations/     # i18n files (EN/FR)
└── var/             # Cache, logs, sessions
```

## API Documentation

The application provides both a web interface and API endpoints.

### Web Interface

| Route | Description |
|-------|-------------|
| `/` | Main application interface |
| `/{en\|fr}/viewref/{docid}` | View and manage references for a document |

### Public API Endpoints

#### `GET /api/extract`

Downloads the PDF from Episciences and extracts its bibliographic references via GROBID. This is a synchronous endpoint — the request blocks until extraction completes (typically a few seconds). If references have already been extracted for this document, returns immediately without re-running GROBID.

**Authentication**

The endpoint is protected by a Bearer token when `API_EXTRACT_TOKEN` is configured (see `.env.dist`). Pass the token in the `Authorization` header:

```bash
curl -H "Authorization: Bearer <your-token>" \
  'https://citations-dev.episciences.org/api/extract?url=...'
```

If `API_EXTRACT_TOKEN` is empty or unset, authentication is disabled and the endpoint is publicly accessible.

**Query parameters**

| Parameter | Required | Description |
|-----------|----------|-------------|
| `url` | Yes | URL of the PDF to download (any publicly accessible URL) |
| `docid` | Conditional | Episciences document ID to associate the references with. Required when the URL does not contain a numeric ID (e.g. arXiv, HAL, external repository). |

**Examples**

```bash
# Episciences URL — docid extracted automatically
curl -H "Authorization: Bearer mytoken" \
  'https://citations-dev.episciences.org/api/extract?url=https://episciences.org/article/view/17204'

# External PDF URL — docid must be provided explicitly
curl -H "Authorization: Bearer mytoken" \
  'https://citations-dev.episciences.org/api/extract?url=https://arxiv.org/pdf/2506.15295v1&docid=17204'

# Document already processed — returns immediately, GROBID not invoked again
# → {"success":true,"docid":17204,"alreadyExtracted":true,"referenceCount":42}
```

**Responses**

| Status | Body | Description |
|--------|------|-------------|
| `200 OK` | `{"success": true, "docid": 17204, "alreadyExtracted": false}` | Extraction succeeded |
| `200 OK` | `{"success": true, "docid": 17204, "alreadyExtracted": true, "referenceCount": 42}` | Already extracted — no GROBID call made |
| `200 OK` | `{"success": false, "docid": 17204, "error": "No references found in the PDF"}` | PDF parsed but no references detected |
| `400 Bad Request` | `{"success": false, "error": "Missing required parameter: url"}` | `url` parameter absent |
| `400 Bad Request` | `{"success": false, "error": "Could not extract a document ID from the provided URL"}` | URL contains no numeric ID |
| `401 Unauthorized` | `{"success": false, "error": "Unauthorized"}` | Token missing or incorrect |
| `404 Not Found` | `{"success": false, "error": "..."}` | PDF not found on Episciences |
| `502 Bad Gateway` | `{"success": false, "error": "..."}` | Episciences API error |

#### `GET /visualize-citations`

Returns bibliographic references for a document in formatted JSON. This endpoint is used by the Episciences platform widget.

**Query parameters**

| Parameter | Required | Description |
|-----------|----------|-------------|
| `url` | Yes | Episciences document URL or PDF URL. The document ID is extracted from this URL. |
| `all` | No | When set to `1`, returns all references. Otherwise, only accepted references are returned. |

**Example**

```bash
curl 'https://citations-dev.episciences.org/visualize-citations?url=http%3A%2F%2Fdev.episciences.org%2F17458%2Fpdf&all=1'
```

**Successful response format**

The response is a JSON object keyed by internal reference ID. Each value contains the formatted reference data and display metadata.

```json
{
  "12345": {
    "ref": {
      "raw_reference": "Doe, J. (2024). Example article. Example Journal.",
      "doi": "10.1234/example",
      "detectors": ["clayFeet"],
      "status": ["watch"],
      "pubpeerurl": ["https://pubpeer.com/publications/10.1234/example"]
    },
    "csl": {
      "raw_reference": "Doe, J. (2024). Example article. Example Journal.",
      "doi": "10.1234/example",
      "csl": {
        "type": "article-journal",
        "title": "Example article"
      },
      "detectors": ["clayFeet"],
      "status": ["watch"],
      "pubpeerurl": ["https://pubpeer.com/publications/10.1234/example"]
    },
    "isAccepted": 1,
    "referenceOrder": 0
  }
}
```

**Reference object fields**

| Field | Type | Description |
|-------|------|-------------|
| `ref` | object | Formatted reference data. It always contains the displayable `raw_reference` when available. |
| `ref.raw_reference` | string | Human-readable reference text. For CSL references, it is rendered from the CSL payload. |
| `ref.doi` | string | DOI of the reference, when known. |
| `ref.detectors` | string[] | Optional Solr enrichment field. Contains detector names returned for the DOI. |
| `ref.status` | string[] | Optional Solr enrichment field. Possible values are `Problematic` and `Genuine`. |
| `ref.pubpeerurl` | string[] | Optional Solr enrichment field. Contains PubPeer URLs returned for the DOI. Values may be absent when no valid Solr match exists. |
| `csl` | object | Present only when the stored reference contains CSL metadata. This object contains the original stored reference payload, including the nested `csl` data. |
| `isAccepted` | integer | `1` when the reference is accepted, `0` otherwise. |
| `referenceOrder` | integer | Display order of the reference in the document. |

Solr enrichment fields are optional and are only present when the reference was enriched by DOI. When Solr has no match for a DOI, these keys are omitted from the reference object.

Known `detectors` values currently returned by the Solr facet are:

```text
clayFeet
annulled
tortured
expression-of-concern
suspect
citejacked
deindexed
Seek&Blastn
journal-cases
scigen
problematic-cell-lines
mathgen
sbir
```

**Other responses**

| Status | Body | Description |
|--------|------|-------------|
| `200 OK` | `{"status": 200, "message": "No reference found"}` | The document exists but no matching references were found for the requested mode. |
| `400 Bad Request` | `{"status": 400, "message": "An URL is missing"}` | `url` parameter absent. |
| `400 Bad Request` | `{"status": 400, "message": "A docid is missing"}` | The document ID could not be extracted from the URL. |
| `403 Forbidden` | `{"status": 403, "message": "Forbidden"}` | Request blocked by CORS origin validation. |

For other endpoints, refer to the controller annotations in `src/Controller/`.

## Importing References via Semantic Scholar

The application supports importing and enriching references for a batch of documents using the [Semantic Scholar API](https://www.semanticscholar.org/product/api).

### 1. Configuration

Obtain a Semantic Scholar API key and add it to your `.env.local` file:

```env
API_S2_KEY=your_api_key_here
```

### 2. Prepare the Input File

Create a CSV file containing the list of documents to process. The CSV must include a header row with at least two columns:
- `docid`: The Episciences internal document ID.
- `doi`: The DOI of the paper for which you want to retrieve references.

Example `import.csv`:
```csv
docid,doi
17204,10.1038/s41586-021-03430-5
17458,10.1126/science.abc1234
```

### 3. Run the Import Command

Execute the following console command to process the CSV:

```bash
# Inside Docker
docker exec epi-citations-php-fpm php bin/console app:get-bibref path/to/import.csv --api S2

# Manual setup
php bin/console app:get-bibref path/to/import.csv --api S2
```

### 4. Optional: BibTeX Export

To export the retrieved references to BibTeX files (one per document), use the `--output` option:

```bash
php bin/console app:get-bibref path/to/import.csv --api S2 --output ./var/export/bib/
```

The command will generate files named `{docid}.bib` in the specified directory.

## Testing

```bash
# Run all tests
php bin/phpunit

# Run specific test suite
php bin/phpunit tests/Unit/

# Run with coverage
php bin/phpunit --coverage-html var/coverage
```

## Deployment

### Production Deployment Steps

1. **Install dependencies (production mode)**
   ```bash
   composer install --no-dev --optimize-autoloader
   npm install --production
   ```

2. **Build assets**
   ```bash
   npm run build
   ```

3. **Set environment to production**
   ```bash
   APP_ENV=prod
   ```

4. **Clear and warm up cache**
   ```bash
   php bin/console cache:clear --env=prod
   php bin/console cache:warmup --env=prod
   ```

5. **Run migrations**
   ```bash
   php bin/console doctrine:migrations:migrate --no-interaction
   ```

6. **Set proper permissions**
   ```bash
   chown -R www-data:www-data var/
   ```

## Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the **GNU General Public License v3.0** - see the [LICENSE](LICENSE) file for details.

## Support

For issues, questions, or contributions:
- Open an issue on [GitHub](https://github.com/CCSDForge/episciences-citations/issues)
- Contact: [CCSD](https://www.ccsd.cnrs.fr/en/)

---

**Developed by [CCSD](https://www.ccsd.cnrs.fr/en/)**
