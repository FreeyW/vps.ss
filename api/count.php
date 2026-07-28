<?php
/**
 * 计数接口
 *   GET  /api/count.php            读取今日 / 累计次数
 *   POST /api/count.php            今日 +1、累计 +1（需同源）
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/counter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!same_origin_request()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    echo json_encode(counter_hit());
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

echo json_encode(counter_read());

/** 校验 Origin / Referer，阻止跨站刷量 */
function same_origin_request(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string)preg_replace('/:\d+$/', '', $host);   // 去掉端口
    if ($host === '') {
        return false;
    }
    $allowed = [$host, 'vps.ss', 'www.vps.ss'];

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $h = strtolower((string)parse_url((string)$_SERVER[$key], PHP_URL_HOST));
        if ($h !== '') {
            return in_array($h, $allowed, true);
        }
    }

    // 两个头都缺失时视为非浏览器请求，拒绝
    return false;
}
