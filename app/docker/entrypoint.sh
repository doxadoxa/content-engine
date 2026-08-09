#!/usr/bin/env sh
set -e

MODE="${CONTAINER_MODE:-app}"
echo "▶ content-engine — starting in ${MODE} mode"

# APP_KEY bootstrap. In production set APP_KEY explicitly and back it up. For
# compose this generates one and persists it to the shared storage volume so
# every container ends up with the same key — without that, a session signed by
# the web container is rejected by the next one.
KEY_FILE="/app/storage/.app_key"
if [ -z "$APP_KEY" ] || echo "$APP_KEY" | grep -q "CHANGE_ME"; then
	if [ -f "$KEY_FILE" ]; then
		APP_KEY="$(cat "$KEY_FILE")"
	elif [ "$MODE" = "app" ]; then
		APP_KEY="$(php artisan key:generate --show)"
		echo "$APP_KEY" > "$KEY_FILE"
	else
		echo "🔑 waiting for APP_KEY from the app container..."
		while [ ! -f "$KEY_FILE" ]; do sleep 1; done
		APP_KEY="$(cat "$KEY_FILE")"
	fi
	export APP_KEY
fi

chown -R www-data:www-data /app/storage /app/bootstrap/cache 2>/dev/null || true

wait_for_database() {
	echo "⏳ waiting for the database..."
	until php artisan db:monitor >/dev/null 2>&1; do
		sleep 2
	done
}

case "$MODE" in
	app)
		wait_for_database
		echo "🔄 migrating..."
		php artisan migrate --force
		# Public-disk symlink so anything under storage/app/public serves at
		# /storage/*. Idempotent.
		php artisan storage:link || true
		php artisan db:seed --force || echo "⚠️  seed skipped"

		if [ "${APP_ENV}" = "production" ]; then
			echo "📦 caching config/routes/views..."
			php artisan config:cache
			php artisan route:cache
			php artisan view:cache
		else
			# Cached config would hide .env edits, which is exactly what you do
			# while setting the stack up.
			php artisan optimize:clear
		fi

		echo "🌐 serving on :80"
		exec frankenphp run --config /etc/caddy/Caddyfile
		;;

	horizon)
		# Horizon owns the queues; concurrency per queue is in config/horizon.php.
		exec php artisan horizon
		;;

	queue)
		# A plain worker, for setups that do not want Horizon.
		exec php artisan queue:work --tries=3 --max-time=3600
		;;

	scheduler)
		exec php artisan schedule:work
		;;

	*)
		echo "❌ Unknown CONTAINER_MODE: ${MODE}"
		exit 1
		;;
esac
