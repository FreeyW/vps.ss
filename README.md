# [VPS.ss](https://vps.ss) VPS剩余价值计算器

根据 https://github.com/coco20652-netizen/vps-calculator

修改为php语言，增加了汇率API调用

## 功能

- 按天折算 VPS / 独服转让的剩余价值，实时汇率换算人民币
- 卖家溢价 / 实付金额双向反推，Push 手续费与 5% 中介担保费分摊
- 复制结果：纯文本 或 Markdown 表格
- 分享图片：把当前结果生成 SVG 卡片保存到服务器，链接形如 `https://vps.ss/share/xxxxxxxx.svg`，并提供 Markdown 与图片链接两种复制方式
- 分享链接：把当前参数写入 URL，无 JS 也可直出结果
- 日期选择使用自带的 [flatpickr](https://flatpickr.js.org/)（`assets/vendor/flatpickr/`，MIT 协议）日历控件，不依赖系统原生弹层；无 JS 时回退为原生 date 输入框

## 目录说明

| 路径 | 说明 |
| --- | --- |
| `index.php` | 页面入口（服务端计算 + 渲染） |
| `inc/calc.php` | 计算核心，与 `assets/app.js` 逻辑一致 |
| `inc/share.php` | 分享图 SVG 生成与存储 |
| `assets/vendor/flatpickr/` | 日期选择控件（本地托管） |
| `api/rates.php` | 汇率接口（服务端缓存代理） |
| `api/count.php` | 计算次数统计 |
| `api/share.php` | 生成分享图（POST，需同源） |
| `cache/` `data/` `share/` | 运行时自动创建：汇率缓存 / 计数 / 分享图，需对 PHP 进程可写 |
