#!/bin/sh

set -eu

case "${PORT:-}" in
	''|*[!0-9]*)
		echo 'PORT must be a number.' >&2
		exit 1
		;;
esac

sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/images
chown -R www-data:www-data /var/www/html/images

set +e
php -r '
$connection = pg_connect( "" );

if ( !$connection ) {
	exit( 2 );
}

$result = pg_query_params(
	$connection,
	"SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = $2",
	[ getenv( "MW_DB_SCHEMA" ) ?: "mediawiki", ( getenv( "MW_DB_PREFIX" ) ?: "" ) . "user" ]
);

exit( $result && pg_num_rows( $result ) > 0 ? 0 : 1 );
'
database_status=$?
set -e

case "$database_status" in
	0)
		;;
	1)
		: "${MW_ADMIN_USER:?MW_ADMIN_USER is required for the initial database setup.}"
		: "${MW_ADMIN_PASSWORD:?MW_ADMIN_PASSWORD is required for the initial database setup.}"

		server="${MW_SERVER:-}"
		if [ -z "$server" ]; then
			: "${RAILWAY_PUBLIC_DOMAIN:?Generate a Railway public domain or set MW_SERVER.}"
			server="https://${RAILWAY_PUBLIC_DOMAIN}"
		fi

		temporary_directory="$(mktemp -d)"
		runtime_settings='/var/www/html/LocalSettings.php'
		saved_settings="$temporary_directory/LocalSettings.php.runtime"

		cleanup() {
			if [ -f "$saved_settings" ]; then
				mv "$saved_settings" "$runtime_settings"
			fi
			rm -rf "$temporary_directory"
		}

		trap cleanup EXIT HUP INT TERM
		mkdir "$temporary_directory/config"
		printf '%s' "$PGPASSWORD" > "$temporary_directory/database-password"
		printf '%s' "$MW_ADMIN_PASSWORD" > "$temporary_directory/admin-password"
		mv "$runtime_settings" "$saved_settings"

		php maintenance/run.php install \
			--confpath "$temporary_directory/config" \
			--scriptpath "" \
			--server "$server" \
			--lang "${MW_LANGUAGE_CODE:-en}" \
			--dbtype postgres \
			--dbserver "$PGHOST" \
			--dbport "$PGPORT" \
			--dbname "$PGDATABASE" \
			--dbprefix "${MW_DB_PREFIX:-}" \
			--dbschema "${MW_DB_SCHEMA:-mediawiki}" \
			--dbuser "$PGUSER" \
			--dbpassfile "$temporary_directory/database-password" \
			--passfile "$temporary_directory/admin-password" \
			"${MW_SITE_NAME:-MediaWiki}" \
			"$MW_ADMIN_USER"

		mv "$saved_settings" "$runtime_settings"
		rm -rf "$temporary_directory"
		trap - EXIT
		;;
	*)
		echo 'Unable to connect to PostgreSQL.' >&2
		exit "$database_status"
		;;
esac

php maintenance/run.php update --quick

unset MW_ADMIN_PASSWORD

exec apache2-foreground
