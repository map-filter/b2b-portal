FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype libpq \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql intl zip gd bcmath pcntl sockets opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app \
    && sed -i 's/^user = .*/user = app/; s/^group = .*/group = app/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/core
USER app
