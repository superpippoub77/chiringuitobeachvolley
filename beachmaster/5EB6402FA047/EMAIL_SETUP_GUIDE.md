# 📧 Guida Configurazione Email - BeachMaster

## Problema Risolto: "Mail Inviata ma non Arriva"

Il sistema ora usa **PHPMailer con SMTP affidabile** al posto della funzione `mail()` nativa di PHP, che era inaffidabile.

---

## ⚡ Installazione Veloce

### Passo 1: Installa PHPMailer via Composer

```bash
cd /workspaces/chiringuitobeachvolley
composer install
```

Se non hai Composer installato:
```bash
curl -sS https://getcomposer.org/installer | php
php composer.phar install
```

### Passo 2: Configura Email con Gmail (Consigliato)

1. **Attiva 2FA su Gmail**: https://myaccount.google.com/security
   
2. **Genera App Password**: https://myaccount.google.com/apppasswords
   - Seleziona "Mail" e "Windows Computer"
   - Copia la password generata (16 caratteri)

3. **Modifica `config/email-config.php`**:
   ```php
   return [
       'enabled' => true,  // ✅ METTI true
       'service' => 'gmail',
       
       'gmail' => [
           'host' => 'smtp.gmail.com',
           'port' => 587,
           'secure' => 'tls',
           'auth' => true,
           'username' => 'tua-email@gmail.com',      // ← MODIFICA
           'password' => 'aaaa bbbb cccc dddd',       // ← INCOLLA APP PASSWORD
       ],
       // ... resto della configurazione
   ];
   ```

4. **Test Email**: Vai nel pannello admin → Impostazioni → Email del gestore → Testa Email

---

## 🔧 Configurazioni Alternative

### SendGrid
1. **Registrati**: https://sendgrid.com
2. **Genera API Key**: https://app.sendgrid.com/settings/api_keys
3. **Modifica config**:
   ```php
   'enabled' => true,
   'service' => 'sendgrid',
   'sendgrid' => [
       'host' => 'smtp.sendgrid.net',
       'port' => 587,
       'secure' => 'tls',
       'auth' => true,
       'username' => 'apikey',
       'password' => 'SG.your-api-key',  // ← INCOLLA KEY
   ],
   ```

### Mailtrap (Ambiente Test/Sviluppo)
1. **Registrati**: https://mailtrap.io
2. **Copia credenziali**: Dashboard → SMTP Settings
3. **Modifica config**:
   ```php
   'enabled' => true,
   'service' => 'mailtrap',
   'mailtrap' => [
       'host' => 'smtp.mailtrap.io',
       'port' => 2525,
       'secure' => 'tls',
       'auth' => true,
       'username' => 'your-username',
       'password' => 'your-password',
   ],
   ```

### Server SMTP Personalizzato
```php
'enabled' => true,
'service' => 'custom',
'custom' => [
    'host' => 'smtp.tuoserver.com',
    'port' => 587,
    'secure' => 'tls',  // 'tls', 'ssl', o ''
    'auth' => true,
    'username' => 'user@tuoserver.com',
    'password' => 'password',
],
```

---

## 🧪 Test Funzionalità

### 1. Test Email dal Pannello Admin
- Pannello Admin → Impostazioni → Email del gestore
- Clicca "🧪 Testa Email"
- Se OK: ✅ "Email inviata con successo"
- Se errore: ❌ Leggi il messaggio di errore

### 2. Controlla Log Email
```bash
# Root del progetto
cat data/email.log

# Dentro tournament (es: A0B647506862)
cat beachmaster/A0B647506862/data/email.log
```

Log di esempio:
```
2026-07-03 10:42:02 - SUCCESS (PHPMailer): Email inviata a admin@test.com (Subject: 📧 Test Email)
2026-07-03 10:42:15 - FAILED (PHPMailer): SMTP Error: Could not authenticate
2026-07-03 10:42:20 - QUEUED (mail() failed): Email messa in coda
```

### 3. Processa Coda Email
Se email è in coda (PHPMailer non disponibile), esegui:
```bash
php scripts/process-email-queue.php
```

---

## 📝 Feedback Utente Migliorato

### Prima (Bug):
```
✅ Squadra registrata con successo
📧 Email inviata!
```
❌ Ma l'email non arriva mai

### Dopo (Fisso):
```
✅ Squadra registrata con successo
📧 Email inviata al gestore
   (Se non arriva: Verifica email, controlla spam)
```

Oppure se email è in coda:
```
✅ Squadra registrata con successo
⏳ Email in coda - verifica tra poco
   (Il gestore riceverà la notifica quando il server sarà disponibile)
```

---

## 🐛 Troubleshooting

### Errore: "Class not found PHPMailer"
```bash
cd /workspaces/chiringuitobeachvolley
composer install
```

### Errore: "SMTP Error: Could not authenticate"
- ✅ Username e password corretti?
- ✅ Se Gmail: Usi App Password (non password normale)?
- ✅ 2FA è abilitato su Gmail?
- ✅ L'account Gmail consente app meno sicure?

### Errore: "SMTP Error: Could not connect"
- ✅ Host e porta corretti?
- ✅ Firewall blocca porte SMTP?
- ✅ Il server SMTP è online?

### Email va in spam
- ✅ Aggiungi mittente a contatti fidati
- ✅ Configura SPF/DKIM/DMARC per il dominio
- ✅ Usa un servizio SMTP affidabile (Gmail, SendGrid)

### Email non viene inviata, nemmeno in coda
- ✅ Controlla `data/email.log` per errori
- ✅ Email è abilitata in config? (`'enabled' => true`)
- ✅ Hai abilitato il debugging: `'debug' => true` per più dettagli

---

## 🔄 Sistema di Coda (Fallback)

Se il servizio SMTP non è disponibile, il sistema:
1. **Salva email in coda**: `data/email-queue/` (root) o `data/email-queue/` (tournament)
2. **Notifica utente**: "Email in coda - verifica tra poco"
3. **Retry automatico**: Puoi rielaborare con `process-email-queue.php`

Struttura file coda:
```json
{
  "timestamp": "2026-07-03 10:42:02",
  "to": "user@example.com",
  "subject": "Conferma Iscrizione",
  "body": "...",
  "from": "admin@tournament.com",
  "attempts": 0,
  "status": "pending"
}
```

---

## 📧 Dall'Email del Gestore

Nel pannello admin, specifica l'email del gestore in:
- Admin → Impostazioni → Email del gestore

Quando un utente si iscrive, il gestore riceverà notifica a questo indirizzo.

---

## ✅ Checklist Configurazione

- [ ] Installer Composer e PHPMailer: `composer install`
- [ ] Scegli servizio email (Gmail consigliato)
- [ ] Modifica `config/email-config.php` con credenziali
- [ ] Imposta `'enabled' => true`
- [ ] Test email dal pannello admin
- [ ] Verifica email ricevuta in inbox (non spam)
- [ ] Leggi log: `data/email.log` per confermare

---

## 🎯 Risultato Finale

✅ Email viene realmente inviata (non finta)
✅ Utente riceve notifica affidabile
✅ Log completo di tutti i tentativi
✅ Fallback automatico se SMTP non disponibile
✅ No più "mail inviata ma non arriva"

