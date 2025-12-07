#!/bin/bash

# Generate self-signed SSL certificate for development
# This script creates a certificate valid for 365 days

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SSL_DIR="$SCRIPT_DIR/ssl"
DOMAIN="citations-dev.episciences.org"

# Create SSL directory if it doesn't exist
mkdir -p "$SSL_DIR"

# Check if certificates already exist
if [ -f "$SSL_DIR/server.crt" ] && [ -f "$SSL_DIR/server.key" ]; then
    echo "SSL certificates already exist in $SSL_DIR"
    echo "If you want to regenerate them, delete the existing files first."
    exit 0
fi

echo "Generating self-signed SSL certificate for $DOMAIN..."

# Generate private key and certificate
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$SSL_DIR/server.key" \
    -out "$SSL_DIR/server.crt" \
    -subj "/C=FR/ST=France/L=Paris/O=Episciences/OU=Dev/CN=$DOMAIN"

# Set appropriate permissions
chmod 600 "$SSL_DIR/server.key"
chmod 644 "$SSL_DIR/server.crt"

echo "SSL certificates generated successfully!"
echo "Certificate: $SSL_DIR/server.crt"
echo "Private key: $SSL_DIR/server.key"
echo ""
echo "Note: This is a self-signed certificate for development purposes only."
echo "Your browser will show a security warning. You can safely proceed."