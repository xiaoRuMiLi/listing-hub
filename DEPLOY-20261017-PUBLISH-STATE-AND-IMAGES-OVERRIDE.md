# 中台补丁 2026-10-17 · ①push 保留发布态 ②图片 override 双向同步

> 变更日：**2026-10-17**
> 目标站点根：`/www/wwwroot/cbc.weixiubang.club`（**解压到站点根**，包内路径已对齐 `app/...`、`database/...`）
> 本包 = 原「keep-publish-state」单补丁 + 新增「images-override」,**一次打完即可 3 个问题全解**。

---

## 0. 解决什么（两个真实故障 · 均已实测复现）

### 故障 A：push 抹掉「发布态」

2026-10-17 12:48 对 12495 执行 `hub:push --ids 12495` 后，中台 listing #103：

| 字段 | 推送前 | 推送后 | 问题 |
|---|---|---|---|
| `status` | `published` | **`ready`** | 已上架记录被打回未上架 |
| `asin` | `B0HN8DGMWL` | **`null`** | ASIN 被清空 |

根因：本地 `listing_copy.csv` 没有 `asin`/`published_at`，`status` 长期停在 `ready`；
`SyncController` 对已存在的行直接 `$existing->fill($row)` → 内容字段和发布状态一起被覆盖。

### 故障 B：图片「删了却还在 / 拉回又被冲掉」

实测（12495）：人工删掉 2 张附图 → push 成功（中台 8 张）→ **`hub:pull` 回拉后变回 11 张**。
根因：`hub:pull` 会触发 `listing:rebuild` → PullFlow 用指纹接口 `effectImages` **覆盖** `designs.other_images`。
本质是「图」只有一个存储位，**无法区分「上游重新出图」与「人工编辑」**。

---

## 1. 改动清单（2 改 1 增 · 1 个迁移）

| 文件 | 动作 |
|---|---|
| `app/Http/Controllers/Api/SyncController.php` | **改**：① listing upsert 加「发布态保护」；② designs upsert/导出/JSON 增加 3 个图片字段 |
| `database/migrations/2026_10_17_000001_add_images_override_to_designs.php` | **新增**：`designs` 加 3 列（幂等） |
| （本地 `skills/hicustom-api`，不在本包内） | 编译器/拉取/ERP 已同步改好，见 §5 |

### 1.1 发布态保护（`if ($existing)` 内，`fill()` 之前）

```php
if (($row['asin'] ?? null) === null && ! empty($existing->asin)) { $row['asin'] = $existing->asin; }
$incomingStatus = $row['status'] ?? null;
if ((string) $existing->status === 'published' && (string) $incomingStatus !== 'published') {
    $row['status'] = 'published';
    $warnings[] = '[WARN] ' . $sku . ' 已上架，忽略推送的 status=...（保持 published；如需下线请走 unpublish）';
}
```

口径：**发布类字段只由 `publish-result` / `unpublish` 改写**，push 一律不降级；未上架的行行为完全不变。

### 1.2 新增列（`designs` 表）

| 列 | 类型 | 含义 |
|---|---|---|
| `images_override` | text null | **人工编辑后的整组图快照**（`main|o1|o2...`，含主图） |
| `images_override_at` | string(32) null | override 写入时间（ISO8601） |
| `images_upstream` | text null | 上游最近一次的整组图（**影子列**，仅供对比，不参与发布） |

> 存**字符串**而非 JSON cast —— 与本地 `designs.csv` 同形，避免两端二次编码（同 `attrs_json` 的处理口径）。

**为什么用「整组快照」而不是墓碑字段**：删（变短）/改（替换）/增（变长）三种变化都能自然表达，
不需要额外的 `deleted_at` 之类标记；清除 override 就回到「跟随上游」。

---

## 2. 部署步骤

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 备份
cp app/Http/Controllers/Api/SyncController.php /root/SyncController.php.bak-20261017

# ② 先做语法自检（重要：本包未在本地 PHP 上验证过语法）
/www/server/php/83/bin/php -l app/Http/Controllers/Api/SyncController.php

# ③ 解压覆盖（包内是 app/... 与 database/migrations/... 相对路径）
tar -xzf hub-patch-20261017-publish-state-and-images-override.tar.gz -C /www/wwwroot/cbc.weixiubang.club

# ④ 再自检一次
/www/server/php/83/bin/php -l app/Http/Controllers/Api/SyncController.php
/www/server/php/83/bin/php -l database/migrations/2026_10_17_000001_add_images_override_to_designs.php

# ⑤ 跑迁移（幂等，可重跑）
/www/server/php/83/bin/php artisan migrate --force

# ⑥ 清缓存
/www/server/php/83/bin/php artisan optimize:clear
```

> 若 `migrate` 报 "Nothing to migrate"，说明迁移名已被记录 → 用
> `php artisan tinker --execute="Schema::hasColumn('designs','images_override') ? print('OK') : print('MISSING');"` 复核列是否存在。

---

## 3. 验证

### 3.1 列已加

```bash
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "https://cbc.weixiubang.club/api/v1/sync/pull?format=csv&dataset=designs" | head -1
# 表头末尾应出现：...,updated_at,images_override,images_override_at,images_upstream
```

### 3.2 发布态不被抹

```bash
# 重推一次（内容没变也没关系）
node scripts/hub.js hub:push --ids 12495
# 再查：status 应仍为 published、asin 仍为 B0HN8DGMWL
# 且 push 回执 warnings[] 里出现「已上架，忽略推送的 status=ready」
```

### 3.3 图片 override 生效

```bash
# ① 在 ERP 列表页删 2 张 → 点「💾 保存效果图」（会写 images_override）
# ② push
node scripts/hub.js hub:push --ids 12495
# ③ 查中台
curl -s -H "Authorization: Bearer $TOKEN" .../api/v1/designs ... | grep images_override   # 应为 9 项(含主图)
# ④ 回拉
node scripts/hub.js hub:pull --ids 12495 --no-rebuild
# ⑤ designs.csv 的 other_images 应仍为 8 张（不再被上游 11 张冲掉）
```

---

## 4. 回滚

```bash
cp /root/SyncController.php.bak-20261017 app/Http/Controllers/Api/SyncController.php
/www/server/php/83/bin/php artisan migrate:rollback --step=1   # 只回滚本迁移
/www/server/php/83/bin/php artisan optimize:clear
```

> 回滚迁移会**删掉 3 列**（含已写入的 override 数据）。若只想停用不改数据，
> 保留列、把 `SyncController` 回滚即可 —— 旧代码不认识这 3 列，不会报错。

---

## 5. 配套的本地改动（不在本包内，需要各机器更新 `skills/hicustom-api`）

| 文件 | 改动 |
|---|---|
| `scripts/app/Support/DesignRepository.js` | `SCHEMA` 末尾加 3 列（23→26） |
| `scripts/app/Support/ProductRepository.js` | 设计入库写 `images_upstream`；**有 override 时强制 `main_image/other_images` 对齐 override**（上游覆盖无效） |
| `scripts/app/Support/ListingRecordCompiler.js` | 优先读 `images_override`（整组含主图），否则回退 `main_image/other_images` |
| `scripts/app/Support/ListingImageAudit.js` | 上架图片闸同步用 override 口径 |
| `scripts/server/serve.js` | `POST /api/listing/effect/save` 设计级保存时写 override；新增 `clearOverride:true` 清除 |
| `scripts/hub.js` | push 带 3 个新字段 |
| `scripts/app/pages/listing.html` | 右键菜单加「♻️ 恢复上游图（清除人工覆盖）」；保存回显标注「已标记人工覆盖」 |
| `database/designs.csv` | 表头已加 3 列（已有备份 `.bak-images-override-20261017`）。**其它电脑**：先更新代码，再跑 `node scripts/dev/oneshot/_designs-images-override.js migrate`（幂等 + 自动备份） |

⚠️ **顺序很重要**：**先部署中台本补丁，再让各机器 `hub:push`**。否则本地把 `images_override`
推给尚未建列的中台 → Laravel 写不存在的列 → 500。

---

## 6. 已知未决（本包不含）

| 项 | 状态 |
|---|---|
| 改 SKU → 中台多一条 | **未做**。原设想「row_id 优先」**不可行**（本地 row_id 会撞号：10809 与 11801 同为 60）。正确做法：`(账号,站点,SKU)` 未命中后，再按 `(账号,站点,product_id,local_row_id)` 判定改名。 |
| `hub:pull` 默认会 rebuild | **未改**。仍会触发 PullFlow，但图集已有 override 保护；文案/价格等其它字段仍可能被上游刷新。 |
| 上游图变了但存在 override | 目前**只写影子列，不提示**。后续可在 ERP 列表页加「上游已更新（N→M 张）」横幅。 |
