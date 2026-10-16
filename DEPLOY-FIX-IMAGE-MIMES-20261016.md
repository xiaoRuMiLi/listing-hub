# 中台 · 图片「类型丢失 → .bin」修复补丁 · 部署说明（宝塔 / Laravel 11 · PHP 8.3）

> 变更日：**2026-10-16**
> 目的：修中台图床的 **`*.bin` / `application/octet-stream`** 问题 —— 图片其实已镜像到 OSS，
> 但**文件类型丢了**，亚马逊会判为非法图、浏览器会「下载」而不是显示，看起来就像「换图床没生效」。
> 目标站点根：`/www/wwwroot/cbc.weixiubang.club`（**解压到站点根**，包里路径已对齐 `app/...`、`routes/...`）

---

## 0. 为什么要改中台（根因）

推送端上传/镜像时若没带（或带了）`application/octet-stream`，中台 `MediaService::extFor()` 只认白名单 →
一律落成 **`.bin`**。更麻烦的是：内容按 **sha256 去重**，**同一张图重传也只会拿回原来那个 `.bin`**，
所以光在推送端改（或重传）是修不回来的 —— 必须中台侧「按文件头重新定类型」。

实测（2026-10-16）：今天 15:07 推到中台的 6 个商品（12115 / 11801 / 12671 / 12461 / 12495 / 12492）
**50 张图全是 `.bin`**；中台其它 32 个 listing 的 883 张图都正常是 `.jpg`。

---

## 1. 改动清单（3 改 · 无迁移）

| 文件 | 动作 |
|---|---|
| `app/Domain/Asset/Services/MediaService.php` | **改**：新增 `isMediaMime()` / `sniffMime()` / `resolveMime()`；`storeBytes()` 落库前按**文件头**纠正 mime；**去重命中**时若旧 blob 是未知类型而这次是真图片类型 → **升级重建**（同 sha、换正确后缀） |
| `app/Http/Controllers/Api/MaintenanceController.php` | **改**：新增 `fixImageMimes()`（管理员）—— 扫描 `.bin`/未知类型 blob → 嗅探 → 重建 OSS 对象 → 更新 blob 行 → **改写 designs / listings / listing_variants 里引用旧 URL 的列**（含 `attrs_json`/`copy_json`/`variant_json` 这些 JSON 桶） |
| `routes/api.php` | **改**：新增 `GET|POST maintenance/fix-image-mimes`（在 `maintenance/trashed` 下面一行） |

> 无数据库迁移。修好后**幂等**：再跑一次会把它们当「已知类型」跳过。

---

## 2. 部署

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 备份（铁律）：只改代码，可只备份这 3 个文件
cp app/Domain/Asset/Services/MediaService.php /root/MediaService.php.bak-$(date +%F)
cp app/Http/Controllers/Api/MaintenanceController.php /root/MaintenanceController.php.bak-$(date +%F)
cp routes/api.php /root/api.php.bak-$(date +%F)

# ② 上传 hub-patch-fix-image-mimes-20261016.tar.gz 到站点根 → 解压（覆盖）

# ③ 语法自检（强烈建议）
/www/server/php/83/bin/php -l app/Domain/Asset/Services/MediaService.php
/www/server/php/83/bin/php -l app/Http/Controllers/Api/MaintenanceController.php
/www/server/php/83/bin/php -l routes/api.php

# ④ 清路由缓存（动了 routes/api.php，必须）
/www/server/php/83/bin/php artisan route:clear && /www/server/php/83/bin/php artisan route:cache
```

---

## 3. 修复存量图片（关键一步）

先**空跑**看会改什么（不写任何东西）：

```bash
curl -s -X POST 'https://cbc.weixiubang.club/api/v1/maintenance/fix-image-mimes?dry_run=1&limit=100' \
  -H "Authorization: Bearer <你的登录 token>" -H 'Accept: application/json'
```

返回形如：`{"ok":true,"data":{"dry_run":true,"scanned":159,"fixed":159,"refs":{...},"items":[...]}}`

确认无误后**真跑**（可分批，`limit` 最大 500）：

```bash
curl -s -X POST 'https://cbc.weixiubang.club/api/v1/maintenance/fix-image-mimes?limit=200' \
  -H "Authorization: Bearer <你的登录 token>" -H 'Accept: application/json'
# 反复跑到 fixed=0 为止
```

> 或者让 C-3PO 用本地 `hub.json` 账号直接帮你调这个接口（脚本已备好）。
> 期望结果：那 6 个商品的 `main_image` / `other_images` 从 `…f2.bin` 变成 `…f2.jpg`，且 URL 可 200 打开、`Content-Type: image/jpeg`。

---

## 4. 验证

```bash
# ① 新上传（不带 type）也应该落成 .jpg —— 说明嗅探生效
#    在 ERP 里随便上传一张效果图，看返回的 URL 后缀
# ② 中台数据里 .bin 归零
curl -s 'https://cbc.weixiubang.club/api/v1/sync/pull?format=csv&dataset=designs' -H "Authorization: Bearer <token>" | grep -c '\.bin'
```

---

## 5. 回滚

```bash
cp /root/MediaService.php.bak-<date> app/Domain/Asset/Services/MediaService.php
cp /root/MaintenanceController.php.bak-<date> app/Http/Controllers/Api/MaintenanceController.php
cp /root/api.php.bak-<date> routes/api.php
/www/server/php/83/bin/php artisan route:clear && /www/server/php/83/bin/php artisan route:cache
```

> 注意：修复动作会把 OSS 对象**另存一份**（正确后缀），旧的 `.bin` 对象**不删**（留痕，不破坏任何引用）。
> 想清旧对象可以以后手工删 `*.bin`；不建议立即删。

---

## 6. 配套（推送端 / ERP）

- ERP `scripts/server/serve.js` 的 `POST /api/listing/effect/upload`：上传前按**文件头**纠正 mime（dataUrl 带
  `application/octet-stream` 时不再原样递给中台）。→ 已改，各机器重启 ERP 生效（`node scripts/server/erp.js restart`）。
