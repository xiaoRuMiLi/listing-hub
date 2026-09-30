# 部署说明 · 中台 R14（2026-09-30）· 按指纹 ID 拉取

> 补丁：`hub-patch-pull-ids-20260930.tar.gz`
> SHA256：`1a17350eecbd75323de3ba1ab42860c2e03d291f18c1c35afa3da72080dded25`（14798 字节）
> **站点根 = 应用根**（补丁内路径已对齐 `app/...`，**无 `server/` 前缀**）
> 解压目标：`/www/wwwroot/cbc.weixiubang.club`

## 1. 改的是什么（1 文件）

| 文件 | 动作 | 说明 |
|---|---|---|
| `app/Http/Controllers/Api/SyncController.php` | **改** | `pull` / `pullCsvOne` 增加 `ids` 过滤（+42/-2） |

**新增能力**：`GET /api/v1/sync/pull` 支持 `ids` 查询参数（逗号分隔指纹 ID）。

```
GET /api/v1/sync/pull?format=csv&dataset=listing_copy&ids=12674,12680
GET /api/v1/sync/pull?scope=products,designs,listings&ids=12674,12680
```

- `ids` **为空 = 全量**（与旧行为完全一致，向后兼容）。
- 命中范围 = 该指纹 ID 的**全部关联表**：

| dataset / scope | 过滤列 | 关联方式 |
|---|---|---|
| `products` | `code` | 直接 |
| `designs` | `product_id` | `code → id` 映射 |
| `product_variants` | `product_id` | `code → id` 映射 |
| `listing_copy`（listings 父体） | `product_code` | 物理列 |
| `listing_variants`（variants 子体） | `product_code` | 物理列 |
| `product_shipping` | `product_id` **OR** `product_variant_id` | 商品级 + **变体级（product_id 可能为空）两路** |

**设计要点**：`product_shipping` 变体级行的 `product_id` 可能为空 → 只按 `product_id` 过滤会漏掉逐规格运费，故**两路 OR 合并**；无匹配时返回空（`whereRaw('1 = 0')`），**绝不误拉全量**。

> ⚠️ 无 migration；改动仅查询侧，不改表结构。
> **必须刷 config 缓存**（同既往补丁惯例）。

## 2. 部署

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 备份
mkdir -p /root/pre-r14 && cp -a app/Http/Controllers/Api/SyncController.php /root/pre-r14/

# ② 校验 + 解压
sha256sum hub-patch-pull-ids-20260930.tar.gz
#   应 = 1a17350eecbd75323de3ba1ab42860c2e03d291f18c1c35afa3da72080dded25
tar -xzf hub-patch-pull-ids-20260930.tar.gz

# ③ 语法 + 刷缓存
php -l app/Http/Controllers/Api/SyncController.php
php artisan config:clear && php artisan config:cache
```

## 3. 部署后验证

```bash
# 本机（客户端）：按 ID 拉取 12674 全部关联数据 + 重建（不下图）
node scripts/hub.js hub:pull --ids 12674
```

期望：
- `GET /sync/pull?...&ids=12674` 只返回 12674 的行；
- 各 dataset 命中行数：products=1、designs=3、listing_copy=1、listing_variants=3、product_variants=1、product_shipping=1(商品级)+N(变体级)；
- 拉回后 `listing:rebuild` 补出 `output/12674/product.json` + `listing/record.json`。

**回归（确认旧行为未坏）**：
```bash
node scripts/hub.js pullcsv          # 不带 ids → 全量合并，行为应与部署前一致
```

---

## 附：改动摘要（R14）

- `pull()`：解析 `$ids`；CSV 分支透传；JSON 各 scope 加 `whereIn` 过滤。
- `pullCsvOne()`：签名加 `array $ids = []`；6 个 case 各自过滤。
- 新增 `productIdsOfCodes()` / `scopeShippingToCodes()` 两个私有方法。
- **未动** `push` 及任何其它文件/表结构。
