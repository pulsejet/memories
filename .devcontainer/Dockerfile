# Use the official Nextcloud image as the base image
FROM nextcloud:apache

# Install Node.js 20 and npm
# Also install common development tools
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs && \
    apt-get install -y sudo git wget make unzip vim ffmpeg && \
    rm -rf /var/lib/apt/lists/*

# Install PHP Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer  && \
    php -r "unlink('composer-setup.php');"
