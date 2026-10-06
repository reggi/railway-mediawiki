FROM mediawiki:stable

RUN apt-get update \
    && apt-get install -y --no-install-recommends imagemagick libimage-exiftool-perl libpq-dev \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data LocalSettings.php /var/www/html/LocalSettings.php
COPY --chown=www-data:www-data extensions/ImageMetadataSanitizer /var/www/html/extensions/ImageMetadataSanitizer
COPY apache-mediawiki.conf /etc/apache2/conf-available/mediawiki.conf
COPY docker-entrypoint.sh /usr/local/bin/wiki-entrypoint
RUN a2enmod rewrite \
    && a2enconf mediawiki \
    && exiftool -ver \
    && mogrify -version \
    && find /var/www/html/extensions/ImageMetadataSanitizer -name '*.php' -exec php -l '{}' ';' \
    && php /var/www/html/extensions/ImageMetadataSanitizer/tests/integration.php \
    && chmod 0755 /usr/local/bin/wiki-entrypoint

ENV PORT=8080

EXPOSE 8080

CMD ["wiki-entrypoint"]
