#!/usr/bin/env bash
#
# Deployment script for Episciences Citations Manager
# Usage: ./bin/deploy.sh <branch-or-tag> [options]
#

set -euo pipefail

# ==============================================================================
# Colors & Output Helpers
# ==============================================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

log_step() {
    echo -e "\n${BOLD}${BLUE}==>${NC} ${BOLD}$1${NC}"
}

# ==============================================================================
# Usage & Arguments
# ==============================================================================
usage() {
    local exit_code="${1:-0}"
    cat <<EOF
Usage: $(basename "$0") <branch-or-tag> [options]

Arguments:
  <branch-or-tag>                 Git branch or tag to deploy (e.g. main, preprod, v1.3.1)

Options:
  --migrate, --with-migrations    Run Doctrine database migrations (skipped by default)
  --skip-assets                   Skip JS/CSS dependencies install and build
  --app-env=<env>                 Set Symfony environment (default: prod)
  -h, --help                      Show this help message
EOF
    exit "${exit_code}"
}

if [[ $# -eq 0 ]]; then
    usage 1
fi

TARGET=""
RUN_MIGRATIONS=0
SKIP_ASSETS=0
APP_ENV="prod"

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            usage 0
            ;;
        --migrate|--with-migrations|--run-migrations)
            RUN_MIGRATIONS=1
            shift
            ;;
        --skip-assets)
            SKIP_ASSETS=1
            shift
            ;;
        --app-env=*)
            APP_ENV="${1#*=}"
            shift
            ;;
        -*)
            log_error "Unknown option: $1"
            usage 1
            ;;
        *)
            if [[ -z "${TARGET}" ]]; then
                TARGET="$1"
                shift
            else
                log_error "Unexpected argument: $1"
                usage 1
            fi
            ;;
    esac
done

if [[ -z "${TARGET}" ]]; then
    log_error "Missing required argument: <branch-or-tag>"
    usage 1
fi

# ==============================================================================
# Path & Directory
# ==============================================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

log_info "Deploying target: ${BOLD}${TARGET}${NC} (Environment: ${BOLD}${APP_ENV}${NC})"
log_info "Project directory: ${PROJECT_ROOT}"

# ==============================================================================
# 1. Pre-flight checks & Git status
# ==============================================================================
log_step "1. Checking git working tree and fetching references..."

if ! git diff-index --quiet HEAD -- 2>/dev/null; then
    log_error "Working directory has uncommitted tracked changes. Please commit or stash them before deploying."
    exit 1
fi

git fetch --all --tags --prune --force

# Check if target exists as branch or tag
if ! git rev-parse --verify --quiet "refs/tags/${TARGET}" >/dev/null \
   && ! git rev-parse --verify --quiet "refs/heads/${TARGET}" >/dev/null \
   && ! git rev-parse --verify --quiet "refs/remotes/origin/${TARGET}" >/dev/null; then
    log_error "Target '${TARGET}' not found as a local/remote branch or tag."
    exit 1
fi

# ==============================================================================
# 2. Checkout target
# ==============================================================================
log_step "2. Checking out '${TARGET}'..."

git checkout "${TARGET}"

# If target is a branch that exists on origin, pull the latest changes
if git rev-parse --verify --quiet "refs/remotes/origin/${TARGET}" >/dev/null; then
    if git symbolic-ref -q HEAD >/dev/null; then
        log_info "Pulling latest changes for branch '${TARGET}'..."
        git pull --ff-only origin "${TARGET}"
    fi
fi

CURRENT_COMMIT="$(git rev-parse --short HEAD)"
log_success "Now at commit: ${CURRENT_COMMIT}"

# ==============================================================================
# 3. Composer (PHP dependencies)
# ==============================================================================
log_step "3. Installing PHP dependencies (Composer)..."

COMPOSER_BIN="$(command -v composer || echo "${PROJECT_ROOT}/composer.phar")"

if [[ ! -x "${COMPOSER_BIN}" ]] && ! command -v composer >/dev/null 2>&1; then
    log_error "Composer not found! Please install composer or place composer.phar in project root."
    exit 1
fi

if [[ "${APP_ENV}" == "prod" ]]; then
    "${COMPOSER_BIN}" install --no-dev --optimize-autoloader --no-interaction --prefer-dist
else
    "${COMPOSER_BIN}" install --optimize-autoloader --no-interaction --prefer-dist
fi

log_success "Composer dependencies installed."

# ==============================================================================
# 4. Frontend Assets (Yarn / npm)
# ==============================================================================
if [[ ${SKIP_ASSETS} -eq 0 ]]; then
    log_step "4. Installing assets and building frontend..."
    if command -v yarn >/dev/null 2>&1; then
        yarn install
        yarn build
    elif command -v npm >/dev/null 2>&1; then
        npm install
        npm run build
    else
        log_warn "Neither yarn nor npm found. Skipping asset compilation."
    fi
    log_success "Assets built successfully."
else
    log_info "Skipping assets step (--skip-assets)."
fi

# ==============================================================================
# 5. Database Migrations (skipped by default)
# ==============================================================================
if [[ ${RUN_MIGRATIONS} -eq 1 ]]; then
    log_step "5. Running Doctrine database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --env="${APP_ENV}"
    log_success "Database migrations executed."
else
    log_info "Database migrations skipped by default (use --migrate to execute)."
fi

# ==============================================================================
# 6. Symfony Cache & Optimization
# ==============================================================================
log_step "6. Clearing and warming up Symfony cache..."

php bin/console cache:clear --env="${APP_ENV}" --no-debug
php bin/console cache:warmup --env="${APP_ENV}" --no-debug

# ==============================================================================
# 7. Permissions
# ==============================================================================
log_step "7. Setting directory permissions on var/..."

mkdir -p var/cache var/log
chmod -R 775 var/cache var/log 2>/dev/null || true

log_step "Deployment completed successfully! 🎉"
log_info "Deployed: ${TARGET} (${CURRENT_COMMIT}) [env: ${APP_ENV}]"
