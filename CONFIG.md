# 云端中台 · 配置项设计（CONFIG）v1.0

> 目标：**任何会变的值都必须落配置，绝不硬编码**；多环境 / 多平台 / 多供应商 / 多站点下都能复用。

---

## 0. 四层配置 + 判定规则

| 层 | 放什么 | 载体 | 谁改 | 发版？ |
|---|---|---|---|---|
| **① 环境/密钥** | 地址、口令、密钥、磁盘、域名 | **`.env`** | 运维 | 否（重载即可） |
| **② 应用默认/阈值** | 默认值、限制、策略 | **`config/*.php`** | 开发 | 是（随代码） |
| **③ 业务字典** | 站点/分类/字段/模板/角色/能力 | **DB 表** | 运营/管理员 | **否（页面/API）** |
| **④ 运行时可调开关** | 少量无需发版的参数 | **`settings` 表** | 管理员 | **否** |

**判定口诀**：因"**环境/密钥**"变 → `.env`；因"**代码逻辑**"变 → `config`；因"**业务数据**"变 → **DB 字典表**；管理员要**随时改** → **`settings` 表**。**一律不写死**。

---

## 1. `.env`（环境/密钥）清单

> 可直接复制 **`env.example`** 改值；逐项归类见 **`CONFIG-FILL.md`**（哪些你给/我生成/有默认）。

```dotenv
# 应用
APP_NAME=listing-hub
APP_ENV=production
APP_KEY=base64:…            # artisan key:generate
APP_URL=https://hub.example.com
APP_TIMEZONE=Asia/Shanghai
APP_LOCALE=zh_CN

# 数据库 / 缓存 / 队列
DB_CONNECTION=mysql
DB_HOST=mysql  DB_PORT=3306  DB_DATABASE=listing_hub  DB_USERNAME=…  DB_PASSWORD=…
REDIS_HOST=redis  REDIS_PORT=6379  REDIS_PASSWORD=…
CACHE_STORE=redis  SESSION_DRIVER=redis  QUEUE_CONNECTION=redis

# 阿里云 OSS（媒体图床）
OSS_ACCESS_KEY_ID=…
OSS_ACCESS_KEY_SECRET=…
OSS_BUCKET=…
OSS_ENDPOINT=oss-cn-<region>.aliyuncs.com
OSS_INTERNAL_ENDPOINT=oss-cn-<region>-internal.aliyuncs.com   # 同区内网（ECS 用）
OSS_CDN_DOMAIN=media.example.com        # 绑定的自有/ CDN 域名（可空→用 OSS 默认域）
OSS_PATH_PREFIX=blobs                   # 内容寻址前缀

# 媒体对外
MEDIA_PUBLIC_BASE_URL=https://media.example.com
MEDIA_ALLOWED_HOSTS=media.example.com,oss-cn-<region>.aliyuncs.com   # ★图片URL归一的"自有域名白名单"

# 鉴权
SANCTUM_STATEFUL_DOMAINS=hub.example.com

# 备份 / 邮件
BACKUP_DISK=oss
MAIL_MAILER=smtp  MAIL_HOST=…  MAIL_PORT=…  MAIL_USERNAME=…  MAIL_PASSWORD=…
```

> **密钥只进 `.env`**：不入代码、不入库、不入 API 响应；RAM 子账号 + 最小权限 + 可轮换。

---

## 2. `config/*.php`（应用默认/阈值）清单

**config/media.php**
- `disk`(oss) · `path_prefix` · `public_base_url` · `allowed_hosts[]` · `presign_ttl`
- `verify_enabled`(上架前回源 HEAD 校验) · `thumb_sizes[]` · `video_max_mb` · `phash_enabled`

**config/sync.php**
- `default_page_size` · `max_batch` · `scopes[]`(products,variants,shipping,designs,listings,assets)
- `incremental_field`(updated_at) · `cursor_precision` · `conflict_policy`(lww) · `csv_bundle.columns_ref`(对齐 `database/*.csv`)

**config/listings.php**
- `status_enum[]` · `revision_policy`(单调递增) · `default_currency` · `default_quantity` · `require_completeness`(发布前必须 is_complete)

**config/catalog.php**
- `supplier_default`(hicustom) · `category_tree_depth` · `auto_match_aspect_tolerance`(贴字模板自动匹配阈值)

**config/auth.php（扩展）**
- `roles[]`(viewer/editor/publisher/admin) · `abilities[]`(catalog:read|write、listings:read|write、assets:write、admin) · `token_ttl`

**config/queue.php / horizon.php**
- `queues[]`(assets, exports, sync) · `retries` · `timeout` · `tries`

**config/platforms.php**
- `platforms[]`(amazon…) · 各平台默认站点/语言映射（业务字典的"默认种子"，实际值仍在 DB）

---

## 3. `settings` 表（运行时可调·免发版）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | BIGINT | PK |
| group | VARCHAR(32) | media / sync / listings / catalog / auth |
| key | VARCHAR(64) | 如 `media.cdn_domain` |
| value_json | JSON | 值 |
| description | VARCHAR(255) | 说明 |
| updated_by | BIGINT | 谁改的（审计） |
| updated_at | TIMESTAMP | |

唯一键 `(group, key)`。**用途**：管理员在页面改、立即生效、无需发版。例：
`media.cdn_domain`、`media.verify_enabled`、`sync.default_page_size`、`listings.default_currency`、`catalog.auto_match_aspect_tolerance`。

> 取值优先级：**settings 表（若存在） > config/*.php 默认 > 代码兜底**。

---

## 4. 务必"配置化"的复用点（别写死）★

| 复用点 | 配置位 | 变了只需改 |
|---|---|---|
| 媒体域名 / CDN | `OSS_CDN_DOMAIN` / `MEDIA_PUBLIC_BASE_URL` / `settings:media.cdn_domain` | 配置（**历史 URL 不用回改**，读取时渲染） |
| 自有域名白名单（URL 归一幂等） | `MEDIA_ALLOWED_HOSTS` | 配置 |
| OSS（桶/端点/内网/前缀） | `OSS_*` | 配置 |
| 站点 / 分类 / 字段 / 模板 / 能力 | **DB 字典表** | 数据（页面/API） |
| 同步（分页/批量/scope/冲突） | `config/sync.php` + `settings:sync.*` | 配置 |
| 去重（视频上限、phash 开关） | `config/media.php` | 配置 |
| 校验（回源校验开关、必填口径） | `config/media.php` / `field_definitions` | 配置/数据 |
| 权限（角色/能力） | `config/auth.php` + DB | 配置 |
| 队列（队列名/重试/超时） | `config/queue.php` | 配置 |
| 备份（保留天数/目标盘） | `config/backup.php` | 配置 |

---

## 5. 原则

1. **不硬编码**：任何"以后可能变"的值 → 配置项。
2. **密钥只进 `.env`**：代码/库/API 响应里**永不出现**。
3. **有默认、缺失不崩**：`config` 提供默认值；`settings` 覆盖；代码兜底。
4. **可复用**：多环境靠 `.env`；多平台/供应商/站点靠 **DB 字典**；二者机制统一。
5. **可审计**：`settings` 改动记 `updated_by`。
