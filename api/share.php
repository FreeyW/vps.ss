<?php
/**
 * 结果分享图接口（生成 SVG 并保存到 share/ 目录）
 *   POST /api/share.php   参数与分享链接一致（application/x-www-form-urlencoded），需同源
 *   返回 { ok, id, url, markdown, title, today, total }
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/rates.php';
require_once __DIR__ . '/../inc/calc.php';
require_once __DIR__ . '/../inc/counter.php';
require_once __DIR__ . '/../inc/request.php';
require_once __DIR__ . '/../inc/share.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

if (!same_origin_request()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$rateData = get_rates();
$rates    = $rateData['rates'];
$in       = parse_input($_POST, $rates);
$R        = calculate($in, $rates);

if (!$R['valid']) {
    http_response_code(422);
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid',
        'message' => $R['expired'] ? '该机器已在交易日前到期，无法生成分享图' : '请先填写续费金额与有效的日期',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$svg    = build_share_svg($in, $R, $rates, (string)$rateData['date']);
$stored = share_store_svg($svg);
if ($stored === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'storage', 'message' => '服务器暂时无法保存分享图，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$counts = counter_hit();
$title  = 'VPS 剩余价值 ¥' . number_format((float)$R['resCNY'], 2, '.', ',')
    . ' · 剩余 ' . number_format((float)$R['days'], $in['isDateTime'] ? 2 : 0, '.', ',') . ' 天';

echo json_encode([
    'ok'       => true,
    'id'       => $stored['id'],
    'url'      => $stored['url'],
    'title'    => $title,
    'markdown' => '![' . str_replace(['[', ']'], ['(', ')'], $title) . '](' . $stored['url'] . ')',
    'today'    => $counts['today'],
    'total'    => $counts['total'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
