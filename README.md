# BusinesSearch

## Motor de Búsqueda de Sitios con PHP y MySQL (Versión FULLTEXT)

Este proyecto implementa una aplicación web que funciona como un motor de búsqueda para páginas HTML públicas. El usuario define una lista de URLs que serán rastreadas (nivel 0) junto con las páginas enlazadas directamente desde ellas (nivel 1). Cada documento válido se limpia, se tokeniza y se almacena en una tabla FULLTEXT para realizar búsquedas booleanas avanzadas.

Esta versión ha sido **refactorizada para utilizar las capacidades nativas de MySQL FULLTEXT** y para integrar un crawler que automatiza la recolección de contenido web, evitando depender de archivos subidos manualmente.

## Funcionamiento

El sistema se divide en dos procesos principales: **Indexación** y **Búsqueda**, ambos optimizados con `MySQL FULLTEXT`.

### 1. Indexación con crawler

Cuando un usuario necesita poblar o refrescar el índice:

1.  **Gestión de URLs**: En `index.php` se encuentra un formulario con una `textarea` para escribir la lista de URLs raíz (una por línea). La lista se guarda automáticamente en `data/url_seeds.txt` y se muestra cada vez que se visita la página.
2.  **Ejecución del crawler**: Desde el mismo formulario se puede lanzar el crawler (`crawler.php`). Este módulo lee las URLs raíz, visita cada una (nivel 0) y extrae los enlaces directos para visitar también el nivel 1.
3.  **Filtrado y limpieza**: Solo se conservan respuestas `text/html`. Antes de almacenarlas se remueven etiquetas `<script>`, se eliminan signos de puntuación, se convierte a minúsculas, se filtran *stopwords* según el idioma detectado (es/en), se singularizan los tokens y se generan cadenas listas para indexar.
4.  **Indexación condicional**: El crawler calcula `sha256` del HTML y usa cabeceras `ETag`/`Last-Modified` para saltar páginas sin cambios. El resultado final se guarda mediante `upsert_document()` (`indexer.php`) en la tabla `documents`, que cuenta con un índice FULLTEXT sobre la columna `full_content`.

### 2. Búsqueda

Cuando un usuario realiza una consulta:

1.  **Análisis de la Consulta**: El `parser.php` analiza la cadena de búsqueda, reconociendo operadores (`AND`, `OR`, `NOT`) y funciones especiales como `CADENA("frase exacta")` y `PATRON(patrón)`.
2.  **Traducción y Búsqueda**: El `search_engine.php` traduce la consulta del usuario a la sintaxis `MATCH...AGAINST` en modo booleano. Luego, ejecuta una única consulta `SELECT` en la base de datos.
3.  **Ranking y Presentación**: MySQL se encarga de encontrar los documentos, calcular una puntuación de relevancia (`score`) y devolver los resultados ya ordenados. La aplicación simplemente los muestra al usuario.

## Guía de Uso

### 1. Configuración Inicial

1.  **Servidor**: Asegurarse de tener un entorno tipo XAMPP con Apache y MySQL.
2.  **Base de Datos**:
    *   Importar el script `search_engine_db.sql`. Creará la base `search_engine_db_bs` y la tabla `documents` con columnas para `source_url`, `parent_url`, `content_hash`, `etag`, etc.
3.  **Conexión**: Verificar que las credenciales en `db_connection.php` correspondan al entorno local.
4.  **Directorio `data/`**: El formulario de URLs usa `data/url_seeds.txt`. Garantiza permisos de escritura sobre esa carpeta para que el listado se mantenga persistente.

### 2. Probar la Indexación

1.  **Configurar URLs**: Ingresa a `index.php`, pega varias URLs (una por línea) y presiona “Guardar lista”.
2.  **Lanzar el crawler**: Presiona “Guardar y ejecutar crawler”. Al finalizar verás cuántas páginas se procesaron, indizaron o se omitieron por no ser HTML/por no cambiar.
3.  **Verificar la base**: Usa phpMyAdmin para revisar que `documents` contiene el contenido tokenizado en `full_content` y la metadata (`content_hash`, `last_crawled_at`, etc.).
4.  **Re-indexar**: Vuelve a ejecutar el crawler cuando quieras. Solo las páginas con cambios se vuelven a almacenar; el resto se marca como revisada mediante `touch_document()`.

### 3. Probar la Búsqueda y Relevancia

1.  **Búsqueda Simple**: Buscar un término que exista en los documentos cargados.
2.  **Búsqueda Booleana**: Probar combinaciones como `termino1 AND termino2`, `termino1 OR termino2` y `termino1 AND NOT termino2`.
3.  **Funciones Especiales**: Probar `CADENA("una frase exacta de tus documentos")` y `PATRON(parte_de_una_palabra)`.
4.  **Prueba de Relevancia**: El algoritmo de MySQL es una variante de TF-IDF. Un documento donde el término de búsqueda es más "raro" o importante en el contexto del documento recibirá una puntuación más alta.

## Comparativa de Versiones: Manual vs. MySQL FULLTEXT

Esta aplicación ha evolucionado desde una implementación manual de un índice invertido a una que utiliza el motor `FULLTEXT` de MySQL.

### Versión Anterior (Manual)

*   **Indexación**: PHP leía archivos `.txt` subidos por el usuario, los normalizaba y calculaba frecuencias para mantener un índice invertido en `terms` y `postings`.
*   **Búsqueda**: Se aplicaba el algoritmo Shunting-yard para evaluar expresiones booleanas y se calculaba la relevancia con TF-IDF + Similitud del Coseno directamente en PHP.

### Versión Actual (Crawler + MySQL FULLTEXT)

*   **Indexación**: El crawler obtiene HTML real, lo filtra, detecta idioma, singulariza tokens y almacena el resultado en `documents.full_content`. El módulo `indexer.php` guarda metadata (hash, `etag`, `last_modified_header`, `parent_url`, `last_crawled_at`) para evitar reprocesar páginas sin cambios.
*   **Búsqueda**: `parser.php` convierte la consulta a tokens y `search_engine.php` construye la sentencia `MATCH...AGAINST`, delegando ranking y puntuación enteramente a MySQL.

### Ventajas de la Versión Actual (MySQL FULLTEXT)

1.  **Rendimiento Superior**: El motor `FULLTEXT` de MySQL está escrito en C/C++ y es órdenes de magnitud más rápido que procesar la lógica en PHP.
2.  **Simplicidad y Mantenibilidad**: Se eliminaron cientos de líneas de código PHP complejo, reduciendo la probabilidad de errores y facilitando el mantenimiento.
3.  **Escalabilidad**: MySQL está diseñado para manejar grandes volúmenes de datos. Esta versión escala mucho mejor a medida que aumenta el número de documentos.
4.  **Funcionalidades Avanzadas**: Se obtiene acceso a características nativas de MySQL como *stemming* (lematización) y listas de *stopwords* optimizadas, que mejoran la calidad de la búsqueda sin esfuerzo adicional.

### Desventajas de la Versión Actual

1.  **Menor Control ("Caja Negra")**: Se pierde el control granular sobre el algoritmo de ranking. No podemos modificar la fórmula de relevancia de MySQL ni implementar algoritmos personalizados como la Similitud del Coseno de la misma manera.
2.  **Dependencia de la Configuración de MySQL**: El comportamiento de la búsqueda (ej. longitud mínima de palabra a indexar) depende de la configuración del servidor MySQL, no solo del código PHP.

En resumen, la versión anterior fue un excelente ejercicio académico para entender los fundamentos de los motores de búsqueda, pero la **versión actual con `MySQL FULLTEXT` es una solución mucho más profesional, eficiente y robusta** para una aplicación real.

## Interfaz y Ejemplos de uso

*Ejemplo de relevancia*
![Interfaz Principal](pictures/Ejemplo-relevancia.jpeg)

*Ejemplo de operador CADENA*
![Resultados de Búsqueda](pictures/Ejemplo-CADENA.jpeg)

*Ejemplo de operador AND*
![Proceso de Indexación](pictures/Ejemplo-AND.jpeg)

*Ejemplo de operador AND NOT*
![Resultados de Búsqueda](pictures/Ejemplo-ANDNOT.jpeg)

*Ejemplo de operador OR*
![Proceso de Indexación](pictures/Ejemplo-OR.jpeg)