<?php
// index.php - Versión final y corregida
/**
 * index.php
 *
 * Es el punto de entrada principal y la interfaz de usuario de la aplicación.
 * Muestra el formulario de búsqueda y el de subida de archivos.
 * Orquesta el proceso de búsqueda: recibe la consulta, la pasa al parser y al motor de búsqueda,
 * y finalmente muestra los resultados ordenados por relevancia.
 * @package    DocumentSearchEngine
 */
require_once 'db_connection.php';
require_once 'parser.php';
require_once 'utils.php'; // Aún necesario para el parser

require_once 'search_engine.php'; // Nuevo motor de búsqueda
require_once 'crawler.php';

$query_string = isset($_GET['q']) ? $_GET['q'] : '';
$results = [];
$error_message = '';
$debug_info = '';
$status_message = '';
$crawl_stats = null;
$url_list_content = get_url_list_contents();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url_list'])) {
    $submitted_list = trim($_POST['url_list']);
    save_url_list_contents($submitted_list);
    $url_list_content = $submitted_list;
    $action = $_POST['action'] ?? 'save_list';
    $status_message = 'La lista de URLs se guardó correctamente.';

    if ($action === 'run_crawler') {
        $crawl_stats = run_crawler_from_url_list($conn);
        if (!empty($crawl_stats['errors'])) {
            $status_message = 'El crawler terminó con incidencias.';
        } else {
            $status_message = 'Crawler ejecutado correctamente.';
        }
    }
}

if (!empty($query_string)) {
    try {
        // 1. Parsear la consulta a tokens
        $tokens = parse_query_to_tokens($query_string);

        // 2. Ejecutar la búsqueda con el motor FULLTEXT
        // Esta función ahora devuelve los resultados completos y ordenados
        $search_result = execute_search($tokens, $conn);

        // 3. Asignar resultados y depuración
        $results = $search_result['results'];
        $debug_info = $search_result['debug'];

    } catch (Exception $e) {
        $error_message = "Error al procesar la consulta: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Búsqueda de Documentos</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1>Buscador de Documentos</h1>
        <form action="index.php" method="get" class="search-form">
            <input type="text" name="q" class="search-input" placeholder="Escribe tu consulta aquí..." value="<?php echo htmlspecialchars($query_string); ?>">
            <button type="submit" class="search-button">Buscar</button>
        </form>

        <div class="url-manager">
            <h2>Definir URLs a rastrear</h2>
            <p>Escribe una URL por línea. Se rastreará la página y un nivel adicional de enlaces.</p>
            <form action="index.php" method="post" class="url-form">
                <label for="url_list">Lista de URLs (nivel 0):</label>
                <textarea name="url_list" id="url_list" rows="8" placeholder="https://ejemplo.com&#10;https://otro-ejemplo.com/articulo"><?php echo htmlspecialchars($url_list_content); ?></textarea>
                <div class="url-form__actions">
                    <button type="submit" name="action" value="save_list" class="secondary-button">Guardar lista</button>
                    <button type="submit" name="action" value="run_crawler" class="search-button">Guardar y ejecutar crawler</button>
                </div>
            </form>

            <?php if ($status_message): ?>
                <p class="status-message"><?php echo htmlspecialchars($status_message); ?></p>
            <?php endif; ?>

            <?php if ($crawl_stats): ?>
                <div class="crawl-report">
                    <p><strong>Páginas procesadas:</strong> <?php echo (int) $crawl_stats['processed']; ?></p>
                    <p><strong>Páginas indizadas:</strong> <?php echo (int) $crawl_stats['indexed']; ?></p>
                    <p><strong>Páginas omitidas (sin cambios/no HTML):</strong> <?php echo (int) $crawl_stats['skipped']; ?></p>
                    <?php if (!empty($crawl_stats['errors'])): ?>
                        <details>
                            <summary>Ver detalles de errores (<?php echo count($crawl_stats['errors']); ?>)</summary>
                            <ul>
                                <?php foreach ($crawl_stats['errors'] as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($debug_info): ?>
            <h3>Información de Depuración:</h3>
            <div class="debug-sql"><?php echo nl2br(htmlspecialchars($debug_info)); ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php elseif (!empty($query_string)): ?>
            <h2>Resultados de la Búsqueda</h2>
            <?php if (!empty($results)): ?>
                <div class="results-list">
                    <?php foreach ($results as $doc): ?>
                        <div class="result-item">
                            <a href="<?php echo htmlspecialchars($doc['source_url']); ?>" class="result-title" target="_blank" rel="noopener noreferrer">
                                <?php echo htmlspecialchars($doc['source_url']); ?>
                            </a>
                            <p class="result-snippet"><?php echo htmlspecialchars($doc['snippet']); ?>...</p>
                            <div class="scores">
                                <span class="result-score">Relevancia: <?php echo number_format($doc['score'], 4); ?></span>
                                <?php if (!empty($doc['parent_url'])): ?>
                                    <span class="result-parent">Origen: <?php echo htmlspecialchars($doc['parent_url']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="no-results">No se encontraron resultados para tu consulta.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>