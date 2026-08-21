#!/bin/bash
# Optionally, include the vhost in the main configuration
if ! grep -q "Include conf/extra/episciences-citations.conf" /usr/local/apache2/conf/httpd.conf; then
    echo "Include conf/extra/episciences-citations.conf" >> /usr/local/apache2/conf/httpd.conf
fi

exec "$@"


