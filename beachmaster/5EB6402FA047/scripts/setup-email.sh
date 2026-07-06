#!/bin/bash
# Script di setup email per BeachMaster

echo "🚀 Setup Email System - BeachMaster"
echo "==================================="
echo ""

# Controlla se composer è installato
if ! command -v composer &> /dev/null; then
    echo "❌ Composer non trovato. Installo..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
fi

echo "📦 Installo PHPMailer..."
composer install

if [ $? -ne 0 ]; then
    echo "❌ Errore durante l'installazione di Composer"
    exit 1
fi

echo ""
echo "✅ PHPMailer installato con successo"
echo ""
echo "📝 Prossimi passi:"
echo "1. Modifica config/email-config.php con le tue credenziali SMTP"
echo "2. Imposta 'enabled' => true"
echo "3. Vai nel pannello admin e clicca 'Testa Email'"
echo ""
echo "📖 Documentazione: EMAIL_SETUP_GUIDE.md"
echo ""
