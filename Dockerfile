# Runs the PHP site on Render (Apache + PHP 8.2 + PostgreSQL driver)
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql pgsql \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers remoteip

# Render gives the site a port in the PORT variable (default 10000)
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

# Behind Render's proxy: use the visitor's real IP (for login/rate limits) and know the page is HTTPS
RUN printf 'RemoteIPHeader X-Forwarded-For\nSetEnvIf X-Forwarded-Proto "https" HTTPS=on\n' > /etc/apache2/conf-available/proxy-fix.conf \
 && a2enconf proxy-fix

COPY . /var/www/html/

# Private uploads (resumes, IDs, payment proofs) go here
ENV PRIVATE_DIR=/var/data/tbb_private
RUN mkdir -p /var/data/tbb_private /var/www/html/uploads/logos /var/www/html/uploads/qr \
 && chown -R www-data:www-data /var/data /var/www/html/uploads
