FROM php:8.2-apache

RUN a2enmod rewrite proxy proxy_http

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

WORKDIR /var/www/html

# COPIA ESPLICITA: Forziamo l'inclusione di tutti i file necessari ignorando eventuali .dockerignore
COPY requirements.txt /var/www/html/
COPY main.py /var/www/html/
COPY app/ /var/www/html/app/
COPY *.php /var/www/html/

# Installazione delle dipendenze Python nella root
RUN pip3 install --no-cache-dir -r requirements.txt --break-system-packages

RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Configurazione di Apache: inoltriamo tutto il traffico API/JSON verso Uvicorn
RUN echo "<VirtualHost *:${PORT}>\n\
    DocumentRoot /var/www/html\n\
    ProxyPreserveHost On\n\
    ProxyPass /genera-turni http://127.0.0.1:8000/genera-turni\n\
    ProxyPassReverse /genera-turni http://127.0.0.1:8000/genera-turni\n\
    ProxyPass /app/genera-turni http://127.0.0.1:8000/app/genera-turni\n\
    ProxyPassReverse /app/genera-turni http://127.0.0.1:8000/app/genera-turni\n\
    ProxyPass /debug-rotte-attive http://127.0.0.1:8000/debug-rotte-attive\n\
    ProxyPassReverse /debug-rotte-attive http://127.0.0.1:8000/debug-rotte-attive\n\
    ProxyPass /api/equita http://127.0.0.1:8000/api/equita\n\
    ProxyPassReverse /api/equita http://127.0.0.1:8000/api/equita\n\
    ProxyPass /api-status http://127.0.0.1:8000/api-status\n\
    ProxyPassReverse /api-status http://127.0.0.1:8000/api-status\n\
    ProxyPass /shopify http://127.0.0.1:8000/shopify\n\
    ProxyPassReverse /shopify http://127.0.0.1:8000/shopify\n\
    <Directory /var/www/html>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>" > /etc/apache2/sites-available/000-default.conf

# Script di avvio: avvia esplicitamente Uvicorn come modulo python3 ed Apache in foreground
RUN echo '#!/bin/bash\npython3 -m uvicorn main:app --host 127.0.0.1 --port 8000 &\napache2-foreground' > /start.sh && chmod +x /start.sh

EXPOSE 10000

CMD ["/start.sh"]
