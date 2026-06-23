<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('JSON_DIR', __DIR__ . '/json');
define('VS_CURRENCY', 'usd');
define('REFRESH_DAYS', 3);

if (!is_dir(JSON_DIR)) {
    mkdir(JSON_DIR, 0755, true);
}

$latestYear = null;
$latestDate = null;

foreach (glob(JSON_DIR . '/*.json') as $file) {

    $data = json_decode(file_get_contents($file), true);

    if (!is_array($data) || empty($data)) {
        continue;
    }

    $date = array_key_last($data);

    if ($latestDate === null || strtotime($date) > strtotime($latestDate)) {
        $latestDate = $date;
        $latestYear = basename($file, '.json');
    }
}

if ($latestDate === null) {
    die("No JSON data found.\n");
}

/*
 * Refresh last 3 dates
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

echo "Fetching:\n$url\n\n";

$json = file_get_contents($url);

if ($json === false) {
    die("Failed to download API data\n");
}

$response = json_decode($json, true);

if (!isset($response['prices'])) {
    die("Invalid API response\n");
}

$years = [];

/*
 * Load existing files
 */
foreach (glob(JSON_DIR . '/*.json') as $file) {

    $year = basename($file, '.json');

    $content = json_decode(file_get_contents($file), true);

    $years[$year] = is_array($content)
        ? $content
        : [];
}

/*
 * Update prices
 */
foreach ($response['prices'] as $row) {

    $timestamp = (int)($row[0] / 1000);
    $price     = round((float)$row[1], 2);

    $date = gmdate('Y-m-d', $timestamp);
    $year = substr($date, 0, 4);

    if (!isset($years[$year])) {
        $years[$year] = [];
    }

    /*
     * Overwrite existing entry if exists.
     * This keeps latest closing values.
     */
    $years[$year][$date] = $price;
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

echo "\nDone\n";