FROM php:8.2-apache

# 安装系统依赖，以及编译 Intl 所需的 ICU 开发库
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libcurl4-openssl-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        curl \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        simplexml \
        zip \
        intl \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy Apache virtual host config
COPY beikeshop.conf /etc/apache2/sites-available/beikeshop.conf

# Disable default site, enable BeikeShop site
RUN a2dissite 000-default && a2ensite beikeshop

# Create log directory
RUN mkdir -p /var/log/php

# Bind mounts may replace the image's directory ownership; repair all runtime-writable paths before Apache starts.
RUN printf '#!/bin/sh\nmkdir -p storage bootstrap/cache public/cache\nchown -R www-data:www-data storage bootstrap/cache public\nchmod -R ug+rwX storage bootstrap/cache public\nexec apache2-foreground\n' > /usr/local/bin/beikeshop-entrypoint \
    && chmod +x /usr/local/bin/beikeshop-entrypoint

WORKDIR /app

EXPOSE 80 443

CMD ["/usr/local/bin/beikeshop-entrypoint"]
