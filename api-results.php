<?php
$apiUrl = 'http://localhost/api/exchange-rates.php';

// ---------------------------------------------------------
// 1. Fetch JSON via cURL (more reliable than file_get_contents:
//    works even if allow_url_fopen is disabled, and lets us
//    inspect HTTP status / errors instead of failing silently)
// ---------------------------------------------------------
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);

$jsonResponse = curl_exec($ch);
$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError    = curl_error($ch);
curl_close($ch);

if ($jsonResponse === false) {
    die("cURL error while contacting API: " . htmlspecialchars($curlError));
}

if ($httpCode !== 200) {
    die("API returned HTTP {$httpCode}. Raw response:<br><pre>" . htmlspecialchars($jsonResponse) . "</pre>");
}

// ---------------------------------------------------------
// 2. Decode JSON
// ---------------------------------------------------------
$data = json_decode($jsonResponse, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die(
        "JSON decode failed: " . json_last_error_msg() . "<br>" .
        "Raw response (check for PHP warnings/notices leaking into the output):<br><pre>" .
        htmlspecialchars($jsonResponse) . "</pre>"
    );
}

// Uncomment to inspect the decoded structure directly:
// die('<pre>' . print_r($data, true) . '</pre>');

// ---------------------------------------------------------
// 3. Validate & loop
// ---------------------------------------------------------
if (is_array($data) && isset($data['status']) && $data['status'] === 'success') {

    if (empty($data['data'])) {
        echo "API responded successfully but returned 0 rates.";
    }

    foreach ($data['data'] as $rate) {
        // 'code' is only populated when we could confidently match a known
        // 3-letter ISO code; fall back to the raw label (e.g. from the flag
        // image's alt text) so nothing shows up blank.
        $currency = $rate['code'] ?? $rate['currency_label'] ?? 'N/A';
        $buying   = $rate['notes']['buying']  ?? null;
        $selling  = $rate['notes']['selling'] ?? null;

        $buyingDisplay  = $buying  !== null ? $buying  : '-';
        $sellingDisplay = $selling !== null ? $selling : '-';

        echo "Currency: {$currency} | Buy: {$buyingDisplay} | Sell: {$sellingDisplay}<br>";
    }

} else {
    $message = $data['message'] ?? 'Unknown error (unexpected response shape).';
    echo "Failed to retrieve rates: " . htmlspecialchars($message);
    echo "<br><pre>" . htmlspecialchars(print_r($data, true)) . "</pre>";
}