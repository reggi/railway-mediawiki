FROM node:24-bookworm-slim AS social-cards

WORKDIR /opt/social-cards
COPY social-cards/package.json social-cards/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY social-cards/generate.mjs social-cards/template.mjs ./
RUN chmod 0755 generate.mjs \
    && node generate.mjs --fingerprint > fingerprint

FROM mediawiki:stable

RUN apt-get update \
    && apt-get install -y --no-install-recommends imagemagick libimage-exiftool-perl libpq-dev \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data LocalSettings.php /var/www/html/LocalSettings.php
COPY --chown=www-data:www-data extensions/ImageMetadataSanitizer /var/www/html/extensions/ImageMetadataSanitizer
COPY --chown=www-data:www-data extensions/SocialCards /var/www/html/extensions/SocialCards
COPY --from=social-cards /usr/local/bin/node /usr/local/bin/node
COPY --from=social-cards /opt/social-cards /opt/social-cards
RUN curl -fsSL https://codeload.github.com/wikimedia/mediawiki-extensions-WikiSEO/tar.gz/25302acbefe846debf762409dc175bfb8a0689af \
    | tar -xz -C /var/www/html/extensions \
    && mv /var/www/html/extensions/mediawiki-extensions-WikiSEO-25302acbefe846debf762409dc175bfb8a0689af /var/www/html/extensions/WikiSEO \
    && ln -s /opt/social-cards/generate.mjs /generate \
    && /generate --fingerprint \
    && find /var/www/html/extensions/SocialCards -name '*.php' -exec php -l '{}' ';'
COPY apache-mediawiki.conf /etc/apache2/conf-available/mediawiki.conf
COPY docker-entrypoint.sh /usr/local/bin/wiki-entrypoint
RUN a2enmod rewrite \
    && a2enconf mediawiki \
    && exiftool -ver \
    && mogrify -version \
    && find /var/www/html/extensions/ImageMetadataSanitizer -name '*.php' -exec php -l '{}' ';' \
    && php /var/www/html/extensions/ImageMetadataSanitizer/tests/integration.php \
    && php /var/www/html/extensions/SocialCards/tests/integration.php \
    && chmod 0755 /usr/local/bin/wiki-entrypoint

ENV PORT=8080

EXPOSE 8080

CMD ["wiki-entrypoint"]
