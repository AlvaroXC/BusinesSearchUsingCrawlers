<?php
// crawler.php
/**
 * crawler.php
 *
 * Define el flujo para rastrear URLs, procesar contenido HTML
 * y almacenarlo en la base de datos para su indexación.
 */

require_once 'utils.php';
require_once 'indexer.php';

define('URL_LIST_FILE', __DIR__ . '/data/url_seeds.txt');
const MAX_LEVEL_DEPTH = 1;
const MAX_LINKS_PER_SEED = 25;

/**
 * Asegura la existencia del archivo con la lista de URLs.
 *
 * @return void
 */
function ensure_url_list_file() {
    $dir = dirname(URL_LIST_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!file_exists(URL_LIST_FILE)) {
        file_put_contents(URL_LIST_FILE, '');
    }
}

/**
 * Devuelve el contenido actual de la lista de URLs.
 *
 * @return string
 */
function get_url_list_contents() {
    ensure_url_list_file();
    return file_get_contents(URL_LIST_FILE);
}

/**
 * Persiste la lista de URLs definida por el usuario.
 *
 * @param string $rawList
 * @return void
 */
function save_url_list_contents($rawList) {
    ensure_url_list_file();
    file_put_contents(URL_LIST_FILE, trim($rawList));
}

/**
 * Ejecuta el crawler tomando como semillas las URLs del archivo.
 *
 * @param mysqli $conn
 * @return array
 */
function run_crawler_from_url_list($conn) {
    $list = get_url_list_contents();
    $seeds = array_values(array_filter(array_map('trim', explode("\n", $list)), function ($url) {
        return !empty($url);
    }));

    $stats = [
        'processed' => 0,
        'indexed' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    if (empty($seeds)) {
        $stats['errors'][] = 'La lista de URLs está vacía.';
        return $stats;
    }

    $visited = [];
    foreach ($seeds as $seedUrl) {
        $normalizedSeed = normalize_url($seedUrl);
        if (!$normalizedSeed) {
            $stats['errors'][] = "URL inválida: {$seedUrl}";
            continue;
        }
        crawl_url($normalizedSeed, null, 0, $conn, $visited, $stats);
    }

    return $stats;
}

/**
 * Rastrea una URL respetando la profundidad máxima permitida.
 *
 * @param string $url
 * @param string|null $parentUrl
 * @param int $level
 * @param mysqli $conn
 * @param array $visited
 * @param array $stats
 * @return void
 */
function crawl_url($url, $parentUrl, $level, $conn, &$visited, &$stats) {
    if ($level > MAX_LEVEL_DEPTH) {
        return;
    }

    if (isset($visited[$url])) {
        return;
    }
    $visited[$url] = true;

    $existing = find_document_by_url($conn, $url);
    $response = fetch_remote_page($url, $existing);

    if ($response['error']) {
        $stats['errors'][] = "Error al recuperar {$url}: {$response['error']}";
        return;
    }

    if ($response['status'] === 304) {
        if (!empty($existing['doc_id'])) {
            touch_document($conn, (int) $existing['doc_id']);
        }
        $stats['skipped']++;
        return;
    }

    if ($response['status'] !== 200) {
        $stats['errors'][] = "HTTP {$response['status']} en {$url}";
        return;
    }

    if (stripos($response['content_type'], 'text/html') === false) {
        $stats['skipped']++;
        return;
    }

    $processed = preprocess_html_document($response['body']);
    if (empty($processed['tokens'])) {
        $stats['errors'][] = "No se pudo extraer texto de {$url}";
        return;
    }

    $contentHash = hash('sha256', $response['body']);
    $stats['processed']++;

    if ($existing && hash_equals($existing['content_hash'], $contentHash)) {
        touch_document($conn, (int) $existing['doc_id']);
        $stats['skipped']++;
    } else {
        upsert_document($conn, [
            'source_url' => $url,
            'parent_url' => $parentUrl,
            'snippet' => $processed['snippet'],
            'tokens' => implode(' ', $processed['tokens']),
            'content_hash' => $contentHash,
            'etag' => $response['etag'],
            'last_modified_header' => $response['last_modified'],
        ]);
        $stats['indexed']++;
    }

    if ($level < MAX_LEVEL_DEPTH) {
        $links = extract_links_from_html($response['body'], $url);
        $counter = 0;
        foreach ($links as $link) {
            crawl_url($link, $url, $level + 1, $conn, $visited, $stats);
            $counter++;
            if ($counter >= MAX_LINKS_PER_SEED) {
                break;
            }
        }
    }
}

/**
 * Recupera el contenido remoto usando cURL con soporte para peticiones condicionales.
 *
 * @param string $url
 * @param array|null $existing
 * @return array
 */
function fetch_remote_page($url, $existing) {
    $headers = [
        'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
    ];

    if ($existing) {
        if (!empty($existing['etag'])) {
            $headers[] = 'If-None-Match: ' . $existing['etag'];
        }
        if (!empty($existing['last_modified_header'])) {
            $headers[] = 'If-Modified-Since: ' . $existing['last_modified_header'];
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'BusinessSearchCrawler/1.0 (+https://example.com)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'status' => null,
            'body' => null,
            'content_type' => '',
            'etag' => $existing['etag'] ?? null,
            'last_modified' => $existing['last_modified_header'] ?? null,
            'error' => $error,
        ];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    $headerString = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);

    $parsedHeaders = parse_headers($headerString);

    return [
        'status' => $status,
        'body' => $body,
        'content_type' => $contentType,
        'etag' => $parsedHeaders['etag'] ?? ($existing['etag'] ?? null),
        'last_modified' => $parsedHeaders['last-modified'] ?? ($existing['last_modified_header'] ?? null),
        'error' => null,
    ];
}

/**
 * Convierte la porción de cabeceras crudas en un arreglo asociativo.
 *
 * @param string $headerBlob
 * @return array
 */
function parse_headers($headerBlob) {
    $headers = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($headerBlob));
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        [$key, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($key))] = trim($value);
    }
    return $headers;
}

/**
 * Excluye scripts, limpia el HTML y genera tokens/snippet.
 *
 * @param string $html
 * @return array
 */
function preprocess_html_document($html) {
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $tokens = normalize_and_tokenize($text);
    $snippet = mb_substr($text, 0, 240, 'UTF-8');

    return [
        'tokens' => $tokens,
        'snippet' => $snippet,
    ];
}

/**
 * Obtiene hasta un nivel de enlaces a partir del HTML proporcionado.
 *
 * @param string $html
 * @param string $baseUrl
 * @return array
 */
function extract_links_from_html($html, $baseUrl) {
    $links = [];
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if ($dom->loadHTML($html)) {
        $anchorTags = $dom->getElementsByTagName('a');
        foreach ($anchorTags as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $resolved = resolve_url($baseUrl, $href);
            if ($resolved) {
                $links[$resolved] = true;
            }
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return array_keys($links);
}

/**
 * Resuelve URLs relativas en función de un origen absoluto.
 *
 * @param string $base
 * @param string $relative
 * @return string|null
 */
function resolve_url($base, $relative) {
    if (empty($relative) || stripos($relative, 'javascript:') === 0 || stripos($relative, 'mailto:') === 0 || $relative[0] === '#') {
        return null;
    }

    $relative = trim($relative);

    if (parse_url($relative, PHP_URL_SCHEME)) {
        return normalize_url($relative);
    }

    if (strpos($relative, '//') === 0) {
        $baseParts = parse_url($base);
        if (!$baseParts || empty($baseParts['scheme'])) {
            return null;
        }
        return normalize_url($baseParts['scheme'] . ':' . $relative);
    }

    $baseParts = parse_url($base);
    if (!$baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
        return null;
    }

    $basePath = $baseParts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $basePath);
    $query = '';

    if ($relative[0] === '/') {
        $path = $relative;
    } elseif ($relative[0] === '?') {
        $path = $basePath;
        $query = $relative;
    } else {
        $path = $dir . $relative;
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    $normalizedPath = '/' . implode('/', $segments);

    if ($query === '' && strpos($relative, '?') !== false) {
        $query = substr($relative, strpos($relative, '?'));
    }

    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    return normalize_url(sprintf('%s://%s%s%s%s', $baseParts['scheme'], $baseParts['host'], $port, $normalizedPath, $query));
}

/**
 * Normaliza una URL para reducir duplicados.
 *
 * @param string $url
 * @return string|null
 */
function normalize_url($url) {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }

    $parts = parse_url($url);
    if (!$parts || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    $path = $path === '' ? '/' : $path;
    if ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }

    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return "{$scheme}://{$host}{$port}{$path}{$query}";
}

?>

