FROM php:8.2-apache

# Abilita i moduli Apache necessari per il rewrite degli URL
RUN a2enmod rewrite

# Installa le dipendenze di sistema, le estensioni PHP e Python3 con pip
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

# Configura la cartella di lavoro del server web
WORKDIR /var/www/html

# Copia tutti i file del progetto nella cartella
COPY . /var/www/html/

# Installa le dipendenze Python dal file requirements.txt
RUN pip3 install --no-cache-dir -r requirements.txt --break-system-packages

# Imposta i permessi corretti per i file web
RUN chown -R www-data:www-data /var/www/html

# Render assegna una porta dinamica tramite la variabile d'ambiente PORT, 
# configuriamo Apache per ascoltare sulla porta 8080 interna, mentre Uvicorn o un proxy gestirà la porta di Render
ENV PORT=10000
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Creiamo uno script di avvio per eseguire sia Uvicorn (Python) che Apache (PHP) contemporaneamente
RUN echo '#!/bin/bash\nuvicorn main:app --host 0.0.0.0 --port 8000 &\napache2-foreground' > /start.sh && chmod +x /start.sh

# Esponi la porta principale richiesta da Render
EXPOSE 10000

# Avvia lo script combinato
CMD ["/start.sh"]
