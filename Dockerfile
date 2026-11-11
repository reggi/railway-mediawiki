FROM node:24.22.2-bookworm-slim@sha256:01d566e8d94d8c619591d78652babfccd3c6992a3dffa9ed5ab39e11cf6c70e9 AS social-cards

WORKDIR /opt/social-cards
COPY social-cards/package.json social-cards/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY social-cards/generate.mjs social-cards/template.mjs ./
RUN chmod 0755 generate.mjs \
    && node generate.mjs --fingerprint > fingerprint

FROM mediawiki:1.46.2@sha256:406a08c7f2cf5a9c9cb590bbe27a21b4d3cb3268b0acf327fa7dd2727ff6b0be

RUN apt-get update \
    && apt-get install -y --no-install-recommends imagemagick libimage-exiftool-perl libpq-dev libpq5 \
    && docker-php-ext-install pgsql \
    && apt-get purge -y --auto-remove libpq-dev \
    && php --ri pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://codeload.github.com/wikimedia/mediawiki-extensions-MobileFrontend/tar.gz/e56b6d66ac7df35979b23ae170d06397918003b5 -o /tmp/mobilefrontend.tar.gz \
    && mkdir -p /var/www/html/extensions/MobileFrontend \
    && tar -xzf /tmp/mobilefrontend.tar.gz --strip-components=1 -C /var/www/html/extensions/MobileFrontend \
    && rm /tmp/mobilefrontend.tar.gz \
    && test -f /var/www/html/skins/MinervaNeue/skin.json

RUN curl -fsSL https://codeload.github.com/wikimedia/mediawiki-extensions-WikiSEO/tar.gz/25302acbefe846debf762409dc175bfb8a0689af -o /tmp/wikiseo.tar.gz \
    && mkdir -p /var/www/html/extensions/WikiSEO \
    && tar -xzf /tmp/wikiseo.tar.gz --strip-components=1 -C /var/www/html/extensions/WikiSEO \
    && rm /tmp/wikiseo.tar.gz

COPY --from=social-cards /usr/local/bin/node /usr/local/bin/node
COPY --from=social-cards /opt/social-cards /opt/social-cards
RUN ln -s /opt/social-cards/generate.mjs /generate \
    && /generate --fingerprint

COPY --chown=www-data:www-data LocalSettings.php /var/www/html/LocalSettings.php
COPY --chown=www-data:www-data site-resources /var/www/html/site-resources
COPY --chown=www-data:www-data extensions/ImageMetadataSanitizer /var/www/html/extensions/ImageMetadataSanitizer
COPY --chown=www-data:www-data extensions/SocialCards /var/www/html/extensions/SocialCards
COPY --chown=www-data:www-data extensions/PublicArticleUrls /var/www/html/extensions/PublicArticleUrls
COPY --chown=www-data:www-data extensions/PublicHome /var/www/html/extensions/PublicHome
COPY apache-mediawiki.conf /etc/apache2/conf-available/mediawiki.conf
COPY docker-entrypoint.sh /usr/local/bin/wiki-entrypoint
RUN a2enmod rewrite \
    && if [ -e /etc/apache2/conf-enabled/short-url.conf ]; then a2disconf short-url; fi \
    && a2enconf mediawiki \
    && exiftool -ver \
    && mogrify -version \
    && find /var/www/html/extensions/ImageMetadataSanitizer /var/www/html/extensions/SocialCards /var/www/html/extensions/PublicArticleUrls /var/www/html/extensions/PublicHome -name '*.php' -exec php -l '{}' + \
    && php /var/www/html/extensions/ImageMetadataSanitizer/tests/integration.php \
    && php /var/www/html/extensions/SocialCards/tests/integration.php \
    && chmod 0755 /usr/local/bin/wiki-entrypoint

ENV PORT=8080

EXPOSE 8080

CMD ["wiki-entrypoint"]
