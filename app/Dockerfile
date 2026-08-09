# syntax=docker/dockerfile:1
#
# One image, several roles chosen at runtime by CONTAINER_MODE:
#   app        FrankenPHP (Caddy + PHP) serving :80
#   horizon    queue supervisor
#   queue      a plain worker, for when Horizon is more than you need
#   scheduler  php artisan schedule:work
#
# Built the same way for local and production, so what you test is what ships.

# ---- Stage 1: PHP runtime base (shared by the build and the final image) ----
FROM dunglas/frankenphp:1-php8.4 AS php-base
WORKDIR /app

# pdo_pgsql/pgsql for Postgres; pcntl and posix are what let queue:work and
# Horizon manage their child processes; intl/bcmath/gd/zip are what the panel
# and the framework expect. curl serves the container healthcheck.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql pcntl posix bcmath intl gd zip

# ---- Stage 2: PHP dependencies ----
# Resolved on php-base rather than the composer image so the platform php-*
# extensions are the real ones. Otherwise every extension a package requires
# needs its own --ignore-platform-req and the lock file stops meaning what it says.
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
# Dependencies first, so editing app code does not invalidate this layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# ---- Stage 3: Wayfinder route helpers ----
# Wayfinder generates the typed route helpers the frontend imports from
# `@/routes`, and they are .gitignored — so they must be produced during the
# build. That needs a working Laravel, which is why this runs on php-base
# rather than in the Node stage.
FROM php-base AS wayfinder
COPY --from=vendor /app /app
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
# Generated with the social presence on, whatever the deployment then runs with.
# Wayfinder writes one module per registered route, so generating with the
# switch off deletes `@/routes/today`, `@/routes/engage` and `@/routes/threads`
# and the build fails on their imports. The helpers are a type-level description
# of every route this application can serve; which of them a given deployment
# registers is a runtime question, and the image is built once for both answers.
# The components that import them are hidden by the `social.enabled` Inertia
# prop, not by whether the module exists.
ENV SOCIAL_PRESENCE_ENABLED=true
RUN php artisan wayfinder:generate --with-form --no-interaction

# ---- Stage 4: Front-end assets ----
FROM node:22 AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=wayfinder /app/resources/js/routes ./resources/js/routes
COPY --from=wayfinder /app/resources/js/actions ./resources/js/actions
COPY --from=wayfinder /app/resources/js/wayfinder ./resources/js/wayfinder
# There is no PHP in this stage, so the Vite plugin must not try to regenerate
# what the previous stage already produced.
ENV WAYFINDER_SKIP=true
RUN npm run build

# ---- Stage 5: Production runtime ----
FROM php-base AS production

COPY --from=vendor /app /app
COPY --from=frontend /app/public/build /app/public/build

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:80 \
    CONTAINER_MODE=app

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]

# ---- Stage 6: Dev ----
# Adds the dev dependencies so the suite, Pint and PHPStan run inside the
# container against the real Postgres:
#   docker compose exec app php artisan test
FROM production AS dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/* \
    && composer install --no-interaction --no-progress \
    && composer clear-cache \
    # Tooling that expects a .env to exist (the test runner among it) warns
    # without one. Empty is correct: Laravel loads .env immutably, so the
    # compose-provided environment still wins.
    && touch .env
ENV APP_ENV=local \
    APP_DEBUG=true
