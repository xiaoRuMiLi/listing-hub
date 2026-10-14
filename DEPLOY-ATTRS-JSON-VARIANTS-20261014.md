# 中台 · `listing_variants.attrs_json` 加列 · 部署说明（宝塔 / Laravel）

> 变更日：2026-10-14
> 目的：**逐子体可带「属性覆盖」**（典型：逐子体 `recommended_browse_nodes` 浏览节点值）。
> 目标站点根：`/www/wwwroot/cbc.weixiubang.club`（解压到**站点根**，不是 app/ 或 migrations/ 子目录）
> 无 tar 补丁，本次改动可直接在站点上编辑/放置（见 §0 清单）。

---

## 0. 改动清单（3 改 + 1 迁移）

| 文件 | 动作 |
|---|---|
| `server/database/migrations/2026_10_14_000001_add_attrs_json_to_listing_variants.php` | **新增**（幂等加列 `attrs_json` TEXT，`after('pkg_weight')`） |
| `server/app/Http/Controllers/Api/SyncController.php` | **改**（① push `upsertVariant` 落该列 ② pull `variants` 带回 ③ CSV 导出 `listing_variants` 列对齐 + `H_VARIANTS` 加列） |
| `cli/hub.js`（可选，客户端侧） | **改**（push `variants[]` 带 `attrs_json`；本仓 `scripts/hub.js` 为准） |

> 列格式：**JSON 文本**（**路径口径**，与 `listing_copy.attrs_json` 一致），如
> `{"recommended_browse_nodes[marketplace_id=A1F83G8C2ARO7P]#1.value":"1730945031","_browse_node_path":"…"}`；
> `_` 前缀键仅审阅用。**刻意不 cast 成 array**（两端同形，避免二次编码）。

---

## 1. 部署前必做（铁律）

```bash
# ① 备份数据库
mysqldump -u <user> -p <db> > /root/listing-hub-backup-$(date +%F)-attrsjson.sql
```

---

## 2. 部署

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 放置/覆盖上述文件（migration + SyncController + cli/hub.js）
# ② 跑迁移（幂等，可安全重跑）
php artisan migrate --force

# ③ 清缓存
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan view:clear
```

---

## 3. 验证

```bash
# ① 列已加
php artisan tinker --execute="echo Schema::hasColumn('listing_variants','attrs_json') ? 'col OK' : 'MISSING';"

# ② 接口自测（本地 skills/hicustom-api）
node scripts/hub.js import              # push（variants 带 attrs_json）
node scripts/hub.js pull variants       # pull（应有 attrs_json 字段）
node scripts/hub.js pullcsv             # 合并回 database/listing_variants.csv（attrs_json 列保留）
```

---

## 4. 回滚

```bash
# 代码回滚：恢复上一版 SyncController.php（与 cli/hub.js）
# 数据库回滚：
php artisan migrate:rollback --step=1     # 或从 §1 备份还原
```

---

## 5. 兼容性

- **老客户端**不带 `attrs_json` → 列留空（= 子体完全继承父体），**不报错**。
- **老数据**：既有行该列为 NULL，行为不变。
- **CSV 合并**（`pullcsv`）按表头并集处理 → 本地已有该列时正常合并；服务端非空值优先。
