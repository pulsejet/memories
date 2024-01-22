# Use the official Nextcloud image as the base image
FROM nextcloud

RUN echo "alias ll='ls -alF'" >> /etc/bash.bashrc
# Install Node.js 18 and npm
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && \
  apt-get install -y nodejs && \
  rm -rf /var/lib/apt/lists/*

# Install Needed libs
# Install Git, wget, and PHP Composer
RUN apt-get update && \
  apt-get install -y git wget make unzip

# Install PHP Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
  php composer-setup.php --install-dir=/usr/local/bin --filename=composer  && \
  php -r "unlink('composer-setup.php');"

RUN composer global require "vimeo/psalm"