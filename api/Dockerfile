FROM dunglas/frankenphp:1-php8.4

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (redis required for symfony/redis-messenger transport)
RUN install-php-extensions pdo_pgsql zip redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

