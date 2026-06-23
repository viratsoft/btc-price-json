<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

define('JSON_DIR', __DIR__ . '/json');
define('VS_CURRENCY', 'usd');
define('REFRESH_DAYS', 3);
define('COINGECKO_API_KEY', getenv('COINGECKO_API_KEY') ?: '');

function fail(string $message): void
{
    fwrite(STDERR, "\n[ERROR] {$message}\n");
    exit(1);
}

function logLine(string $message): void
{
    echo $message . PHP_EOL;
}

function httpGet(string $url, int $retries = 3): array
{
    $lastHttpCode = 0;

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
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ]);

        $response = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        $lastHttpCode = $httpCode;

        logLine("HTTP Attempt {$attempt}/{$retries} => {$httpCode}");

        if ($response !== false && $httpCode === 200) {
            return [
                'http_code' => $httpCode,
                'body' => $response
            ];
        }

        if ($curlError) {
            logLine("cURL Error: {$curlError}");
        }

        if ($attempt < $retries) {
            sleep(5);
        }
    }

    fail("CoinGecko request failed. Last HTTP code: {$lastHttpCode}");
}

if (!is_dir(JSON_DIR)) {
    mkdir(JSON_DIR, 0755, true);
}

$years = [];
$latestDate = null;

foreach (glob(JSON_DIR . '/*.json') as $file) {

    $year = basename($file, '.json');

    $raw = file_get_contents($file);

    if ($raw === false) {
        fail("Unable to read {$file}");
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        fail(
            "Invalid JSON file: {$year}.json | " .
            json_last_error_msg()
        );
    }

    if (!is_array($data)) {
        fail("Invalid structure in {$year}.json");
    }

    foreach ($data as $date => $price) {
    
        $dt = DateTime::createFromFormat('Y-m-d', $date);
    
        if (
            !$dt ||
            $dt->format('Y-m-d') !== $date
        ) {
            fail("Invalid date key in {$year}.json : {$date}");
        }
    
        if (!is_numeric($price) || $price <= 0) {
            fail("Invalid price in {$year}.json : {$date}");
        }
    }
    
    ksort($data);

    $years[$year] = $data;

    if (!empty($data)) {

        $date = array_key_last($data);

        if (
            $latestDate === null ||
            strtotime($date) > strtotime($latestDate)
        ) {
            $latestDate = $date;
        }
    }
}

if ($latestDate === null) {
    fail("No valid JSON files found");
}

$fetchStartDate = date(
    'Y-m-d',
    strtotime(
        $latestDate . ' -' . (REFRESH_DAYS - 1) . ' days'
    )
);

$from = strtotime($fetchStartDate);
$to   = time();

$url = sprintf(
    'https://api.coingecko.com/api/v3/coins/bitcoin/market_chart/range?vs_currency=%s&from=%d&to=%d',
    VS_CURRENCY,
    $from,
    $to
);

logLine('');
logLine('========================================');
logLine('BTC PRICE UPDATE');
logLine('========================================');
logLine("Latest stored date : {$latestDate}");
logLine("Fetch start date   : {$fetchStartDate}");
logLine("Fetch end date     : " . gmdate('Y-m-d', $to));
logLine('');

$result = httpGet($url);

$httpCode = $result['http_code'];
$responseBody = $result['body'];

$response = json_decode($responseBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    fail(
        'API returned invalid JSON: ' .
        json_last_error_msg()
    );
}

if (
    !isset($response['prices']) ||
    !is_array($response['prices'])
) {
    fail('API response missing prices array');
}

$apiRows = count($response['prices']);

if ($apiRows === 0) {
    fail('CoinGecko returned zero price records');
}

$updatedFiles = [];
$newFiles = [];

$minUpdatedDate = null;
$maxUpdatedDate = null;

$dailyPrices = [];
$uniqueDates = [];

foreach ($response['prices'] as $row) {

    if (
        !is_array($row) ||
        count($row) < 2
    ) {
        continue;
    }

    if (
        !is_numeric($row[0]) ||
        !is_numeric($row[1])
    ) {
        continue;
    }

    $timestamp = (int) floor($row[0] / 1000);

    if ($timestamp < strtotime('2010-01-01')) {
        continue;
    }

    if ($timestamp > (time() + 86400)) {
        continue;
    }

    $price = round((float) $row[1], 2);

    if ($price <= 0) {
        continue;
    }

    $date = gmdate('Y-m-d', $timestamp);

    $dt = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    if (
        !$dt ||
        $dt->format('Y-m-d') !== $date
    ) {
        continue;
    }

   $uniqueDates[$date] = true;

   if (
       !isset($dailyPrices[$date]) ||
       $timestamp > $dailyPrices[$date]['timestamp']
   ) {
       $dailyPrices[$date] = [
           'timestamp' => $timestamp,
           'price'     => $price
       ];
   }
}

ksort($dailyPrices);

foreach ($dailyPrices as $date => $item) {

    $year = substr($date, 0, 4);

    if (!isset($years[$year])) {

        $years[$year] = [];

        $newFiles[$year] = true;

        logLine("New year file will be created: {$year}.json");
    }

    $years[$year][$date] = $item['price'];

    $updatedFiles[$year] = true;

    if (
        $minUpdatedDate === null ||
        strcmp($date, $minUpdatedDate) < 0
    ) {
        $minUpdatedDate = $date;
    }

    if (
        $maxUpdatedDate === null ||
        strcmp($date, $maxUpdatedDate) > 0
    ) {
        $maxUpdatedDate = $date;
    }
}

ksort($years);

foreach ($years as $year => $data) {

    ksort($data);

    $file = JSON_DIR . '/' . $year . '.json';

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        fail("Failed to encode {$year}.json");
    }

    if (
        file_put_contents(
            $file,
            $json . PHP_EOL
        ) === false
    ) {
        fail("Failed writing {$year}.json");
    }

   $verifyRaw = file_get_contents($file);

   if ($verifyRaw === false) {
       fail("Failed reading back {$year}.json");
   }

   $verify = json_decode($verifyRaw, true);

   if (json_last_error() !== JSON_ERROR_NONE) {
       fail(
           "Saved file validation failed: {$year}.json | " .
           json_last_error_msg()
       );
   }

   if (!is_array($verify)) {
       fail("Saved file structure invalid: {$year}.json");
   }

    logLine(
        "Saved {$year}.json (" .
        count($data) .
        " entries)"
    );
}

logLine('');
logLine('========================================');
logLine('BTC PRICE UPDATE SUMMARY');
logLine('========================================');
logLine("HTTP Status       : {$httpCode}");
logLine("API Rows Received : {$apiRows}");
logLine("Unique Days Found : " . count($uniqueDates));
logLine("Days Written      : " . count($dailyPrices));

if ($minUpdatedDate !== null) {
    logLine(
        "Date Range Update: {$minUpdatedDate} -> {$maxUpdatedDate}"
    );
}

logLine('');

logLine('Files Updated:');

if (empty($updatedFiles)) {
    logLine('  None');
} else {
    foreach (array_keys($updatedFiles) as $year) {
        logLine("  {$year}.json");
    }
}

logLine('');

logLine('New Files Created:');

if (empty($newFiles)) {
    logLine('  None');
} else {
    foreach (array_keys($newFiles) as $year) {
        logLine("  {$year}.json");
    }
}

logLine('');
logLine('Status: SUCCESS');
logLine('========================================');
