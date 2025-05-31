FROM php:8.1-cli

# Устанавливаем зависимости и расширение mysqli
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Копируем все файлы проекта
COPY . /app
WORKDIR /app

# Запускаем встроенный PHP-сервер
CMD ["php", "-S", "0.0.0.0:8000", "-t", "."]