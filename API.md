# 云端中台 · API 接口设计 v1.0

> 设计稿（非代码）。Base：`https://<host>/api/v1`　JSON（`Content-Type: application/json`）。
> 用途：Web(Vue) 与 Agent(本地 CLI) **共用同一套 API**。

---

## 0. 通用约定

| 项 | 约定 |
|---|---|
| 命名 | 请求/响应字段 **snake_case**（与现有 CSV 一致，便于"拉取即用"） |
| 时间 | ISO8601 带时区，如 `2026-09-20T14:00:00+08:00` |
| 鉴权 | `Authorization: Bearer <token>`（Agent=PAT；人=SPA 会话同头） |
| 分页 | `?page=1&per_page=50` → 响应含 `data[]` + `meta{page,per_page,total,pages}` |
| 过滤 | 白名单参数，如 `?marketplace=&status=&product_id=&updated_since=` |
| 排序 | `?sort=-updated_at`（`-` 降序） |
| 包含 | `?include=assets,revisions,children` |
| 条件请求 | `If-Match: <revision>`（写）／响应带 `ETag` |
| 幂等 | 写整数键用请求头 `Idempotency-Key`；自然键 upsert 天然幂等 |
| 并发 | 冲突返回 **409** + 最新版本 |
| 错误 | `{ "ok": false, "error": { "code": "...", "message": "...", "fields": {...} } }` |
| 成功 | `{ "ok": true, "data": ... }`；列表带 `meta` |

**错误码**：`unauthorized`(401) · `forbidden`(403) · `validation_failed`(422) · `not_found`(404) · `conflict`(409) · `rate_limited`(429) · `server_error`(500)。

---

## 1. 鉴权

```
POST /auth/login      # 人：{email,password} → {token,user,abilities}
POST /auth/logout
GET  /me              # 当前身份 + abilities
POST /tokens          # 发 Agent Token：{name, abilities:[...], expires_at?} → {token, plain_text_token}
DELETE /tokens/{id}
```
```jsonc
// POST /tokens 请求
{ "name": "local-workspace-A", "abilities": ["catalog:write","listings:write","assets:write"], "expires_at": null }
// 响应
{ "ok": true, "data": { "id": 7, "name": "local-workspace-A", "abilities": ["catalog:write","listings:write","assets:write"], "plain_text_token": "…仅此一次显示…" } }
```
**abilities 取值**：`catalog:read|write` · `designs:read|write` · `listings:read|write` · `assets:write` · `admin`。

---

## 2. 同步（本地 CLI 主力）★

### 2.1 `POST /sync/push` —— 本地→中台（幂等 upsert）

> ★ **R15（2026-09-30）**：同一份数据**软删后再推 → 自动 `restore`（复活）再更新**（不再撞唯一键报错；也不产生幽灵活行）。适用于 `products` / `designs` / `listings` / `listing_variants` / `product_variants` / `product_shipping`。

```jsonc
{
  "machine_id": "ws-A",
  "products": [                       // 可选；按 code 或 (supplier_code,external_id) upsert
    { "code": "AXW1061", "cn_name": "撞色中筒袜（3D打印款）", "en_name": "Adult Socks",
      "material_cn": "涤纶", "print_face_w": 1063, "print_face_h": 1045,
      "sources":    [ { "supplier_code":"hicustom", "external_id":"11973", "supplier_sku":"AXW1061", "is_primary":true } ],
      "categories": [ { "platform":"amazon", "code":"SOCKS", "is_primary":true },
                      { "platform":"internal", "code":"袜子" } ],
      "detail_json": { /* 其余商品事实 */ } }
  ],
  "product_variants": [
    { "product_code":"AXW1061", "external_variant_id":"F8P9P7",
      "spec_json": {"colorId":29,"sizeId":139,"code":"F8P9P7"}, "color":"29", "size":"139",
      "weight_g": 112, "size_l_cm":14, "size_w_cm":14, "size_h_cm":2,
      "pkg_l_cm":14, "pkg_w_cm":14, "pkg_h_cm":2, "volume_cm3":392, "status":"synced" }
  ],
  "variant_shipping": [
    { "product_code":"AXW1061", "external_variant_id":"F8P9P7", "country":"US", "amount":30.39, "channel":"递四方服装专线" }
  ],
  "designs": [
    { "design_code":"FFGB3WL2","product_code":"AXW1061",
      "design_key":"default","version":"v1","pattern":"patterns/xxx.png","template":"cap-front","status":"active" }
  ],
  "listings": [
    { "account":"HHY","platform":"amazon","marketplace":"A1F83G8C2ARO7P","sku":"HHY-ZW-11973-AdultSocks-260918-UK",
      "product_code":"AXW1061","design_code":"FFGB3WL2",
      "is_parent":true,"variation_theme":"COLOR","status":"ready",
      "price":12.99,"product_price":6.99,"shipping_fee":6.99,"currency":"GBP","quantity":1,
      "is_custom":true,
      "published_at":"2026-09-20T01:21:00+08:00",     // 本地发布成功后回写；首次由服务端记入 first_published_at
      "customization_json": { "print_faces":[1], "pattern_source":"gallery" },
      "attrs_json": { "item_name":"…", "bullet_point":["…","…"], "product_description":"…", "…":"…" },
      "revision": 3,
      "assets": [
        { "media_type":"image","role":"main","sha256":"…64hex…","mime":"image/jpeg","width":1600,"height":1600 },
        { "media_type":"image","role":"other_1","sha256":"…" }
      ] }
  ]
}
```
```jsonc
// 200 响应
{
  "ok": true,
  "data": {
    "products":  { "created": 2, "updated": 1, "skipped": 0 },
    "variants":  { "created": 3, "updated": 0, "skipped": 0 },
    "product_variants": { "created": 6, "updated": 0, "skipped": 0 },
    "product_shipping": { "created": 8, "updated": 0, "skipped": 0 },
    "variant_shipping": { "created": 0, "updated": 48, "skipped": 0 },
    "designs":   { "created": 1, "updated": 0, "skipped": 0 },
    "listings":  { "created": 0, "updated": 4, "skipped": 1 },
    "assets":    { "referenced": 9, "missing_blobs": 0 },
    "conflicts": [
      { "key": {"marketplace":"A1F83G8C2ARO7P","sku":"…"}, "your_revision": 2, "server_revision": 5,
        "hint": "服务端比你新，请先 GET /listings/{id} 合并后再推" }
    ],
    "warnings": [
      "[WARN] HHY-ZW-12669-… (CARRIER_BAG_CASE) attrs_json 为空：schema 模式上架将缺必填属性"
    ],
    "missing_blobs": 3,
    "blobs_pending": [ "https://nimg5.hicustom.com/static/…jpg" ]
  }
}
```
- **媒体**：`assets` 里的对象**先经 OSS 直传**（见 §6），push 只传 `sha256` 引用；`missing_blobs>0` 表示有资产没传成。
  ★ **R9（2026-09-30）**：push 时对 `designs` / `listings` / `variants` 的图 URL 做归一 —— 已是 OSS 则不动；
  未 OSS 的计入 `blobs_pending`（最多 20 条）+ `missing_blobs` 计数；带 `sync_images` 或 `normalize_images` 时**立即镜像**。
- **冲突**：某条带 `revision` 且 `< 服务端` → 进 `conflicts`，该条不写（其余照写）。
- ★ **R4（2026-09-30）**：`warnings[]` 是**不拒写**的提醒（`attrs_json` 为空 / 缺该 PT 必填；
  清单见 `server/config/hub-pt-required.php`，改完需 `config:cache`）。
- ★ **R1（2026-09-30）**：父体 `listings` 可带 `local_row_id`（= 本地 `listing_copy.row_id`，跨端稳定键）；
  子体 `variants[].local_row_id` / `parent_row_id` 语义同前，但**导出时 `parent_row_id` 以父体真实 `local_row_id` 校正**。
- ★ **R2（2026-09-30）**：`variant_shipping[]` 写库时**冗余回填 `product_id`**，使变体级运费在各读路径都带 `product_code`。

### 2.2 `GET /sync/pull` —— 中台→本地（拉取即用）

```
GET /sync/pull?scope=products,listings,designs&marketplace=A1F83G8C2ARO7P&since=2026-09-19T00:00:00+08:00&format=json&limit=200&include_deleted=0
```
- `scope`：`products|variants|shipping|product_variants|product_shipping|designs|listings|assets`（逗号多选）
- `since`：增量（省略=全量）；用返回的 `cursor` 做"接着拉"。
- `format`：`json`（给 Agent）或 `csv`（给现成工具链，返回 zip）。
- ★ **R8**：`limit`（每个数据集最多返回条数；`0`/省略=不限，**向后兼容**）。
- ★ **R10**：`include_deleted=1` 连**软删**行一起返回（默认不返回；`products`/`designs`/`listings`/`variants` 生效）。
- ★ **R1**：`listing_copy` 导出的 `row_id` = 父体**本地** `row_id`（无则回退中台 id）；`listing_variants` 的 `parent_row_id` 亦以父体本地 `row_id` 为准。
- ★ **R3b**：`listing_variants` 的 `pkg_*` 为空时，导出会**由 `product_variants` 规格层 derive**（不落库）。

```jsonc
// format=json
{ "ok": true, "data": {
  "cursor": "2026-09-20T14:00:00+08:00",
  "products": [ { /* 同 push 的 product 结构 */ } ],
  "product_variants": [ { "product_code":"10809", "external_variant_id":"ZSZ24B", "spec_json":{"colorId":29,"sizeId":139}, "weight_g":112, "size_l_cm":"14.00", "size_w_cm":"14.00", "size_h_cm":"2.00", "pkg_l_cm":"14.00", ... } ],
  "product_shipping": [ { "product_code":"10809", "external_variant_id":"ZSZ24B", "country":"US", "amount":"30.39", "channel":"递四方服装专线" } ],
  "designs": [ { "design_code":"FFGB3WL2", "…": "…" } ],
  "listings": [ { "…":"…", "assets":[ { "role":"main", "public_url":"https://media.…/….jpg" } ] } ]
} }
```
```jsonc
// format=csv → 200 application/zip，包内：
//   products.csv          ← 列 = 现有 database/products.csv
//   listing_copy.csv      ← 列 = 现有 database/listing_copy.csv
//   listing_variants.csv  ← 列 = 现有 database/listing_variants.csv
//   product_variants.csv  ← 指纹商品规格（product_code, external_variant_id, color, size, spec_json, weight_g, size_*_cm, pkg_*_cm, volume_cm3, status）
//   product_shipping.csv  ← 运费（product_code, external_variant_id, country, amount, currency, channel）
//   designs.csv           ← 列 = 现有 database/designs.csv
//   assets.csv            ← sku,role,media_type,public_url（本地补图用）
//   manifest.json         ← { generated_at, cursor, counts }
```
> 本地 `hub pull` 解包 → **直接覆盖 `database/*.csv`** → 编辑 → 本地发 SP-API。

### 2.3 同步会话（可选，便于审计/断点）

```
GET  /sync/jobs                 # 历史与状态
POST /sync/jobs                 # 创建一次同步记录（可选）
```

---

## 3. 商品域

```
GET    /products?category=&status=&q=&updated_since=
POST   /products
GET    /products/{id}?include=variants,shipping,assets
PATCH  /products/{id}
DELETE /products/{id}          # 软删
GET    /products/{id}/variants
GET    /products/{id}/shipping?country=
```

> ★ **R11（2026-09-30）· `?force=true` 真删（hard delete）**：`DELETE /designs/by-code/{code}`、
> `DELETE /listings/by-sku/{sku}`、`DELETE /products/{code}/variants/{ext}`、`DELETE /products/{code}/shipping`
> 传 `?force=true` → **物理删除**（区别于默认软删）。
> design 仍被活 listing 引用时，默认仅**解绑**；`?force=true` 则**解绑 + 真删**。
> 注意：商品级 `DELETE /products/{code}?force=true` 的语义是「允许连带删已上架 listing」（**不是** hard delete）。
> `blobs / assets` 永不删。

### 3.1 ★ 删除（软删 · 2026-09-29）

```
DELETE /products/{code}[?force=true]                  # 整体删商品（级联软删从属；已上架须 force）
DELETE /products/{code}/variants/{external_variant_id} # 删单个指纹规格（连带其运费）
DELETE /products/{code}/shipping?country=US[&scope=product|variant|all]
DELETE /designs/by-code/{design_code}                 # 仍被引用→仅解绑；否则删（孤儿）
DELETE /listings/by-sku/{sku}                         # 删 listing + 其子体
```

- **软删**（`deleted_at`）；`blobs`/`assets`（内容寻址·共享）**永不删**
- **Q3**：有已上架 listing 且无 `force` → **409** `has_published_listings`（带 `published_count`）
- **Q5 权限**：`users.is_admin=1` 删任意；普通用户只删 `pushed_by` 含自己 email 的 → 否则 **403**
- **幂等**：删不存在 → `{ok:true, data:{note:"not_found_idempotent"}}`

```jsonc
// DELETE /products/10809?force=true → 200
{ "ok": true, "data": {
  "code": "10809",
  "soft_deleted": { "products":1, "product_suppliers":1, "product_variants":6, "product_shipping":56,
                    "designs":3, "orphan_designs":1, "listings":19, "listing_variants":18 },
  "untouched": { "blobs":"shared", "assets":"shared" }
} }
```
```jsonc
// GET /products/{id}
{ "ok": true, "data": {
  "id": 88, "code":"AXW1061", "cn_name":"撞色中筒袜（3D打印款）", "en_name":"Adult Socks",
  "material_cn":"涤纶", "material_en":"Polyester", "print_face_w":1063, "print_face_h":1045,
  "min_price":10.08, "currency":"USD", "status":"synced",
  "sources": [ { "supplier":{"code":"hicustom"}, "external_id":"11973", "supplier_sku":"AXW1061", "is_primary":true } ],
  "categories": [ { "platform":"amazon", "code":"SOCKS", "is_primary":true } ],
  "variants": [ { "id": 501, "spec_json":{"color":"White","size":"One Size"}, "color":"White","size":"One Size",
                  "cost_json":{"retail":8.9}, "weight_g":68,
                  "shipping":[ {"country":"UK","amount":5.99,"channel":"云途普货专线"} ] } ]
} }
```

---

## 4. 设计域

```
GET    /designs?product_id=&design_key=&status=
POST   /designs
GET    /designs/{id}
PATCH  /designs/{id}
DELETE /designs/{id}
DELETE /designs/by-code/{design_code}   # ★ 按 design_code 软删（2026-09-29）；仍被引用→仅解绑
```
响应同 `designs` 表字段（design_code/design_key/version/parent_code/pattern/template/status…）。

---

## 5. 上架域 ★

```
GET    /listings?account=&platform=&marketplace=&status=&product_id=&sku=&updated_since=&include=assets,revisions
POST   /listings                      # 新建（单条）
GET    /listings/{id}?include=assets,attributes,revisions,children
PATCH  /listings/{id}                 # 局部更新（改 revision→+1，留快照）
DELETE /listings/{id}                 # 软删
GET    /listings/{id}/revisions       # 版本历史
GET    /listings/{id}/children        # 变体子体
POST   /listings:batchUpsert          # 批量（= push 的 listings 段）
GET    /listings/by-sku?marketplace=&sku=
POST   /listings/{id}/publish-result  # ★本地发布后回写：{status,asin,published_at,issues} → 服务端记 first_published_at
```
```jsonc
// GET /listings/{id}?include=assets
{ "ok": true, "data": {
  "id": 1201, "account": {"name":"HHY"}, "platform":"amazon", "marketplace":"A1F83G8C2ARO7P",
  "sku":"HHY-ZW-11973-AdultSocks-260918-UK", "parent_sku":null, "is_parent":true,
  "variation_theme":"COLOR", "amazon_product_type":"SOCKS", "status":"published",
  "price":12.99, "product_price":6.99, "shipping_fee":6.99, "currency":"GBP", "quantity":1,
  "asin":"B0HKFF5FRL", "first_published_at":"2026-09-20T01:19:00+08:00", "published_at":"2026-09-20T01:21:00+08:00",
  "is_custom": true, "customization_json": { "print_faces":[1], "pattern_source":"gallery", "notes":"…" },
  "revision": 4, "is_complete": true, "missing_fields": [],
  "attrs_json": { "item_name":"…", "bullet_point":["…"], "…":"…" },
  "assets": [
    { "id":31,"media_type":"image","role":"main","public_url":"https://media.…/main.jpg","width":1600,"height":1600,"status":"verified" },
    { "id":32,"media_type":"image","role":"other_1","public_url":"…" }
  ]
} }
```
```jsonc
// PATCH /listings/{id}  请求（局部；带 base revision 做乐观锁）
{ "price": 13.49, "attrs_json": { "bullet_point": ["新第一点","…"] }, "revision": 4 }
// 409 冲突
{ "ok": false, "error": { "code":"conflict", "message":"revision 过期",
  "latest": { "id":1201, "revision": 5, "updated_at":"…" } } }
```

---

## 6. 资产（图 + 视频）· **去重优先**

```
POST /assets/check     # ★ 秒传/去重：先问哪些内容服务端已有
POST /assets/sign      # 取 OSS 预签名直传 URL（只对"缺的"图）
POST /assets           # 直传/镜像完成后登记元数据（绑定 listing/design/product）
POST /assets/mirror    # ★ 远端链接镜像：服务端拉取 href → sha256 去重 → 存 OSS
GET  /assets/{id}
DELETE /assets/{id}
```
```jsonc
// POST /assets/sign
{ "sha256":"…", "mime":"image/jpeg", "size": 320541 }
// → { "ok": true, "data": { "upload_url":"https://oss…?sign…", "method":"PUT", "storage_key":"blobs/ab/abcd….jpg", "expires_in":900 } }
// 直传 PUT 到 upload_url 后：
// POST /assets
{ "sha256":"…", "storage_key":"blobs/ab/abcd….jpg", "mime":"image/jpeg",
  "owner_type":"listing", "owner_id":1201, "media_type":"image", "role":"main", "position":1 }
// 视频：media_type=video, mime=video/mp4, duration_ms=12000, poster_sha256="…"
```
> `public_url` = `https://media.<域名>/blobs/ab/abcd….jpg`（公共读 + CDN）。上传后队列回源校验 → `status=verified`。

### 6.1 去重策略（核心）

**两个去重键**：
- **内容键 = `sha256`（硬去重）**：同一张图（不管来自本地文件还是指纹链接）→ 同一个 `blob`、同一个 OSS 对象、同一个 URL。**100 个 listing 用同一图 = 1 份存储**。
- **来源键 = `url_hash`（免重复下载）**：指纹 CDN 的图名自带内容哈希、**不可变**，所以可安全按 URL 缓存：同一链接第二次推送**不再下载**，直接复用已有 blob。

**三种场景怎么走**：
| 场景 | 处理 |
|---|---|
| 本地同一物料**重复推送** | 本地先算 `sha256` → `POST /assets/check` → **已存在则**客户端**跳过上传**（秒传）；只有缺失的才 PUT 到 OSS |
| **相同图片链接**的多个 listing | 首个 listing 触发一次镜像(下载→sha256→OSS)；**后续 listing 命中 `url_hash` → 0 下载、0 上传**，只加一条 `assets` 引用 |
| 不同链接但**同一张图** | `sha256` 相同 → 命中同一 blob（URL 只是别名，存 `blob_sources`） |

**幂等**：`assets` 按 `(owner_type, owner_id, role, position)` upsert，重复推不产生重复引用行；`blobs` 按 `sha256` 唯一。
**近似重复（可选）**：`blobs.phash`（感知哈希）→ 对"同一图被重新编码"(如本地原图 vs 指纹转码)给出**疑似重复**提示，人工决定是否归一（不做自动合并，避免误删）。
**GC**：`ref_count=0` 的孤立 blob 定期清理（不是靠扫描 listing）。

```jsonc
// POST /assets/check  (批量)
{ "items": [ { "sha256":"…" }, { "sha256":"…" } ] }
// → { "ok": true, "data": { "exists": ["…"], "missing": ["…"] } }   # 只上传 missing

// POST /assets/mirror  (远端链接→镜像到 OSS；服务端 fetch 一次)
{ "url": "https://nimg5.hicustom.com/static/productDet2/XXXX.jpg",
  "owner_type":"listing", "owner_id":1201, "media_type":"image", "role":"main" }
// → { "ok": true, "data": { "blob_id": 33, "public_url":"https://media.…/blobs/ab/abcd….jpg", "deduped": true } }
```

### 6.2 图片 URL 归一（换成自有图床）★

**目标**：listing 里所有图片字段，最终都指向**我们的稳定 URL**（不再指向 hicustom CDN）。

**两种做法**（我采用 **A 为主 + B 兜底**）：
- **A 派生式（推荐·单一真源）**：图片**不**在 `attrs_json` 里存死 URL；图片字段**由 `assets → blob` 派生**。发布时（本地）用 `blob.storage_key` + 当前配置域名**渲染**成 URL 填进 payload。
  - 好处：**换域名/CDN 只改配置、零改库**；一个图一处真源。
- **B 就地改写（兜底·兼容旧数据/外部直传 URL）**：入库（push/mirror）时，服务端**扫描 listing 的图片字段**，把命中"非自有域名"的 URL → 镜像到 OSS → **就地改写**成自有 URL。
  - 识别哪些字段是图片：由 `field_definitions` 标 `is_media=true` + `media_role`（main / other_1..8 / swatch…）。
  - **幂等**：只改写"host ∉ 自有媒体域名白名单"的 URL（已是我们域名的**不再二次改写**）。
  - 原链接留痕在 `blobs.origin_url`。

**实际执行（你问的那步）**：
```
push listing(带 assets 或图片字段里是 hicustom URL)
  → 逐张解析为 blob：本地=已在 OSS / 远端链接=mirror 一次
  → 图片字段归一到自有 URL（A：派生；B：就地改写 attrs_json）
  → 响应回写"归一后的 listing"（客户端立刻看到新 URL）
```
- **不改发布动作**：本地 `pull` 下来的 listing，图片字段已经是自有 OSS URL，直接进 SP-API payload。
- **可选**：服务端渲染时用配置域名拼 URL（`https://media.<域名>/blobs/…`），域名后补时**不用回改历史数据**。

---

## 7. 字典 / 元数据（只读为主）

```
GET /suppliers
GET /categories?platform=amazon&parent_id=
GET /marketplaces?platform=amazon
GET  /field-definitions?platform=amazon&product_type=UNDERPANTS   # 必填/条件/枚举
GET  /customization-definitions?platform=amazon&product_type=    # 定制项定义（前瞻）
GET  /templates?kind=layout&product_type=    # 共享模板库：kind=layout(贴字) | amazon_xlsm(上架)
POST /templates                              # 新增模板（贴字存 spec_json / 上架存 blob_id）
PATCH /templates/{id} · DELETE /templates/{id}
GET  /settings                     # 运行时配置（分组返回）
PUT  /settings                     # 管理员改运行时配置（免发版）
```

---

## 8. 本地 CLI（封装上面的 API）

```bash
hub login                 # 存 token 到 .hub/credentials
hub push --scope products,listings,designs [--since]   # = POST /sync/push
hub import <csvDir>       # 读现有 CSV → 幂等 upsert 推到中台
hub pull --scope listings --marketplace UK [--since]   # 拉 JSON（给 Agent）
hub pullcsv <csvDir>      # ★拉 CSV 包并【合并】到本地 database/*.csv（同键更新 · 本地独有保留 · 线上新增追加 · 写前备份）
hub pullcsv <csvDir> --overwrite   # 才真覆盖（慎用）
hub assets upload <file...> --owner listing:<id> --role main
```
> `pullcsv` 默认**合并**：本地未 push 的行**不会被覆盖丢失**。
> CLI 用 Node 写，**独立于 skill**（单独仓库/目录），只依赖中台 API。

---

## 9. 分页/列表响应统一形态

```jsonc
{ "ok": true,
  "data": [ /* 资源数组 */ ],
  "meta": { "page":1, "per_page":50, "total": 128, "pages": 3 } }
```

---

## 10. 待确认

1. `push` 的**分段**：一次全推 vs 按 scope 多次推？（我建议按 scope，传输小、好重试）
2. `pull` 的 **csv 包**是否就是"直接覆盖 `database/*.csv`"用途（列对齐）？
3. 是否需要 **webhook/事件**（如"某 listing 状态变了"通知本地）？还是本地轮询 `since` 即可。
4. abilities 命名/粒度是否合适。
