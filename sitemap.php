<?php
/**
 * 🆕 Sitemap XML dinamica per il sistema multi-tenant: elenca automaticamente
 * TUTTI i tornei creati (con il rispettivo slug, non il codice interno),
 * leggendo il registro centrale data/tournaments.json — nessun aggiornamento
 * manuale necessario quando viene creato un nuovo torneo, compare qui da solo.
 *
 * Va messa nella stessa cartella di questo file: .htaccess, slug-router.php
 * e data/tournaments.json (la radice multi-tenant, es. /projects/bm/).
 */

header('Content-Type: application/xml; charset=utf-8');

// Base URL pubblica di questa installazione, dedotta automaticamente dalla
// posizione reale di questo script — cosi' funziona ovunque sia installato
// senza doverla scrivere a mano.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? '';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl = $scheme . $host . $basePath;

function readJsonFileSafe(string $path, array $default): array {
    if (!file_exists($path)) return $default;
    $raw = @file_get_contents($path);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : $default;
}

$registry = readJsonFileSafe(__DIR__ . '/data/tournaments.json', ['tournaments' => []]);
$tournaments = $registry['tournaments'] ?? [];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// La pagina di creazione tornei stessa (radice multi-tenant)
echo "  <url>\n";
echo '    <loc>' . htmlspecialchars($baseUrl . '/index.html') . "</loc>\n";
echo "    <changefreq>weekly</changefreq>\n";
echo "    <priority>0.8</priority>\n";
echo "  </url>\n";

foreach ($tournaments as $t) {
    $slug = trim((string)($t['slug'] ?? ''));
    $code = trim((string)($t['code'] ?? ''));
    // Usa lo slug se presente (URL "belle" da indicizzare), altrimenti
    // ripiega sul codice interno — meglio comparire con quello che con niente.
    $identifier = $slug !== '' ? $slug : $code;
    if ($identifier === '') continue;

    $createdAt = $t['createdAt'] ?? null;
    $lastmod = null;
    if ($createdAt) {
        $ts = strtotime((string)$createdAt);
        if ($ts !== false) $lastmod = date('Y-m-d', $ts);
    }

    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($baseUrl . '/' . rawurlencode($identifier) . '/') . "</loc>\n";
    if ($lastmod) {
        echo '    <lastmod>' . htmlspecialchars($lastmod) . "</lastmod>\n";
    }
    echo "    <changefreq>daily</changefreq>\n";
    echo "    <priority>0.9</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
