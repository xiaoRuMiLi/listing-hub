# 云端中台 · 系统架构设计方案 v1.0

> 项目代号：**中台**（≠GitHub）。性质：**设计文档**（非代码）。确认后再进入建表/编码。
> 定位：让"上架数据 + 媒体资产"从单机 CSV 升级为**远程、多人、可被 Agent 与人共同操作的共享库**；
> **SP-API 发布永远在本地**，中台只负责**存储 / 管理 / 分发**。

---

## 1. 定位与边界

| 做 | 不做 |
|---|---|
| 存：商品/设计/上架/字段/变体/物流/媒体 | ❌ 不发 Amazon（释放给本地） |
| 管：多人增删改查 + 权限 + 审计 + 版本 | ❌ 不托管卖家 SP-API 凭证 |
| 分发：本地 `push` 上传、`pull` 拉取即用 | ❌ 不做指纹平台的出图/合成/定价（本地做） |
| 供：Web(人) + REST API(Agent) | ❌ 不做 ERP 页面（现有本地 8098 继续用） |

**核心承诺**：`pull` 下来的东西 = 本地**能直接发**的东西（数据全 + 图可访问）。

---

## 2. 总体架构

```
                     ┌────────────────────────── 阿里云 ECS（docker-compose）──────────────────────────┐
                     │  Nginx  ──▶  PHP-FPM 8.3 (Laravel 11)                                          │
   Agent(本地CLI) ──▶ │        ├─ Web/API (/api/v1)（人：会话；Agent：Token）                            │
   Browser(人)   ──▶ │        └─ Queue Worker (Horizon)  ── Redis                                        │
                     │  MySQL 8（自建）   Redis（队列/缓存/限流）                                        │
                     └───────────────┬───────────────────────────────────────────────────────────────┘
                                     │ Flysystem(OSS adapter)
                                     ▼
                        阿里云 OSS（图 + 视频，公共读）+ 阿里云 CDN + 自有域名(后补)
```

- 中台对外只暴露 **HTTPS**（Nginx + 证书）。
- 媒体**预签名直传 OSS**（本地/浏览器直传，不过中台带宽）；中台只登记元数据。
- 队列（Redis/Horizon）承接：媒体回源校验、缩略图/封面、导出打包、审计落库等异步活。

---

## 3. 技术栈

| 层 | 选型 | 说明 |
|---|---|---|
| 框架 | **Laravel 11 / PHP 8.3** | 你说的熟悉；CRUD/权限/队列/存储开箱 |
| DB | **MySQL 8**（自建）| JSON 列、全文/联合索引 |
| 缓存/队列 | **Redis** + **Laravel Horizon** | |
| 对象存储 | **阿里云 OSS**（`iidestiny/flysystem-oss` 或 `aliyuncs/oss-sdk-php`） | 同区、内网 endpoint |
| 鉴权 | **Laravel Sanctum**（SPA 会话 + PAT Token）| |
| 权限/审计 | **spatie/laravel-permission** + **laravel-activitylog** | |
| API 文档 | **Scribe**（OpenAPI） | |
| 质量 | **Pest/PHPUnit + Larastan + Pint + GitHub Actions** | CI 在你自己那侧跑，服务器不装 git |
| 前端 | **Vue 3 + Vite（SPA）+ Element Plus**（简单起步） | Laravel 只做 API；页面按功能，先 CRUD 为主 |
| 部署 | **docker-compose**（nginx / php-fpm / mysql / redis / horizon）| 阿里云 ECS |

> xlsm 写器、出图对齐、定价、SP-API 等"重活"**不进中台**，继续留在本地 Node/Python。

---

## 4. 模块划分（Laravel，按"域"）

```
app/Domain/
├── Catalog/      商品域：suppliers / products / product_suppliers(来源) / categories+product_categories(分类) / product_variants / product_shipping
├── Design/       设计域：designs
├── Listing/      上架域：listings / listing_revisions / field_definitions（核心）
├── Asset/        资产域：assets / blobs（OSS 图+视频）
├── Identity/     身份域：users / roles / permissions / tokens / audit
└── Sync/         同步域：sync_jobs（push/pull 契约）
每个域：Models / Actions / DTOs / Policies / Events / Http(Controllers+Requests+Resources)
```
原则：**控制器只编排**，业务进 `Actions`；输入 `FormRequest` 校验，输出 `Resource` 序列化；跨域用 `Events`。

---

## 5. 核心数据流

- **本地 push（上传）**：`本地 → POST /api/v1/sync/push`（商品/上架/设计 + 媒体元数据；媒体走 OSS 直传）→ 中台按业务键 **upsert** → 落 `revision` + 审计。
- **本地 pull（拉取即用）**：`GET /api/v1/sync/pull?since=&scope=&marketplace=` → **JSON**（给 Agent）或 **CSV 包**（给现成工具链，列=现有 `database/*.csv`）→ 本地落地即编辑、即发。
- **人（Web）**：CRUD listings/products/designs、看资产、看版本历史、看"哪站上了哪些 SKU"。
- **Agent**：Token 调同套 API（拉/推/查）；本地 CLI 封装成 `hub push` / `hub pull`。

---

## 6. 与本地工作机的契约（"拉取即用"）

- **幂等键**：商品 `(supplier, external_id)`；上架 `(account, platform, marketplace, sku)`；设计 `design_code`。重复推不产生重复行。
- **增量**：`pull?since=<ISO8601|游标>` 只回"改过的"；`scope=products|listings|designs|assets`；可按 `marketplace` 过滤。支持"手动全量"（不带 since）。
- **CSV 包**：列**对齐现有** `products.csv` / `listing_copy.csv` / `listing_variants.csv`（真·拉取即用）。
- **并发/冲突**：每条 `listings` 带 `revision`；push 带"基于版本"，过期返回 **409** + 最新版，让本地重拉比对（字段级 last-write-wins + 快照可回滚）。

---

## 7. 鉴权与权限

- **人类**：账号密码登录 → Sanctum 会话；角色 `viewer / editor / publisher / admin`。
- **Agent**：`POST /api/v1/tokens` 发 PAT，带 **abilities**：`catalog:read|write`、`listings:read|write`、`assets:write`、`admin`。
- **不做账号隔离**（按你的意见）；但**上架记录带 `platform` 字段**（默认 `amazon`），`marketplaces` 也按 `platform` 分组 → 为将来别的平台预留，`pull` 时一眼知道"这是给 Amazon 的上架资料"。
- 传输 HTTPS + 限流；凭证/密钥只进 `.env`/配置，绝不入 API 响应。

---

## 8. 版本、审计、一致性

- `listings.revision` 单调递增；每次写留 `listing_revisions`（全字段快照 + 变更字段 + 来源/作者）。
- 审计：activitylog 记录"谁/何时/改了哪条 listing 的哪些字段"。
- 幂等 + 乐观锁 → 多人可安全协作。

---

## 9. 媒体（图 + 视频，阿里云 OSS）

- **一张 `assets` 表 + 一张 `blobs` 表**管到底：`media_type(image|video)`；视频加 `duration_ms` + 封面 `poster_blob_id`。
- **内容寻址**：`blobs.sha256` 去重；对象 key 用哈希前缀；**公共读** + 自有域名/CDN（Amazon 抓图需要公网可达）。
- **上传**：OSS **预签名直传**（本地/浏览器 → OSS），中台只落 `assets` 记录。
- **上架前校验**：队列回源 HEAD 200 → `asset.status=verified`（避免 Amazon 抓不到）。

---

## 10. 关键接口清单（REST /api/v1）

```
# 身份
POST /auth/login                POST /tokens          GET /me
# 同步（本地 CLI 主力）
POST /sync/push                 GET  /sync/pull?since=&scope=&marketplace=
# 商品域
GET/POST /products  · GET/PATCH/DELETE /products/{id}
GET /products/{id}/variants     GET /products/{id}/shipping
GET /suppliers
# 设计域
GET/POST /designs  · GET/PATCH/DELETE /designs/{id}
# 上架域
GET/POST /listings · GET/PATCH/DELETE /listings/{id}
GET /listings/{id}/revisions    GET /listings/{id}/children
POST /listings:batchUpsert
GET /field-definitions?platform=&product_type=
# 资产
POST /assets/sign（预签名）      POST /assets（登记）   GET /assets/{id}
# 字典/模板
GET /marketplaces   GET /templates
```
统一：分页、过滤、`include=assets,attributes,revisions`、统一错误码（`code/message/fields`）、`Idempotency-Key`。

> 这是**概要**；**完整端点 + 请求/响应字段见 `API.md`**（含 `assets/check`·`assets/mirror`、`listings/{id}/publish-result`、`categories`、`templates` CRUD 等）。

---

## 11. 部署与运维（阿里云 · 自建）

- **ECS + docker-compose**：`nginx / php-fpm8.3 / mysql8 / redis / horizon`；数据卷持久化。
- **OSS 同区** + 内网 endpoint（省流量、快）。
- **HTTPS**：Nginx + 证书（域名后补，先用 IP/临时域名）。
- **备份**：MySQL 每日 dump（留 7/30 天）+ OSS 版本化；`spatie/laravel-backup` 可选。
- **监控**：容器健康检查 + Laravel 日志（可用阿里云 SLS）；Horizon 面板看队列。
- **升级**：`docker compose pull && up -d`（服务器不装 git；代码用镜像/打包上传）。

---

## 12. 扩展点（"优雅可扩展"落点）

| 想加 | 只需 |
|---|---|
| 新供应商 | 加 `suppliers` 一行；商品用 `(supplier, external_id)` |
| 新平台（非 Amazon） | `platform` 维度已留；`field_definitions.platform` 扩展；发布仍在本地 |
| 新站点 | `marketplaces` 加一行 |
| 新媒介（视频/3D/PDF） | `assets.media_type` + OSS 规则扩展 |
| 换云/换存储 | Flysystem 适配器 |
| 新同步源 | `Sync` 域加适配器 |

---

## 13. 里程碑

- **M1 骨架**：Laravel + MySQL 迁移/种子 + Sanctum 鉴权 + `suppliers/products/listings` CRUD API。
- **M2 资产**：OSS 接入 + 预签名直传 + `assets/blobs` + 回源校验。
- **M3 同步**：`sync/push|pull`（JSON + CSV 包）+ 版本/审计 + **导入现有 CSV** + 本地 `hub push/pull` CLI。
- **M4 打磨**：Web 页面完善、`field_definitions` 驱动校验、OpenAPI、备份/监控。

---

## 14. 进度 / 待确认

- ✅ 要上队列（Redis + Horizon）。
- ✅ 前端用 **Vue 3 + Vite（SPA）+ Element Plus**，简单起步。
- ✅ `attrs_json` = **数据库里的 JSON 列**（不是磁盘 JSON 文件）；详见 `DATABASE.md §18`。
- ✅ 项目**独立部署**，**不并入 skill**（本目录 `listing-hub/` 就是独立项目）。
- ✅ API 方案见 `API.md`。
- ✅ **配置项设计**见 `CONFIG.md`（`.env` / `config/*.php` / DB 字典 / `settings` 四层，绝不硬编码）。
- ⏳ 待你最后点头：**是否进入 M1（建表 + 骨架）**。
