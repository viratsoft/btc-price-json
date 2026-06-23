<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('JSON_DIR', __DIR__ . '/json');
define('VS_CURRENCY', 'usd');
define('REFRESH_DAYS', 3);

// Optional CoinGecko Demo API Key
define('COINGECKO_API_KEY', getenv('COINGECKO_API_KEY') ?: '');

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function httpGet(string $url, int $retries = 3): string
{
    for ($attempt = 1; $attempt <= $retries; $attempt++) {

        $headers = [
            'Accept: application/json',
            'User-Agent: btc-price-json/1.0'
        ];

        if (COINGECKO_API_KEY !== '') {
            $headers[] = 'x-cg-demo-api-key: ' . COINGECKO_API_KEY;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
        ]);

        $response = curl_exec($ch);

        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        echo "Attempt {$attempt}/{$retries} - HTTP {$httpCode}\n";

        if ($response !== false && $httpCode === 200) {
            return $response;
        }

        if ($curlError) {
            echo "cURL Error: {$curlError}\n";
        }

        if ($attempt < $retries) {
            sleep(5);
        }
    }

    fail("Failed to fetch API data");
}

if (!is_dir(JSON_DIR)) {
    mkdir(JSON_DIR, 0755, true);
}

$latestDate = null;

foreach (glob(JSON_DIR . '/*.json') as $file) {

    $data = json_decode(file_get_contents($file), true);

    if (!is_array($data) || empty($data)) {
        continue;
    }

    $date = array_key_last($data);

    if ($latestDate === null || strtotime($date) > strtotime($latestDate)) {
        $latestDate = $date;
    }
}

if ($latestDate === null) {
    fail("No JSON data found");
}

/*
 * Refresh last 3 dates
 *
 * Example:
 * Last stored = 2026-06-15
 * Fetch from  = 2026-06-13
 */
$from = strtotime($latestDate . ' -' . (REFRESH_DAYS - 1) . ' days');
$to   = time();

$url = sprintf(
    'https://api.coingecko.com/api/v3/coins/bitcoin/market_chart/range?vs_currency=%s&from=%d&to=%d',
    VS_CURRENCY,
    $from,
    $to
);

echo "Fetching:\n{$url}\n\n";

$json = httpGet($url);

$response = json_decode($json, true);

if (
    !is_array($response) ||
    !isset($response['prices']) ||
    !is_array($response['prices'])
) {
    fail("Invalid API response");
}

$years = [];

/*
 * Load existing JSON files
 */
foreach (glob(JSON_DIR . '/*.json') as $file) {

    $year = basename($file, '.json');

    $content = json_decode(file_get_contents($file), true);

    $years[$year] = is_array($content)
        ? $content
        : [];
}

$updatedCount = 0;

/*
 * Update prices
 */
foreach ($response['prices'] as $row) {

    if (!isset($row[0], $row[1])) {
        continue;
    }

    $timestamp = (int) floor($row[0] / 1000);
    $price     = round((float) $row[1], 2);

    $date = gmdate('Y-m-d', $timestamp);
    $year = substr($date, 0, 4);

    if (!isset($years[$year])) {
        $years[$year] = [];
    }

    $years[$year][$date] = $price;
    $updatedCount++;
}

/*
 * Save files
 */
ksort($years);

foreach ($years as $year => $data) {

    ksort($data);

    $file = JSON_DIR . '/' . $year . '.json';

    file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) . PHP_EOL
    );

    echo "Updated {$year}.json (" . count($data) . " entries)\n";
}

echo "\nRows processed: {$updatedCount}\n";
echo "Done\n";
