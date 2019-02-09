FROM php:7.2-fpm

RUN apt-get update && apt-get install -y wget git unzip
RUN docker-php-ext-install mysqli
RUN pecl install xdebug-2.6.1 \
    && docker-php-ext-enable xdebug

ADD ./php.ini /usr/local/etc/php/php.ini

RUN wget https://getcomposer.org/installer -O - -q \
    | php -- --install-dir=/bin --filename=composer --quiet

WORKDIR /var/www