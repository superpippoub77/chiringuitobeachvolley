<?php
/**
 * Configurazione Email SMTP per BeachMaster
 * 
 * Questa configurazione permette l'invio affidabile di email tramite SMTP.
 * Modifica questi parametri per il tuo servizio email (Gmail, SendGrid, ecc.)
 */

return [
    // Abilita l'invio email via SMTP
    'enabled' => true,
    
    // Servizio SMTP da usare (gmail, sendgrid, mailtrap, custom)
    'service' => 'gmail',
    
    // Configurazioni specifiche per servizio
    'gmail' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,  // TLS
        'secure' => 'tls',
        'auth' => true,
        // IMPORTANTE: Usa una "App Password" di Gmail, non la password normale!
        // Attiva 2FA su Gmail e genera una App Password da https://myaccount.google.com/apppasswords
        'username' => 'your-email@gmail.com',
        'password' => 'your-app-password',
    ],
    
    'sendgrid' => [
        'host' => 'smtp.sendgrid.net',
        'port' => 587,
        'secure' => 'tls',
        'auth' => true,
        'username' => 'apikey',
        'password' => 'SG.your-api-key-here',
    ],
    
    'mailtrap' => [
        'host' => 'smtp.mailtrap.io',
        'port' => 2525,
        'secure' => 'tls',
        'auth' => true,
        'username' => 'your-mailtrap-username',
        'password' => 'your-mailtrap-password',
    ],
    
    // Configurazione custom per server SMTP generico
    'custom' => [
        'host' => '',
        'port' => 587,
        'secure' => 'tls',  // 'tls', 'ssl', o vuoto per nessuna crittografia
        'auth' => true,
        'username' => '',
        'password' => '',
    ],
    
    // Email mittente (sender)
    'from' => [
        'email' => 'noreply@beachmaster.local',
        'name' => 'BeachMaster Tournament',
    ],
    
    // Timeout per connessione SMTP (secondi)
    'timeout' => 30,
    
    // Abilita debug (vedi dettagli errori nel log)
    'debug' => false,
];
?>
