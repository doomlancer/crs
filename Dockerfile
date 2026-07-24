# Kameruner-Tickets (CRS) – PHP/Apache App-Container
FROM php:8.2-apache

# --- System-Pakete + PHP-Extensions ---------------------------------------
# pdo_mysql: Datenbank | mbstring: mb_* Funktionen | curl: PayPal-IPN
# msmtp:     SMTP-Relay, damit PHP mail() E-Mails über einen echten Server sendet
# default-mysql-client: für den DB-Warte-Check im Entrypoint
RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        msmtp \
        default-mysql-client \
    && docker-php-ext-install pdo_mysql mbstring curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- Apache: mod_rewrite + mod_headers aktivieren (für .htaccess / CSP) -----
RUN a2enmod rewrite headers

# --- Apache: AllowOverride All, damit .htaccess greift ---------------------
RUN sed -ri 's!<Directory /var/www/>!<Directory /var/www/html/>!g' /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/html/>\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/app-override.conf \
    && a2enconf app-override

# --- PHP-Produktionseinstellungen + msmtp als sendmail_path ----------------
# variables_order = EGPCS: sorgt dafür, dass Container-Umgebungsvariablen in
# $_ENV landen (config.php liest daraus). Ohne "E" wäre $_ENV leer und die App
# würde auf die localhost-Fallbacks zurückfallen.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'variables_order = "EGPCS"'; \
        echo 'upload_max_filesize = 8M'; \
        echo 'post_max_size = 12M'; \
        echo 'expose_php = Off'; \
        echo 'sendmail_path = "/usr/bin/msmtp -t --read-envelope-from"'; \
    } > "$PHP_INI_DIR/conf.d/zz-app.ini"

# --- Anwendungscode kopieren ----------------------------------------------
COPY . /var/www/html/

# Entrypoint + Startskripte
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Schreibbare Verzeichnisse
RUN mkdir -p /var/www/html/uploads /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
