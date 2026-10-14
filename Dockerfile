FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader --no-scripts

FROM php:8.3-cli
WORKDIR /app
RUN apt-get update \
    && apt-get install -y libonig-dev libpq-dev unzip \
    && docker-php-ext-install mbstring pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=vendor /app/vendor /app/vendor
COPY . /app
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
