<?php
/**
 * 剩余价值计算核心（服务端）
 * 与 assets/app.js 中的前端逻辑保持一致，保证「无 JS 可用 + 分享链接可直出结果」
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * 解析并归一化请求参数（参数名沿用原版，旧分享链接依然有效）
 *
 * @param array<string,mixed>  $q      $_GET
 * @param array<string,float>  $rates
 * @return array<string,mixed>
 */
function parse_input(array $q, array $rates): array
{
    $has = static fn(string $k): bool => isset($q[$k]) && $q[$k] !== '';
    $str = static function (array $q, string $k, string $def = ''): string {
        $v = $q[$k] ?? $def;
        return is_string($v) ? trim($v) : $def;
    };

    $hasParams = count(array_intersect(array_keys($q), [
        'renewalAmount', 'currencySelector', 'tradeDate', 'expiryDate', 'actualPaid',
        'premiumInput', 'pushFee', 'pushPayer', 'middlemanPayer', 'cycleDays',
        'lastInput', 'pushCurrency', 'isDateTime', 'useCustomRate', 'customRateInput', 'useMiddleman',
    ])) > 0;

    // 付款周期
    $cycleDays = (int)($q['cycleDays'] ?? 365);
    if (!isset(CYCLES[$cycleDays])) {
        $cycleDays = 365;
    }

    $isDateTime = ($str($q, 'isDateTime') === '1');

    // 计价币种
    $currency = strtoupper($str($q, 'currencySelector', 'USD'));
    if (!isset(CURRENCIES[$currency])) {
        $currency = 'USD';
    }

    // 日期：默认交易日 = 今天，到期日 = 今天 + 周期
    $tradeDate  = normalize_date_input($str($q, 'tradeDate'), $isDateTime);
    $expiryDate = normalize_date_input($str($q, 'expiryDate'), $isDateTime);
    if ($tradeDate === '') {
        $tradeDate = date($isDateTime ? 'Y-m-d\TH:i' : 'Y-m-d');
    }
    if ($expiryDate === '') {
        $expiryDate = date(
            $isDateTime ? 'Y-m-d\TH:i' : 'Y-m-d',
            strtotime($tradeDate . ' +' . $cycleDays . ' days') ?: time()
        );
    }

    // 无 JS 场景：点击周期按钮提交后，按新周期重算到期日期
    $prevCycle = (int)($q['prevCycle'] ?? $cycleDays);
    if ($prevCycle !== $cycleDays) {
        $expiryDate = date(
            $isDateTime ? 'Y-m-d\TH:i' : 'Y-m-d',
            strtotime($tradeDate . ' +' . $cycleDays . ' days') ?: time()
        );
    }

    // Push 费币种
    $pushCurrency = strtoupper($str($q, 'pushCurrency', 'USD'));
    if (!isset($rates[$pushCurrency])) {
        $pushCurrency = 'USD';
    }

    $pushPayer      = in_array($str($q, 'pushPayer', 'buyer'), ['buyer', 'seller', 'split'], true) ? $str($q, 'pushPayer', 'buyer') : 'buyer';
    $middlemanPayer = in_array($str($q, 'middlemanPayer', 'buyer'), ['buyer', 'seller', 'split'], true) ? $str($q, 'middlemanPayer', 'buyer') : 'buyer';
    $lastInput      = $str($q, 'lastInput', 'actualPaid') === 'premiumInput' ? 'premiumInput' : 'actualPaid';

    $useCustomRate = ($str($q, 'useCustomRate') === '1') && $currency !== 'CNY';
    $useMiddleman  = ($str($q, 'useMiddleman') === '1');

    return [
        'hasParams'      => $hasParams,
        'amountRaw'      => $has('renewalAmount') ? $str($q, 'renewalAmount') : '',
        'amount'         => $has('renewalAmount') && is_numeric($str($q, 'renewalAmount')) ? (float)$str($q, 'renewalAmount') : null,
        'currency'       => $currency,
        'useCustomRate'  => $useCustomRate,
        'customRateRaw'  => $has('customRateInput') ? $str($q, 'customRateInput') : '',
        'customRate'     => $has('customRateInput') && is_numeric($str($q, 'customRateInput')) ? (float)$str($q, 'customRateInput') : null,
        'cycleDays'      => $cycleDays,
        'isDateTime'     => $isDateTime,
        'tradeDate'      => $tradeDate,
        'expiryDate'     => $expiryDate,
        'actualPaidRaw'  => $has('actualPaid') ? $str($q, 'actualPaid') : '',
        'actualPaid'     => $has('actualPaid') && is_numeric($str($q, 'actualPaid')) ? (float)$str($q, 'actualPaid') : null,
        'premiumRaw'     => $has('premiumInput') ? $str($q, 'premiumInput') : '',
        'premium'        => $has('premiumInput') && is_numeric($str($q, 'premiumInput')) ? (float)$str($q, 'premiumInput') : null,
        'lastInput'      => $lastInput,
        'pushFeeRaw'     => $has('pushFee') ? $str($q, 'pushFee') : '',
        'pushFee'        => $has('pushFee') && is_numeric($str($q, 'pushFee')) ? (float)$str($q, 'pushFee') : 0.0,
        'pushCurrency'   => $pushCurrency,
        'pushPayer'      => $pushPayer,
        'useMiddleman'   => $useMiddleman,
        'middlemanPayer' => $middlemanPayer,
    ];
}

/** 校验并归一化 date / datetime-local 字符串 */
function normalize_date_input(string $v, bool $isDateTime): string
{
    if ($v === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $isDateTime ? $v . 'T00:00' : $v;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2})?$/', $v, $m)) {
        return $isDateTime ? $m[1] . 'T' . $m[2] : $m[1];
    }
    return '';
}

/**
 * 计算剩余价值等全部结果
 *
 * @param array<string,mixed> $in
 * @param array<string,float> $rates
 * @return array<string,mixed>
 */
function calculate(array $in, array $rates): array
{
    $dec = $in['isDateTime'] ? 2 : 0;

    $out = [
        'valid'          => false,
        'expired'        => false,
        'days'           => 0.0,
        'resCNY'         => 0.0,
        'resCNYText'     => '¥ --',
        'resOrigText'    => '--',
        'resActualText'  => '¥ --',
        'dayOfficial'    => '--',
        'dayActual'      => '--',
        'remainingText'  => '-- 天',
        'progressPct'    => 0.0,
        'progressHint'   => '',
        'showPremium'    => false,
        'premiumTitle'   => '卖家溢价',
        'premiumText'    => '--',
        'premiumPositive' => true,
        'showMiddleman'  => false,
        'middlemanText'  => '¥ --',
        'middlemanLabel' => '买家中介费',
        'finalTotalText' => '¥ --',
        'extraFeeText'   => '含额外支出 ¥ 0.00',
        'extraFeeActive' => false,
        'showRatio'      => false,
        'barValuePct'    => 0.0,
        'barPremiumPct'  => 0.0,
        'actualPaidValue' => $in['actualPaidRaw'],
        'premiumValue'    => $in['premiumRaw'],
        'rateUsed'        => 1.0,
        // 以下为原始数值（供分享图 / 接口复用）
        'resOrig'         => 0.0,
        'actualPaid'      => null,
        'premium'         => null,
        'pushBuyer'       => 0.0,
        'middlemanBuyer'  => 0.0,
        'extraFee'        => 0.0,
        'totalCost'       => 0.0,
    ];

    $tradeTs  = strtotime($in['tradeDate']);
    $expiryTs = strtotime($in['expiryDate']);
    if ($tradeTs === false || $expiryTs === false) {
        return $out;
    }

    $days = ($expiryTs - $tradeTs) / 86400;
    $out['days'] = $days;

    if ($in['amount'] === null || $days <= 0) {
        $out['expired']       = $days <= 0;
        $out['remainingText'] = $days <= 0 ? '已到期' : '-- 天';
        $out['progressHint']  = $days <= 0 ? '该机器已在交易日前到期' : '';
        return $out;
    }

    // 汇率（自定义优先）
    $rate = $rates[$in['currency']] ?? 1.0;
    if ($in['useCustomRate'] && $in['customRate'] !== null && $in['customRate'] > 0) {
        $rate = $in['customRate'];
    }
    $out['rateUsed'] = $rate;

    $resOrig = ($in['amount'] / $in['cycleDays']) * $days;
    $resCNY  = $resOrig * $rate;

    // Push 费换算为 CNY 并按承担方拆分
    $push  = $in['pushFee'] * ($rates[$in['pushCurrency']] ?? 1.0);
    $bPush = $in['pushPayer'] === 'buyer' ? $push : ($in['pushPayer'] === 'split' ? $push / 2 : 0.0);
    $sPush = $in['pushPayer'] === 'seller' ? $push : ($in['pushPayer'] === 'split' ? $push / 2 : 0.0);

    $actPaid = $in['actualPaid'];
    $prem    = $in['premium'];
    $isMid   = (bool)$in['useMiddleman'];
    $midWho  = (string)$in['middlemanPayer'];

    // 双向反推：锁定一端推算另一端
    if ($in['lastInput'] === 'actualPaid') {
        if ($in['actualPaidRaw'] === '') {
            $prem = null;
            $out['premiumValue'] = '';
        } elseif ($actPaid !== null) {
            $sMid = 0.0;
            if ($isMid) {
                if ($midWho === 'seller') {
                    $sMid = $actPaid * MIDDLEMAN_FEE_RATE;
                } elseif ($midWho === 'split') {
                    $sMid = $actPaid * MIDDLEMAN_FEE_RATE / 2;
                }
            }
            $prem = ($actPaid - $sPush - $sMid) - $resCNY;
            $out['premiumValue'] = number_format($prem, 2, '.', '');
        }
    } else {
        if ($in['premiumRaw'] === '') {
            $actPaid = null;
            $out['actualPaidValue'] = '';
        } elseif ($prem !== null) {
            if ($isMid && $midWho === 'seller') {
                $actPaid = ($resCNY + $prem + $sPush) / (1 - MIDDLEMAN_FEE_RATE);
            } elseif ($isMid && $midWho === 'split') {
                $actPaid = ($resCNY + $prem + $sPush) / (1 - MIDDLEMAN_FEE_RATE / 2);
            } else {
                $actPaid = $resCNY + $prem + $sPush;
            }
            $out['actualPaidValue'] = number_format($actPaid, 2, '.', '');
        }
    }

    $middlemanFee = 0.0;
    $bMid = 0.0;
    if ($isMid && $actPaid !== null && $actPaid > 0) {
        $middlemanFee = $actPaid * MIDDLEMAN_FEE_RATE;
        if ($midWho === 'buyer') {
            $bMid = $middlemanFee;
        } elseif ($midWho === 'split') {
            $bMid = $middlemanFee / 2;
        }
    }
    $totalBuyerCost = ($actPaid ?? 0.0) + $bPush + $bMid;

    $pct = min(($days / $in['cycleDays']) * 100, 100);

    $out['valid']         = true;
    $out['resCNY']        = $resCNY;
    $out['resOrig']       = $resOrig;
    $out['pushBuyer']     = $bPush;
    $out['resCNYText']    = '¥ ' . number_format($resCNY, 2, '.', ',');
    $out['resOrigText']   = number_format($resOrig, 2, '.', ',') . ' ' . $in['currency'];
    $out['remainingText'] = number_format($days, $dec, '.', ',') . ' 天';
    $out['progressPct']   = $pct;
    $out['progressHint']  = '剩余 ' . number_format($pct, 1, '.', '') . '% 周期';
    $out['dayOfficial']   = '¥' . number_format($resCNY / $days, 3, '.', '') . ' / 天';

    if ($actPaid !== null && $actPaid >= 0) {
        $extraFee = $bPush + $bMid;

        $out['actualPaid']      = $actPaid;
        $out['premium']         = (float)$prem;
        $out['middlemanBuyer']  = $bMid;
        $out['extraFee']        = $extraFee;
        $out['totalCost']       = $totalBuyerCost;
        $out['resActualText']   = '¥ ' . number_format($actPaid, 2, '.', ',');
        $out['showMiddleman']   = $bMid > 0;
        $out['middlemanLabel']  = $midWho === 'split' ? '中介费(AA)' : '买家中介费';
        $out['middlemanText']   = '+ ¥ ' . number_format($bMid, 2, '.', ',');
        $out['showPremium']     = true;
        $out['premiumPositive'] = ($prem ?? 0) >= 0;
        $out['premiumTitle']    = ($prem ?? 0) >= 0 ? '卖家溢价' : '卖家折价';
        $out['premiumText']     = (($prem ?? 0) >= 0 ? '+' : '') . '¥ ' . number_format((float)$prem, 2, '.', ',');
        $out['dayActual']       = '¥' . number_format($totalBuyerCost / $days, 3, '.', '') . ' / 天';
        $out['finalTotalText']  = '¥ ' . number_format($totalBuyerCost, 2, '.', ',');
        $out['extraFeeText']    = $extraFee > 0
            ? '额外支出 (Push/中介) + ¥ ' . number_format($extraFee, 2, '.', ',')
            : '无额外买家支出';
        $out['extraFeeActive']  = $extraFee > 0;

        $totalPart = $resCNY + max(0.0, (float)$prem);
        if ($totalPart <= 0) {
            $totalPart = 1.0;
        }
        $out['showRatio']     = true;
        $out['barValuePct']   = ($resCNY / $totalPart) * 100;
        $out['barPremiumPct'] = (max(0.0, (float)$prem) / $totalPart) * 100;
    }

    return $out;
}
