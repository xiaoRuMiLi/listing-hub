# 中台删除 API 部署说明（宝塔 / Laravel）

> 补丁：`hub-patch-delete-api.tar.gz`（5860 B）
> SHA256：`9cd0ea032a72208d735c4019dae1342f956b40a07e20083883c9f58df2748f2c`
> 内容：软删支撑（`deleted_at` + unique 改复合）+ `users.is_admin` + 删除控制器 + 5 条路由 + CLI `remove`
> 目标站点根：`/www/wwwroot/cbc.weixiubang.club`，**解压到站点根**

---

## 0. 补丁内容（7 文件）

| 文件 | 动作 |
|---|---|
| `server/database/migrations/2026_09_29_000002_soft_deletes_and_unique_composite.php` | **新增**（补 `deleted_at` + unique 改复合 + `users.is_admin`） |
| `server/app/Http/Controllers/Api/DeleteController.php` | **新增** |
| `server/app/Models/User.php` | **改**（`is_admin` cast + `isAdmin()`） |
| `server/app/Domain/Design/Models/Design.php` | **改**（`SoftDeletes`） |
| `server/app/Domain/Catalog/Models/ProductVariant.php` | **改**（`SoftDeletes`） |
| `server/app/Domain/Catalog/Models/ProductShipping.php` | **改**（`SoftDeletes`） |
| `server/routes/api.php` | **改**（5 条 DELETE 路由） |

> ⚠️ 本次**需要** `php artisan migrate`（有结构变更）。

---

## 1. 部署前必做（铁律）

```bash
# ① 备份数据库（migrate 有结构变更）
mysqldump -u cbc_weixiubang -p cbc_weixiubang > /root/listing-hub-backup-$(date +%F).sql

# ② 备份将被覆盖的文件
cd /www/wwwroot/cbc.weixiubang.club
tar -czf /root/pre-delete-api-$(date +%Y%m%d-%H%M%S).tgz \
  server/routes/api.php server/app/Models/User.php \
  server/app/Http/Controllers/Api/DeleteController.php \
  server/app/Domain/Design/Models/Design.php \
  server/app/Domain/Catalog/Models/ProductVariant.php \
  server/app/Domain/Catalog/Models/ProductShipping.php 2>/dev/null || true

# ③ 核对 SHA256（应 = 9cd0ea03...748f2c）
sha256sum /root/hub-patch-delete-api.tar.gz
```

---

## 2. 部署

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 解压覆盖
tar -xzf /root/hub-patch-delete-api.tar.gz

# ② 语法自检（3 个关键文件）
php -l server/app/Http/Controllers/Api/DeleteController.php
php -l server/database/migrations/2026_09_29_000002_soft_deletes_and_unique_composite.php
php -l server/app/Models/User.php

# ③ 跑迁移（结构变更）
php artisan migrate --force

# ④ 清缓存
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
```

---

## 3. 验证

```bash
# ① 列/表已加
php artisan tinker --execute="echo Schema::hasColumn('users','is_admin') ? 'is_admin OK' : 'MISSING';"
php artisan tinker --execute="echo Schema::hasColumn('designs','deleted_at') ? 'designs softdel OK' : 'MISSING';"

# ② 管理员已设
php artisan tinker --execute="echo App\Models\User::where('email','admin@weixiubang.club')->value('is_admin');"

# ③ 路由已注册
php artisan route:list | grep -E "DELETE.*products|by-code|by-sku"

# ④ CLI 侧（本地）：
#    node scripts/hub.js remove --product 99999   # 不存在→幂等 ok
```

---

## 4. 回滚

```bash
cd /www/wwwroot/cbc.weixiubang.club
php artisan migrate:rollback --step=1     # 撤销本次 migration
tar -xzf /root/pre-delete-api-<时间戳>.tgz   # 恢复文件
php artisan config:clear && php artisan config:cache
```

---

## 5. 权限口径（Q5）

- **管理员**（`users.is_admin=1`，首个 = `admin@weixiubang.club`）：删任意
- **普通用户**：只删 `pushed_by` 含自己 email 的行
- 越权 → **403** `forbidden`

## 6. 语义要点

- **软删**（`deleted_at`）：可恢复；`blobs`/`assets` **永不删**
- **Q3**：商品有已上架 listing → 无 `?force=true` 拒删（409 `has_published_listings`）
- **Q4**：design 仍被引用 → 仅解绑；无引用 → 软删（孤儿）
- **幂等**：删不存在 → `ok:true`
