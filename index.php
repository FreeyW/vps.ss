<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/rates.php';
require_once __DIR__ . '/inc/calc.php';
require_once __DIR__ . '/inc/counter.php';

$rateData   = get_rates();
$rates      = $rateData['rates'];
$rateOk     = in_array($rateData['source'], ['live', 'cache'], true);
$in         = parse_input($_GET, $rates);
$R          = calculate($in, $rates);
$counts     = counter_read();
$hasQuery   = !empty($_SERVER['QUERY_STRING']);
$canonical  = SITE_URL . '/';

// 分享链接（带参数）不参与索引，避免重复内容
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($hasQuery) {
    header('X-Robots-Tag: noindex, follow');
}

$pushCurrOptions = array_merge(['CNY'], RATE_GRID);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(SITE_TITLE) ?></title>
<meta name="description" content="<?= h(SITE_DESC) ?>">
<meta name="keywords" content="<?= h(SITE_KEYWORDS) ?>">
<meta name="robots" content="<?= $hasQuery ? 'noindex, follow' : 'index, follow, max-image-preview:large' ?>">
<meta name="author" content="<?= h(SITE_BRAND) ?>">
<meta name="theme-color" content="#00bfa5">
<link rel="canonical" href="<?= h($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= h(SITE_BRAND) ?>">
<meta property="og:locale" content="zh_CN">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:title" content="<?= h(SITE_TITLE) ?>">
<meta property="og:description" content="<?= h(SITE_DESC) ?>">
<meta property="og:image" content="<?= h(SITE_URL) ?>/assets/og-cover.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h(SITE_TITLE) ?>">
<meta name="twitter:description" content="<?= h(SITE_DESC) ?>">
<meta name="twitter:image" content="<?= h(SITE_URL) ?>/assets/og-cover.png">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
<link rel="stylesheet" href="/assets/vendor/flatpickr/flatpickr.min.css?v=4.6.13">
<link rel="stylesheet" href="/assets/style.css?v=<?= h((string)@filemtime(__DIR__ . '/assets/style.css')) ?>">
<script async src="https://stat.re/js/pa-huCrc-i_Hm2hnAiJGR6Fm.js"></script>
<script>
  window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
  plausible.init()
</script>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'           => 'WebApplication',
            '@id'             => SITE_URL . '/#app',
            'name'            => SITE_NAME,
            'url'             => $canonical,
            'description'     => SITE_DESC,
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem' => 'Any',
            'inLanguage'      => 'zh-CN',
            'isAccessibleForFree' => true,
            'offers'          => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'CNY'],
            'featureList'     => ['按天折算 VPS 剩余价值', '实时汇率换算人民币', '卖家溢价双向反推', 'Push 手续费分摊', '5% 中介担保费核算', '一键复制文本 / Markdown 表格', '生成 SVG 结果分享图'],
            'publisher'       => ['@type' => 'Organization', 'name' => SITE_BRAND, 'url' => SITE_URL . '/'],
        ],
        [
            '@type'           => 'FAQPage',
            '@id'             => SITE_URL . '/#faq',
            'mainEntity'      => [
                [
                    '@type' => 'Question',
                    'name'  => 'VPS 剩余价值怎么计算？',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => '剩余价值 = 续费金额 ÷ 付款周期天数 × 剩余天数，再按当前汇率换算为人民币。例如年付 30 美元、还剩 200 天，日均约 0.0822 美元，剩余价值约 16.44 美元。'],
                ],
                [
                    '@type' => 'Question',
                    'name'  => '卖家溢价和实付金额是什么关系？',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => '溢价 = 实付卖家金额 − 剩余价值 − 卖家承担的 Push 费与中介费。计算器支持双向反推：填实付金额自动算出溢价，填溢价自动算出应付金额。'],
                ],
                [
                    '@type' => 'Question',
                    'name'  => '中介担保费和 Push 手续费如何分摊？',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => '中介担保费按成交额的 5% 计算，可选择买家付、卖家付或双方 AA 平摊；Push 手续费同样支持三种分摊方式，并按所选币种的实时汇率折算为人民币计入总支出。'],
                ],
                [
                    '@type' => 'Question',
                    'name'  => '汇率数据多久更新一次？',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => '汇率由服务端每小时从公开汇率接口拉取一次并缓存，页面展示保留 3 位小数。如需按自己的收款渠道结算，可勾选「自定义」手动填写汇率。'],
                ],
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
</head>
<body>

<div class="toast" id="toast" role="status" aria-live="polite">
    <i class="fas fa-circle-check"></i><span id="toastMsg"></span>
</div>

<div class="wrap">
    <header class="topbar">
        <div class="brand">
            <div class="brand-logo" aria-hidden="true"><i class="fas fa-server"></i></div>
            <div>
                <h1>VPS 剩余价值计算器</h1>
                <p class="tagline">Remaining Value Calculator · <?= h(SITE_BRAND) ?></p>
            </div>
        </div>
        <div class="stats">
            <div class="today"><i class="fas fa-calendar-day"></i> 今日计算 <span id="todayCount"><?= (int)$counts['today'] ?></span></div>
            <div class="total"><i class="fas fa-fire"></i> 累计计算 <span id="totalCount"><?= (int)$counts['total'] ?></span></div>
        </div>
    </header>

    <form class="layout" id="calcForm" method="get" action="/">
        <input type="hidden" name="cycleDays" id="cycleDaysInput" value="<?= (int)$in['cycleDays'] ?>">
        <input type="hidden" name="prevCycle" id="prevCycleInput" value="<?= (int)$in['cycleDays'] ?>">
        <input type="hidden" name="lastInput" id="lastInputField" value="<?= h($in['lastInput']) ?>">
        <input type="hidden" name="isDateTime" id="isDateTimeField" value="<?= $in['isDateTime'] ? '1' : '' ?>">
        <input type="hidden" name="pushCurrency" id="pushCurrencyField" value="<?= h($in['pushCurrency']) ?>">

        <!-- 左侧：参数配置 -->
        <div class="col col-form">
            <section class="card" aria-labelledby="sec-renew">
                <p class="section-label" id="sec-renew">① 续费信息</p>
                <div class="grid-renew">
                    <div>
                        <label class="field-label" for="renewalAmount">续费金额</label>
                        <input type="number" step="any" min="0" inputmode="decimal" id="renewalAmount" name="renewalAmount"
                               class="field-input" placeholder="请输入金额" value="<?= h($in['amountRaw']) ?>">
                    </div>
                    <div>
                        <div class="field-label row">
                            <label for="currencySelector">计价币种</label>
                            <label class="mini-toggle" id="customRateToggleBox"<?= $in['currency'] === 'CNY' ? ' style="display:none"' : '' ?>>
                                <input type="checkbox" id="useCustomRate" name="useCustomRate" value="1"<?= $in['useCustomRate'] ? ' checked' : '' ?>> 自定义
                            </label>
                        </div>
                        <div class="csel-wrap csel-block">
                            <button type="button" class="csel-btn" id="currencySelectorBtn" aria-haspopup="listbox" aria-expanded="false" aria-label="计价币种">
                                <span id="currencySelectorLabel"><?= h($in['currency'] . ' · ' . CURRENCIES[$in['currency']]) ?></span> <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="csel-dropdown" id="currencySelectorDropdown" role="listbox">
                                <?php foreach (CURRENCIES as $code => $label): ?>
                                    <div class="csel-option<?= $in['currency'] === $code ? ' active' : '' ?>" role="option" data-value="<?= h($code) ?>" data-label="<?= h($code . ' · ' . $label) ?>"><?= h($code . ' · ' . $label) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" id="currencySelector" name="currencySelector" value="<?= h($in['currency']) ?>">
                        <div id="customRateWrapper" class="custom-rate<?= $in['useCustomRate'] ? '' : ' hidden' ?>">
                            <span>1 <span id="customRateLabel"><?= h($in['currency']) ?></span> =</span>
                            <input type="number" step="any" min="0" inputmode="decimal" id="customRateInput" name="customRateInput"
                                   class="field-input" placeholder="汇率" value="<?= h($in['customRateRaw']) ?>" aria-label="自定义汇率">
                            <span>CNY</span>
                        </div>
                    </div>
                </div>

                <p class="field-label">付款周期</p>
                <div class="grid-cycle" id="cycleSelector">
                    <?php foreach (CYCLES as $days => $label): ?>
                        <button type="submit" name="cycleDays" value="<?= (int)$days ?>"
                                class="cycle-btn<?= $in['cycleDays'] === $days ? ' active' : '' ?>" data-cycle="<?= (int)$days ?>"><?= h($label) ?></button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card" aria-labelledby="sec-time">
                <div class="card-head">
                    <p class="section-label" id="sec-time">② 时间信息</p>
                    <button type="button" id="toggleTimePrecision" class="link-btn<?= $in['isDateTime'] ? ' on' : '' ?>">
                        <i class="fas fa-clock"></i> 精确到时分
                    </button>
                </div>
                <div class="grid-2">
                    <div>
                        <label class="field-label" for="tradeDate" id="tradeDateLabel">交易日期</label>
                        <div class="date-field">
                            <input type="<?= $in['isDateTime'] ? 'datetime-local' : 'date' ?>" id="tradeDate" name="tradeDate"
                                   class="field-input" value="<?= h($in['tradeDate']) ?>" autocomplete="off">
                            <i class="fas fa-calendar-days" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div>
                        <label class="field-label" for="expiryDate" id="expiryDateLabel">到期日期</label>
                        <div class="date-field">
                            <input type="<?= $in['isDateTime'] ? 'datetime-local' : 'date' ?>" id="expiryDate" name="expiryDate"
                                   class="field-input" value="<?= h($in['expiryDate']) ?>" autocomplete="off">
                            <i class="fas fa-calendar-days" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card" aria-labelledby="sec-settle">
                <p class="section-label" id="sec-settle">③ 交易结算</p>
                <div class="settle-box">
                    <div class="grid-2">
                        <div>
                            <label class="settle-label" for="actualPaid">实付卖家 (CNY)</label>
                            <input type="number" step="any" min="0" inputmode="decimal" id="actualPaid" name="actualPaid"
                                   class="field-input core-input" value="<?= h((string)$R['actualPaidValue']) ?>">
                        </div>
                        <div>
                            <label class="settle-label" for="premiumInput">卖家溢价 (CNY)</label>
                            <input type="number" step="any" inputmode="decimal" id="premiumInput" name="premiumInput"
                                   class="field-input core-input" value="<?= h((string)$R['premiumValue']) ?>">
                        </div>
                    </div>
                    <div id="bindHint" class="bind-hint">
                        <i class="fas fa-lock"></i>
                        <?= $in['lastInput'] === 'actualPaid' ? '当前锁定【实付卖家】推算溢价' : '当前锁定【卖家溢价】推算实付' ?>
                    </div>
                </div>

                <div class="push-row">
                    <div class="push-title">Push 费</div>
                    <div class="push-controls">
                        <input type="number" step="any" min="0" inputmode="decimal" id="pushFee" name="pushFee"
                               class="field-input push-fee" placeholder="0" value="<?= h($in['pushFeeRaw']) ?>" aria-label="Push 手续费">
                        <div class="csel-wrap">
                            <button type="button" class="csel-btn" id="pushCurrBtn" aria-haspopup="listbox" aria-expanded="false">
                                <span id="pushCurrLabel"><?= h($in['pushCurrency']) ?></span> <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="csel-dropdown" id="pushCurrDropdown" role="listbox">
                                <?php foreach ($pushCurrOptions as $code): ?>
                                    <div class="csel-option<?= $in['pushCurrency'] === $code ? ' active' : '' ?>" role="option" data-value="<?= h($code) ?>"><?= h($code) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="csel-wrap">
                            <button type="button" class="csel-btn" id="pushPayerBtn" aria-haspopup="listbox" aria-expanded="false" aria-label="Push 费承担方">
                                <span id="pushPayerLabel"><?= h(PUSH_PAYER_LABELS[$in['pushPayer']]) ?></span> <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="csel-dropdown" id="pushPayerDropdown" role="listbox">
                                <?php foreach (PUSH_PAYER_LABELS as $value => $label): ?>
                                    <div class="csel-option<?= $in['pushPayer'] === $value ? ' active' : '' ?>" role="option" data-value="<?= h($value) ?>" data-label="<?= h($label) ?>"><?= h($label) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" id="pushPayer" name="pushPayer" value="<?= h($in['pushPayer']) ?>">
                    </div>
                </div>

                <noscript>
                    <div class="noscript-submit">
                        <button type="submit" class="btn btn-primary">开始计算</button>
                    </div>
                </noscript>
            </section>

            <section class="card" aria-labelledby="sec-rate">
                <div class="rate-head">
                    <p class="rate-title" id="sec-rate"><i class="fas fa-chart-area"></i>实时汇率 (对 CNY)</p>
                    <div class="rate-meta">
                        <span class="badge<?= $rateOk ? '' : ' warn' ?>" id="rateStatus">
                            <i class="fas fa-<?= $rateOk ? 'check' : 'triangle-exclamation' ?>"></i>
                            <?= $rateOk ? h($rateData['date']) . ' 已更新' : '接口异常 · 使用备用汇率' ?>
                        </span>
                        <span class="rate-source">CURRENCY-API</span>
                    </div>
                </div>
                <div class="rate-grid" id="rateGrid">
                    <?php foreach (RATE_GRID as $code): ?>
                        <?php $r = $rates[$code] ?? null; if ($r === null) continue; ?>
                        <div class="rate-cell<?= $code === $in['currency'] ? ' is-base' : '' ?>">
                            <div class="code"><?= h($code) ?></div>
                            <div class="value"><?= h(fmt_rate((float)$r)) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- 右侧：结果面板（浅色） -->
        <div class="col col-result">
            <a class="esim-banner" href="https://esim.now/zh/esim/china?utm_source=vps.ss&utm_medium=banner" target="_blank" rel="noopener">
                <div class="esim-banner__body">
                    <div class="esim-banner__title">eSIM.Now Anywhere</div>
                    <div class="esim-banner__desc">旅行 eSIM 流量套餐——在线购买，几分钟即可安装，无需VPN，即可访问Google。</div>
                </div>
                <span class="esim-banner__cta">立即获取 eSIM</span>
            </a>

            <aside class="result-panel" aria-live="polite">
                <div class="result-hero">
                    <p class="hero-label">VPS 剩余价值 (CNY)</p>
                    <div class="hero-value" id="resCNYBig"><?= h($R['resCNYText']) ?></div>
                </div>

                <div class="premium-box<?= $R['showPremium'] ? '' : ' hidden' ?>" id="premiumBox">
                    <p class="premium-title <?= $R['premiumPositive'] ? 'is-premium' : 'is-discount' ?>" id="premiumTitle"><?= h($R['premiumTitle']) ?></p>
                    <div class="premium-value <?= $R['premiumPositive'] ? 'is-premium' : 'is-discount' ?>" id="premiumValueBig"><?= h($R['premiumText']) ?></div>
                </div>

                <div class="result-rows">
                    <div class="result-row"><span class="result-label">折合原币</span><span class="result-value" id="resOrig"><?= h($R['resOrigText']) ?></span></div>
                    <div class="result-row"><span class="result-label">实付卖家</span><span class="result-value" id="resActual"><?= h($R['resActualText']) ?></span></div>
                    <div class="result-row<?= $R['showMiddleman'] ? '' : ' hidden' ?>" id="middlemanRow">
                        <span class="result-label" id="middlemanRowLabel"><?= h($R['middlemanLabel']) ?></span>
                        <span class="result-value soft" id="resMiddleman"><?= h($R['middlemanText']) ?></span>
                    </div>
                    <div class="result-row"><span class="result-label">官方日均</span><span class="result-value" id="dayOfficial"><?= h($R['dayOfficial']) ?></span></div>
                    <div class="result-row accent"><span class="result-label">综合日均</span><span class="result-value" id="dayActual"><?= h($R['dayActual']) ?></span></div>
                </div>

                <div class="progress-block">
                    <div class="progress-head">
                        <span class="p-title">剩余有效期</span>
                        <span class="p-days<?= $R['expired'] ? ' expired' : '' ?>" id="remainingDays"><?= h($R['remainingText']) ?></span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-bar" id="remainingProgress" style="width:<?= number_format((float)$R['progressPct'], 2, '.', '') ?>%"></div>
                    </div>
                    <div class="progress-hint" id="progressHint"><?= h($R['progressHint']) ?></div>
                </div>

                <div class="ratio-block<?= $R['showRatio'] ? '' : ' hidden' ?>" id="valueRatioBox">
                    <div class="ratio-title">价值构成比例</div>
                    <div class="ratio-track">
                        <div class="bar-value" id="barValue" style="width:<?= number_format((float)$R['barValuePct'], 2, '.', '') ?>%"></div>
                        <div class="bar-premium" id="barPremium" style="width:<?= number_format((float)$R['barPremiumPct'], 2, '.', '') ?>%"></div>
                    </div>
                    <div class="ratio-legend">
                        <div><span class="dot dot-value"></span>剩余价值</div>
                        <div><span class="dot dot-premium"></span>溢价部分</div>
                    </div>
                </div>

                <div class="settle-block">
                    <div class="settle-head">
                        <label class="check-label" for="useMiddleman">
                            <input type="checkbox" id="useMiddleman" name="useMiddleman" value="1"<?= $in['useMiddleman'] ? ' checked' : '' ?>>
                            开启中介担保 (5%)
                        </label>
                        <div class="csel-wrap">
                            <button type="button" class="csel-btn" id="middlemanPayerBtn" aria-haspopup="listbox" aria-expanded="false" aria-label="中介费承担方">
                                <span id="middlemanPayerLabel"><?= h(MIDDLEMAN_PAYER_LABELS[$in['middlemanPayer']]) ?></span> <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="csel-dropdown" id="middlemanPayerDropdown" role="listbox">
                                <?php foreach (MIDDLEMAN_PAYER_LABELS as $value => $label): ?>
                                    <div class="csel-option<?= $in['middlemanPayer'] === $value ? ' active' : '' ?>" role="option" data-value="<?= h($value) ?>" data-label="<?= h($label) ?>"><?= h($label) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" id="middlemanPayer" name="middlemanPayer" value="<?= h($in['middlemanPayer']) ?>">
                    </div>

                    <div class="final-box<?= $R['extraFeeActive'] ? ' active' : '' ?>" id="finalCostBox">
                        <div class="final-head">
                            <span class="final-label" id="finalCostLabel">买家最终总支出</span>
                            <span class="final-extra" id="extraFeeDetail"><?= h($R['extraFeeText']) ?></span>
                        </div>
                        <div class="final-value" id="finalTotalCostDisplay"><?= h($R['finalTotalText']) ?></div>
                    </div>
                </div>

                <div class="actions">
                    <div class="menu-wrap">
                        <button type="button" class="btn btn-primary" id="copyBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="copyMenu">
                            <i class="fas fa-copy"></i> 复制详情 <i class="fas fa-chevron-down caret" aria-hidden="true"></i>
                        </button>
                        <div class="csel-dropdown menu-dropdown" id="copyMenu" role="menu" aria-label="复制格式">
                            <button type="button" class="csel-option" role="menuitem" data-format="text"><i class="fas fa-align-left"></i> 纯文本</button>
                            <button type="button" class="csel-option" role="menuitem" data-format="markdown"><i class="fab fa-markdown"></i> Markdown 表格</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-share" id="shareImgBtn" title="生成 SVG 分享图"><i class="fas fa-image"></i> 分享图片</button>
                    <button type="button" class="btn btn-ghost btn-icon" id="shareBtn" title="复制分享链接" aria-label="复制分享链接"><i class="fas fa-link"></i></button>
                    <button type="button" class="btn btn-danger btn-icon" id="resetBtn" title="重置" aria-label="重置"><i class="fas fa-rotate-right"></i></button>
                </div>
            </aside>
        </div>
    </form>

    <!-- SEO 内容区 -->
    <section class="card prose" id="guide">
        <h2>VPS 剩余价值怎么算？一条公式说清楚</h2>
        <p class="formula">剩余价值 = 续费金额 ÷ 付款周期天数 × 剩余天数 × 汇率</p>
        <p>在 VPS / 独服的二手交易里，买家真正买到的是「尚未使用的服务时长」。因此定价的基准不是原价，而是把续费价格按天摊平后，乘以从交易日到到期日之间的剩余天数。本站的计算器会自动完成折算、汇率换算、溢价反推与手续费分摊，全部计算在服务端与浏览器内完成，不保存你的交易数据。</p>

        <h3>四步得到结果</h3>
        <ol>
            <li><strong>填续费信息</strong>：输入商家标注的续费价格，选择计价币种与付款周期（月付 / 季付 / 半年 / 年付 / 两年 / 三年）。</li>
            <li><strong>填时间信息</strong>：默认交易日期为今天，到期日期按周期自动推算，也可点开日历选择或直接输入；点击「精确到时分」可按小时结算短周期机器。</li>
            <li><strong>填结算金额</strong>：填写「实付卖家」自动反推溢价，或填写「卖家溢价」自动反推应付金额，两个方向随时切换。</li>
            <li><strong>加上附加费用</strong>：按需填写 Push 手续费与 5% 中介担保费，并选择由买家承担、卖家承担还是双方 AA 平摊。</li>
        </ol>

        <h3>计算示例</h3>
        <p>某机器年付 30 USD，交易当天距到期还有 200 天，按 1 USD = <?= h(fmt_rate((float)($rates['USD'] ?? 0))) ?> CNY 计算：日均 30 ÷ 365 ≈ 0.0822 USD，剩余价值 ≈ 16.44 USD ≈ <?= h(number_format(30 / 365 * 200 * (float)($rates['USD'] ?? 0), 2, '.', '')) ?> CNY。若卖家要价高于该数值，差额即为溢价，常见于 NAT 小鸡、优惠年付机、稀缺 IP 段等有额外附加值的机器。</p>

        <h3>常见问题</h3>
        <h4>溢价一定是坏事吗？</h4>
        <p>不一定。绝版促销价、可续费的低价年付、优质 IP 段、特殊线路（CN2 GIA、9929、AS4837 直连）都可能让机器的实际价值高于纯剩余价值。反之，接近到期、无法续费或存在 ToS 风险的机器，出现折价（负溢价）也很正常。</p>
        <h4>汇率用哪一个？</h4>
        <p>页面默认使用每小时更新一次的公开汇率，保留 3 位小数展示。如果你和对方约定按支付宝、PayPal 或某交易所的汇率结算，勾选「自定义」手动填写即可，计算结果会立刻按你的汇率重算。</p>
        <h4>数据会被上传吗？</h4>
        <p>不会主动保存你的输入。金额、日期等参数只在你本机与本次请求中使用；点击「分享链接」时才会把当前参数写进 URL；点击「分享图片」时会把本次计算结果生成一张 SVG 图片保存在服务器，得到可长期访问的图片链接，方便你把结算明细发给交易对方核对。</p>
    </section>

    <footer class="site-footer">
        <p>© <?= date('Y') ?> <a href="<?= h(SITE_URL) ?>/"><?= h(SITE_BRAND) ?></a> · VPS 剩余价值计算器 · 汇率更新于 <?= h($rateData['date']) ?> · 结果仅供交易参考</p>
    </footer>
</div>

<!-- 分享图弹窗 -->
<div class="modal" id="shareModal" role="dialog" aria-modal="true" aria-labelledby="shareModalTitle" aria-hidden="true">
    <div class="modal-backdrop" data-close></div>
    <div class="modal-box">
        <div class="modal-head">
            <h2 id="shareModalTitle"><i class="fas fa-image"></i>分享图片</h2>
            <button type="button" class="modal-close" data-close aria-label="关闭"><i class="fas fa-xmark"></i></button>
        </div>
        <a class="share-preview" id="sharePreviewLink" href="#" target="_blank" rel="noopener" title="在新窗口打开图片">
            <img id="sharePreviewImg" src="" alt="VPS 剩余价值分享图" width="1200" height="630">
        </a>
        <div class="share-row">
            <span class="share-key"><i class="fab fa-markdown"></i>Markdown</span>
            <input type="text" readonly class="share-input" id="shareMdInput" aria-label="Markdown 图片代码">
            <button type="button" class="btn btn-primary btn-sm" data-copy="shareMdInput" data-msg="Markdown 图片代码已复制"><i class="fas fa-copy"></i> 复制</button>
        </div>
        <div class="share-row">
            <span class="share-key"><i class="fas fa-link"></i>图片链接</span>
            <input type="text" readonly class="share-input" id="shareUrlInput" aria-label="图片链接">
            <button type="button" class="btn btn-primary btn-sm" data-copy="shareUrlInput" data-msg="图片链接已复制"><i class="fas fa-copy"></i> 复制</button>
        </div>
        <p class="share-note">图片已保存在服务器，可直接粘贴到论坛、群聊或帖子中；相同结果只会生成一张图。</p>
    </div>
</div>

<script>
window.VPS = {
    rates: <?= json_encode(array_map(static fn($v): float => round((float)$v, 6), $rates), JSON_UNESCAPED_UNICODE) ?>,
    rateDate: <?= json_encode($rateData['date']) ?>,
    rateDecimals: <?= (int)RATE_DECIMALS ?>,
    midFee: <?= (float)MIDDLEMAN_FEE_RATE ?>,
    brand: <?= json_encode(SITE_BRAND) ?>,
    siteUrl: <?= json_encode(SITE_URL . '/') ?>,
    cycles: <?= json_encode(CYCLES, JSON_UNESCAPED_UNICODE) ?>,
    pushPayerLabels: <?= json_encode(PUSH_PAYER_LABELS, JSON_UNESCAPED_UNICODE) ?>,
    rateGrid: <?= json_encode(RATE_GRID) ?>,
    initialCycle: <?= (int)$in['cycleDays'] ?>,
    hasParams: <?= $in['hasParams'] ? 'true' : 'false' ?>
};
</script>
<script src="/assets/vendor/flatpickr/flatpickr.min.js?v=4.6.13" defer></script>
<script src="/assets/vendor/flatpickr/zh.js?v=4.6.13" defer></script>
<script src="/assets/app.js?v=<?= h((string)@filemtime(__DIR__ . '/assets/app.js')) ?>" defer></script>
</body>
</html>
