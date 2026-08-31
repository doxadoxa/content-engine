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
# and the framework expect. curl serves the container healthcheck. excimer is
# the sampling profiler Sentry's `profiles_sample_rate` needs — absent, the SDK
# quietly does not profile, which is why a host PHP without it is fine and only
# the image that actually serves traffic carries it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql pcntl posix bcmath intl gd zip excimer

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

# Sentry, all optional. Vite inlines VITE_* literally, so the browser DSN and
# the release have to be present *here* — setting them on the running container
# does nothing, because by then the bundle is already compiled.
#
# SENTRY_RELEASE has to be the same string the server reports under and the same
# one the maps are uploaded against, or Sentry holds maps it will never apply.
# Pass the commit sha: --build-arg SENTRY_RELEASE=$(git rev-parse HEAD).
# The sample rate is inlined at build time like the rest of them, so it has to
# be declared here to be settable at all: .env is not in the build context, and
# setting it on the running container changes nothing. Left out, an operator
# following .env.example would set it, see no effect, and have no way to tell
# that the value never reached the compiler.
ARG VITE_SENTRY_DSN=""
ARG VITE_SENTRY_ENVIRONMENT="production"
ARG VITE_SENTRY_TRACES_SAMPLE_RATE=""
ARG SENTRY_RELEASE=""
ARG SENTRY_ORG=""
ARG SENTRY_PROJECT=""
ENV VITE_SENTRY_DSN=$VITE_SENTRY_DSN \
    VITE_SENTRY_ENVIRONMENT=$VITE_SENTRY_ENVIRONMENT \
    VITE_SENTRY_TRACES_SAMPLE_RATE=$VITE_SENTRY_TRACES_SAMPLE_RATE \
    VITE_SENTRY_RELEASE=$SENTRY_RELEASE \
    SENTRY_RELEASE=$SENTRY_RELEASE \
    SENTRY_ORG=$SENTRY_ORG \
    SENTRY_PROJECT=$SENTRY_PROJECT

# The auth token is a secret and not a build arg: an ARG is readable in the
# image history of anyone who can pull the intermediate stage. Mounted, it
# exists only for the life of this command.
#
#   docker build --secret id=sentry_auth_token,env=SENTRY_AUTH_TOKEN .
#
# Without it, vite.config.ts emits no source maps and skips the upload
# entirely, so this builds unchanged with no Sentry configuration at all.
RUN --mount=type=secret,id=sentry_auth_token \
    SENTRY_AUTH_TOKEN="$(cat /run/secrets/sentry_auth_token 2>/dev/null || true)" \
    npm run build

# ---- Stage 5: Production runtime ----
FROM php-base AS production

# The server's half of the release. Declared again because build args do not
# cross stages and this one starts from php-base, not from `frontend`: the ARG
# up there compiled the browser bundle and went with it. config/sentry.php reads
# SENTRY_RELEASE from the runtime environment, so without this line server events
# arrive with no release while browser events carry one, the source maps uploaded
# against that release are never applied to a PHP stack trace, and "the same
# string the server reports under" -- which this file asks for above -- is not
# true of the image this file builds.
ARG SENTRY_RELEASE=""

COPY --from=vendor /app /app
COPY --from=frontend /app/public/build /app/public/build

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

# A stack rather than plain stderr, so `Log::error` reaches Sentry as well as
# the container log. The stderr half is unchanged and stays the source of
# truth for `docker logs`; the sentry half is a no-op until a DSN is set.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stack \
    LOG_STACK=stderr,sentry \
    SENTRY_RELEASE=$SENTRY_RELEASE \
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
