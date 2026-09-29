# 中台变体同步改造 · 部署说明（宝塔 / Laravel）

> 补丁：`hub-patch-variants.tar.gz`
> 内容：多规格物流费 / 多规格售价 / 父子变体关系 → 独立 `listing_variants` 表
> 目标站点根：`/www/wwwroot/cbc.weixiubang.club`（**解压到站点根，不是 app/ 或 migrations/ 子目录**）

---

## 0. 补丁内容（3 改 + 1 迁移 + 1 新模型）

| 文件 | 动作 |
|---|---|
| `server/database/migrations/2026_09_29_000001_create_listing_variants_table.php` | **新增**（幂等建表 + products 补列 + 旧数据迁移） |
| `server/app/Domain/Listing/Models/ListingVariant.php` | **新增** 模型（含 casts） |
| `server/app/Http/Controllers/Api/SyncController.php` | **改**（push 新增 variants 通道 / pull 新增 scope / CSV 直读新表 / products 8 国物理列） |
| `server/app/Http/Controllers/Api/ListingController.php` | **改**（children 改读新表） |

> ⚠️ `model + migration + controller` 三者必须**一起**部署，缺一不可。

---

## 1. 部署前必做（铁律）

```bash
# ① 备份数据库（迁移不可回滚）
mysqldump -u <user> -p <db> > /root/listing-hub-backup-$(date +%F).sql

# ② 核对补丁字节数 + SHA256（与交付清单一致）
sha256sum hub-patch-variants.tar.gz
```

---

## 2. 部署

```bash
cd /www/wwwroot/cbc.weixiubang.club

# ① 解压覆盖（tar 内路径已含 server/ 前缀）
tar -xzf /root/hub-patch-variants.tar.gz

# ② 跑迁移（幂等，可安全重跑）
php artisan migrate --force

# ③ 清缓存（改过代码/迁移后必须）
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan view:clear
```

---

## 3. 验证

```bash
# ① 表已建 + 列已补
php artisan tinker --execute="echo Schema::hasTable('listing_variants') ? 'table OK' : 'MISSING';"
php artisan tinker --execute="echo Schema::hasColumn('products','shipping_UK') ? 'products cols OK' : 'MISSING';"

# ② 旧子体已迁移（应为 >0）
php artisan tinker --execute="echo App\Domain\Listing\Models\ListingVariant::count();"

# ③ 接口自测（本地 CLI 跑）
#    cd skills/hicustom-api
#    node scripts/hub.js import            # 推 variants
#    node scripts/hub.js pull variants     # 拉回验证
#    node scripts/hub.js pullcsv           # 合并拉回 listing_variants.csv
```

---

## 4. 回滚

```bash
# 代码回滚：恢复上一版 server/（保留备份）
# 数据库回滚：php artisan migrate:rollback --step=1  （或从 §1 备份还原）
```

---

## 5. 兼容性

- **老客户端**（仍把子体塞在 `listings[]` 且 `is_parent=false`）：服务端**自动转存**新表，不报错。
- **老数据**：`listings` 里的旧子体行**保留不动**（迁移只读复制，不删）。
- **products 8 国列**：CSV 导出**物理列优先 → profile_json 桶兜底**，兼容两种来源。
