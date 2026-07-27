<?php
/**
 * BOC (Bank of Ceylon) Exchange Rate Scraper
 * Fetches and caches currency exchange rates from boc.lk/rates-tariff
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cacheFile   = __DIR__ . '/cache_rates.json';
$cacheTime   = 10800; // 1 hour
$debug       = isset($_GET['debug']) && $_GET['debug'] == '1'; // ?debug=1 to bypass cache & show raw counts

// ---------------------------------------------------------
// 1. Serve from cache if valid
// ---------------------------------------------------------
if (!$debug && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    echo file_get_contents($cacheFile);
    exit;
}

// ---------------------------------------------------------
// 2. Fetch page HTML
// ---------------------------------------------------------
$targetUrl = 'https://www.boc.lk/rates-tariff';
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$html      = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || empty($html)) {
    http_response_code(502);
    echo json_encode([
        'status'    => 'error',
        'message'   => 'Unable to fetch rates from BOC source.',
        'http_code' => $httpCode,
        'curl_error'=> $curlError ?: null,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Quick sanity check: bot-protection / captcha pages rarely contain this phrase
if (stripos($html, 'Exchange Rate') === false && stripos($html, 'rates-tariff') === false) {
    http_response_code(502);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Fetched page does not look like the expected rates page (possible bot protection / redirect).',
    ], JSON_PRETTY_PRINT);
    exit;
}

// ---------------------------------------------------------
// 3. Parse HTML DOM
// ---------------------------------------------------------
libxml_use_internal_errors(true);
$dom = new DOMDocument();

// Force UTF-8 interpretation to avoid mangled characters
$dom->loadHTML(
    '<?xml encoding="UTF-8">' . $html,
    LIBXML_NOERROR | LIBXML_NOWARNING
);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

$rows  = $xpath->query('//table[contains(@class, "light-table")]//tr');

// Fallback in case the class name ever changes: grab every table row
if ($rows->length === 0) {
    $rows = $xpath->query('//table//tr');
}

$rates = [];

foreach ($rows as $row) {
    $cols = $xpath->query('td', $row);

    // The real exchange-rate rows have exactly 7 <td>s:
    // [currency flag/img + code text]  Buying  Selling  Buying  Selling  Buying  Selling
    if ($cols->length !== 7) {
        continue;
    }

    $firstCell = $cols->item(0);

    // --- Identify the currency ---------------------------------------
    // BOC renders the first cell as: <img alt="FULL NAME flag"> + the
    // 3-letter ISO code as plain trailing text (e.g. "AUD", "USD").
    // The code text is the reliable part - the img contributes nothing
    // to textContent, so trimming the cell's textContent gives us the
    // ISO code directly.
    $code = trim(preg_replace('/\s+/', ' ', $firstCell->textContent));

    $currencyLabel = '';
    $imgs = $xpath->query('.//img', $firstCell);
    if ($imgs->length > 0) {
        $img = $imgs->item(0);
        $currencyLabel = trim($img->getAttribute('alt'));
        if ($currencyLabel === '') {
            $currencyLabel = trim($img->getAttribute('title'));
        }
    }
    // Fall back to the code itself if there's no image/alt text at all
    if ($currencyLabel === '') {
        $currencyLabel = $code;
    }

    // Skip anything that doesn't look like a 3-letter currency code
    // (guards against stray header/footer rows that happen to have 7 <td>s)
    if (!preg_match('/^[A-Z]{3}$/', $code)) {
        continue;
    }

    // --- Parse the 6 rate values ---------------------------------------
    $vals = [];
    for ($i = 1; $i <= 6; $i++) {
        $raw = trim($cols->item($i)->textContent);
        $raw = str_replace([',', "\xC2\xA0"], ['', ''], $raw); // strip commas + nbsp
        $vals[] = ($raw === '' || $raw === '-') ? null : (float) $raw;
    }

    $rates[] = [
        'currency_label' => $currencyLabel,   // e.g. "AUSTRALIAN DOLLAR flag" (from img alt)
        'code'           => $code,            // e.g. "AUD"
        'notes' => [
            'buying'  => $vals[0],
            'selling' => $vals[1],
        ],
        'drafts' => [
            'buying'  => $vals[2],
            'selling' => $vals[3],
        ],
        'transfers' => [
            'buying'  => $vals[4],
            'selling' => $vals[5],
        ],
    ];
}

// ---------------------------------------------------------
// 4. Build response
// ---------------------------------------------------------
if (empty($rates)) {
    http_response_code(502);
    $response = [
        'status'  => 'error',
        'message' => 'Rates table structure did not match expected format (0 rows parsed). BOC may have changed their page markup.',
    ];

    if ($debug) {
        // Helpful diagnostics: how many tables/rows exist, and per-column-count breakdown
        $tables = $xpath->query('//table');
        $colCounts = [];
        foreach ($xpath->query('//table//tr') as $r) {
            $n = $xpath->query('td', $r)->length;
            $colCounts[$n] = ($colCounts[$n] ?? 0) + 1;
        }
        $response['debug'] = [
            'table_count'        => $tables->length,
            'total_rows'         => $rows->length,
            'row_column_counts'  => $colCounts, // e.g. {"7": 26, "0": 3, ...}
        ];
    }

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

$response = [
    'status'     => 'success',
    'bank'       => 'Bank of Ceylon (BOC)',
    'source'     => $targetUrl,
    'updated_at' => date('Y-m-d H:i:s'),
    'count'      => count($rates),
    'data'       => $rates,
];

$jsonOutput = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------
// 5. Cache & output
// ---------------------------------------------------------
file_put_contents($cacheFile, $jsonOutput);
echo $jsonOutput;