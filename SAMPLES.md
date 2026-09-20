# 样本与字段映射（源自现有数据结构）

> 来自 `skills/hicustom-api`。用于 M1 的建表/种子/迁移映射。（只摘结构，非全文）

---

## 1. `product.json`（最新样本：`output/11973/product.json`）

两级：`raw`（上游原始详情）+ `profile`（本仓归一）。

- **`raw`**：`id, cn_name, en_name, default_values{color,view}, colors[], sizes[], stock_info[], hot_stars, factory_name, spu_code, size_attr_map[]/size_attr_info[], product_timeliness{}, product_description{print_areas[], product_technology, material_explain[], product_features[]}, prices{price, default_*, wholesale_price[]}, renderings_info[], detail_img[]`
- **`profile`**：
  - `meta{parsedAt,hotStars,timeliness}`
  - `identity{id, spuCode, cnName, enName, alias, factory, releaseTime}`
  - `attributes{technology, materialCn, materialEn, description, materialExplain[], productFeatures[]}`
  - `designFaces[{id,name,width,height}]`
  - `colors[{id,cnName,enName,tone1,tone2}]`
  - `sizes[{id,name,spec,dims,length,width,height}]`
  - `variants[{id,code,colorId,sizeId,length,width,height,volume,weight}]`
  - `pricing{minPrice, defaultColorId/Name, defaultSizeId/Name, accumulatedQuarter, membership, tiers[{stockInfo,retail,gold,platinum,diamond,blackDiamond,starDiamond}]}`
  - `images{renderings[{colorId,colorName,renderings[]}], detailImg[]}`

> ⚠️ **product.json 不含物流费** → 物流在 `products.csv` 的 `shipping_*` 列（或 `output/<id>/shipping.json`）。

**映射**：
| 目标表 | 来源 |
|---|---|
| `products` | `profile.identity` + `attributes` + `designFaces[0]`(印刷区) |
| `product_variants` | `profile.variants`（尺寸重量）+ `pricing.tiers`(成本价档) |
| `product_shipping` | `products.csv` 的 `shipping_<C>` / `shipping_channel_<C>`（按国家拆分） |

---

## 2. `database/products.csv`（**79 列**）

```
id, spu_code, cn_name, en_name, alias, factory, material, material_en, technology, release_time,
is_custom, default_color_id, default_color_name, default_size_id, default_size_name,
variant_id, variant_code, variants_count, colors, sizes,
size_L_cm, size_W_cm, size_H_cm, package_L_cm, package_W_cm, package_H_cm, volume_cm3, weight_g,
design_face_w, design_face_h, design_face_count, min_price,
qty_from, qty_to, retail_price, gold_price, platinum_price, diamond_price, black_diamond_price, star_diamond_price,
shipping_US, shipping_UK, shipping_CA, shipping_DE, shipping_MX, shipping_FR, shipping_ES, shipping_IT,
shipping_channel_US, shipping_channel_UK, shipping_channel_CA, shipping_channel_DE, shipping_channel_MX, shipping_channel_FR, shipping_channel_ES, shipping_channel_IT,
freight_template_US, freight_template_UK, freight_template_CA, freight_template_DE, freight_template_MX, freight_template_FR, freight_template_ES, freight_template_IT,
shipping_updated_at,
price_US, price_UK, price_CA, price_DE, price_MX, price_FR, price_ES, price_IT, price_currency, rate_note,
status, notes, created_at, updated_at
```
- **物流在此**（`shipping_* / shipping_channel_*`）→ `product_shipping`
- `price_<C> / freight_template_<C>` = 各国上架价口径

## 3. `database/listing_copy.csv`（**39 列**，上架真源）

```
id, design_code, marketplace, product_type, sku, item_name, highlight,
bullet_1, bullet_2, bullet_3, bullet_4, bullet_5, product_description, generic_keyword,
material, fabric_type, color, size, capacity, capacity_unit, model_number, model_name,
handling_time, country_of_origin, price, currency, template, amazon_template,
status, source, generated_at, edited_at, updated_by, review_notes, notes,
shipping_fee, product_price, attrs_json, row_id
```
- 热列 → `listings`；**`attrs_json` → `listings.attrs_json`（直接搬）**

## 4. `database/listing_variants.csv`（**23 列**，变体子体）

```
row_id, parent_row_id, id, marketplace, sku, parent_sku, variation_theme, variant_value, variant_code,
variant_color, variant_size, design_code, main_image, other_images,
price, product_price, shipping_fee, quantity, status, source, generated_at, edited_at, notes
```
- → `listings`（`is_parent=false` 子体行；`parent_sku` 关联）

## 5. `database/designs.csv`（**23 列**）

```
design_code, product_id, design_key, version, parent_code, source, adjust, cn_name, en_name,
design_zh_name, design_zh_tags, design_en_name, design_en_tags, design_pattern, design_template,
gallery_codes, effect_image_count, main_image, other_images, status, notes, created_at, updated_at
```
- → `designs`

## 6. `amazon_category_table/schema/*.json`（**12 个品类**，精简 schema）

结构：
```
{ _note, pt, marketplace, marketplaceKey, productTypeVersion, displayName, fetchedAt,
  required: ["brand","bullet_point",...],                       // 顶层必填
  conditionalRules: [ {when, then:[...]} ],                     // if-then
  propertyGroups: { offer, images, shipping, variations, safety_and_compliance, product_identity, product_details },
  properties: { <field>: { t, type, slots[], zh?, enum?, enumOpen, slotEnums? } } }
```
**12 品类**：BASKET · COSMETIC_CASE · DUFFEL_BAG · HANDBAG · HANGING_ORNAMENT · HAT · PICNIC_HOLDER · SOCKS · STORAGE_BAG · THERMOS · TOTE_BAG · UNDERPANTS
文件：`<PT>_A1F83G8C2ARO7P.json`（约 140–215KB/个）

**→ `field_definitions`（platform=amazon, product_type=pt）**：
| 字段 | 来源 |
|---|---|
| `field_key` | `properties` 的键 |
| `display_name` | `properties[k].zh \|\| .t` |
| `data_type` | `properties[k].type` |
| `enum_values` | `properties[k].enum` / `.slotEnums` |
| `required_rule` | `required[]`(always) + `conditionalRules[]`(if-then) |
| `group_name` | 按 `propertyGroups` 归属 |
| `is_media` / `media_role` | 按 `images` 组 / `main_product_image_locator`·`other_product_image_locator_N` 规则 |

---

## TBD（还需你/后续确认）
- `attrs_json` 的"图片 locator"字段清单（归一用）——从 schema `images` 组提取即可，M1 时生成。
- 账号/站点种子：待你给（先 1 账号 + UK）。
