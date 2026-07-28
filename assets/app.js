/* VPS 剩余价值计算器 · vps.ss
   前端实时计算，逻辑与 inc/calc.php 保持一致 */
(function () {
    'use strict';

    var CFG = window.VPS || {};
    var rates = CFG.rates || {};
    var MID = typeof CFG.midFee === 'number' ? CFG.midFee : 0.05;
    var RATE_DEC = CFG.rateDecimals || 3;

    var cycleDays = CFG.initialCycle || 365;
    var isDateTime = false;
    var lastInput = 'actualPaid';
    var toastTimer = null;

    var $ = function (id) { return document.getElementById(id); };
    var money = function (n, d) {
        d = d === undefined ? 2 : d;
        if (!isFinite(n)) { return '--'; }
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
    };
    var plain = function (n, d) { return isFinite(n) ? Number(n).toFixed(d) : '--'; };
    var num = function (v) { var f = parseFloat(v); return isNaN(f) ? null : f; };
    var localISO = function (d, withTime) {
        var t = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString();
        return withTime ? t.slice(0, 16) : t.slice(0, 10);
    };

    /* ---------------- 汇率面板 ---------------- */
    var gridKey = '';
    function renderRateGrid() {
        var grid = $('rateGrid');
        if (!grid) { return; }
        var base = $('currencySelector').value;
        var key = base + '|' + JSON.stringify(rates);
        if (key === gridKey) { return; }
        gridKey = key;
        var html = '';
        (CFG.rateGrid || []).forEach(function (code) {
            var r = rates[code];
            if (!r) { return; }
            html += '<div class="rate-cell' + (code === base ? ' is-base' : '') + '">' +
                '<div class="code">' + code + '</div>' +
                '<div class="value">' + Number(r).toFixed(RATE_DEC) + '</div></div>';
        });
        grid.innerHTML = html;
    }

    function refreshRates() {
        fetch('/api/rates.php', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.rates) { return; }
                rates = d.rates;
                var badge = $('rateStatus');
                if (badge) {
                    var ok = d.source === 'live' || d.source === 'cache';
                    badge.className = 'badge' + (ok ? '' : ' warn');
                    badge.innerHTML = '<i class="fas fa-' + (ok ? 'check' : 'triangle-exclamation') + '"></i> ' +
                        (ok ? d.date + ' 已更新' : '接口异常 · 使用备用汇率');
                }
                renderRateGrid();
                calculate();
            })
            .catch(function () { /* 保留服务端渲染的汇率 */ });
    }

    /* ---------------- 计数 ---------------- */
    function bumpCount() {
        fetch('/api/count.php', { method: 'POST', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) { return; }
                if ($('todayCount')) { $('todayCount').textContent = d.today; }
                if ($('totalCount')) { $('totalCount').textContent = d.total; }
            })
            .catch(function () {});
    }

    /* ---------------- 核心计算 ---------------- */
    function calculate() {
        var curr = $('currencySelector').value;
        $('customRateLabel').textContent = curr;

        var toggleBox = $('customRateToggleBox');
        var toggle = $('useCustomRate');
        if (curr === 'CNY') {
            toggle.checked = false;
            toggleBox.style.display = 'none';
        } else {
            toggleBox.style.display = 'inline-flex';
        }
        $('customRateWrapper').classList.toggle('hidden', !toggle.checked);

        var amount = num($('renewalAmount').value);
        var trade = new Date($('tradeDate').value);
        var expiry = new Date($('expiryDate').value);
        var days = (expiry - trade) / 86400000;

        saveConfig(curr, amount, toggle.checked);
        syncHidden();
        renderRateGrid();

        if (amount === null || !isFinite(days) || days <= 0) {
            return resetResults(days);
        }

        var rate = rates[curr] || 1;
        if (toggle.checked) {
            var cr = num($('customRateInput').value);
            if (cr !== null && cr > 0) { rate = cr; }
        }

        var resOrig = (amount / cycleDays) * days;
        var resCNY = resOrig * rate;

        var pushCurr = $('pushCurrLabel').textContent.trim();
        var push = (num($('pushFee').value) || 0) * (rates[pushCurr] || 1);
        var payer = $('pushPayer').value;
        var bPush = payer === 'buyer' ? push : (payer === 'split' ? push / 2 : 0);
        var sPush = payer === 'seller' ? push : (payer === 'split' ? push / 2 : 0);

        var actStr = $('actualPaid').value;
        var premStr = $('premiumInput').value;
        var actPaid = num(actStr);
        var prem = num(premStr);

        var isMid = $('useMiddleman').checked;
        var midWho = $('middlemanPayer').value;

        /* 双向反推 */
        if (lastInput === 'actualPaid') {
            if (actStr === '') {
                $('premiumInput').value = '';
                prem = null;
            } else if (actPaid !== null) {
                var sMid = 0;
                if (isMid) {
                    if (midWho === 'seller') { sMid = actPaid * MID; }
                    else if (midWho === 'split') { sMid = actPaid * MID / 2; }
                }
                prem = (actPaid - sPush - sMid) - resCNY;
                $('premiumInput').value = prem.toFixed(2);
            }
        } else {
            if (premStr === '') {
                $('actualPaid').value = '';
                actPaid = null;
            } else if (prem !== null) {
                if (isMid && midWho === 'seller') { actPaid = (resCNY + prem + sPush) / (1 - MID); }
                else if (isMid && midWho === 'split') { actPaid = (resCNY + prem + sPush) / (1 - MID / 2); }
                else { actPaid = resCNY + prem + sPush; }
                $('actualPaid').value = actPaid.toFixed(2);
            }
        }

        var bMid = 0;
        if (isMid && actPaid !== null && actPaid > 0) {
            var fee = actPaid * MID;
            if (midWho === 'buyer') { bMid = fee; }
            else if (midWho === 'split') { bMid = fee / 2; }
        }
        var totalCost = (actPaid || 0) + bPush + bMid;

        $('resCNYBig').textContent = '¥ ' + money(resCNY);
        $('resOrig').textContent = money(resOrig) + ' ' + curr;
        $('dayOfficial').textContent = '¥' + plain(resCNY / days, 3) + ' / 天';

        var pct = Math.min((days / cycleDays) * 100, 100);
        $('remainingDays').textContent = money(days, isDateTime ? 2 : 0) + ' 天';
        $('remainingDays').classList.remove('expired');
        $('remainingProgress').style.width = pct + '%';
        $('progressHint').textContent = '剩余 ' + pct.toFixed(1) + '% 周期';

        if (actPaid !== null && actPaid >= 0) {
            var extra = bPush + bMid;

            $('resActual').textContent = '¥ ' + money(actPaid);
            $('middlemanRow').classList.toggle('hidden', bMid <= 0);
            $('middlemanRowLabel').textContent = midWho === 'split' ? '中介费(AA)' : '买家中介费';
            $('resMiddleman').textContent = '+ ¥ ' + money(bMid);

            var positive = (prem || 0) >= 0;
            $('premiumBox').classList.remove('hidden');
            $('premiumTitle').textContent = positive ? '卖家溢价' : '卖家折价';
            $('premiumValueBig').textContent = (positive ? '+' : '') + '¥ ' + money(prem || 0);
            $('premiumTitle').className = 'premium-title ' + (positive ? 'is-premium' : 'is-discount');
            $('premiumValueBig').className = 'premium-value ' + (positive ? 'is-premium' : 'is-discount');

            $('dayActual').textContent = '¥' + plain(totalCost / days, 3) + ' / 天';
            $('finalTotalCostDisplay').textContent = '¥ ' + money(totalCost);
            $('extraFeeDetail').textContent = extra > 0
                ? '额外支出 (Push/中介) + ¥ ' + money(extra)
                : '无额外买家支出';
            $('finalCostBox').classList.toggle('active', extra > 0);

            var total = resCNY + Math.max(0, prem || 0);
            if (total <= 0) { total = 1; }
            $('valueRatioBox').classList.remove('hidden');
            $('barValue').style.width = (resCNY / total) * 100 + '%';
            $('barPremium').style.width = (Math.max(0, prem || 0) / total) * 100 + '%';
        } else {
            $('premiumBox').classList.add('hidden');
            $('resActual').textContent = '¥ --';
            $('middlemanRow').classList.add('hidden');
            $('dayActual').textContent = '--';
            $('valueRatioBox').classList.add('hidden');
            $('barValue').style.width = '0%';
            $('barPremium').style.width = '0%';
            $('finalTotalCostDisplay').textContent = '¥ --';
            $('extraFeeDetail').textContent = '含额外支出 ¥ 0.00';
            $('finalCostBox').classList.remove('active');
        }
    }

    function resetResults(days) {
        var expired = isFinite(days) && days <= 0;
        $('resCNYBig').textContent = '¥ --';
        $('remainingDays').textContent = expired ? '已到期' : '-- 天';
        $('remainingDays').classList.toggle('expired', expired);
        $('remainingProgress').style.width = '0%';
        $('progressHint').textContent = expired ? '该机器已在交易日前到期' : '';
        $('premiumBox').classList.add('hidden');
        $('valueRatioBox').classList.add('hidden');
        $('barValue').style.width = '0%';
        $('barPremium').style.width = '0%';
        $('dayOfficial').textContent = '--';
        $('dayActual').textContent = '--';
        $('resOrig').textContent = '--';
        $('resActual').textContent = '¥ --';
        $('middlemanRow').classList.add('hidden');
        $('finalTotalCostDisplay').textContent = '¥ --';
        $('extraFeeDetail').textContent = '含额外支出 ¥ 0.00';
        $('finalCostBox').classList.remove('active');
    }

    /* ---------------- 状态同步 ---------------- */
    function syncHidden() {
        $('cycleDaysInput').value = cycleDays;
        $('prevCycleInput').value = cycleDays;
        $('lastInputField').value = lastInput;
        $('isDateTimeField').value = isDateTime ? '1' : '';
        $('pushCurrencyField').value = $('pushCurrLabel').textContent.trim();
    }

    function saveConfig(curr, amount, useCustom) {
        try {
            localStorage.setItem('vps_config', JSON.stringify({
                amount: amount === null ? '' : amount,
                curr: curr,
                cycleDays: cycleDays,
                useCustom: useCustom,
                customRate: $('customRateInput').value,
                useMiddleman: $('useMiddleman').checked,
                middlemanPayer: $('middlemanPayer').value
            }));
        } catch (e) {}
    }

    function restoreConfig() {
        var mem = null;
        try { mem = JSON.parse(localStorage.getItem('vps_config')); } catch (e) {}
        if (!mem) { return false; }

        if (mem.amount !== undefined && mem.amount !== '') { $('renewalAmount').value = mem.amount; }
        if (mem.curr && $('currencySelector').querySelector('option[value="' + mem.curr + '"]')) {
            $('currencySelector').value = mem.curr;
        }
        if (mem.useCustom) {
            $('useCustomRate').checked = true;
            if (mem.customRate !== undefined) { $('customRateInput').value = mem.customRate; }
        }
        if (mem.useMiddleman) { $('useMiddleman').checked = true; }
        if (mem.middlemanPayer) { $('middlemanPayer').value = mem.middlemanPayer; }

        if (mem.cycleDays && mem.cycleDays !== cycleDays) {
            cycleDays = parseInt(mem.cycleDays, 10);
            setActiveCycle();
            var d = new Date($('tradeDate').value);
            if (!isNaN(d.getTime())) {
                d.setDate(d.getDate() + cycleDays);
                $('expiryDate').value = localISO(d, isDateTime);
            }
        }
        return true;
    }

    function setActiveCycle() {
        Array.prototype.forEach.call(document.querySelectorAll('.cycle-btn'), function (b) {
            b.classList.toggle('active', parseInt(b.dataset.cycle, 10) === cycleDays);
        });
    }

    /* ---------------- 事件绑定 ---------------- */
    function bind() {
        $('calcForm').addEventListener('submit', function (e) { e.preventDefault(); calculate(); });

        ['renewalAmount', 'currencySelector', 'tradeDate', 'expiryDate', 'actualPaid', 'premiumInput',
            'pushFee', 'pushPayer', 'useCustomRate', 'customRateInput', 'useMiddleman', 'middlemanPayer'
        ].forEach(function (id) {
            var el = $(id);
            if (!el) { return; }
            el.addEventListener('change', calculate);
            el.addEventListener('input', calculate);
        });

        $('actualPaid').addEventListener('focus', function () {
            lastInput = 'actualPaid';
            $('bindHint').innerHTML = '<i class="fas fa-lock"></i> 当前锁定【实付卖家】推算溢价';
        });
        $('premiumInput').addEventListener('focus', function () {
            lastInput = 'premiumInput';
            $('bindHint').innerHTML = '<i class="fas fa-lock"></i> 当前锁定【卖家溢价】推算实付';
        });

        Array.prototype.forEach.call(document.querySelectorAll('.cycle-btn'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                cycleDays = parseInt(btn.dataset.cycle, 10);
                setActiveCycle();
                var d = new Date($('tradeDate').value);
                if (!isNaN(d.getTime())) {
                    d.setDate(d.getDate() + cycleDays);
                    $('expiryDate').value = localISO(d, isDateTime);
                }
                calculate();
            });
        });

        $('toggleTimePrecision').addEventListener('click', function () {
            isDateTime = !isDateTime;
            this.classList.toggle('on', isDateTime);
            var t = $('tradeDate').value, x = $('expiryDate').value;
            $('tradeDate').type = isDateTime ? 'datetime-local' : 'date';
            $('expiryDate').type = isDateTime ? 'datetime-local' : 'date';
            if (isDateTime) {
                if (t && t.length === 10) { $('tradeDate').value = t + 'T00:00'; }
                if (x && x.length === 10) { $('expiryDate').value = x + 'T00:00'; }
            } else {
                if (t && t.length > 10) { $('tradeDate').value = t.slice(0, 10); }
                if (x && x.length > 10) { $('expiryDate').value = x.slice(0, 10); }
            }
            calculate();
        });

        var dd = $('pushCurrDropdown');
        $('pushCurrBtn').addEventListener('click', function (e) {
            e.stopPropagation();
            var open = dd.classList.toggle('open');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        Array.prototype.forEach.call(dd.querySelectorAll('.csel-option'), function (opt) {
            opt.addEventListener('click', function () {
                $('pushCurrLabel').textContent = opt.dataset.curr;
                dd.classList.remove('open');
                $('pushCurrBtn').setAttribute('aria-expanded', 'false');
                calculate();
            });
        });
        document.addEventListener('click', function () { dd.classList.remove('open'); });

        $('copyBtn').addEventListener('click', function () {
            var midText = '';
            if ($('useMiddleman').checked) {
                var act = num($('actualPaid').value) || 0;
                var v = act * MID;
                var who = $('middlemanPayer').value;
                if (who === 'buyer') {
                    midText = '\n买家付中介: ¥' + money(v) + '\n最终总支出: ' + $('finalTotalCostDisplay').textContent;
                } else if (who === 'split') {
                    midText = '\nAA平摊中介: ¥' + money(v / 2) + '\n最终总支出: ' + $('finalTotalCostDisplay').textContent;
                } else {
                    midText = '\n卖家扣中介: -¥' + money(v) + '\n买家总支出: ' + $('finalTotalCostDisplay').textContent;
                }
            }
            var text = '【VPS 剩余价值详情】\n剩余价值: ' + $('resCNYBig').textContent.trim() +
                '\n有效期: ' + $('remainingDays').textContent.trim() +
                '\n实付卖家: ' + $('resActual').textContent.trim() + midText +
                '\n综合日均: ' + $('dayActual').textContent.trim() +
                '\n── by ' + (CFG.brand || 'VPS.ss');
            copyText(text, '详情已复制，今日计算+1');
        });

        $('shareBtn').addEventListener('click', function () {
            var p = new URLSearchParams();
            ['renewalAmount', 'currencySelector', 'tradeDate', 'expiryDate', 'actualPaid',
                'premiumInput', 'pushFee', 'pushPayer', 'middlemanPayer'
            ].forEach(function (id) {
                var v = $(id).value;
                if (v !== '') { p.set(id, v); }
            });
            p.set('cycleDays', String(cycleDays));
            p.set('lastInput', lastInput);
            p.set('pushCurrency', $('pushCurrLabel').textContent.trim());
            if (isDateTime) { p.set('isDateTime', '1'); }
            if ($('useCustomRate').checked) {
                p.set('useCustomRate', '1');
                if ($('customRateInput').value !== '') { p.set('customRateInput', $('customRateInput').value); }
            }
            if ($('useMiddleman').checked) { p.set('useMiddleman', '1'); }
            copyText(location.origin + location.pathname + '?' + p.toString(), '状态专属链接已生成并复制');
        });

        $('resetBtn').addEventListener('click', function () {
            try { localStorage.removeItem('vps_config'); } catch (e) {}
            location.href = location.origin + location.pathname;
        });
    }

    function copyText(text, msg) {
        var done = function () { showToast(msg); bumpCount(); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
        } else {
            fallbackCopy(text);
            done();
        }
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }

    function showToast(msg) {
        clearTimeout(toastTimer);
        var t = $('toast');
        $('toastMsg').textContent = msg;
        t.classList.add('show');
        toastTimer = setTimeout(function () { t.classList.remove('show'); }, 2000);
    }

    /* ---------------- 初始化 ---------------- */
    function init() {
        isDateTime = $('isDateTimeField').value === '1';
        lastInput = $('lastInputField').value === 'premiumInput' ? 'premiumInput' : 'actualPaid';

        if (!CFG.hasParams) { restoreConfig(); }
        setActiveCycle();
        bind();
        calculate();
        refreshRates();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
