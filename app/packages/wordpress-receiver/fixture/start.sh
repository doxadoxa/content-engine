#!/usr/bin/env bash
set -euo pipefail
fixture_dir="$(cd "$(dirname "$0")" && pwd)"
compose=(docker compose -f "$fixture_dir/compose.yaml")
"${compose[@]}" up -d db wordpress
for attempt in $(seq 1 45); do
  if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-config.php; then break; fi
  sleep 1
done
if ! "${compose[@]}" run --rm cli core is-installed; then
  "${compose[@]}" run --rm cli core install --url=http://localhost:8093 --title='Avyo WordPress fixture' --admin_user=fixture-admin --admin_password=local-fixture-admin-only --admin_email=admin@wordpress-fixture.test --skip-email
fi
"${compose[@]}" run --rm cli plugin activate avyo-receiver
if ! "${compose[@]}" run --rm cli plugin is-installed wordpress-seo; then
  "${compose[@]}" run --rm cli plugin install wordpress-seo --version=28.5
fi
"${compose[@]}" run --rm cli eval 'require "/fixture/seed.php";'
printf '%s\n' 'Local fixture ready at http://localhost:8093. No volumes were deleted.'
