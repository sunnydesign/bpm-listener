FROM php:7.2-cli
LABEL MAINTAINER="am@kubia.com"

RUN apt-get update && apt-get -yqq install git zlib1g-dev libsodium-dev libmcrypt-dev libgmp-dev \
    && pecl install mcrypt-1.0.2 \
    && docker-php-ext-install -j$(nproc) bcmath \
    && docker-php-ext-install -j$(nproc) gmp \
    && docker-php-ext-install -j$(nproc) sockets \
    && docker-php-ext-install -j$(nproc) zip \
    && docker-php-ext-install -j$(nproc) pcntl


COPY ./ /opt/ms

RUN useradd composer -b /home/composer && mkdir /home/composer && chown composer:composer /home/composer

RUN curl -sS https://getcomposer.org/installer | php -- \
        --filename=composer \
        --install-dir=/usr/local/bin && \
        echo "alias composer='composer'" >> /home/composer/.bashrc

RUN cd /opt/ms \
    && chown -R composer:composer /opt/ms \
    && su composer -c 'composer install' \
    && chown -R www-data:www-data /opt/ms

WORKDIR /opt/ms
CMD [ "php", "./start.php" ]