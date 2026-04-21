FROM php:8.2-apache

# ── System deps + PHP extensions ─────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
        default-mysql-server \
        default-mysql-client \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install mysqli gd \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Copy application files ────────────────────────────────────────────────────
COPY . /var/www/html/

# ── Uploads directory with correct permissions ────────────────────────────────
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

# ── Apache: allow .htaccess overrides ────────────────────────────────────────
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# ── Dynamic PORT support (for Render, Railway, etc.) ─────────────────────────
# Apache listens on $PORT at runtime; defaults to 80 if PORT is unset
ENV PORT=80

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE ${PORT}

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]