# 云端中台 · 数据库字段及表设置 v1.0

> MySQL 8 / utf8mb4。**设计稿（非迁移代码）**。确认后再落到 Laravel migrations。
> 通用约定见 §0；逐表见 §1–§16；关系图 §17；关键取舍 §18。

---

## 0. 通用约定

- **每表都有 `id` BIGINT UNSIGNED AUTO_INCREMENT 主键**（代理键）。
- 业务唯一键另设 `UNIQUE`（如 `(supplier_id, external_id)`、`(account_id, platform, marketplace, sku)`）。
- 通用时间列：`created_at TIMESTAMP NULL`、`updated_at TIMESTAMP NULL`；需要软删的加 `deleted_at TIMESTAMP NULL`。
- 金额 `DECIMAL(12,2)`；尺寸 `DECIMAL(10,2)`；`country CHAR(2)`（ISO）。
- 结构化查询字段**成列**；零散/多变结构进 **`JSON`**。
- 字符集 `utf8mb4_0900_ai_ci`；引擎 InnoDB。
- 命名：表名复数小写下划线；FK 名 `<entity>_id`。

---

### 0.1 字段位置速查（常见疑问）

| 你要找 | 在哪个表 / 列 |
|---|---|
| 上架字段全集（Amazon 269） | **`listings.attrs_json`**（MySQL JSON 列，在库内） |
| 上架售价 / 商品价 / 运费档 | `listings.price / product_price / shipping_fee` |
| 商品成本价档 | `product_variants.cost_json`（按来源时用 `product_suppliers.cost_json`） |
| 各国物流费 / 渠道 | `product_shipping`（`product_variant × country`） |
| 商品来源供应商（不止指纹） | **`product_suppliers`**（商品 ↔ 供应商 多对多） |
| 商品分类（多平台 / 多对多） | **`categories` + `product_categories`** |
| 站点 | `marketplaces`（带 `platform`） |
| 该 listing 属哪个平台 | `listings.platform`（默认 `amazon`） |
| 媒体（图/视频） | `assets` + `blobs` |

---

## 1. suppliers（供应商）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| code | VARCHAR(32) | N | UQ | 如 `hicustom` |
| name | VARCHAR(128) | N | | 显示名 |
| base_url | VARCHAR(255) | Y | | 平台地址 |
| status | ENUM('active','disabled') | N | | 默认 active |
| created_at/updated_at | TIMESTAMP | Y | | |

## 2. products（商品·平台/供应商无关的抽象）

> 一次"商品"= 一个有名字/材质/印刷区/设计的**可上架物**；**不绑定**具体供应商、也不绑定某平台分类（二者都是多对多，见 §2.1–§2.3）。

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| code | VARCHAR(64) | Y | UQ | 内部商品码（可选） |
| cn_name / en_name | VARCHAR(255) | Y | | 中英文名 |
| material_cn / material_en | VARCHAR(64) | Y | | 材质（主口径） |
| print_face_w / print_face_h | DECIMAL(10,2) | Y | | 主印刷区尺寸 |
| min_price | DECIMAL(12,2) | Y | | 最低价（口径保留） |
| currency | CHAR(3) | Y | | |
| status | ENUM('draft','ready','synced','archived') | N | | |
| detail_json | JSON | Y | | 其余商品事实 |
| created_at/updated_at/deleted_at | TIMESTAMP | Y | | |

索引：`INDEX(status)`。

### 2.1 product_suppliers（★ 商品 ↔ 供应商 来源对应·多对多）

> 一个商品可来自**多个供应商**（指纹科技 / 将来其它工厂）；每个供应商有自己的商品号与成本。**商品来源关系就存这张表**。

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| product_id | BIGINT UNSIGNED | N | FK products | |
| supplier_id | BIGINT UNSIGNED | N | FK suppliers | |
| external_id | VARCHAR(64) | N | | 供应商商品号（指纹产品id） |
| supplier_sku | VARCHAR(64) | Y | | 供应商 SKU / SPU |
| cost_json | JSON | Y | | 该来源的成本/价档 |
| is_primary | TINYINT(1) | N | | 主来源 |
| status | VARCHAR(24) | Y | | |
| created_at/updated_at | TIMESTAMP | Y | | |

索引：`UNIQUE(supplier_id, external_id)`，`UNIQUE(product_id, supplier_id)`，`INDEX(product_id)`。

### 2.2 categories（分类·多平台树）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| platform | VARCHAR(24) | N | | `amazon` / `internal` / 将来其它平台 |
| parent_id | BIGINT UNSIGNED | Y | FK categories | 树 |
| code | VARCHAR(96) | N | | 平台分类码 / 产品类型（如 `SOCKS`） |
| name_cn / name_en | VARCHAR(128) | Y | | |
| path | VARCHAR(512) | Y | | 分类路径（缓存） |
| is_active | TINYINT(1) | N | | |

索引：`UNIQUE(platform, code)`，`INDEX(parent_id)`。

### 2.3 product_categories（★ 商品 ↔ 分类 多对多）

> 一个商品可同时属于**多个平台/多个分类**（如 amazon=SOCKS、internal=袜子、将来 ebay=…）。

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| product_id | BIGINT UNSIGNED | N | FK products | |
| category_id | BIGINT UNSIGNED | N | FK categories | |
| platform | VARCHAR(24) | N | | 冗余，便于按平台查 |
| is_primary | TINYINT(1) | N | | 主分类 |
| created_at | TIMESTAMP | Y | | |

索引：`UNIQUE(product_id, category_id)`，`INDEX(category_id)`，`INDEX(platform)`。

## 3. product_variants（商品变体 / 规格）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| product_id | BIGINT UNSIGNED | N | FK products | |
| product_supplier_id | BIGINT UNSIGNED | Y | FK product_suppliers | 规格随来源不同时填；通用规格留空 |
| external_variant_id | VARCHAR(64) | Y | | 平台变体 id |
| spec_json | JSON | Y | | 规格键值（如 `{"color":"Black","size":"M"}`） |
| color / size | VARCHAR(32) | Y | | 热字段（便于查询） |
| cost_json | JSON | Y | | 价档：retail/gold/platinum/diamond/… |
| weight_g | INT UNSIGNED | Y | | |
| size_l_cm / size_w_cm / size_h_cm | DECIMAL(10,2) | Y | | 成品尺寸 |
| pkg_l_cm / pkg_w_cm / pkg_h_cm | DECIMAL(10,2) | Y | | 包装尺寸 |
| volume_cm3 | DECIMAL(12,2) | Y | | |
| status | VARCHAR(24) | Y | | |
| created_at/updated_at | TIMESTAMP | Y | | |

索引：`INDEX(product_id)`，`INDEX(product_id, color)`，`INDEX(product_id, size)`。

**写入（2026-09-29）**：`POST /sync/push` 顶层 **`product_variants[]`** 写入本表。
- 幂等键：**`(product_id, external_variant_id)`**（`external_variant_id` = 指纹 `variantCode`，如 `ZSZ24B`）
- 数据源：本地 `output/<id>/product.json` 的 `profile.variants[]`（指纹规格：颜色×尺寸 + 包装/重量）
- 粒度：**指纹规格**（不是上架子体）。上架子体（`listing_variants`）经「款式+尺寸 → 尺寸规格 → 运费」倒查，不重复存。

**读取**：`GET /sync/pull?scope=product_variants` → `data.product_variants[]`；`GET /sync/pull?format=csv&dataset=product_variants` → `product_variants.csv`。

> ★ **软删（2026-09-29）**：本表已加 `deleted_at`（`SoftDeletes`）。unique 键 = `(product_id, external_variant_id, deleted_at)`（软删后可同名重建）。删除见 `DELETE-API-DESIGN.md`。

## 4. product_shipping（商品物流信息·从 product JSON 拆出）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| product_variant_id | BIGINT UNSIGNED | Y | FK product_variants | 变体级运费挂此（逐规格×逐国） |
| product_id | BIGINT UNSIGNED | Y | FK products | 商品级运费挂此（默认规格代表值） |
| country | CHAR(2) | N | | 目的国 |
| amount | DECIMAL(12,2) | Y | | 运费 |
| currency | CHAR(3) | Y | | |
| channel | VARCHAR(64) | Y | | 物流渠道 |
| updated_at | TIMESTAMP | Y | | |

索引：`UNIQUE(product_variant_id, country)`，`UNIQUE(product_id, country)`，`INDEX(country)`。

> ★ **两种粒度并存**（migration `2026_09_20_000007` 后）：
> - **商品级**（`product_id` 有值、`product_variant_id` 空）：默认规格的**代表值**，来自 `products.csv` 的 `shipping_*` 列 → push 的 **`product_shipping[]`**
> - **变体级**（`product_variant_id` 有值）：**逐规格×逐国**真实运费 → push 的 **`variant_shipping[]`**（幂等键 `(product_variant_id, country)`）
> - 数据源：本地 `output/<id>/shipping.json` 的 `byVariant[].countries`
>
> **读运费优先读 `profile_json`**（`shipping_US/UK/...` 桶）；`pullCsvOne` 导出 products 走「物理列优先 → `profile_json` 兜底」：`$p->shipping_US ?? $g('shipping_US')`。
> 因此 `products` 表的独立 8 国列（`shipping_*`/`price_*`/`freight_template_*`）**通常为 NULL 属正常**——真值在 `profile_json`。

**读取**：`GET /sync/pull?scope=product_shipping` → `data.product_shipping[]`；`GET /sync/pull?format=csv&dataset=product_shipping` → `product_shipping.csv`。

> ★ **软删（2026-09-29）**：本表已加 `deleted_at`。unique 改复合：`(product_variant_id, country, deleted_at)` + `(product_id, country, deleted_at)`。

## 5. accounts（亚马逊账号/店铺）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| name | VARCHAR(128) | N | | 账号别名 |
| platform | VARCHAR(24) | N | | 默认 `amazon` |
| seller_id | VARCHAR(64) | Y | | 卖家ID |
| region | VARCHAR(16) | Y | | NA/EU/FE… |
| status | ENUM('active','disabled') | N | | |
| notes | VARCHAR(255) | Y | | |
| created_at/updated_at | TIMESTAMP | Y | | |

> **不存 SP-API 凭证**（发布在本地）。

## 6. marketplaces（站点）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| platform | VARCHAR(24) | N | | `amazon` |
| code | VARCHAR(16) | N | UQ(platform) | 如 `A1F83G8C2ARO7P` |
| country | CHAR(2) | N | | UK/DE/US… |
| currency | CHAR(3) | N | | |
| language | VARCHAR(8) | Y | | en_GB |
| domain | VARCHAR(64) | Y | | |
| is_active | TINYINT(1) | N | | 默认 1 |

## 7. designs（设计·含变体家族）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| design_code | VARCHAR(32) | N | UQ | 合成产品码（原主键） |
| product_id | BIGINT UNSIGNED | N | FK products | |
| design_key | VARCHAR(64) | Y | | 设计身份（一商品可多设计） |
| version | VARCHAR(16) | Y | | v1/v2… |
| parent_code | VARCHAR(32) | Y | | 溯源 |
| source | VARCHAR(24) | Y | | llm/human/agent |
| adjust_json | JSON | Y | | 改进方向记录 |
| cn_name / en_name | VARCHAR(255) | Y | | |
| design_zh_name/zh_tags/en_name/en_tags | VARCHAR(255) | Y | | 设计标签 |
| pattern | VARCHAR(512) | Y | | 图案路径/URL |
| template | VARCHAR(64) | Y | | 布局模板 key |
| gallery_codes | VARCHAR(255) | Y | | 图库码 |
| status | ENUM('draft','active','superseded','archived') | N | | |
| effect_count | INT UNSIGNED | Y | | 效果图数 |
| notes | VARCHAR(255) | Y | | |
| created_at/updated_at | TIMESTAMP | Y | | |

索引：`INDEX(product_id, design_key)`，`INDEX(status)`。

## 8. listings（★ 上架单元·真源核心）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| account_id | BIGINT UNSIGNED | N | FK accounts | |
| platform | VARCHAR(24) | N | | **`amazon`**（预留其它平台；拉取时一眼知道） |
| marketplace | VARCHAR(16) | N | | 站点码 |
| product_id | BIGINT UNSIGNED | N | FK products | |
| design_id | BIGINT UNSIGNED | Y | FK designs | 同一商品多设计→多 listing |
| sku | VARCHAR(64) | N | | |
| parent_sku | VARCHAR(64) | Y | | 子体指向父 |
| is_parent | TINYINT(1) | N | | 默认 1 |
| variation_theme | VARCHAR(24) | Y | | COLOR / SIZE / COLOR/SIZE |
| variant_color | VARCHAR(32) | Y | | |
| variant_size | VARCHAR(16) | Y | | |
| amazon_product_type | VARCHAR(40) | Y | | 如 UNDERPANTS |
| status | ENUM('draft','candidate','ready','published','error','archived') | N | | |
| price | DECIMAL(12,2) | Y | | 上架价 |
| product_price | DECIMAL(12,2) | Y | | 商品价（split 口径） |
| shipping_fee | DECIMAL(12,2) | Y | | 运费档 |
| currency | CHAR(3) | Y | | |
| quantity | INT UNSIGNED | Y | | |
| asin | VARCHAR(16) | Y | | 发布后回填 |
| first_published_at | TIMESTAMP | Y | | ★**首次上架时间** |
| published_at | TIMESTAMP | Y | | ★**最近一次上架时间** |
| is_custom | TINYINT(1) | N | | 是否定制商品（POD），默认 1 |
| customization_json | JSON | Y | | ★**定制相关信息**（可扩展桶；见 §8.1） |
| revision | INT UNSIGNED | N | | 默认 1；乐观锁 |
| attrs_json | JSON | Y | | 其余全部 Amazon 字段 |
| is_complete | TINYINT(1) | N | | 完整性 |
| missing_fields | JSON | Y | | 缺哪些必填 |
| created_at/updated_at/deleted_at | TIMESTAMP | Y | | |

索引：`UNIQUE(account_id, platform, marketplace, sku)`；`INDEX(platform, marketplace, status)`；`INDEX(product_id)`；`INDEX(parent_sku)`；`INDEX(updated_at)`；`INDEX(first_published_at)`。

### 8.1 定制相关信息（POD·前瞻设计）★

**现状**：定制要素已经分散存在——
- `designs`：图案 / 布局模板 / 印刷面 / 效果图
- `product_variants`：颜色 / 尺码 / 规格
- `assets`：各角色图
- `templates`：贴字/布局模板（共享）

**前瞻**：再加一个**可扩展桶 `listings.customization_json`**，先容纳"定制相关类信息"，**不锁死结构**。待结构稳定、需要"可查/可筛/驱动 UI"时，再按需升级（二选一或并存）：

| 方案 | 表 | 用途 |
|---|---|---|
| EAV（可查） | `listing_customizations`(id, listing_id, key, value, type, source) | 按 `key` 精确查/筛定制项 |
| 定义字典 | `customization_definitions`(platform, product_type, key, label, type, enum_values, required, ui_json) | 声明"某品类支持哪些定制项"→ 驱动页面表单 + 校验（类比 `field_definitions`） |

**原则**：**先 JSON 兜底、后按需结构化** —— 现在就把字段位留好，将来平滑演进，不返工。

**★ 一条边界（别放错地方）**：分清两类"定制信息"——
| 类别 | 特征 | 归属 |
|---|---|---|
| **平台侧定制字段**（要发给 Amazon 的） | 如 Amazon「Custom / 定制」商品的属性字段 | → 进 `listings.attrs_json` + 登记 `field_definitions(platform='amazon')` |
| **内部定制/生产元数据**（不发 Amazon，自己流程用） | 印刷面、图案来源、DPI、合成参数、客户个性化… | → 进 `listings.customization_json` |

> 等你去研究完 Amazon「Custom」定制字段后，我们再把"平台侧"那部分按需**升级成列/字典**；内部那部分继续放 `customization_json`。**现在零返工**。


## 9. listing_revisions（版本快照）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| listing_id | BIGINT UNSIGNED | N | FK listings | |
| revision | INT UNSIGNED | N | | |
| snapshot_json | JSON | N | | 全字段快照 |
| changed_fields | JSON | Y | | 本次改了哪些 |
| source | VARCHAR(24) | Y | | human/agent/sync |
| author | VARCHAR(64) | Y | | 用户或机器标识 |
| created_at | TIMESTAMP | Y | | |

索引：`INDEX(listing_id, revision)`。

## 10. field_definitions（字段字典·驱动校验）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| platform | VARCHAR(24) | N | | amazon |
| product_type | VARCHAR(40) | N | | |
| field_key | VARCHAR(80) | N | | 如 `item_name` |
| group_name | VARCHAR(64) | Y | | 分组 |
| display_name | VARCHAR(128) | Y | | 中文名 |
| data_type | VARCHAR(24) | Y | | text/number/enum/… |
| required_rule | JSON | Y | | always / if-then |
| enum_values | JSON | Y | | 合法枚举 |
| source | VARCHAR(24) | Y | | 来源 |
| is_media | TINYINT(1) | N | | 是否"图片/视频 URL"字段（图片 URL 归一用） |
| media_role | VARCHAR(24) | Y | | main / other_1..8 / swatch（映射到 `assets.role`） |
| updated_at | TIMESTAMP | Y | | |

索引：`UNIQUE(platform, product_type, field_key)`。

## 11. blobs（★ 去重资产池·内容寻址）

> **去重核心**：一切媒体按**内容 sha256** 唯一定位；路径 = `blobs/<sha256[0:2]>/<sha256>.<ext>`（不可变、可永久缓存）。
> 同一张图不管被多少个 listing 引用 → **只有 1 行 blob、1 个 OSS 对象、1 个 URL**。

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| sha256 | CHAR(64) | N | **UQ** | 内容哈希（去重键） |
| storage_disk | VARCHAR(24) | N | | `oss` |
| storage_key | VARCHAR(512) | N | | OSS 对象 key（=哈希路径） |
| mime | VARCHAR(64) | Y | | |
| size_bytes | BIGINT UNSIGNED | Y | | |
| public_url | VARCHAR(1024) | Y | | 公网 URL（稳定） |
| source_type | ENUM('local','remote') | Y | | 本地文件 / 远端链接镜像 |
| origin_url | VARCHAR(1024) | Y | | 原始来源（指纹 CDN URL 等，留痕） |
| phash | CHAR(16) | Y | | 感知哈希（可选·近似重复提示） |
| ref_count | INT UNSIGNED | N | | 被引用次数（GC 用；可异步重建） |
| created_at | TIMESTAMP | Y | | |

索引：`UNIQUE(sha256)`，`INDEX(phash)`，`INDEX(ref_count)`。

### 11.1 blob_sources（外部 URL → blob 映射·免重复下载）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| blob_id | BIGINT UNSIGNED | N | FK blobs | |
| url_hash | CHAR(64) | N | | `sha256(规范化URL)` |
| url | VARCHAR(1024) | N | | 原始 URL |
| fetched_at | TIMESTAMP | Y | | 首次拉取时间 |

索引：`UNIQUE(url_hash)`，`INDEX(blob_id)`。

## 12. assets（媒体资产·图 + 视频）

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| blob_id | BIGINT UNSIGNED | N | FK blobs | |
| owner_type | VARCHAR(32) | N | | listing/design/product |
| owner_id | BIGINT UNSIGNED | N | | 多态关联 |
| media_type | ENUM('image','video') | N | | |
| role | VARCHAR(32) | N | | main/other_1..8/design/composite/original/video |
| position | SMALLINT UNSIGNED | Y | | 排序 |
| width / height | INT UNSIGNED | Y | | |
| duration_ms | INT UNSIGNED | Y | | 视频时长 |
| poster_blob_id | BIGINT UNSIGNED | Y | FK blobs | 视频封面 |
| status | ENUM('uploaded','verified','failed') | N | | 回源校验结果 |
| created_at/updated_at | TIMESTAMP | Y | | |

索引：`INDEX(owner_type, owner_id)`，`INDEX(media_type, role)`。

### 12.1 映射关系（owner ↔ 图片）★

**`assets` 就是"物料 ↔ 主体"的映射表**（多态）：

- **owner 一对多 assets**：一个主体可挂很多媒体（`owner_type` + `owner_id` + `role` + `position`）。
- **assets 多对一 blob**：多行 asset 可指向同一张图（内容寻址去重）。
- ⇒ **owner ↔ 图片 = 多对多**：同一张图可同时挂给多个 listing / 商品 / 设计。

**owner_type 取值与 role 约定**：

| owner_type | 典型 role | 说明 |
|---|---|---|
| `product` | `blank`(空白商品图) / `print_area`(印刷区参考) / `spec`(规格图) / `gallery` | **商品对应图片就存这里** |
| `design` | `pattern`(图案原稿) / `composite` / `effect`(效果图) | 设计相关物料 |
| `listing` | `main` / `other_1..8` / `video` / `swatch` | 上架用图（主图+附图+视频） |

用 `position` 排顺序；同一 `(owner_type, owner_id, role, position)` 唯一（upsert，重复推不产生重复行）。
> 例：给某**空白商品**存图 → `assets(owner_type='product', owner_id=88, role='blank', blob_id=…)`；
> 某个 **listing** 的主图 → `assets(owner_type='listing', owner_id=1201, role='main', blob_id=…)`。

## 13. templates（★ 模板登记库·共享复用）

> **集中式模板库**：贴字/布局模板 + 上架模板，**放中台给多人/多机共享**（同类商品零介入复用）。
> 两种形态：**参数型**（贴字模板，存 `spec_json`）与 **文件型**（xlsm 上架模板，存 `blob_id`）。

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| kind | ENUM('layout','amazon_xlsm','other') | N | | **layout=贴字/布局模板**；amazon_xlsm=上架模板 |
| platform | VARCHAR(24) | Y | | amazon / internal |
| product_type | VARCHAR(40) | Y | | 适用品类（如 SOCKS） |
| category / subcategory | VARCHAR(64) | Y | | 贴字模板分类（如 服饰/袜子） |
| aspect | DECIMAL(8,4) | Y | | 印刷区宽高比（自动匹配用） |
| name | VARCHAR(128) | N | | 模板名/key |
| spec_json | JSON | Y | | **参数型**：贴字模板的 norm{w,h,cx,cy}/params 等 |
| blob_id | BIGINT UNSIGNED | Y | FK blobs | **文件型**：xlsm 等模板文件 |
| uses | INT UNSIGNED | N | | 被复用次数 |
| status | ENUM('active','disabled') | N | | |
| created_by | VARCHAR(64) | Y | | 谁建的（共享库可溯源） |
| created_at/updated_at | TIMESTAMP | Y | | |

索引：`UNIQUE(kind, platform, name)`，`INDEX(category, subcategory)`，`INDEX(product_type)`，`INDEX(status)`。

## 14. 身份域（Spatie/Sanctum 标准）

- `users`(id, name, email UQ, password, status, timestamps)
- `roles` / `permissions` / `model_has_roles` / `model_has_permissions` / `role_has_permissions`（spatie）
- `personal_access_tokens`（Sanctum：tokenable、name、abilities JSON、last_used_at、expires_at）

## 15. audit_logs（activitylog）

- `activity_log`(id, log_name, description, subject_type, subject_id, causer_type, causer_id, properties JSON, created_at)

## 16. sync_jobs / outbox / settings

**sync_jobs**：id, machine_id, direction(push|pull), scope, cursor, status, stats_json, started_at, finished_at
**outbox_events**：id, type, payload_json, status(pending|done|failed), created_at, processed_at

**settings（运行时可调·免发版）**：

| 字段 | 类型 | 空 | 键 | 说明 |
|---|---|---|---|---|
| id | BIGINT UNSIGNED | N | PK | |
| group | VARCHAR(32) | N | | media / sync / listings / catalog / auth |
| key | VARCHAR(64) | N | | 如 `media.cdn_domain` |
| value_json | JSON | Y | | 值 |
| description | VARCHAR(255) | Y | | |
| updated_by | BIGINT UNSIGNED | Y | FK users | 审计 |
| updated_at | TIMESTAMP | Y | | |

索引：`UNIQUE(group, key)`。取值优先级：**settings 表 > `config/*.php` > 代码兜底**（详见 `CONFIG.md`）。

---

## 17. 关系图（简）

```
products ──┬─ n:N ─ suppliers        （经 product_suppliers：来源对应）
           ├─ n:N ─ categories       （经 product_categories：多平台分类）
           ├─ 1:n ─ product_variants ─ 1:n ─ product_shipping
           ├─ 1:n ─ designs
           └─ 1:n ─ listings  n─1 accounts / designs
                    listings 1:n listing_revisions
listings/designs/products 1:n assets n─1 blobs
marketplaces(platform,code)   field_definitions(platform,product_type)   categories(platform,code)
```

---

## 18. 关键取舍

1. **代理键 vs 业务键**：全部用自增 `id` 做 PK；业务键 UQ —— 便于换供应商/平台、便于关联。
2. **商品来源 & 分类都是多对多**：`product_suppliers`（商品↔供应商）、`product_categories`（商品↔分类，带 `platform`）→ 一个商品可多供应商来源、可跨平台多分类，**将来扩供应商/平台零改表**。
3. **`platform` 维度**：`listings.platform` + `marketplaces.platform` + `field_definitions.platform`；默认为 `amazon`，**非 Amazon 平台将来零改表**。
4. **热字段成列 + `attrs_json`**：`attrs_json` 是 **MySQL 的 JSON 列**（数据存在库里，可用 `JSON_EXTRACT`/生成列查询），**不是磁盘上的 JSON 文件**；既好查，又能容纳 Amazon 全部 269 字段。将来若要"每个字段一列"，可再镜像出一张 `listing_attributes`(EAV) 表，二者并存不冲突。
5. **商品/物流/变体拆表**：变体与各国运费**可查可算**；其余零散进 `detail_json`。
6. **媒体统一 `assets`(+`blobs`)**：图/视频一张表，内容寻址去重，公共读 URL 稳定。
7. **不存卖家凭证**：发布在本地，安全面最小。
8. **暂不做多账号隔离**：`account_id` 仍保留（将来要隔离只是加 Policy/Scope，不改表）。
9. **先 JSON 兜底、后按需结构化**：`attrs_json` / `customization_json` / `detail_json` 都是"结构未定先容纳、稳定后升级成表"的策略，**永不返工**。
