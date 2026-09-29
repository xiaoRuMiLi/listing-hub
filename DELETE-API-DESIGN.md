# 中台删除 API 设计（含软删 + 级联 + 权限）

> 状态：**已实现**（2026-09-29；待部署验证）
> 决策来源：2026-09-29 用户口径
> 目标：给 listing-hub 补「删一个商品 / 删子资源」的能力，支持软删可恢复 + 级联 + 权限隔离。
> 索引实测：2026-09-29（`product_shipping` 有 2 个 unique、`designs` 有 1 个 unique、`product_variants` 无 unique）
> 首个管理员：`admin@weixiubang.club`（可用 env `HUB_ADMIN_EMAIL` 覆盖）
> 交付物：见 §8。

---

## 0. 决策摘要（用户已定）

| 问题 | 决策 |
|---|---|
| **Q1 粒度** | **B**：整体删 + 分部删都做 |
| **Q2 软硬** | **A**：软删（`deleted_at`，可恢复） |
| **Q3 已上架** | **B**：允许连带删 listings，但**必须 `?force=true`**；不带则拒删并提示 N 个已上架 |
| **Q4 共享 design** | **B**：解绑（只删关联），**并顺带软删孤儿 design** |
| **Q5 权限** | **B**：非管理员只删自己建的（`pushed_by` 匹配）；管理员删任意 |
| **从属表软删** | **①A**：给 `designs` / `product_variants` / `product_shipping` 补 `deleted_at` |
| **unique 冲突** | **②A**：unique 改复合 `(..., deleted_at)` |
| **管理员判定** | **(a)** 加 `users.is_admin BOOLEAN DEFAULT 0`（**待定**首个管理员 email） |
| **孤儿 design 口径** | **B**：无任何活 listing 引用即孤儿 |
| **分部删权限** | **A**：同 Q5（非管理员只删自己推的） |

---

## 1. 数据模型：一个商品牵涉的表

```
products (1)
├── product_suppliers (N)         从属 → 级联软删
├── product_variants (N)          从属 → 级联软删（**需补 deleted_at**）
│   └── product_shipping (N)      从属 → 级联软删（**需补 deleted_at**）
├── product_shipping (N, product_id)  商品级运费 → 级联软删
├── designs (N)                   从属 → 级联软删（**需补 deleted_at**）+ 孤儿判定
│   └── listings (N)              从属 → 级联软删（有 deleted_at）
│       └── listing_variants (N)  从属 → 级联软删（有 deleted_at）
└── blobs / assets (N)             **共享·内容寻址 → 永不删**（孤儿无害）
```

**关键区分**：
- **从属（cascade soft-delete）**：随商品走
- **共享（不动）**：`blobs` / `blob_sources` / `assets`（图片按 sha256 去重，可能被多商品引用）

---

## 2. 端点设计

### 2.1 整体删除（商品级）

```
DELETE /api/v1/products/{code}
DELETE /api/v1/products/{code}?force=true      # 有已上架 listing 时必须带
```

**语义**：软删商品 + 级联软删全部从属；**blobs/assets 不动**；**孤儿 design 顺带软删**。

**响应**：
```jsonc
// 成功
{ "ok": true, "data": {
  "code": "10809",
  "soft_deleted": {
    "products": 1, "product_variants": 6, "product_shipping": 56,
    "designs": 3, "orphan_designs": 1, "listings": 19, "listing_variants": 18
  },
  "untouched": { "blobs": "shared", "assets": "shared" }
} }

// 有已上架且未带 force（409 或 400）
{ "ok": false, "error": {
  "code": "has_published_listings",
  "message": "该商品有 19 个已上架 listing，加 ?force=true 强删",
  "published_count": 19
} }

// 权限不足
{ "ok": false, "error": { "code": "forbidden", "message": "非管理员只能删自己推送的商品" } }
```

### 2.2 分部删除（子资源级）

```
DELETE /api/v1/products/{code}/variants/{external_variant_id}   # 删单个规格（连带其运费）
DELETE /api/v1/products/{code}/shipping?country=US             # 删某国运费（可选 scope=product|variant）
DELETE /api/v1/designs/{design_code}                            # 删单个设计（含孤儿判定）
DELETE /api/v1/listings/{sku}                                   # 删单个 listing（含子体）
```

**语义**：只软删指定子资源；**不触发商品级级联**。

---

## 3. 级联规则（核心表）

| 删什么 | 连带软删 | 不动 | 其他 |
|---|---|---|---|
| **商品** `products` | suppliers / variants / variant-shipping / 商品级 shipping / designs / listings / listing_variants | blobs / assets | 孤儿 design 额外软删 |
| **规格** `product_variants` | 该规格的 product_shipping（`product_variant_id`） | — | 若删后商品无规格 → 可选警告 |
| **设计** `designs` | 可选连带其 listings | — | 若仍被别的商品引用 → 只解绑 |
| **listing** `listings` | 其 listing_variants | designs | — |

---

## 4. 权限口径（Q5）

**判据**：`pushed_by`（格式 `machine_id@email`）。

| 角色 | 能删 |
|---|---|
| **非管理员** | 只删 `pushed_by` 含**自己 email** 的行 |
| **管理员** | 删任意 |

**已定**：**(a)** 加 `users.is_admin BOOLEAN DEFAULT 0` 列 + `User::isAdmin()` helper。
> ⚠️ **待定**：migration 里把哪个账号设为**首个管理员**（待用户给 email，默认 `admin@weixiubang.club`）。

---

## 5. 落实清单（代码阶段）

### 5.1 Migration（新增 1 个）

`server/database/migrations/2026_09_29_000002_soft_deletes_and_unique_composite.php`

- `designs` / `product_variants` / `product_shipping` 加 `deleted_at`（`softDeletes()`）
- `product_variants`：drop `unique(product_id, external_variant_id)`（若有）→ 建 `unique(product_id, external_variant_id, deleted_at)`
  - ⚠️ 线上**当前可能没有** `unique(product_id, external_variant_id)`（000001 里只建了 index）；需先探测再改
- `product_shipping`：drop `unique(product_variant_id, country)` / `unique(product_id, country)` → 建复合含 `deleted_at`
- `designs`：`unique(design_code)` → `unique(design_code, deleted_at)`

> ⚠️ MySQL 8 中 `unique(a, b, NULL)` 允许重复（NULL 不参与唯一）→ 软删行 `deleted_at` 有值可重复；活行 `deleted_at=NULL` �a 仍唯一。**符合预期**。

### 5.2 模型

- `Design` / `ProductVariant` / `ProductShipping` 加 `use SoftDeletes`
- `ProductShipping` 加 `deleted_at` 支持（当前 `$timestamps=false`，需确认 `updated_at` 手动）

### 5.3 控制器

新增 `DeleteController`（或扩展 `ProductController`）：
- `destroyProduct($code)`：权限 → 已上架检查（force）→ 级联软删 → 孤儿 design → 统计返回
- 分部删方法若干

### 5.4 路由（`routes/api.php`）

```php
Route::delete('products/{code}', [DeleteController::class, 'destroyProduct']);
Route::delete('products/{code}/variants/{external_variant_id}', [DeleteController::class, 'destroyVariant']);
Route::delete('products/{code}/shipping', [DeleteController::class, 'destroyShipping']);
Route::delete('designs/{design_code}', [DeleteController::class, 'destroyDesign']);
Route::delete('listings/{sku}', [DeleteController::class, 'destroyListing']);
```

### 5.5 本地 CLI（`hub.js`）

```bash
node scripts/hub.js remove --product 10809 [--force]
node scripts/hub.js remove --variant 10809:ZSZ24B
node scripts/hub.js remove --design ZSZ24B
node scripts/hub.js remove --listing <sku>
```

---

## 6. 边界与注意

1. **幂等**：删不存在的 → 返回 `ok:true`（多机同步友好）
2. **blobs 永不删**：内容寻址，孤儿 blob 无害（可另设 GC）
3. **孤儿 design 判定**：`designs.product_id` 指向已软删商品 → 且无其他活 listing 引用 → 软删
4. **软删 + 幂等重建**：因 unique 改复合，删后同名重推**可成功**
5. **`force` 语义**：只用于"有已上架 listing"这一种拦截；不滥用
6. **不可逆操作留痕**：软删也写 `audit_logs`（可选）

---

## 7. 已确认（2026-09-29）

1. **管理员判定**：加 `users.is_admin` 列（首个管理员 email 待给）。
2. **孤儿 design 口径**：**B** —— 无任何活 listing 引用即孤儿。
3. **分部删除权限**：**A** —— 同 Q5（非管理员只删自己推的）。

---

## 8. 交付物（2026-09-29 已实现）

| 文件 | 动作 |
|---|---|
| `server/database/migrations/2026_09_29_000002_soft_deletes_and_unique_composite.php` | **新增**（补 `deleted_at` + unique 改复合 + `users.is_admin`） |
| `server/app/Http/Controllers/Api/DeleteController.php` | **新增**（删商品/规格/运费/设计/listing） |
| `server/app/Models/User.php` | **改**（`is_admin` cast + `isAdmin()`） |
| `server/app/Domain/Design/Models/Design.php` | **改**（`SoftDeletes`） |
| `server/app/Domain/Catalog/Models/ProductVariant.php` | **改**（`SoftDeletes`） |
| `server/app/Domain/Catalog/Models/ProductShipping.php` | **改**（`SoftDeletes`） |
| `server/routes/api.php` | **改**（5 条 DELETE 路由） |
| `scripts/hub.js` | **改**（新增 `remove` 命令） |

**CLI 用法**：
```bash
node scripts/hub.js remove --product 10809              # 无已上架直接软删
node scripts/hub.js remove --product 10809 --force      # 有已上架时强删
node scripts/hub.js remove --variant 10809:ZSZ24B       # 删单个规格（连带其运费）
node scripts/hub.js remove --shipping 10809 --country US --scope all
node scripts/hub.js remove --design <design_code>       # 仍被引用→仅解绑，否则软删
node scripts/hub.js remove --listing <sku>
```

### 8.1 索引实测结论（2026-09-29）

| 表 | 当前 unique | 动作 |
|---|---|---|
| `product_variants` | 无 | **新建** `unique(product_id, external_variant_id, deleted_at)` |
| `product_shipping` | `unique(product_variant_id, country)` + `unique(product_id, country)` | **drop 2 → 重建含 `deleted_at`** |
| `designs` | `unique(design_code)` | **drop → 重建** `unique(design_code, deleted_at)` |

> 注意：`product_shipping.product_variant_id` 可空 → MySQL「NULL 不占唯一」语义保留；
> 商品级行靠 `(product_id, country, deleted_at)` 兜底。
