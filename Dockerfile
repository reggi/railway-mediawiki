FROM mediawiki:stable

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data LocalSettings.php /var/www/html/LocalSettings.php
COPY apache-mediawiki.conf /etc/apache2/conf-available/mediawiki.conf
COPY docker-entrypoint.sh /usr/local/bin/wiki-entrypoint
RUN a2enmod rewrite \
    && a2enconf mediawiki \
    && chmod 0755 /usr/local/bin/wiki-entrypoint

ENV PORT=8080

EXPOSE 8080

CMD ["wiki-entrypoint"]
