<?php
/**
 * 请求校验工具
 */

declare(strict_types=1);

/** 校验 Origin / Referer 是否同源，阻止跨站刷量 / 跨站调用写接口 */
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
