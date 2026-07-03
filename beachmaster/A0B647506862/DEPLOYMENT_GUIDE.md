# Guida Deposito e Distribuzione - BeachMaster Beach Volley

## Sistema FTP/SFTP per Deposito Pacchetto

### Accesso
- Pannello Admin → Tab "Strumenti" → Sezione "📤 Deposita su Server Remoto (FTP/SFTP)"

### Tipi di Connessione Supportati

#### FTP (File Transfer Protocol)
- **Porta default**: 21
- **Requisiti**: Funzione PHP `ftp_*` abilitate
- **Uso**: Server tradizionali, hosting condiviso
- **Vantaggi**: Ampiamente supportato
- **Svantaggi**: Non crittografato

#### SFTP (SSH File Transfer Protocol)
- **Porta default**: 22
- **Requisiti**: Estensione PHP `ssh2` installata
- **Uso**: Server moderni, VPS, cloud
- **Vantaggi**: Crittografato e sicuro
- **Svantaggi**: Richiede configurazione aggiuntiva

### Procedura di Deposito

#### 1. Testa la Connessione
```
1. Seleziona FTP o SFTP
2. Inserisci parametri:
   - Host: indirizzo server (es: ftp.example.com)
   - Porta: default FTP=21, SFTP=22
   - Username: utente FTP/SSH
   - Password: password
   - Percorso remoto: cartella destinazione (es: /public_html/updates)
3. Clicca "🔌 Testa Connessione"
4. Se OK → procedere al deposito
```

#### 2. Deposita il Pacchetto
```
1. Clicca "📤 Deposita su Server"
2. Conferma il deposito (doppia verifica)
3. Sistema:
   - Crea ZIP programma completo
   - Si connette al server remoto
   - Fa l'upload del pacchetto
   - Mostra percorso remoto file
4. Pacchetto pronto per distribuzione
```

### Utilizzo per la Distribuzione

Dopo il deposito via FTP/SFTP, puoi:

1. **GitHub Releases** (consigliato)
   - Download ZIP da server remoto
   - Upload su GitHub as release asset
   - Distribuisci link pubblico

2. **Sito Web Ufficiale**
   - File rimane su server remoto
   - Link diretto per download
   - Pagina istruzioni di installazione

3. **Cloud Storage** (Google Drive, Dropbox, etc.)
   - Download dal server remoto
   - Upload su cloud
   - Condividi link pubblico

### Workflow Completo Distribuzione

```
Admin Panel (Versione 1.0.0)
    ↓
"Controlla Aggiornamenti" → Vede versione 1.1.0 disponibile
    ↓
"Scarica Programma Completo" → ZIP locale (backup)
    ↓
"Deposita su Server" → ZIP caricato su server remoto (FTP/SFTP)
    ↓
Aggiorna data/releases.json con URL download e versione 1.1.0
    ↓
Utenti vedono "Aggiornamento disponibile"
    ↓
Download ZIP (manualmente o link fornito)
    ↓
"Installa Aggiornamento" → Sistema fa merge + aggiornamento
    ↓
Versione aggiornata a 1.1.0 ✅
```

### Configurazione Server

#### FTP
```bash
# Su server Linux con vsftpd
# I file caricati appariranno nella directory specificata
# Assicurarsi che directory sia scrivibile
chmod 755 /percorso/cartella
```

#### SFTP
```bash
# Su server con SSH
# Assicurarsi che utente abbia accesso SFTP
# Installare php-ssh2 se necessario
sudo apt-get install php-ssh2
# O tramite PECL
pecl install ssh2
```

### Errori Comuni

| Errore | Causa | Soluzione |
|--------|-------|-----------|
| FTP extension non disponibile | PHP non ha modulo FTP | Usare SFTP o contattare provider |
| SSH2 extension non disponibile | PHP non ha modulo SSH2 | Installare php-ssh2 o usare FTP |
| Cannot connect | Host/porta sbagliati | Verificare parametri connessione |
| Login fallito | Credenziali sbagliate | Controllare username/password |
| Upload failed | Permessi directory | Verificare permessi cartella remota (755+) |

### Sicurezza

⚠️ **Raccomandazioni:**
- Usa SFTP quando possibile (connessione crittografata)
- Non salvare password in file di configurazione
- Usa account FTP dedicato con permessi limitati
- Cambia password dopo il primo uso
- Monitora accessi FTP/SFTP nei log server

### API Endpoints Disponibili

```php
// Test connessione FTP
POST /api.php?action=admin_test_ftp_connection
{
  "ftpHost": "ftp.example.com",
  "ftpPort": 21,
  "ftpUsername": "user",
  "ftpPassword": "pass"
}

// Test connessione SFTP
POST /api.php?action=admin_test_sftp_connection
{
  "sftpHost": "sftp.example.com",
  "sftpPort": 22,
  "sftpUsername": "user",
  "sftpPassword": "pass"
}

// Upload via FTP
POST /api.php?action=admin_upload_program_to_ftp
{
  "ftpHost": "ftp.example.com",
  "ftpPort": 21,
  "ftpUsername": "user",
  "ftpPassword": "pass",
  "ftpRemotePath": "/public_html/updates"
}

// Upload via SFTP
POST /api.php?action=admin_upload_program_to_sftp
{
  "sftpHost": "sftp.example.com",
  "sftpPort": 22,
  "sftpUsername": "user",
  "sftpPassword": "pass",
  "sftpRemotePath": "/home/user/updates"
}
```
