# 中台改动方案：多规格物流费 / 多规格售价 / 父子变体关系

> 立项：2026-09-29 · 目标：本地 `listing_variants.csv`（逐规格物流费 + 逐规格售价）+ 父子关系 → 中台**可存、可拉、拉回即用**。
> 决策（已确认）：① 逐规格 8 国走 **JSON 桶**；② 商品级 8 国物流/售价**提升为物理列**；③ 旧子体数据**保留 + 迁移**到新表。
> 方案：**新建独立 `listing_variants` 表**（对齐本地 CSV），并扩展 `products` 物流/价格列为物理列。

---

## A. 现状与缺口

| 项 | 中台现状 | 缺口 |
|---|---|---|
| **变体存放** | 塞在 `listings` 同表（`is_parent=false` + `parent_sku` 关联） | 无独立表 |
| **多规格物流费** | `listings.shipping_fee`（单值）+ `products.profile_json.shipping_<CC>`（商品级 8 国） | ❌ 无**逐规格**物流费 |
| **多规格售价** | `listings.price/product_price`（逐 SKU 有值）+ `products.profile_json.price_<CC>`（商品级） | ⚠️ 有逐 SKU，但无**逐规格 8 国**售价 |
| **父子关系** | `parent_sku` + `parent_row_id`（指向 listing.id，**跨端对不上**） | ⚠️ 关联靠本地自增 `row_id` |
| **变体 pkg_** | ❌ 表头都无此列 | ❌ 逐规格包装尺寸/重量丢失（本地已有） |
| **逐规格运费明细** | ❌ 无（渠道/档位/分国） | ❌ |

---

## B. 新表 `listing_variants`（对齐本地 CSV 27 列）

### B.1 字段设计

| 中台列 | 类型 | 说明 | 本地对应 |
|---|---|---|---|
| `id` | bigint PK | 中台内部自增 | — |
| **`local_row_id`** | bigint **unique** | ★ 本地 `listing_variants.row_id`（两端一对一稳定键） | `row_id` |
| `parent_listing_id` | bigint FK→listings.id | 父体行 | — |
| `parent_local_row_id` | bigint | 父体本地 row_id | `parent_row_id` |
| `parent_sku` | varchar(64) | 冗余（按 SKU 反查） | `parent_sku` |
| `product_code` | varchar(64) | = 本地 `id` | `id` |
| `account_id` | bigint FK | 账号 | — |
| `platform` | varchar(24) | amazon | — |
| `marketplace` | varchar(16) | 站点 | `marketplace` |
| **`sku`** | varchar(64) | 子体 SKU | `sku` |
| `variation_theme` | varchar(24) | SIZE / COLOR / SIZE/COLOR | `variation_theme` |
| `variant_color` | varchar(32) | 款式（FS/NS/PF 语义） | `variant_color` |
| `variant_size` | varchar(16) | 尺码/尺寸 | `variant_size` |
| `variant_code` | varchar(16) | FS/NS/PF | `variant_code` |
| `variant_value` | varchar(64) | 维度值（组合） | `variant_value` |
| `design_code` | varchar(32) | 该子体设计 | `design_code` |
| `main_image` | text | 主图（OSS） | `main_image` |
| `other_images` | text | 附图（`|` 分隔，OSS） | `other_images` |
| **`price`** | decimal(12,2) | ★ 到手价（逐规格售价） | `price` |
| **`product_price`** | decimal(12,2) | ★ 商品价（逐规格售价） | `product_price` |
| **`shipping_fee`** | decimal(12,2) | ★ 运费档（逐规格物流费） | `shipping_fee` |
| `currency` | char(3) | 币种 | — |
| `quantity` | unsigned int | 库存 | `quantity` |
| **`pkg_length`** | decimal(10,2) | ★ 逐规格包装长 cm | `pkg_length` |
| **`pkg_width`** | decimal(10,2) | ★ 包装宽 cm | `pkg_width` |
| **`pkg_height`** | decimal(10,2) | ★ 包装高 cm | `pkg_height` |
| **`pkg_weight`** | decimal(10,2) | ★ 包装重 g | `pkg_weight` |
| `status` | enum | planned/ready/published/error/archived | `status` |
| `source` | varchar(48) | listing:family / spec:variants | `source` |
| `generated_at` | varchar(32) | 生成时间（原样字符串） | `generated_at` |
| `edited_at` | datetime | 编辑时间 | `edited_at` |
| `notes` | text | 备注 | `notes` |
| **`pricing_json`** | json | ★ 逐规格 8 国售价桶（见 B.2） | 扩展 |
| **`shipping_json`** | json | ★ 逐规格物流明细桶（见 B.3） | 扩展 |
| `pushed_by` | varchar(64) | 推送者留痕 | — |
| `created_at`/`updated_at` | timestamp | | — |

**索引**：`unique(local_row_id)`、`unique(account_id, platform, marketplace, sku)`、`index(parent_sku)`、`index(parent_listing_id)`、`index(product_code)`、`index(updated_at)`

### B.2 `pricing_json`（逐规格 8 国售价 · JSON 桶）
```json
{
  "currency": "GBP",
  "byCountry": {
    "US": { "price": 24.99, "product_price": 11.50, "shipping_fee": 6.99 },
    "UK": { "price": 18.98, "product_price": 8.99,  "shipping_fee": 9.99 },
    "DE": { ... } , "FR": {...}, "ES": {...}, "IT": {...}, "CA": {...}, "MX": {...}
  },
  "rate_note": "汇率 yyyy-mm-dd"
}
```
> 顶层 `price/product_price/shipping_fee` 保留（默认站点 UK 口径，便于单值筛选）。

### B.3 `shipping_json`（逐规格物流明细 · JSON 桶）
```json
{
  "byCountry": {
    "UK": { "amount": 9.99, "channel": "云途普货专线", "freight_template": "飞特6.99perp" },
    "US": { "amount": 12.50, "channel": "云途标快", "freight_template": "..." }
  },
  "weight_g": 105, "dims_cm": { "l": 30, "w": 20, "h": 3 }
}
```

---

## C. `products` 表补列（商品级 8 国物流/售价 → 物理列）

| 列组 | 列名 | 类型 |
|---|---|---|
| 运费 | `shipping_US/UK/CA/DE/MX/FR/ES/IT` | decimal(12,2) |
| 渠道 | `shipping_channel_<CC>` | varchar(64) |
| 运费模板 | `freight_template_<CC>` | varchar(64) |
| 售价 | `price_US/UK/CA/DE/MX/FR/ES/IT` | decimal(12,2) |
| 标记 | `shipping_variant` | varchar(64) | ← 本地有、中台完全无 |
| 时间 | `shipping_updated_at` | timestamp |

> 现有 `profile_json` 桶**保留**（向后兼容，不再作为权威；pull 时物理列优先）。

---

## D. 接口改动

| 端点 | 现状 | 改后 |
|---|---|---|
| `POST /api/v1/sync/push` | `listings[]` 里混父子 | **新增顶层 `variants[]`** → 写 `listing_variants` 表；`listings[]` 仅父体（子体仍兼容收但转存新表） |
| `GET /api/v1/sync/pull?scope=variants` | 无 | 返回 `variants` 数组（JSON） |
| `GET /api/v1/sync/pull?format=csv&dataset=listing_variants` | 从 listings 临时拼装 | **直读新表**（列对齐本地 CSV，含 pkg_* + 逐规格价） |
| `GET /api/v1/listings/{id}/children` | 按 `parent_sku` 查 listings | 改查 `listing_variants` 表 |
| `POST /api/v1/listings/oss-images` | 只处理 listings | 补 variants 图 |

**幂等键**：`local_row_id`（首选，跨端稳定）；回落 `(account_id, marketplace, sku)`。
**乐观锁**：沿用 `revision`（新表加 `revision` 列，可选）。

**兼容策略**：老 `listings` 子体行（`is_parent=false`）**保留不动**；迁移脚本搬入新表；pull 以新表为权威。

---

## E. 本地 `hub.js` 配套改动

1. `import`：把 `listing_variants.csv` 组装为顶层 **`variants[]`**（字段对齐 §B.1，含 `pkg_*`、`local_row_id=row_id`），不再塞进 `listings[]`。
2. `pull`：新增 `scope=variants`；`pullcsv` 的 `listing_variants` dataset 直读新表。
3. `ossify`：variants 图继续镜像 OSS（不变）。

---

## F. 迁移脚本（③ 保留 + 迁移）

`migrations/2026_09_29_000001_create_listing_variants_table.php`（幂等）：
1. 建 `listing_variants` 表；
2. `products` 补列（§C，幂等 `hasColumn`）；
3. **数据迁移**：`INSERT ... SELECT` 把 `listings WHERE is_parent=0` 搬入新表（`local_row_id = parent_row_id` 无法还原 → 先置空，以 `(account_id,marketplace,sku)` 兜底）；
4. 不回删旧行（保留可回滚）。

> ⚠️ 迁移按"铁律"：**幂等**（每列/索引先判断）、**附字节数 + SHA256**、解压到站点根。

---

## G. 交付物清单（代码阶段）

1. `database/migrations/2026_09_29_000001_create_listing_variants_table.php`
2. `app/Domain/Listing/Models/ListingVariant.php`（含 `$casts`：`pricing_json/shipping_json => array`）
3. `SyncController::push`（新增 variants 通道）+ `pull`（新增 scope + CSV 直读新表）
4. `ListingController::children`（改读新表）
5. 本地 `scripts/hub.js` 配套（§E）
6. 补丁 `hub-patch-variants.tar.gz` + SHA256 + 宝塔部署说明

---

## H. 数据质量提醒（非本次范围，但建议修）

- 当前本地 `listing_variants.design_code` 存的是**款式值**（如 `FaceStyle`），非真实 `design_code`（应如 `QIT4DCPE`）——推中台前建议核对口径。
