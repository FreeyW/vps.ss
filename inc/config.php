<?php
/**
 * 全局配置
 * VPS 剩余价值计算器 · https://vps.ss
 */

declare(strict_types=1);

// ---------- 站点信息 ----------
const SITE_URL      = 'https://vps.ss';
const SITE_NAME     = 'VPS 剩余价值计算器';
const SITE_BRAND    = 'VPS.ss';
const SITE_TITLE    = 'VPS 剩余价值计算器 - 服务器转让剩余价值/溢价在线计算 | VPS.ss';
const SITE_DESC     = 'VPS 剩余价值计算器：输入续费金额、付款周期与到期日期，自动按天折算 VPS/服务器转让的剩余价值，实时汇率换算为人民币，并支持卖家溢价、Push 手续费、5% 中介担保费的双向反推与总支出核算。';
const SITE_KEYWORDS = 'VPS剩余价值计算器,剩余价值计算,VPS转让计算器,服务器剩余价值,VPS溢价计算,按天折算,VPS交易,Push费用,中介担保费,汇率换算';

// ---------- 汇率 ----------
const RATE_API      = 'https://latest.currency-api.pages.dev/v1/currencies/usd.json';
const RATE_CACHE    = __DIR__ . '/../cache/rates.json';
const RATE_TTL      = 3600;   // 汇率缓存有效期（秒）
const RATE_DECIMALS = 3;      // 汇率显示保留小数位

// 计价币种（与原页面保持一致）
const CURRENCIES = [
    'USD' => '美元',
    'CNY' => '人民币',
    'EUR' => '欧元',
    'GBP' => '英镑',
    'JPY' => '日元',
    'HKD' => '港币',
    'TWD' => '新台币',
];

// 汇率面板展示的币种（与原页面保持一致）
const RATE_GRID = ['USD', 'EUR', 'GBP', 'JPY', 'HKD', 'TWD', 'KRW', 'SGD', 'AUD', 'CAD'];

// 接口不可用时的兜底汇率（1 外币 = ? CNY）
const RATE_FALLBACK = [
    'USD' => 6.768, 'EUR' => 7.701, 'GBP' => 9.000, 'JPY' => 0.041, 'HKD' => 0.863,
    'TWD' => 0.209, 'KRW' => 0.005, 'SGD' => 5.240, 'AUD' => 4.734, 'CAD' => 4.794,
    'CNY' => 1.0,
];

// ---------- 计数器 ----------
const COUNTER_FILE = __DIR__ . '/../data/counter.json';
const COUNTER_KEEP_DAYS = 60;   // 每日计数保留天数

// ---------- 业务常量 ----------
const MIDDLEMAN_FEE_RATE = 0.05;   // 中介担保费比例
const CYCLES = [
    30   => '月付',
    91   => '季付',
    182  => '半年',
    365  => '年付',
    730  => '两年',
    1095 => '三年',
];

date_default_timezone_set('Asia/Shanghai');

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
