<?php
// API JSON: obtiene automáticamente metadatos ICY de un stream
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$streamUrl = $_GET['url'] ?? '';
$response = [
    'success' => false,
    'title' => '',
    'error' => ''
];

if (empty($streamUrl)) {
    $response['error'] = 'Missing stream URL';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!filter_var($streamUrl, FILTER_VALIDATE_URL)) {
    $response['error'] = 'Invalid stream URL';
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$title = fetchStreamNowPlaying($streamUrl);
if ($title !== '') {
    $response['success'] = true;
    $response['title'] = $title;
} else {
    $response['error'] = 'No metadata available';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

/**
 * Conecta a un stream MP3 (Shoutcast / Icecast)
 * y devuelve el título de la canción actual
 */
function fetchStreamNowPlaying(string $streamUrl): string
{
    $icyMetaInterval = -1;
    $streamTitleKey = 'StreamTitle=';
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

    $context = stream_context_create([
        'http' => [
            'method'     => 'GET',
            'header'     => 'Icy-MetaData: 1',
            'user_agent' => $userAgent,
            'timeout'    => 5
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);

    $streamHandle = @fopen($streamUrl, 'r', false, $context);
    if (!$streamHandle) {
        return '';
    }

    foreach (stream_get_meta_data($streamHandle)['wrapper_data'] ?? [] as $headerLine) {
        if (stripos($headerLine, 'icy-metaint') !== false) {
            $icyMetaInterval = (int) trim(explode(':', $headerLine, 2)[1]);
            break;
        }
    }

    if ($icyMetaInterval > 0) {
        $metadataBuffer = stream_get_contents($streamHandle, 300, $icyMetaInterval);
        if (strpos($metadataBuffer, $streamTitleKey) !== false) {
            $rawTitle = explode($streamTitleKey, $metadataBuffer, 2)[1];
            $nowPlaying = substr(trim($rawTitle), 1, strpos($rawTitle, ';') - 2);
            $nowPlaying = preg_replace('/^(now\s+(on\s+air|playing)|on\s+air)\s*[:\-]\s*/i', '', $nowPlaying);
            if (!mb_check_encoding($nowPlaying, 'UTF-8')) {
                $nowPlaying = mb_convert_encoding($nowPlaying, 'UTF-8', 'ISO-8859-1');
            }
            fclose($streamHandle);
            return $nowPlaying;
        }
    }

    fclose($streamHandle);
    return '';
}
?>