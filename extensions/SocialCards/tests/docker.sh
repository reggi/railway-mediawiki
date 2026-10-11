#!/bin/sh
set -eu

prefix="socialcards-test-$$"
image="railway-mediawiki-socialcards-test:$$"
database="${prefix}-postgres"
wiki="${prefix}-wiki"
network="${prefix}-network"

cleanup() {
	docker rm --force "$wiki" "$database" >/dev/null 2>&1 || true
	docker network rm "$network" >/dev/null 2>&1 || true
	docker image rm "$image" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

docker build --quiet --tag "$image" .
docker network create "$network" >/dev/null
docker run --detach --name "$database" --network "$network" \
	-e POSTGRES_PASSWORD=test-only-password -e POSTGRES_DB=mediawiki \
	postgres:17 >/dev/null
database_ready=false
for attempt in $(seq 1 60); do
	if docker exec "$database" pg_isready --quiet --username postgres --dbname mediawiki; then
		database_ready=true
		break
	fi
	sleep 1
done
if [ "$database_ready" != true ]; then
	docker logs "$database"
	exit 1
fi
docker run --detach --name "$wiki" --network "$network" \
	-e PORT=8080 -e MW_SERVER=http://127.0.0.1:8080 \
	-e MW_SECRET_KEY=test-only-secret -e MW_UPGRADE_KEY=test-only-upgrade \
	-e MW_ADMIN_USER=Admin -e MW_ADMIN_PASSWORD=test-only-password \
	-e PGHOST="$database" -e PGPORT=5432 -e PGDATABASE=mediawiki \
	-e PGUSER=postgres -e PGPASSWORD=test-only-password \
	"$image" >/dev/null

ready=false
for attempt in $(seq 1 90); do
	if docker exec "$wiki" curl --silent --fail http://127.0.0.1:8080/wiki/Special:UserLogin >/dev/null 2>&1; then
		ready=true
		break
	fi
	sleep 1
done
if [ "$ready" != true ]; then
	docker logs "$wiki"
	exit 1
fi
docker exec --user www-data "$wiki" php extensions/SocialCards/tests/integration.php
docker exec --user www-data "$wiki" php maintenance/run.php ./extensions/SocialCards/tests/MediaWikiIntegration.php
docker exec --user www-data "$wiki" php maintenance/run.php ./extensions/PublicArticleUrls/tests/Integration.php
docker exec --user www-data "$wiki" php maintenance/run.php ./extensions/PublicHome/tests/Integration.php
docker exec --user www-data "$wiki" php maintenance/run.php ./extensions/SocialCards/maintenance/RefreshSocialCards.php
