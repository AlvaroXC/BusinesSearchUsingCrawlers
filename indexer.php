<?php
// indexer.php
/**
 * indexer.php
 *
 * Contiene funciones auxiliares para almacenar y actualizar documentos HTML
 * rastreados por el crawler. La información final se indexa mediante FULLTEXT
 * en la columna `full_content`.
 *
 * @package    DocumentSearchEngine
 */

/**
 * Obtiene un documento existente por URL.
 *
 * @param mysqli $conn
 * @param string $url
 * @return array|null
 */
function find_document_by_url($conn, $url) {
    $stmt = $conn->prepare("
        SELECT doc_id, content_hash, etag, last_modified_header
        FROM documents
        WHERE source_url = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $url);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $document;
}

/**
 * Inserta o actualiza un documento rastreado.
 *
 * @param mysqli $conn
 * @param array $payload
 * @return void
 */
function upsert_document($conn, array $payload) {
    $requiredKeys = ['source_url', 'parent_url', 'snippet', 'tokens', 'content_hash', 'etag', 'last_modified_header'];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException("Falta la llave requerida: {$key}");
        }
    }

    $existing = find_document_by_url($conn, $payload['source_url']);

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE documents
            SET parent_url = ?, snippet = ?, full_content = ?, content_hash = ?, etag = ?, last_modified_header = ?, last_crawled_at = NOW(), last_indexed_at = NOW()
            WHERE doc_id = ?
        ");
        $stmt->bind_param(
            'ssssssi',
            $payload['parent_url'],
            $payload['snippet'],
            $payload['tokens'],
            $payload['content_hash'],
            $payload['etag'],
            $payload['last_modified_header'],
            $existing['doc_id']
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO documents (source_url, parent_url, snippet, full_content, content_hash, etag, last_modified_header, last_crawled_at, last_indexed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param(
            'sssssss',
            $payload['source_url'],
            $payload['parent_url'],
            $payload['snippet'],
            $payload['tokens'],
            $payload['content_hash'],
            $payload['etag'],
            $payload['last_modified_header']
        );
    }

    $stmt->execute();
    $stmt->close();
}

/**
 * Marca un documento como revisado sin necesidad de reindexarlo.
 *
 * @param mysqli $conn
 * @param int $docId
 * @return void
 */
function touch_document($conn, $docId) {
    $stmt = $conn->prepare("UPDATE documents SET last_crawled_at = NOW() WHERE doc_id = ?");
    $stmt->bind_param('i', $docId);
    $stmt->execute();
    $stmt->close();
}

?>