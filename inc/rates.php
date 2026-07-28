<?php
/**
 * 汇率模块
 * 数据源：https://latest.currency-api.pages.dev/v1/currencies/usd.json
 * 返回结构：1 外币 = ? CNY，即 rate[X] = cny_per_usd / X_per_usd
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * 获取汇率（带文件缓存 + 过期兜底）
 *
 * @return array{rates: array<string,float>, date: string, source: string}
 */
function get_rates(): array
{
    $cache = read_rate_cache();
    if ($cache !== null && (time() - (int)$cache['ts']) < RATE_TTL) {
        return ['rates' => $cache['rates'], 'date' => (string)$cache['date'], 'source' => 'cache'];
    }

    $fresh = fetch_remote_rates();
    if ($fresh !== null) {
        write_rate_cache($fresh);
        return ['rates' => $fresh['rates'], 'date' => $fresh['date'], 'source' => 'live'];
    }

    // 接口失败：优先使用过期缓存，其次使用兜底汇率
    if ($cache !== null) {
        return ['rates' => $cache['rates'], 'date' => (string)$cache['date'], 'source' => 'stale'];
    }

    return ['rates' => RATE_FALLBACK, 'date' => date('Y-m-d'), 'source' => 'fallback'];
}

/**
 * 请求远端接口并换算为对 CNY 的汇率
 *
 * @return array{rates: array<string,float>, date: string}|null
 */
function fetch_remote_rates(): ?array
{
    $raw = http_get(RATE_API, 5);
    if ($raw === null) {
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['usd']) || !is_array($json['usd'])) {
        return null;
    }

    $usd = $json['usd'];
    if (empty($usd['cny']) || !is_numeric($usd['cny'])) {
        return null;
    }
    $cnyPerUsd = (float)$usd['cny'];

    $codes = array_unique(array_merge(array_keys(CURRENCIES), RATE_GRID));
    $rates = [];
    foreach ($codes as $code) {
        if ($code === 'CNY') {
            $rates['CNY'] = 1.0;
            continue;
        }
        $key = strtolower($code);
        if (!isset($usd[$key]) || !is_numeric($usd[$key]) || (float)$usd[$key] <= 0) {
            // 单个币种缺失时使用兜底值，避免整表失效
            $rates[$code] = RATE_FALLBACK[$code] ?? 1.0;
            continue;
        }
        $rates[$code] = $cnyPerUsd / (float)$usd[$key];
    }

    return [
        'rates' => $rates,
        'date'  => isset($json['date']) ? (string)$json['date'] : date('Y-m-d'),
    ];
}

function http_get(string $url, int $timeout = 5): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_USERAGENT      => 'vps.ss-rate-bot/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'header'  => "User-Agent: vps.ss-rate-bot/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/** @return array{ts:int,date:string,rates:array<string,float>}|null */
function read_rate_cache(): ?array
{
    if (!is_file(RATE_CACHE)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents(RATE_CACHE), true);
    if (!is_array($data) || empty($data['rates']) || !is_array($data['rates'])) {
        return null;
    }
    $rates = [];
    foreach ($data['rates'] as $k => $v) {
        if (is_numeric($v)) {
            $rates[(string)$k] = (float)$v;
        }
    }
    if (!$rates) {
        return null;
    }
    return ['ts' => (int)($data['ts'] ?? 0), 'date' => (string)($data['date'] ?? ''), 'rates' => $rates];
}

/** @param array{rates:array<string,float>,date:string} $payload */
function write_rate_cache(array $payload): void
{
    $dir = dirname(RATE_CACHE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $body = json_encode([
        'ts'    => time(),
        'date'  => $payload['date'],
        'rates' => $payload['rates'],
    ], JSON_UNESCAPED_UNICODE);
    if ($body !== false) {
        @file_put_contents(RATE_CACHE, $body, LOCK_EX);
    }
}

/** 汇率显示：保留 3 位小数 */
function fmt_rate(float $rate): string
{
    return number_format($rate, RATE_DECIMALS, '.', '');
}
