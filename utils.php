<?php
// utils.php
/**
 * utils.php
 *
 * Contiene funciones de utilidad compartidas por diferentes partes de la aplicación,
 * como la normalización, detección de idioma y tokenización de texto.
 *
 * @package    DocumentSearchEngine
 */

/**
 * Catálogo de stopwords soportados por idioma.
 *
 * @return array
 */
function get_stopwords_catalog() {
    return [
        'es' => [
            'de','la','que','el','en','y','a','los','del','se','las','por','un','para','con','no','una','su',
            'al','lo','como','mas','pero','sus','le','ya','o','este','ha','me','si','sin','sobre','es','son',
            'entre','cuando','muy','ya','nos','hasta','desde','todo','nosotros','usted','ellos','ellas','ser',
            'fue','era','tambien','tan','solo','donde'
        ],
        'en' => [
            'the','and','is','in','to','of','a','for','on','with','as','by','that','it','this','an','be','or',
            'are','from','at','was','were','but','not','have','has','had','you','your','their','they','we',
            'our','will','would','can','could','there','about','which','one','all'
        ],
    ];
}

/**
 * Obtiene las stopwords en forma de mapa para acelerarlas búsquedas.
 *
 * @param string $language
 * @return array
 */
function get_stopwords_map($language) {
    static $cache = [];
    $catalog = get_stopwords_catalog();
    if (!isset($catalog[$language])) {
        $language = 'es';
    }
    if (!isset($cache[$language])) {
        $cache[$language] = array_flip($catalog[$language]);
    }
    return $cache[$language];
}

/**
 * Detecta el idioma más probable (es/en) en función de la presencia de stopwords.
 *
 * @param array $tokens
 * @return string
 */
function detect_language_from_tokens(array $tokens) {
    $catalog = get_stopwords_catalog();
    $scores = ['es' => 0, 'en' => 0];
    foreach ($catalog as $lang => $stopwords) {
        $stopwordSet = array_flip($stopwords);
        foreach ($tokens as $token) {
            if (isset($stopwordSet[$token])) {
                $scores[$lang]++;
            }
        }
    }
    // Empate -> español por defecto
    return ($scores['en'] > $scores['es']) ? 'en' : 'es';
}

/**
 * Singulariza un token de acuerdo con reglas heurísticas básicas por idioma.
 *
 * @param string $token
 * @param string $language
 * @return string
 */
function singularize_token($token, $language) {
    $length = strlen($token);
    if ($length <= 3) {
        return $token;
    }

    if ($language === 'en') {
        if (preg_match('/(ies)$/', $token)) {
            return substr($token, 0, -3) . 'y';
        }
        if (preg_match('/(ves)$/', $token)) {
            return substr($token, 0, -3) . 'f';
        }
        if (preg_match('/(ses|xes|zes)$/', $token)) {
            return substr($token, 0, -2);
        }
        if (preg_match('/(s)$/', $token) && !preg_match('/(ss)$/', $token)) {
            return substr($token, 0, -1);
        }
        return $token;
    }

    // Reglas básicas para español
    if (preg_match('/(ces)$/', $token)) {
        return substr($token, 0, -3) . 'z';
    }
    if (preg_match('/(es)$/', $token) && $length > 4) {
        return substr($token, 0, -2);
    }
    if (preg_match('/(s)$/', $token) && !preg_match('/(as|es|is|os|us)$/', $token)) {
        return substr($token, 0, -1);
    }
    return $token;
}

/**
 * Procesa y normaliza el contenido de un texto para la indexación o consulta.
 * El flujo elimina scripts, puntuación, minúsculiza, filtra stopwords por idioma
 * y devuelve tokens singularizados.
 *
 * @param string $content El contenido del texto a procesar.
 * @return array Un array de tokens (palabras) normalizados.
 */
function normalize_and_tokenize($content) {
    if (empty($content)) {
        return [];
    }

    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = mb_strtolower($content, 'UTF-8');

    if (function_exists('transliterator_transliterate')) {
        $content = transliterator_transliterate('Any-Latin; Latin-ASCII', $content);
    } else {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $content);
        if ($converted !== false) {
            $content = $converted;
        }
    }

    $content = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $content);
    $content = preg_replace('/\s+/u', ' ', $content);

    $tokens = preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) {
        return [];
    }

    $language = detect_language_from_tokens($tokens);
    $stopwords = get_stopwords_map($language);

    $filtered = [];
    foreach ($tokens as $token) {
        if (!isset($stopwords[$token])) {
            $filtered[] = singularize_token($token, $language);
        }
    }

    return array_values(array_filter($filtered));
}
?>