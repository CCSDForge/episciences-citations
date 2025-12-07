# Episciences Citations Manager

![GPL](https://img.shields.io/github/license/CCSDForge/episciences-citations)
![Language](https://img.shields.io/github/languages/top/CCSDForge/episciences-citations)
![Symfony](https://img.shields.io/badge/Symfony-6.4-black)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4)

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
- **Modern UI**: Responsive interface built with Tailwind CSS

## Tech Stack

**Backend:**
- PHP 8.2+
- Symfony 6.4
- Doctrine ORM
- MySQL/MariaDB

**Frontend:**
- Tailwind CSS 3.2.7
- Stimulus (Hotwired)
- Webpack Encore
- FontAwesome 6.4.0

**External Services:**
- GROBID (for PDF citation extraction)
- Semantic Scholar API
- CAS Authentication

## Requirements

- PHP 8.2 or higher
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
   docker-compose up -d
   ```

4. **Install dependencies**
   ```bash
   docker exec epi-citations-php-fpm composer install
   docker exec epi-citations-php-fpm npm install
   ```

5. **Run database migrations**
   ```bash
   docker exec epi-citations-php-fpm php bin/console doctrine:migrations:migrate --no-interaction
   ```

6. **Build frontend assets**
   ```bash
   docker exec epi-citations-php-fpm npm run build
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
```

### Coding Standards

- **PHP**: Follow Symfony coding standards (PSR-12)
- **JavaScript**: ES6+ syntax with Stimulus controllers
- **CSS**: Tailwind utility-first approach with custom components

## Project Structure

```
episciences-citations/
├── assets/                 # Frontend assets
│   ├── controllers/       # Stimulus controllers
│   ├── js/               # JavaScript files
│   └── styles/           # CSS/Tailwind files
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

The application provides both a web interface and API endpoints:

- **Web Interface**: `/` - Main application interface
- **View References**: `/en/viewref/{docId}` or `/fr/viewref/{docId}`
- **Public API**: Various endpoints for citation retrieval and management

For detailed API documentation, refer to the controller annotations in `src/Controller/`.

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