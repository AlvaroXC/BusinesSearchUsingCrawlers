<?php
// upload_handler.php
/**
 * upload_handler.php
 *
 * Procesa las subidas de archivos desde el formulario en `index.php`.
 * Valida los archivos, los mueve a la carpeta `uploads/` y luego invoca
 * al script `indexer.php` para que procese cada nuevo archivo y lo añada al índice.
 *
 * @package    DocumentSearchEngine
 */

// Este endpoint se mantiene por compatibilidad, pero la carga de archivos
// ha sido reemplazada por el flujo de rastreo de URLs.
header("Location: index.php");
exit();