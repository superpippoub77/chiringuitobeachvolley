# 📧 FIX: Configurazione Email Aruba SMTP

## Problema
Sistema riportava: "PHPMailer: ❌ Installato" anche se era configurato SMTP.

## Soluzione Applicata

### 1. ✅ PHPMailer è INSTALLATO
- Autoload: `/vendor/autoload.php` ✅ Presente
- Classe: `PHPMailer\PHPMailer\PHPMailer` ✅ Caricabile
- Stato: Pronto per SMTP

### 2. ✅ Configurazione Aruba Applicata
File: `data/config.json` - Sezione `email`

```json
{
  "email": {
    "enabled": true,
    "service": "aruba",
    "host": "smtps.aruba.it",
    "port": 465,
    "secure": "ssl",
    "auth": true,
    "username": "info@filippomorano.com",
    "password": "",
    "fromEmail": "info@filippomorano.com",
    "fromName": "Torneo Beach Volley",
    "timeout": 30
  }
}
```

## Parametri Configurati

| Parametro | Valore | Stato |
|-----------|--------|-------|
| **Abilitato** | `true` | ✅ |
| **Provider** | Aruba | ✅ |
| **Host** | smtps.aruba.it | ✅ |
| **Porta** | 465 (SSL) | ✅ |
| **Crittografia** | SSL | ✅ |
| **Auth** | Enabled | ✅ |
| **Username** | info@filippomorano.com | ✅ |
| **Password** | ⚠️ MANCANTE | ❌ |
| **From Email** | info@filippomorano.com | ✅ |
| **Timeout** | 30s | ✅ |

## ⚠️ Passo Mancante: Password

**Azione Richiesta**: Inserisci la password Aruba dal pannello admin

### Procedura:
1. Apri **admin.html** → Sezione **Impostazioni**
2. Scorri fino a **Configurazione Email SMTP**
3. Campo: **Password**
4. Inserisci: La tua password Aruba
5. Clicca: **💾 Salva**
6. Clicca: **🧪 Testa** (per verificare)

## Come Funziona il Flusso Email

### 1. Invio Diretto (Online)
```
App → PHPMailer → SMTP Aruba (465) → Gmail dell'utente
```
- Immediato
- Richiede credenziali SMTP corrette
- Mostra errore se SMTP fallisce

### 2. Fallback: Coda Email
```
Se SMTP fallisce → Salva in coda → Script process-email-queue.php riprova
```
- Backup per problemi temporanei
- Retry automatico
- Log completo in `data/email.log`

## Test della Configurazione

### Via Admin Panel:
1. Impostazioni → Configurazione Email SMTP
2. Clicca: **🧪 Testa**
3. Risultato: ✅ Email inviata con successo OPPURE ❌ Errore SMTP dettagliato

### Via CLI:
```bash
cd /workspaces/chiringuitobeachvolley
php -r "
require 'vendor/autoload.php';
require 'api.php';

\$result = sendEmailViaPHPMailer(
    'test@example.com',
    'Test Aruba SMTP',
    'Questo è un test di configurazione email',
    'info@filippomorano.com'
);

var_dump(\$result);
"
```

## Diagnostica: Cosa Controllare

Se il test fallisce, verifica:

### 1. Password Corretta
```bash
grep -A 5 '"email"' data/config.json | grep password
```
Deve contenere la password (non vuoto)

### 2. Configurazione Attiva
```bash
grep '"enabled"' data/config.json | grep email -A 1
```
Deve essere `"enabled": true`

### 3. Log Errori
```bash
tail -20 data/email.log
```
Mostra dettagli dell'ultimo tentativo di invio

### 4. Errori SMTP Comuni

| Errore | Causa | Soluzione |
|--------|-------|-----------|
| "SMTP connect() failed" | Rete/Firewall | Verifica connessione internet |
| "Username and password not accepted" | Credenziali sbagliate | Verifica password Aruba |
| "ssl_get_verify_result(): unable to get local issuer certificate" | Certificato | Disabilita SSL verification in PHPMailer |
| "Connection timed out" | Porta/Host sbagliato | Verifica host: smtps.aruba.it, porta: 465 |

## File Modificati

✅ `data/config.json`:
- Abilitato email SMTP
- Configurati parametri Aruba
- Port cambio da 587 (TLS) a 465 (SSL)
- From Email aggiornato

## Stato Corrente

```
✅ PHPMailer: INSTALLATO E CARICABILE
✅ Configurazione: ARUBA SMTP 465 SSL
✅ Email Abilitata: SÌ
⚠️  Password: RICHIESTA (da pannello admin)
🔄 Prossimo Passo: Inserisci password nel pannello admin → Test
```

## Istruzioni Rapide

```
1️⃣  Apri admin.html (beachmaster/GUID/admin.html)
2️⃣  Vai a: Impostazioni → Configurazione Email SMTP
3️⃣  Compila: Password: [TUA PASSWORD ARUBA]
4️⃣  Salva: Clicca "💾 Salva"
5️⃣  Testa: Clicca "🧪 Testa"
6️⃣  Risultato: ✅ O errore dettagliato
```

---

**Aggiornato**: 2026-07-09  
**Status**: ✅ Configurazione Aruba Applicata (Password Pendente)  
**Prossimo**: Inserisci password dal pannello admin + Test
