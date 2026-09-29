# 中台（listing-hub）整改清单 · 2026-09-29

> **目的**：让「中台 ↔ 本地」真正**拉取即用、往返无损**。
> **来源**：本次实测（单拉 `12669` / 中台 id=31→`10809` 全套 + 逐格对账）+ 既有体检报告
> （`skills/hicustom-api/references/hub-sync-gap-report-20260929.md`、`hub-push-data-requirements.md`）。
> **仓库**：`listing-hub`（本次已 `pull` 到 `b64712a`）。**代码基线**：`server/app/Http/Controllers/Api/SyncController.php`（下称 SC）。
>
> ⚠️ 交付铁律（沿用中台老规矩）：**补丁附 字节数 + SHA256**；解压到**站点根**；**migration 必须幂等**（先 `hasColumn`/`hasIndex`）；改数据前先备份且备份可读。

---

## P0 — 阻断「拉取即用」

### R1. `row_id` 跨端语义混用（父体用中台自增 id，子体用推送机本地 row_id）★核心

**症状（实测）**
- 本地拉回后：`listing_copy.row_id = 82`（**其实是中台 `listings.id`**），而子体 `listing_variants.parent_row_id = 3`（**推送机本地 row_id**）→ **子体找不到父体**。
- 子体 `local_row_id` 也是**推送机本地序号**，与本地库不同源 → 与本地现存 `row_id` **撞号**（本次 `27/28/29` 就撞了 12669）；
  且 12669 的两条旧子体 `local_row_id = null`。
- 10809 的 `product_variants.id = 1..6` 同理（与本地无关）。

**代码定位**
| 位置 | 现状 |
|---|---|
| SC:679（`case 'listing_copy'` 导出） | `'row_id' => $l->id` ← **中台自增 id 冒充本地 row_id** |
| SC:690/691（`case 'listing_variants'` 导出） | `'row_id' => $l->local_row_id`、`'parent_row_id' => $l->parent_local_row_id` ← 推送机值 |
| SC:232/233（listings 写入） | 只收 `parent_row_id`；**没有** `local_row_id` 概念 |
| SC:328/331/332（子体写入） | `local_row_id ← v.local_row_id`，`parent_local_row_id ← v.parent_row_id` |
| `Listing` 模型 | **无 `local_row_id` 列**（父体本地主键根本没存） |
| `hub.js:266-276`（推送父体） | 根本没发 `row_id` |
| SC:310 注释 | 已自认「local_row_id 由 push 写入 attrs? 不——用 parent_sku 为主」= 半成品 |

**改法（建议）**
1. `listings` 加列 `local_row_id`（幂等 migration），push 存 `listing_copy.row_id`；`hub.js` 父体映射补 `local_row_id:`。
2. 导出 `listing_copy.row_id` ← `local_row_id ?? id`（**向后兼容**：旧数据回退 id）。
3. 子体 `parent_local_row_id` 语义明确为「父体本地 row_id」，并在**导出时用父体 `local_row_id` 校正**（若与 `parent_local_row_id` 不一致 → 以 `parent_listing_id` 权威关联为准，对不上则告警）。
4. `local_row_id` 全局唯一约束改为 **`(account_id, marketplace, local_row_id)`**（现在 `unique(local_row_id)` 会跨机撞号直接失败）。

**验收**：`12669` 单拉后，本地 `listing_variants.parent_row_id == listing_copy.row_id`；`_hub_verify_full.js` 无"跨端不一致"告警。

---

### R2. 变体级运费的 `product_code` 导出为空（48 条"无归属"行）

**症状（实测）**：`GET /sync/pull?format=csv&dataset=product_shipping` 里，10809 的 **48 条变体级行（6 规格 × 8 国）`product_code` 全空**，只有 `external_variant_id`；`/products/{id}?include=shipping` 的 JSON 里这些行 `product_id` 也是 `null`。
→ 用商品码过滤会**整批漏掉**；`pullcsv` 全量拉回后本地是"无归属"行，规格级运费形同丢失。

**代码定位**：SC:753
```php
'product_code' => $s->product_id ? ($codeOf[$s->product_id] ?? null) : null,   // ← 变体级行 product_id 为 null
```
（变体级行只存 `product_variant_id`，`product_id` 留空）

**改法**
1. 导出兜底：`product_id` 为空时经 `product_variant_id → ProductVariant.product_id → $codeOf[...]` 反查；`/products/{id}` 的 include 同理。
2. push `variant_shipping[]` 时若给了 `product_code` 而 `product_id` 空 → **冗余回填 `product_id`**（写库时解析一次，读时不再 join）。
3. 清理历史：对现存变体级行做一次回填（幂等脚本或 SQL）。

**验收**：单拉 10809 → `product_shipping.csv` 56 行**每行都有 product_code**；`_hub_verify_full.js` 的「规格级 48 条」可被商品码过滤命中。

---

### R3. 子体 `pkg_*` 端到端没值（表有列、接口有读写，落库全空）

**症状（实测）**：中台 `listing_variants` **54/54 行 `pkg_length/width/height/weight` 全空**；本地同源（但本机原生 `listing:family` 产出的 12604 有 6 条非空）。
→ ERP `listing.html` 子体详情「包装规格」大面积空白（页面靠父体 `family-records.json` 兜底，拉回重建后该文件不存在 → 全空）。

**现状澄清（避免改错地方）**：中台**能力已具备** —— 建表 migration 有 4 列（`2026_09_29_000001:83-86`）、写入 SC:355-358、导出 SC:702-703、`hub.js:344-345` 也在发。**问题是推送时本地那几列本身就是空**（`VariantPricing` 逐规格回填只写价、不写包装）。

**改法（建议 a + b 都做）**
- **a. 推送侧（根治）**：`VariantPricing.backfillVariantPricing` 增加 `pkg_*` 写入 —— 从 `product.json#profile.variants[]`（指纹规格包装/重量）按尺寸归一匹配（`normSize`）后回填子体。
- **b. 中台侧（兜底，符合"拉取即用"）**：`pull/export listing_variants` 时若 `pkg_*` 为空 → 用该品 `product_variants`（规格层）按 `variant_size`/`variant_code` 归一匹配 **derive 兜底**（不落库也先让拉回可用；或落库缓存）。

**验收**：10809 拉回后 18 子体 `pkg_weight` 非空；`listing.html` 子体详情包装格全部有值（无需 `family-records.json`）。

---

### R4. `attrs_json` 为空（品类属性缺失，挡 schema 模式上架）

**症状**：12669/10809 拉回后 `record.attrs` 空、`attrs_json` 空；`listing_diff.json#requiredMissing` 有 `externally_assigned_product_identifier` / `merchant_suggested_asin` / `bottom_style` / `number_of_items` 等品类必填。
**改法**
1. 推送侧：按 `product_type` 补 `attrs_json`（口径见 `hub-push-data-requirements.md §2.3`）。
2. 中台侧：`push` 时若 `product_type` 已知但 `attrs_json` 缺该 PT 必填 → **返回 WARN 清单（不拒写）**：
   `[WARN] 12670 BACKPACK 缺 5 项条件必填: closure, lining_description, …`

**验收**：`hub import` 输出含 WARN 段；按要求补后 WARN 消失。

---

## P1 — 一致性 / 体验

| # | 问题 | 证据 | 改法 |
|---|---|---|---|
| **R5** | `/products/{id}` 的 `categories` 为空 | 10809 返回 `categories: []` | 推送侧（或中台从 `product_type` 推）补品类映射；schema 模式依赖它 |
| **R6** | `listing_copy.edited_at` 导出用 `updated_at` 冒充 | SC:679 同段 `'edited_at' => $l->updated_at` | 存/导出真实 `edited_at`（写进 `copy_json` 桶即可，零迁移） |
| **R7** | `products.shipping_variant` 缺列/桶 | 体检报告 §1.1 | 塞 `profile_json` 桶（零迁移）或加列 |
| **R8** | `pull` 全量无分页 | `cursor`/`manifest` 未真正落地 | 大数据量前加 `limit/cursor`（低优先，先记） |
| **R9** | `designs` 图必须 OSS | 中台已有 `/assets/mirror` + import 自动 | push 时对 designs/变体图**强制归一**，回执 `missing_blobs` 明确列出未镜像项 |
| **R10** | 软删 API 后的 `pull` 语义 | 软删不参与同步（`push` 是 upsert） | 文档明确；必要时 `pull` 支持 `include_deleted=1`（低优先） |

---

## 本地/推送侧（非中台，但同一闭环，供对照）

| 现象 | 说明 | 归口 |
|---|---|---|
| 重建①（`listing:pull`）会把 `designs` 图**回写成指纹 CDN** + `status=synced` | 与中台 OSS/`active` 不一致（12669 `9KUCMN4S`、10809 `NY6Z89XP`） | 推送侧：重建后跑一次 `hub ossify`（或 pull 后不覆盖 designs 图） |
| `output/<id>/{shipping.json, family/**, 原稿/, keywords…}` 拉回不生成 | 中台只存 4 张 CSV + 图床 | 推送侧：`listing:rebuild` 只补上架最小集，其余按需重跑（见 `listing-rebuild.md §5`） |
| `record.specPricing[]` 无价 | 缺 `output/<id>/shipping.json`；但规格级运费**已能从中台拉到** | 推送侧：写「`product_shipping.csv` → `shipping.json` + `specPricing`」的零 cookie 生成命令 |

---

## 验收方式（统一）

1. **单拉一套**：`node dev/oneshot/_hub_pull_one.js --hub-id 31 --apply`（或 `--hub-id <id>`；脚本已支持中台数字 id → code）
2. **重建**：`node scripts/hi.js listing:rebuild --ids <code>`
3. **对账**：`node dev/oneshot/_hub_verify_full.js <code>` → 期望 **6 数据集 0 实质差异 + 覆盖率全绿**
   （数值格式差 `392` vs `392.00`、时间戳格式差不算）
4. **回归样机**：`10809`（6 规格家族，规格层最全）、`12669`（单规格，已被当回环样本）

## 建议实施顺序
`R2`（最小改动、当天可验）→ `R1`（要一条幂等 migration）→ `R3b`（阅读路径兜底）→ `R3a`（推送侧）→ `R4` → P1 按需。

---

## ✅ 实施状态（2026-09-29 21:5x）

| # | 状态 | 落点 |
|---|---|---|
| **R1** | ✅ 已改 | migration `2026_09_30_000001`（`listings.local_row_id` + 复合索引，**不设唯一约束**：多机同号是常态）· `Listing` cast · SyncController 写入/导出（JSON+CSV）· `hub.js` 父体补发 `local_row_id` |
| **R2** | ✅ 已改 | SyncController：变体级运费 `product_code` 反查（JSON + CSV）· `variant_shipping` push 冗余回填 `product_id` |
| **R3a** | ✅ 已改（推送侧） | `hub.js import`：子体 `pkg_*` 为空 → 由规格层推导（`profile.variants` × sizes/colors 名，尺寸归一匹配，单规格兜底） |
| **R3b** | ✅ 已改 | migration `2026_09_30_000002`（`product_variants.size_name/color_name`）· push 存储 · 导出兜底 derive（`specsByProductCode` / `deriveVariantPkg`） |
| **R4** | ✅ 已改 | `server/config/hub-pt-required.php` + push 返回 `warnings[]`（不拒写）· `hub.js` 打印 |
| **R6** | ✅ 顺手 | `listing_copy.edited_at` 优先取 `copy_json.edited_at`（回退 `updated_at`） |
| **R5** | ✅ 已改 | `ProductController::show`：`categories` 为空 → 从该商品 listings 的 PT **派生**（`categories_derived`，只读）· push 支持 `products[].categories[]` 落库 · `hub.js` 按 `listing_copy.product_type` 自动带品类 |
| **R7** | ✅ 已改 | `H_PRODUCTS` 补 `shipping_variant` 列 · 导出取值 · push 落库 · `hub.js` 发送 + 入 `profile_json` 桶 |
| **R8** | ✅ 已改 | pull 新增可选 `limit`（每数据集限量，0=不限，向后兼容） |
| **R9** | ✅ 已改 | push 对 designs/listings/variants 图 URL **归一**：非 OSS → 计入 `blobs_pending`/`missing_blobs`；`sync_images`/`normalize_images` 时立即镜像 |
| **R10** | ✅ 已改 | pull 新增 `include_deleted=1`（连软删行；默认关）；API.md 已写明软删不参与同步 |
| **R11** | ✅ 已改（2026-09-30） | ① 分部删除支持 **`?force=true` 真删**（design / listing / variant / shipping；design 被引用时解绑后真删）② 新 migration `2026_09_30_000003`：**历史回填** `product_shipping.product_id`（变体级）+ `listing_variants.product_code`（幂等、只补空） |
| **R12** | ✅ 已改（2026-09-30） | ① **R4 告警 key 归一化**（真源 attrs_json 是路径格式 → 取 `[`/`#`/`.` 之前的基名小写后比较，消除“填了却报全缺”的误报）② `hub-pt-required.php`：`_always` 清空（EAP/merchant_suggested_asin 亚马逊侧条件项，不带也 VALID）+ 新增 **`PILLOWCASE`** 实测必填清单 |

**交付**：`hub-patch-r1r4-20260929.tar.gz`（**20562 bytes**，sha256 `f0ab9af6aee9bccb573e28d773eef4186ac377b88899640718049006f482c9b0`）· 部署步骤见 `DEPLOY-R1R4-20260929.md`。
**客户端**（另一仓 `skills/hicustom-api/scripts/hub.js`）：已改、**未提交**；中台部署后需用新 `hub.js` 重新 `import` 才能回填 R1/R3 的历史数据。
