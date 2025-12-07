#!/bin/bash
# Optionally, include the vhost in the main configuration
if ! grep -q "Include conf/extra/episciences-citations.conf" /usr/local/apache2/conf/httpd.conf; then
    echo "Include conf/extra/episciences-citations.conf" >> /usr/local/apache2/conf/httpd.conf
fi

# Add Listen 443 for HTTPS
if ! grep -q "^Listen 443" /usr/local/apache2/conf/httpd.conf; then
    echo "Listen 443" >> /usr/local/apache2/conf/httpd.conf
fi

# Configure SSL session cache
if ! grep -q "SSLSessionCache" /usr/local/apache2/conf/httpd.conf; then
    echo "SSLSessionCache shmcb:/usr/local/apache2/logs/ssl_scache(512000)" >> /usr/local/apache2/conf/httpd.conf
fi

#chown -R www-data:www-data /var/www/data /var/www/cache /var/www/logs
exec "$@"

