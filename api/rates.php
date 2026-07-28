<?php
/**
 * 汇率接口（服务端缓存代理，1 外币 = ? CNY）
 *   GET /api/rates.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/rates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
header('X-Content-Type-Options: nosniff');

$data  = get_rates();
$rates = [];
foreach ($data['rates'] as $code => $rate) {
    $rates[$code] = round($rate, 6);
}

echo json_encode([
    'base'   => 'CNY',
    'date'   => $data['date'],
    'source' => $data['source'],
    'rates'  => $rates,
], JSON_UNESCAPED_UNICODE);
