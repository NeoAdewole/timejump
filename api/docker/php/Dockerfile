FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql intl zip bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-timejump.ini
COPY docker/php/entrypoint.sh /usr/local/bin/timejump-entrypoint
# Strip CRLF in case the file was checked out on Windows.
RUN sed -i 's/\r$//' /usr/local/bin/timejump-entrypoint && chmod +x /usr/local/bin/timejump-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["timejump-entrypoint"]
CMD ["php-fpm"]
