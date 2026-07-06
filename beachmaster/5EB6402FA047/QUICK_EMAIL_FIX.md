# 🚀 GUIDA RAPIDA: Risolvi Bug Email in 3 Passi

## ✅ Il Problema è Risolto!

Il sistema ora usa **PHPMailer con SMTP** invece della vecchia funzione `mail()` inaffidabile.

---

## 3️⃣ Passaggi per Attivare Email Affidabile

### Passo 1: Installa PHPMailer (1 minuto)
```bash
cd /workspaces/chiringuitobeachvolley
composer install
```

### Passo 2: Configura Email (2 minuti)

#### Opzione A: Gmail (Consigliato)
1. Vai a: https://myaccount.google.com/apppasswords
2. Genera una App Password (16 caratteri)
3. Apri: `config/email-config.php`
4. Modifica:
   ```php
   'enabled' => true,
   'gmail' => [
       'username' => 'tua-email@gmail.com',
       'password' => 'aaaa bbbb cccc dddd',  // ← Incolla qui
   ],
   ```

#### Opzione B: Altro Servizio
Vedi `EMAIL_SETUP_GUIDE.md` per SendGrid, Mailtrap, o server custom.

### Passo 3: Testa (30 secondi)
```bash
php scripts/verify-email-config.php
```

Se tutto è verde ✅:
- Vai nel Pannello Admin
- Impostazioni → Email del gestore → Testa Email
- Controlla inbox (includi spam)

---

## 📊 Cosa Cambia per l'Utente?

### PRIMA (Bug):
```
"Email inviata!" ← Messaggio falso
(L'email non arriva mai)
```

### DOPO (Fisso):
```
"✅ Email inviata al gestore" ← Reale
L'email arriva veramente!
```

O se il server è offline:
```
"⏳ Email in coda - verifica tra poco"
(Sarà inviata quando il server è disponibile)
```

---

## 📁 File Principale

**Leggi:** `EMAIL_SETUP_GUIDE.md` per guida completa con troubleshooting

**Verifica:** `config/email-config.php` per le tue credenziali SMTP

---

## ❓ FAQ

**Q: Devo usare Gmail?**  
A: No, puoi usare qualsiasi servizio SMTP (SendGrid, Mailtrap, server custom). Vedi `EMAIL_SETUP_GUIDE.md`.

**Q: Funziona senza Composer?**  
A: Sì, ma usa il fallback `mail()` nativa PHP (meno affidabile). Con Composer ottieni PHPMailer (affidabile).

**Q: Le vecchie email "fallite" si recuperano?**  
A: Sì, usa: `php scripts/process-email-queue.php`

**Q: Perché email va in spam?**  
A: Configura SPF/DKIM/DMARC per il dominio, oppure aggiungi mittente ai contatti fidati.

---

## ✨ Fine!

Email è ora **affidabile e trasparente**. L'utente vede sempre la verità:
- ✅ Email inviata
- ⏳ Email in coda
- ❌ Email non può essere inviata (con motivo)

No più inganni "mail inviata ma non arriva"! 🎉
