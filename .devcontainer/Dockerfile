# Keep the Nextcloud version in sync with appinfo/info.xml
# (max-version) and Node.js in sync with .nvmrc / package.json engines.
FROM nextcloud:35-apache

# Install Node.js and common development tools
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg && \
    curl -fsSL https://deb.nodesource.com/setup_24.x | bash - && \
    apt-get install -y --no-install-recommends nodejs sudo git wget make unzip vim ffmpeg && \
    rm -rf /var/lib/apt/lists/*

# Install PHP Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    php -r "unlink('composer-setup.php');"
