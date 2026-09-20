/* VPS 剩余价值计算器 · vps.ss
   前端实时计算，逻辑与 inc/calc.php 保持一致 */
(function () {
    'use strict';

    var CFG = window.VPS || {};
    var rates = CFG.rates || {};
    var MID = typeof CFG.midFee === 'number' ? CFG.midFee : 0.05;
    var RATE_DEC = CFG.rateDecimals || 3;
    var BRAND = CFG.brand || 'VPS.ss';

    var cycleDays = CFG.initialCycle || 365;
    var isDateTime = false;
    var lastInput = 'actualPaid';
    var toastTimer = null;
    var R = null;   // 最近一次有效计算结果（供复制 / 分享使用）

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
    var fmtDate = function (v) { return String(v || '').replace('T', ' '); };
    var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

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
    function updateCounts(d) {
        if (!d) { return; }
        if ($('todayCount') && d.today !== undefined) { $('todayCount').textContent = d.today; }
        if ($('totalCount') && d.total !== undefined) { $('totalCount').textContent = d.total; }
    }

    function bumpCount() {
        fetch('/api/count.php', { method: 'POST', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(updateCounts)
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
        var customUsed = false;
        if (toggle.checked) {
            var cr = num($('customRateInput').value);
            if (cr !== null && cr > 0) { rate = cr; customUsed = true; }
        }

        var resOrig = (amount / cycleDays) * days;
        var resCNY = resOrig * rate;

        var pushCurr = $('pushCurrLabel').textContent.trim();
        var pushFee = num($('pushFee').value) || 0;
        var push = pushFee * (rates[pushCurr] || 1);
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
        var midFee = 0;
        if (isMid && actPaid !== null && actPaid > 0) {
            midFee = actPaid * MID;
            if (midWho === 'buyer') { bMid = midFee; }
            else if (midWho === 'split') { bMid = midFee / 2; }
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

        var hasPaid = actPaid !== null && actPaid >= 0;
        if (hasPaid) {
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

        R = {
            curr: curr, amount: amount, cycleDays: cycleDays, rate: rate, customUsed: customUsed,
            tradeDate: $('tradeDate').value, expiryDate: $('expiryDate').value,
            days: days, pct: pct, resOrig: resOrig, resCNY: resCNY,
            hasPaid: hasPaid, actPaid: actPaid, prem: prem || 0,
            pushFee: pushFee, pushCurr: pushCurr, pushPayer: payer,
            isMid: isMid, midWho: midWho, midFee: midFee, bMid: bMid, bPush: bPush,
            totalCost: totalCost
        };
    }

    function resetResults(days) {
        R = null;
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

    /* ---------------- 结果导出（纯文本 / Markdown 表格） ---------------- */
    function resultRows() {
        if (!R) { return null; }
        var rows = [];
        var cycleLabel = (CFG.cycles && CFG.cycles[R.cycleDays]) || (R.cycleDays + ' 天');
        var payerLabels = CFG.pushPayerLabels || {};

        rows.push(['续费金额', money(R.amount) + ' ' + R.curr + ' · ' + cycleLabel]);
        if (R.curr !== 'CNY') {
            rows.push(['汇率', '1 ' + R.curr + ' = ' + plain(R.rate, RATE_DEC) + ' CNY' + (R.customUsed ? ' (自定义)' : '')]);
        }
        rows.push(['交易日期', fmtDate(R.tradeDate)]);
        rows.push(['到期日期', fmtDate(R.expiryDate)]);
        rows.push(['剩余天数', money(R.days, isDateTime ? 2 : 0) + ' 天 / ' + R.cycleDays + ' 天 (' + R.pct.toFixed(1) + '%)']);
        rows.push(['剩余价值', '¥ ' + money(R.resCNY) + (R.curr !== 'CNY' ? ' ≈ ' + money(R.resOrig) + ' ' + R.curr : '')]);
        rows.push(['官方日均', '¥' + plain(R.resCNY / R.days, 3) + ' / 天']);

        if (R.hasPaid) {
            rows.push(['实付卖家', '¥ ' + money(R.actPaid)]);
            rows.push([R.prem >= 0 ? '卖家溢价' : '卖家折价', (R.prem >= 0 ? '+' : '-') + '¥ ' + money(Math.abs(R.prem))]);
            if (R.pushFee > 0) {
                rows.push(['Push 费', money(R.pushFee) + ' ' + R.pushCurr + ' · ' + (payerLabels[R.pushPayer] || R.pushPayer)]);
            }
            if (R.isMid) {
                if (R.midWho === 'buyer') { rows.push(['买家付中介', '¥ ' + money(R.midFee)]); }
                else if (R.midWho === 'split') { rows.push(['AA平摊中介', '¥ ' + money(R.midFee / 2)]); }
                else { rows.push(['卖家扣中介', '-¥ ' + money(R.midFee)]); }
            }
            rows.push(['买家总支出', '¥ ' + money(R.totalCost)]);
            rows.push(['综合日均', '¥' + plain(R.totalCost / R.days, 3) + ' / 天']);
        }
        return rows;
    }

    function formatText(rows) {
        var lines = ['【VPS 剩余价值详情】'];
        rows.forEach(function (r) { lines.push(r[0] + ': ' + r[1]); });
        lines.push('── by ' + BRAND);
        return lines.join('\n');
    }

    function formatMarkdown(rows) {
        var esc = function (s) { return String(s).replace(/\|/g, '\\|'); };
        var lines = ['**VPS 剩余价值详情**', '', '| 项目 | 数值 |', '| :-- | --: |'];
        rows.forEach(function (r) { lines.push('| ' + esc(r[0]) + ' | ' + esc(r[1]) + ' |'); });
        lines.push('');
        lines.push('由 [' + BRAND + '](' + shareURL() + ') 提供计算服务');
        return lines.join('\n');
    }

    /* ---------------- 分享参数 / 链接 ---------------- */
    function shareParams() {
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
        return p;
    }

    function shareURL() {
        return location.origin + location.pathname + '?' + shareParams().toString();
    }

    /* ---------------- 分享图（SVG） ---------------- */
    function openShareModal(d) {
        var modal = $('shareModal');
        var img = $('sharePreviewImg');
        img.src = d.url;
        img.alt = d.title || 'VPS 剩余价值分享图';
        $('sharePreviewLink').href = d.url;
        $('shareMdInput').value = d.markdown || ('![' + (d.title || 'VPS 剩余价值') + '](' + d.url + ')');
        $('shareUrlInput').value = d.url;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeShareModal() {
        var modal = $('shareModal');
        if (!modal.classList.contains('open')) { return; }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function generateShareImage(btn) {
        if (!R) { showToast('请先填写续费金额与有效日期', true); return; }
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 生成中…';
        var restore = function () { btn.disabled = false; btn.innerHTML = original; };

        fetch('/api/share.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: shareParams().toString()
        })
            .then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, d: d }; }, function () { return { ok: false, d: null }; });
            })
            .then(function (res) {
                restore();
                if (!res.ok || !res.d || !res.d.url) {
                    showToast((res.d && res.d.message) || '生成分享图失败，请稍后重试', true);
                    return;
                }
                updateCounts(res.d);
                openShareModal(res.d);
            })
            .catch(function () {
                restore();
                showToast('网络异常，生成分享图失败', true);
            });
    }

    /* ---------------- 日期选择器（flatpickr，替代系统原生控件） ---------------- */
    var pickers = {};
    var DATE_IDS = ['tradeDate', 'expiryDate'];

    function pickerLocale() {
        var fp = window.flatpickr;
        return (fp && fp.l10ns && fp.l10ns.zh) ? fp.l10ns.zh : 'default';
    }

    function createPicker(id) {
        var fp = window.flatpickr;
        var el = $(id);
        if (!fp || !el) { return null; }
        var picker = fp(el, {
            enableTime: isDateTime,
            time_24hr: true,
            minuteIncrement: 1,
            dateFormat: isDateTime ? 'Y-m-dTH:i' : 'Y-m-d',
            altInput: true,
            altFormat: isDateTime ? 'Y-m-d H:i' : 'Y-m-d',
            altInputClass: 'field-input',
            allowInput: true,
            disableMobile: true,
            locale: pickerLocale(),
            onChange: function () { calculate(); }
        });
        if (picker.altInput) {
            picker.altInput.id = id + 'Text';
            picker.altInput.setAttribute('autocomplete', 'off');
            picker.altInput.setAttribute('inputmode', 'none');
            picker.altInput.setAttribute('placeholder', isDateTime ? 'YYYY-MM-DD HH:MM' : 'YYYY-MM-DD');
            var label = $(id + 'Label');
            if (label) {
                picker.altInput.setAttribute('aria-label', label.textContent.trim());
                label.setAttribute('for', picker.altInput.id);
            }
        }
        if (el.parentNode && el.parentNode.classList.contains('date-field')) {
            el.parentNode.classList.add('has-picker');
        }
        return picker;
    }

    function initPickers() {
        DATE_IDS.forEach(function (id) { pickers[id] = createPicker(id); });
    }

    function destroyPickers() {
        DATE_IDS.forEach(function (id) {
            var p = pickers[id];
            if (p) { try { p.destroy(); } catch (e) {} }
            pickers[id] = null;
            var el = $(id);
            if (el && el.parentNode && el.parentNode.classList) { el.parentNode.classList.remove('has-picker'); }
            var label = $(id + 'Label');
            if (label) { label.setAttribute('for', id); }
        });
    }

    /* 写入日期值（同步到选择器显示） */
    function setDateValue(id, v) {
        var p = pickers[id];
        if (p) { p.setDate(v, false); } else { $(id).value = v; }
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
        if (mem.curr) {
            setCselByValue('currencySelectorDropdown', 'currencySelectorLabel', 'currencySelector', mem.curr);
        }
        if (mem.useCustom) {
            $('useCustomRate').checked = true;
            if (mem.customRate !== undefined) { $('customRateInput').value = mem.customRate; }
        }
        if (mem.useMiddleman) { $('useMiddleman').checked = true; }
        if (mem.middlemanPayer) {
            setCselByValue('middlemanPayerDropdown', 'middlemanPayerLabel', 'middlemanPayer', mem.middlemanPayer);
        }

        if (mem.cycleDays && mem.cycleDays !== cycleDays) {
            cycleDays = parseInt(mem.cycleDays, 10);
            setActiveCycle();
            var d = new Date($('tradeDate').value);
            if (!isNaN(d.getTime())) {
                d.setDate(d.getDate() + cycleDays);
                setDateValue('expiryDate', localISO(d, isDateTime));
            }
        }
        return true;
    }

    /* ---------------- 自定义下拉 (csel-wrap) ---------------- */
    function closeDropdowns(except) {
        each(document.querySelectorAll('.csel-dropdown.open'), function (o) {
            if (o !== except) {
                o.classList.remove('open');
                var b = o.parentNode && o.parentNode.querySelector('[aria-expanded]');
                if (b) { b.setAttribute('aria-expanded', 'false'); }
            }
        });
    }

    function initCsel(opts) {
        var btn = $(opts.btnId), dd = $(opts.ddId), label = $(opts.labelId), hidden = opts.hiddenId ? $(opts.hiddenId) : null;
        if (!btn || !dd) { return; }
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeDropdowns(dd);
            var open = dd.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        each(dd.querySelectorAll('.csel-option'), function (opt) {
            opt.addEventListener('click', function () {
                if (label) { label.textContent = opt.dataset.label || opt.textContent.trim(); }
                if (hidden) { hidden.value = opt.dataset.value; }
                each(dd.querySelectorAll('.csel-option'), function (o) { o.classList.toggle('active', o === opt); });
                dd.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
                calculate();
            });
        });
    }

    function setCselByValue(ddId, labelId, hiddenId, value) {
        var dd = $(ddId);
        if (!dd) { return false; }
        var opt = dd.querySelector('.csel-option[data-value="' + value + '"]');
        if (!opt) { return false; }
        if ($(labelId)) { $(labelId).textContent = opt.dataset.label || opt.textContent.trim(); }
        if ($(hiddenId)) { $(hiddenId).value = value; }
        each(dd.querySelectorAll('.csel-option'), function (o) { o.classList.toggle('active', o === opt); });
        return true;
    }

    function setActiveCycle() {
        each(document.querySelectorAll('.cycle-btn'), function (b) {
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

        each(document.querySelectorAll('.cycle-btn'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                cycleDays = parseInt(btn.dataset.cycle, 10);
                setActiveCycle();
                var d = new Date($('tradeDate').value);
                if (!isNaN(d.getTime())) {
                    d.setDate(d.getDate() + cycleDays);
                    setDateValue('expiryDate', localISO(d, isDateTime));
                }
                calculate();
            });
        });

        $('toggleTimePrecision').addEventListener('click', function () {
            isDateTime = !isDateTime;
            this.classList.toggle('on', isDateTime);
            var t = $('tradeDate').value, x = $('expiryDate').value;
            destroyPickers();
            $('tradeDate').type = isDateTime ? 'datetime-local' : 'date';
            $('expiryDate').type = isDateTime ? 'datetime-local' : 'date';
            if (isDateTime) {
                if (t && t.length === 10) { $('tradeDate').value = t + 'T00:00'; }
                if (x && x.length === 10) { $('expiryDate').value = x + 'T00:00'; }
            } else {
                if (t && t.length > 10) { $('tradeDate').value = t.slice(0, 10); }
                if (x && x.length > 10) { $('expiryDate').value = x.slice(0, 10); }
            }
            initPickers();
            calculate();
        });

        initCsel({ btnId: 'currencySelectorBtn', ddId: 'currencySelectorDropdown', labelId: 'currencySelectorLabel', hiddenId: 'currencySelector' });
        initCsel({ btnId: 'pushCurrBtn', ddId: 'pushCurrDropdown', labelId: 'pushCurrLabel' });
        initCsel({ btnId: 'pushPayerBtn', ddId: 'pushPayerDropdown', labelId: 'pushPayerLabel', hiddenId: 'pushPayer' });
        initCsel({ btnId: 'middlemanPayerBtn', ddId: 'middlemanPayerDropdown', labelId: 'middlemanPayerLabel', hiddenId: 'middlemanPayer' });

        document.addEventListener('click', function () { closeDropdowns(null); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeDropdowns(null); closeShareModal(); }
        });

        /* 复制详情：纯文本 / Markdown 表格 */
        var copyBtn = $('copyBtn'), copyMenu = $('copyMenu');
        copyBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeDropdowns(copyMenu);
            var open = copyMenu.classList.toggle('open');
            copyBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        each(copyMenu.querySelectorAll('[data-format]'), function (opt) {
            opt.addEventListener('click', function (e) {
                e.stopPropagation();
                copyMenu.classList.remove('open');
                copyBtn.setAttribute('aria-expanded', 'false');
                var rows = resultRows();
                if (!rows) { showToast('请先填写续费金额与有效日期', true); return; }
                if (opt.dataset.format === 'markdown') {
                    copyText(formatMarkdown(rows), 'Markdown 表格已复制，今日计算+1');
                } else {
                    copyText(formatText(rows), '详情已复制，今日计算+1');
                }
            });
        });

        /* 分享图 */
        $('shareImgBtn').addEventListener('click', function () { generateShareImage(this); });
        each(document.querySelectorAll('#shareModal [data-close]'), function (el) {
            el.addEventListener('click', closeShareModal);
        });
        each(document.querySelectorAll('#shareModal [data-copy]'), function (btn) {
            btn.addEventListener('click', function () {
                var input = $(btn.dataset.copy);
                if (!input || !input.value) { return; }
                copyText(input.value, btn.dataset.msg || '已复制', true);
            });
        });
        each(document.querySelectorAll('#shareModal .share-input'), function (input) {
            input.addEventListener('focus', function () { this.select(); });
            input.addEventListener('click', function () { this.select(); });
        });

        $('shareBtn').addEventListener('click', function () {
            copyText(shareURL(), '状态专属链接已生成并复制');
        });

        $('resetBtn').addEventListener('click', function () {
            try { localStorage.removeItem('vps_config'); } catch (e) {}
            location.href = location.origin + location.pathname;
        });
    }

    function copyText(text, msg, noCount) {
        var done = function () { showToast(msg); if (!noCount) { bumpCount(); } };
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

    function showToast(msg, isError) {
        clearTimeout(toastTimer);
        var t = $('toast');
        $('toastMsg').textContent = msg;
        t.querySelector('i').className = isError ? 'fas fa-circle-exclamation' : 'fas fa-circle-check';
        t.classList.toggle('error', !!isError);
        t.classList.add('show');
        toastTimer = setTimeout(function () { t.classList.remove('show'); }, isError ? 2800 : 2000);
    }

    /* ---------------- 初始化 ---------------- */
    function init() {
        isDateTime = $('isDateTimeField').value === '1';
        lastInput = $('lastInputField').value === 'premiumInput' ? 'premiumInput' : 'actualPaid';

        if (!CFG.hasParams) { restoreConfig(); }
        setActiveCycle();
        bind();
        initPickers();
        calculate();
        refreshRates();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
