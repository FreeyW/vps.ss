<?php
/**
 * 分享图（SVG）生成与存储
 * 生成 1200×630 的结果卡片，保存到 share/ 目录，对外地址 https://vps.ss/share/xxxxxxxx.svg
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** XML 文本转义 */
function svg_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** 由内容哈希生成短 ID（同样的结果只会生成一份文件） */
function share_id(string $svg): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $hash     = hash('sha256', $svg, true);
    $id       = '';
    for ($i = 0; $i < SHARE_ID_LEN; $i++) {
        $id .= $alphabet[ord($hash[$i]) % 36];
    }
    return $id;
}

/** 校验分享 ID 格式 */
function share_id_valid(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9]{' . SHARE_ID_LEN . '}$/', $id);
}

/**
 * 保存 SVG 到 share/ 目录
 *
 * @return array{id:string,url:string,path:string}|null
 */
function share_store_svg(string $svg): ?array
{
    if (!is_dir(SHARE_DIR)) {
        @mkdir(SHARE_DIR, 0755, true);
    }
    if (!is_dir(SHARE_DIR) || !is_writable(SHARE_DIR)) {
        return null;
    }

    $id   = share_id($svg);
    $path = SHARE_DIR . '/' . $id . '.svg';
    if (!is_file($path)) {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $svg, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }
        @chmod($path, 0644);
    }

    return [
        'id'   => $id,
        'url'  => SITE_URL . SHARE_URL_PATH . '/' . $id . '.svg',
        'path' => $path,
    ];
}

/** 金额显示 */
function share_money(float $n, int $dec = 2): string
{
    return number_format($n, $dec, '.', ',');
}

/** 日期显示：2026-09-20 / 2026-09-20 08:30 */
function share_date(string $v): string
{
    return str_replace('T', ' ', $v);
}

/** 带符号金额：+¥ 12.00 / -¥ 3.50 */
function share_signed(float $n): string
{
    return ($n >= 0 ? '+' : '-') . '¥ ' . share_money(abs($n));
}

/**
 * 生成分享 SVG
 *
 * @param array<string,mixed> $in     parse_input() 结果
 * @param array<string,mixed> $R      calculate() 结果（valid 必须为 true）
 * @param array<string,float> $rates
 */
function build_share_svg(array $in, array $R, array $rates, string $rateDate): string
{
    $font = "'DM Sans', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', 'Noto Sans SC', sans-serif";
    $dec  = $in['isDateTime'] ? 2 : 0;

    $currency   = (string)$in['currency'];
    $cycleLabel = CYCLES[$in['cycleDays']] ?? ($in['cycleDays'] . ' 天');
    $days       = (float)$R['days'];
    $pct        = max(0.0, min(100.0, (float)$R['progressPct']));
    $resCNY     = (float)$R['resCNY'];
    $hasPaid    = $R['actualPaid'] !== null;

    // ---- 文案 ----
    $heroText = '¥' . share_money($resCNY);
    $heroSize = 82;
    $heroLen  = mb_strlen($heroText);
    if ($heroLen > 13) {
        $heroSize = 54;
    } elseif ($heroLen > 10) {
        $heroSize = 66;
    }

    $subParts = ['续费 ' . share_money((float)$in['amount']) . ' ' . $currency . ' · ' . $cycleLabel];
    if ($currency !== 'CNY') {
        $subParts[] = '1 ' . $currency . ' = ' . number_format((float)$R['rateUsed'], RATE_DECIMALS, '.', '') . ' CNY'
            . ($in['useCustomRate'] && $in['customRate'] !== null && $in['customRate'] > 0 ? '（自定义）' : '');
    }
    $subLine = implode(' · ', $subParts);
    $subSize = mb_strlen($subLine) > 44 ? 21 : 24;

    $barW    = 688;

    // 右侧统计
    $stats = [
        ['剩余天数', share_money($days, $dec) . ' 天', '#FFFFFF'],
        ['周期天数', (string)$in['cycleDays'] . ' 天', '#FFFFFF'],
    ];
    if ($hasPaid) {
        $prem    = (float)$R['premium'];
        $stats[] = ['实付卖家', '¥ ' . share_money((float)$R['actualPaid']), '#FFFFFF'];
        $stats[] = [$prem >= 0 ? '卖家溢价' : '卖家折价', share_signed($prem), $prem >= 0 ? '#FFD166' : '#C9F7EE'];
    }
    $n      = count($stats);
    $pitch  = $n <= 2 ? 150 : 96;
    $startY = $n <= 2 ? 205 : 160;
    $statsSvg = '';
    foreach ($stats as $i => [$label, $value, $color]) {
        $ly = $startY + $i * $pitch;
        $vy = $ly + 54;
        $vs = mb_strlen($value) > 9 ? 36 : 46;
        $statsSvg .= '<text x="888" y="' . $ly . '" font-size="24" opacity=".72">' . svg_esc($label) . '</text>'
            . '<text x="888" y="' . $vy . '" font-size="' . $vs . '" font-weight="800" fill="' . $color . '">' . svg_esc($value) . '</text>';
    }

    // 左下：买家总支出
    $totalSvg = '';
    if ($hasPaid) {
        $extra = (float)$R['extraFee'];
        $totalSvg = '<text x="104" y="500"><tspan font-size="22" opacity=".72">买家总支出 </tspan>'
            . '<tspan font-size="30" font-weight="800">¥ ' . svg_esc(share_money((float)$R['totalCost'])) . '</tspan></text>'
            . '<text x="792" y="500" font-size="20" opacity=".62" text-anchor="end">'
            . ($extra > 0 ? '含 Push / 中介 + ¥ ' . svg_esc(share_money($extra)) : '无额外买家支出')
            . '</text>';
    }

    $title = 'VPS 剩余价值 ' . $heroText;
    $desc  = '剩余 ' . share_money($days, $dec) . ' 天，共 ' . $in['cycleDays'] . ' 天，到期 ' . share_date((string)$in['expiryDate'])
        . ($hasPaid ? '，实付 ¥' . share_money((float)$R['actualPaid']) : '');

    $flow = 'M78 548 C230 505 390 572 560 552 S880 500 1122 546';

    // 预先转义模板中用到的文本
    $eTitle  = svg_esc($title);
    $eDesc   = svg_esc($desc);
    $eHero   = svg_esc($heroText);
    $eSub    = svg_esc($subLine);
    $ePct    = number_format($pct, 1, '.', '');
    $eTrade  = svg_esc(share_date((string)$in['tradeDate']));
    $eExpiry = svg_esc(share_date((string)$in['expiryDate']));
    $eRate   = svg_esc($rateDate);
    $barFill = round($barW * $pct / 100, 1);

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" role="img" aria-labelledby="title desc">
  <title id="title">{$eTitle}</title>
  <desc id="desc">{$eDesc}</desc>
  <defs>
    <linearGradient id="card" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#00C4A8"/>
      <stop offset="1" stop-color="#008F7B"/>
    </linearGradient>
  </defs>
  <style>@media (prefers-reduced-motion: reduce) { .flow-motion { display: none } }</style>
  <rect width="1200" height="630" rx="36" fill="#EEF7F4"/>
  <circle cx="1110" cy="80" r="260" fill="#FBBF24" opacity=".16"/>
  <circle cx="90" cy="600" r="150" fill="#00BFA5" opacity=".12"/>
  <rect x="54" y="52" width="1092" height="526" rx="32" fill="url(#card)"/>
  <circle cx="1080" cy="315" r="270" fill="#FFFFFF" opacity=".06"/>
  <circle cx="200" cy="90" r="160" fill="#FFFFFF" opacity=".04"/>
  <g aria-hidden="true">
    <path d="{$flow}" fill="none" stroke="#FFFFFF" stroke-width="3" opacity=".09"/>
    <path class="flow-motion" d="{$flow}" fill="none" stroke="#B9F3E8" stroke-width="2" stroke-dasharray="8 18" opacity=".32">
      <animate attributeName="stroke-dashoffset" from="0" to="-104" dur="6s" repeatCount="indefinite"/>
    </path>
    <circle cx="78" cy="548" r="7" fill="#FBBF24" opacity=".65"/>
    <circle cx="312" cy="541" r="7" fill="#FBBF24" opacity=".65"/>
    <circle class="flow-motion" cx="560" cy="552" r="20" fill="none" stroke="#FBBF24" stroke-width="2" opacity=".34">
      <animate attributeName="r" values="17;23;17" dur="2.8s" repeatCount="indefinite"/>
      <animate attributeName="opacity" values=".42;.12;.42" dur="2.8s" repeatCount="indefinite"/>
    </circle>
    <circle cx="560" cy="552" r="12" fill="#FBBF24"/>
    <circle cx="814" cy="524" r="6" fill="#FFFFFF" opacity=".28"/>
    <circle cx="1122" cy="546" r="6" fill="#FFFFFF" opacity=".28"/>
    <circle class="flow-motion" r="5" fill="#FBBF24" opacity=".9">
      <animateMotion dur="8s" repeatCount="indefinite" path="{$flow}"/>
    </circle>
  </g>
  <g font-family="{$font}" fill="#FFFFFF">
    <text x="104" y="116"><tspan font-size="32" font-weight="800">VPS.ss</tspan><tspan font-size="22" font-weight="600" opacity=".78"> · 剩余价值计算器</tspan></text>
    <text x="104" y="170" font-size="26" opacity=".78">当前剩余价值 (CNY)</text>
    <text x="104" y="266" font-size="{$heroSize}" font-weight="800" letter-spacing="-1">{$eHero}</text>
    <text x="104" y="312" font-size="{$subSize}" opacity=".74">{$eSub}</text>
    <text x="104" y="378" font-size="24" opacity=".74">剩余周期</text>
    <text x="792" y="378" font-size="26" font-weight="700" text-anchor="end">{$ePct}%</text>
    <rect x="104" y="394" width="{$barW}" height="14" rx="7" fill="#0A5F52" opacity=".75"/>
    <rect x="104" y="394" width="{$barFill}" height="14" rx="7" fill="#FBBF24"/>
    <text x="104" y="452" font-size="22" opacity=".68">交易日 {$eTrade}</text>
    <text x="792" y="452" font-size="22" opacity=".68" text-anchor="end">到期日 {$eExpiry}</text>
    {$totalSvg}
    <line x1="846" y1="120" x2="846" y2="510" stroke="#FFFFFF" opacity=".18"/>
    {$statsSvg}
  </g>
  <text x="600" y="611" text-anchor="middle" font-family="{$font}" font-size="18" font-weight="600" fill="#5F7A73">由 VPS.ss 提供计算服务</text>
  <text x="1146" y="611" text-anchor="end" font-family="{$font}" font-size="14" fill="#8AA39B">汇率 {$eRate}</text>
</svg>
SVG;

    return $svg . "\n";
}
