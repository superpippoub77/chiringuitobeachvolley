# 🧪 Guida Diagnostica Email - BeachMaster

## Problema: Email Non Inviata (False Positive)

Sintomo: Il test email dice "✅ Email inviata con successo!" ma nessun email viene ricevuta.

## Soluzione: 3 Metodi di Diagnostica

### Metodo 1️⃣: Diagnostica nel Frontend (Admin Panel)

1. **Apri Admin Panel** → Vai a **"Email & Notifiche"** → Sezione "CONFIGURAZIONE SMTP"
2. **Clicca "🔍 Diagnostica"** - Mostra:
   - ✅/❌ PHPMailer installato
   - ✅/❌ Host/Username/Password presenti
   - ✅/❌ File log esiste
   - Valori attuali della configurazione

3. **Clicca "🧪 Testa Configurazione"** - Ora mostra:
   - ✅ Successo → Email inviata
   - ❌ Errore specifico SMTP con dettagli del fallimento

### Metodo 2️⃣: Diagnostica via CLI (Script PHP)

```bash
cd /workspaces/chiringuitobeachvolley
php scripts/test-email-smtp.php
```

**Output fornisce:**
- Verifica che PHPMailer sia installato
- Valida configurazione in config.json
- **Tenta connessione SMTP** (vero test di connettività)
- Prompt interattivo per email di test
- **Invia reale email di test** con feedback
- Legge ultimi 10 entry del log email

### Metodo 3️⃣: Leggi il Log Email Direttamente

```bash
tail -20 /workspaces/chiringuitobeachvolley/data/email.log
```

**Mostra:**
- Timestamp e dettagli di ogni tentativo email
- Errori SMTP specifici (es: "SMTP connect() failed")
- Se email è messa in coda (fallback)
- Eccezioni PHP

## 🔍 Possibili Errori e Soluzioni

### ❌ "SMTP connect() failed"
**Causa:** Host/Porta errati o server SMTP irraggiungibile
**Soluzione:**
- Verifica host (es: `smtp.gmail.com`)
- Verifica porta (587 per TLS, 465 per SSL)
- Prova connessione manuale: `telnet smtp.gmail.com 587`

### ❌ "SMTP ERROR: Username and password not accepted"
**Causa:** Credenziali errate
**Soluzione:**
- Verifica username e password in admin panel
- Per Gmail: usa App Password, non password account
- Copia/incolla da email per evitare spazi

### ❌ "SMTP ERROR: Could not authenticate"
**Causa:** Autenticazione SMTP fallita
**Soluzione:**
- Verifica che "Secure" sia `tls` o `ssl` (non `none`)
- Per Gmail: assicurati che "Less secure apps" sia abilitato OR usa 2FA + App Password

### ❌ "Email in coda" (fallback message)
**Causa:** SMTP non disponibile, ma sistema ha messo email in coda
**Soluzione:**
- Controlla che processore di coda sia attivo
- Verifica log per capire perché SMTP fallì

## 📋 Checklist Configurazione

```
☐ Email Enabled = ✅
☐ Host = smtp.TUOPROVIDER.com (non vuoto)
☐ Port = 587 o 465 (non vuoto)
☐ Username = tuoa@email.com (non vuoto)
☐ Password = ••••• (non vuoto)
☐ From Email = tuoa@email.com (corretto)
☐ From Name = BeachMaster (o tuo nome)
☐ Secure = tls o ssl (non vuoto)
```

Dopo ogni modifica: **Clicca "💾 Salva" prima di testare!**

## 🔧 Parametri Comuni

### Gmail SMTP
```
Host: smtp.gmail.com
Port: 587
Secure: tls
Username: tuamail@gmail.com
Password: NOTA - Usa App Password se hai 2FA abilitato
```

### Outlook/Hotmail
```
Host: smtp-mail.outlook.com
Port: 587
Secure: tls
Username: tuamail@outlook.com
Password: Tua password account
```

### Provider generico
```
Host: smtp.TUOPROVIDER.com
Port: 587 (o 25 se TLS non disponibile)
Secure: tls (o ssl)
Username: Vedi doc provider
Password: Vedi doc provider
```

## 📞 Debug Avanzato

Leggi errori SMTP completi nel log:

```bash
grep -i "error\|failed\|exception" /workspaces/chiringuitobeachvolley/data/email.log | tail -5
```

## ✅ Verifiche Rapide

1. **Configurazione salvata?**
   ```bash
   cat /workspaces/chiringuitobeachvolley/data/config.json | grep -A 10 '"email"'
   ```

2. **PHPMailer disponibile?**
   ```bash
   ls -la /workspaces/chiringuitobeachvolley/vendor/phpmailer/phpmailer/
   ```

3. **Ultime email nel log?**
   ```bash
   tail -5 /workspaces/chiringuitobeachvolley/data/email.log
   ```

## 🚀 Prossimi Passi

1. **Usa "🔍 Diagnostica"** per verificare lo stato attuale
2. **Clicca "🧪 Testa"** per inviare email di test
3. **Leggi l'errore** mostrato nel messaggio di errore (ora più dettagliato)
4. **Se necessario, usa CLI** per debug più profondo: `php scripts/test-email-smtp.php`
5. **Verifica il log** per storici dei tentativi

**Nota:** I messaggi di errore sono ora molto più dettagliati - leggi bene l'errore SMTP specifico che vedi, non solo "errore generico"!
