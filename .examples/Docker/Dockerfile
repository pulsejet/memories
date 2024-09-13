# ==========================================================
# Please see docker-compose.yml for usage information
# ==========================================================

FROM nextcloud:28-fpm

RUN set -ex; \
    \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ffmpeg \
        libmagickcore-6.q16-6-extra \
        procps \
        supervisor \
    ; \
    rm -rf /var/lib/apt/lists/*

# php-fpm configuration
RUN echo 'pm.max_children = 16'        >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_spare_servers = 8'    >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.start_servers = 8'        >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Enable PHP 8 JIT
RUN { \
        echo 'opcache.jit=1255'; \
        echo 'opcache.jit_buffer_size=256M'; \
    } >> "${PHP_INI_DIR}/conf.d/opcache-recommended.ini";

# Add preview generation cron job
RUN echo '*/6 * * * * php -f /var/www/html/occ preview:pre-generate' >> /var/spool/cron/crontabs/www-data

RUN mkdir -p \
    /var/log/supervisord \
    /var/run/supervisord \
;

COPY supervisord.conf /

ENV NEXTCLOUD_UPDATE=1

CMD /usr/bin/supervisord -c /supervisord.conf