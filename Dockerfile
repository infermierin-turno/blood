FROM php:8.2-apache

# Abilita i moduli Apache necessari (incluso il proxy per girare le richieste a Python)
RUN a2enmod rewrite proxy proxy_http

# Installa le dipendenze di sistema, PHP e Python3 con pip
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libpq-dev \
    libzip-dev \
    unzip \
    git \
    python3 \
    python3-pip \
    python3-venv \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    opcache \
    calendar

# Configura la cartella di lavoro
WORKDIR /var/www/html

# Copia i file del progetto
COPY . /var/www/html/

# Installa le dipendenze Python dalla cartella app/
RUN pip3 install --no-cache-dir -r app/requirements.txt --break-system-packages

# Permessi
RUN chown -R www-data:www-data /var/www/html

# Configura Apache per ascoltare sulla porta dinamica di Render
ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Configurazione del VirtualHost con i ProxyPass per le API Python (incluso /genera-turni)
RUN echo "<VirtualHost *:${PORT}>\n\
    DocumentRoot /var/www/html\n\
    ProxyPass /genera-turni http://127.0.0.1:8000/genera-turni\n\
    ProxyPassReverse /genera-turni http://127.0.0.1:8000/genera-turni\n\
    ProxyPass /api-status http://127.0.0.1:8000/api-status\n\
    ProxyPassReverse /api-status http://127.0.0.1:8000/api-status\n\
    ProxyPass /ricevi-booking http://127.0.0.1:8000/ricevi-booking\n\
    ProxyPassReverse /ricevi-booking http://127.0.0.1:8000/ricevi-booking\n\
    ProxyPass /analizza-scorte http://127.0.0.1:8000/analizza-scorte\n\
    ProxyPassReverse /analizza-scorte http://127.0.0.1:8000/analizza-scorte\n\
    ProxyPass /shopify http://127.0.0.1:8000/shopify\n\
    ProxyPassReverse /shopify http://127.0.0.1:8000/shopify\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>" > /etc/apache2/sites-available/000-default.conf

# Script di avvio: avvia Uvicorn (puntando ad app.main:app) in background e Apache in primo piano
RUN echo '#!/bin/bash\nuvicorn app.main:app --host 127.0.0.1 --port 8000 &\napache2-foreground' > /start.sh && chmod +x /start.sh

EXPOSE 10000

CMD ["/start.sh"]
