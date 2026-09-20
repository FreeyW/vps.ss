<?php
/**
 * 计数接口
 *   GET  /api/count.php            读取今日 / 累计次数
 *   POST /api/count.php            今日 +1、累计 +1（需同源）
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/counter.php';
require_once __DIR__ . '/../inc/request.php';

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
