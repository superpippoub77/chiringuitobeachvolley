<?php
/**
 * Router per raggiungere un torneo tramite il suo SLUG (es. "chiringuito-beach-volley-2026")
 * invece che tramite il codice alfanumerico generato dal sistema (es. "a1b2c3d4e5f6").
 *
 * Come funziona: l'.htaccess in questa stessa cartella intercetta qualunque
 * richiesta a un percorso che NON corrisponde a una cartella o file realmente
 * esistente (quindi non tocca in alcun modo l'accesso normale tramite codice,
 * che continua a funzionare esattamente come prima) e la inoltra qui. Questo
 * script cerca lo slug nel registro centrale multi-tenant (data/tournaments.json)
 * e, se lo trova, reindirizza al percorso reale (.../<codice>/...).
 *
 * Nota: è un REDIRECT (la barra degli indirizzi del browser cambia e mostra
 * il codice), non una riscrittura invisibile — l'applicazione usa ovunque
 * percorsi relativi (api.php, immagini, upload) pensati per essere serviti
 * dalla cartella del proprio codice, quindi mascherare l'URL rischierebbe di
 * rompere quei percorsi. Un redirect è il modo sicuro e affidabile per rendere
 * raggiungibile un torneo anche dal suo slug.
 */

$slug = trim((string)($_GET['slug'] ?? ''), '/');
$path = trim((string)($_GET['path'] ?? ''), '/');

// 🆕 Se non arriva via query string (RewriteRule con mod_rewrite), prova a
// derivarlo dalla URL originale richiesta così come la fornisce Apache
// quando questo script viene invocato tramite "ErrorDocument 404" — più
// compatibile, perché funziona anche quando mod_rewrite non è disponibile o
// non è consentito nel .htaccess di questa cartella.
if ($slug === '' && !empty($_SERVER['REDIRECT_URL'])) {
    $requestedPath = trim((string)$_SERVER['REDIRECT_URL'], '/');
    $scriptDirRel = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($scriptDirRel !== '' && strpos($requestedPath, $scriptDirRel) === 0) {
        $requestedPath = trim(substr($requestedPath, strlen($scriptDirRel)), '/');
    }
    $parts = explode('/', $requestedPath, 2);
    $slug = $parts[0] ?? '';
    $path = $parts[1] ?? '';
}

if ($slug === '') {
    // 🔧 FIX: NON usare http_response_code(404) qui. Con "ErrorDocument 404"
    // configurato su questa stessa cartella, un 404 emesso da QUESTO script
    // richiama di nuovo ErrorDocument, che rilancia questo stesso script,
    // all'infinito ("troppi redirect" / 404 finale quando Apache si ferma).
    // Un 200 con un messaggio semplice evita completamente il ciclo.
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Torneo non trovato.";
    exit;
}

// Normalizza lo slug richiesto nello stesso modo in cui viene generato/salvato
// (minuscolo, solo lettere/numeri/trattini) per un confronto affidabile.
function normalizeSlugForCompare(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9-]/', '', $s);
    return $s;
}

$registryFile = __DIR__ . '/data/tournaments.json';
$registry = ['tournaments' => []];
if (file_exists($registryFile)) {
    $raw = @file_get_contents($registryFile);
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $registry = $decoded;
    }
}

$wantedSlug = normalizeSlugForCompare($slug);
$matchedCode = null;

foreach ($registry['tournaments'] ?? [] as $t) {
    $tSlug = normalizeSlugForCompare((string)($t['slug'] ?? ''));
    if ($tSlug !== '' && $tSlug === $wantedSlug) {
        // Salta i tornei disabilitati: si comportano come se non esistessero
        if (!empty($t['disabled'])) {
            continue;
        }
        $matchedCode = $t['path'] ?? $t['code'] ?? null;
        break;
    }
}

if ($matchedCode === null) {
    // 🔧 Stesso motivo del blocco sopra: niente http_response_code(404) qui,
    // per non richiamare di nuovo ErrorDocument in un ciclo infinito.
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nessun torneo trovato per \"" . htmlspecialchars($slug) . "\".";
    exit;
}

// 🔧 FIX: prima costruiva il redirect come percorso assoluto dalla radice
// del DOMINIO (es. "/D812A86C16DE/"), ma l'app vive sotto una sottocartella
// (es. "/projects/bm/"), non alla radice del sito — il redirect finiva quindi
// nel posto sbagliato. Deriviamo il percorso base direttamente dalla
// posizione reale di QUESTO script (SCRIPT_NAME), così funziona
// automaticamente qualunque sia la sottocartella in cui è installato,
// invece di doverla indovinare/scrivere a mano.
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
// 🔧 FIX: reindirizza esplicitamente a "index.html" invece che a una cartella
// "nuda" (solo la barra finale). Se per qualche motivo Apache non risolve da
// solo l'index di quella cartella, genererebbe un altro 404 lì — che con
// ErrorDocument configurato richiamerebbe di nuovo QUESTO stesso script,
// creando un ciclo (esattamente il "troppi redirect" osservato). Puntare a
// un file esplicito ed esistente elimina questa ambiguità alla radice.
$destination = $scriptDir . '/' . rawurlencode($matchedCode) . '/' . ($path !== '' ? $path : 'index.html');
if (!empty($_SERVER['QUERY_STRING'])) {
    // Rimuovi i parametri slug/path che abbiamo aggiunto noi tramite la
    // regola di rewrite, mantenendo eventuali altri parametri originali
    // dell'URL (es. ?tab=settings) per non perderli nel redirect.
    parse_str($_SERVER['QUERY_STRING'], $qs);
    unset($qs['slug'], $qs['path']);
    if (!empty($qs)) {
        $destination .= '?' . http_build_query($qs);
    }
}

header('Location: ' . $destination, true, 302);
exit;
