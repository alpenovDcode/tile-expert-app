# Используем официальный PHP образ с Apache
FROM php:8.2-apache

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libicu-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Устанавливаем PHP расширения
RUN docker-php-ext-configure intl \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        dom \
        xml

# Включаем Apache mod_rewrite
RUN a2enmod rewrite

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настраиваем рабочую директорию
WORKDIR /var/www/html

# Копируем файлы composer для кэширования зависимостей
COPY composer.json composer.lock ./

# Устанавливаем зависимости PHP
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-progress --prefer-dist

# Копируем исходный код приложения
COPY . .

# Создаем необходимые директории и устанавливаем права
RUN mkdir -p var/cache var/log public/processed-images \
    && chown -R www-data:www-data var/ public/processed-images/ \
    && chmod -R 775 var/ public/processed-images/

# Завершаем установку Composer скриптов
RUN composer dump-autoload --optimize

# Настраиваем Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Копируем Apache конфигурацию
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

# Создаем пользователя для Symfony
RUN groupadd -g 1000 symfony && useradd -u 1000 -g symfony -m symfony

# Экспонируем порт 80
EXPOSE 80

# Запускаем Apache
CMD ["apache2-ctl", "-D", "FOREGROUND"] 