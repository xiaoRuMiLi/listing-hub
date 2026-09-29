# 中台商品规格同步修复 · 部署说明（宝塔 / Laravel）

> 补丁：`hub-patch-product-variants.tar.gz`
> 内容：① 新增 `product_variants`（指纹商品规格）写入 ② 新增变体级 `product_shipping`（逐规格×逐国运费）写入
> ③ pull/CSV 增加 `product_variants` / `product_shipping` 读取
> 目标站点根：`/www/wwwroot/cbc.weixiubang.club`，**解压到站点根，不是 app/ 或 migrations/ 子目录**

---

## 0. 补丁内容（1 改动文件）

| 文件 | 动作 |
|---|---|
| `server/app/Http/Controllers/Api/SyncController.php` | **改**：push 新增 `product_variants[]` + `variant_shipping[]` 处理；pull 新增 `product_variants` / `product_shipping` scope；CSV 新增两个 dataset |

> 无 migration、无模型新增（`product_variants` / `product_shipping` 表与模型**早已存在**，本次只补读写逻辑）。
> ⚠️ 本次**不需要** `php artisan migrate`。

---

## 1. 部署前必做（铁律）

```bash
# ① 备份当前 SyncController（回滚用）
cd /www/wwwroot/cbc.weixiubang.club
cp server/app/Http/Controllers/Api/SyncController.php \
   /root/SyncController.php.bak-$(date +%Y%m%d-%H%M%S)

# ② 核对补丁字节数 + SHA256（与交付清单一致）
sha256sum /root/hub-patch-product-variants.tar.gz
```

---

## 2. 部署

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 解压覆盖（tar 内路径已含 server/ 前缀；用 -P 保留路径）
tar -xzf /root/hub-patch-product-variants.tar.gz

# ② 清缓存（改过代码后必须）
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan view:clear
```

---

## 3. 验证

```bash
# ① 语法自检
php -l server/app/Http/Controllers/Api/SyncController.php

# ② 新 scope 可用性（应 200，product_variants/product_shipping 键存在）
php artisan tinker --execute="echo 'ok';"

# ③ 本地 CLI 侧（skills/hicustom-api）：
#    node scripts/hub.js import --ids 10809     # 推 10809（含 product_variants + variant_shipping）
#    node scripts/hub.js pull product_variants  # 拉回验证
#    node scripts/hub.js pull product_shipping  # 拉回验证
#    node scripts/hub.js pullcsv                # 合并拉回 *_variants.csv / product_shipping.csv
```

---

## 4. 回滚

```bash
# 代码回滚
cd /www/wwwroot/cbc.weixiubang.club
cp /root/SyncController.php.bak-<时间戳> server/app/Http/Controllers/Api/SyncController.php
php artisan config:clear && php artisan config:cache
```

> 无数据库结构变更 → **无需 migrate:rollback**。新增写入的行如需清理，手工 `DELETE` 即可。

---

## 5. 兼容性

- **老客户端**（不推 `product_variants` / `variant_shipping`）：服务端 `?? []` 兜底，**不报错**。
- **老数据**：`product_shipping` 里既有的商品级行（`product_id` 有值、`product_variant_id` 空）**保留不动**。
- **幂等键**：`product_variants` = `(product_id, external_variant_id)`；`product_shipping` = `(product_variant_id, country)` / `(product_id, country)`。
- **重复推送安全**：`updateOrCreate` 幂等，无重复行。

---

## 6. 验证结果（2026-09-29 实测 · 10809）

部署后 `node scripts/hub.js import --ids 10809` → 中台 REST 回读：

| 表 | 结果 |
|---|---|
| `product_variants` | **6 条**（ZSZ24B/MTV29R/9R5ZCF/3LG8AF/64QTR6/KJPBBJ，含 pkg/重量） |
| `product_shipping` 变体级 | **48 条**（6 规格 × 8 国） |
| `product_shipping` 商品级 | 8 条（默认规格代表值） |
| `listing_variants` | 18 条 |

服务器侧自检：`ProductShipping::whereNotNull('product_variant_id')->count()` = **48** ✅

> ⚠️ **回读提示**：变体级行只挂 `product_variant_id`（`product_id` 为空），按 `product_code` 筛选**看不到**；
> 应改为按 `external_variant_id` 非空 或 `whereNotNull('product_variant_id')` 筛选。
