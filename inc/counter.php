<?php
/**
 * 计算次数统计（文件存储 + flock，替代原版 Firebase）
 * 数据文件：data/counter.json  { "total": 123, "daily": { "20260729": 12 } }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** @return array{today:int,total:int} */
function counter_read(): array
{
    $data = counter_load();
    $key  = date('Ymd');
    return [
        'today' => (int)($data['daily'][$key] ?? 0),
        'total' => (int)($data['total'] ?? 0),
    ];
}

/** 今日 +1、累计 +1，返回最新计数 @return array{today:int,total:int} */
function counter_hit(): array
{
    $dir = dirname(COUNTER_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $fp = @fopen(COUNTER_FILE, 'c+');
    if ($fp === false) {
        return counter_read();
    }

    $result = ['today' => 0, 'total' => 0];
    if (flock($fp, LOCK_EX)) {
        $raw  = (string)stream_get_contents($fp);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
        $key   = date('Ymd');

        $daily[$key] = (int)($daily[$key] ?? 0) + 1;
        $total       = (int)($data['total'] ?? 0) + 1;

        // 清理过期的每日记录
        $limit = date('Ymd', strtotime('-' . COUNTER_KEEP_DAYS . ' days'));
        foreach (array_keys($daily) as $d) {
            if ((string)$d < $limit) {
                unset($daily[$d]);
            }
        }
        ksort($daily);

        $body = json_encode(['total' => $total, 'daily' => $daily], JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$body);
        fflush($fp);
        flock($fp, LOCK_UN);

        $result = ['today' => $daily[$key], 'total' => $total];
    }
    fclose($fp);

    return $result;
}

/** @return array<string,mixed> */
function counter_load(): array
{
    if (!is_file(COUNTER_FILE)) {
        return ['total' => 0, 'daily' => []];
    }
    $data = json_decode((string)@file_get_contents(COUNTER_FILE), true);
    return is_array($data) ? $data : ['total' => 0, 'daily' => []];
}
