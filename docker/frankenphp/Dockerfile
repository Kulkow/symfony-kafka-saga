FROM dunglas/frankenphp:php8.5

RUN install-php-extensions pdo_pgsql intl mbstring zip opcache redis rdkafka pcntl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
