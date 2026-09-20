# 开工清单 · 我需要你提供的东西（Checklist）

> 分四类：**A 环境/凭证** · **B 待拍板** · **C 现有文件/样本** · **D 我自备**。
> 其中 **【最小可开工集】** 给最少信息即可先跑 M1/M2。

---

## A. 环境与凭证（部署用）

| # | 需要 | 说明 |
|---|---|---|
| A1 | **阿里云 ECS** | 是否已有？地域 / 公网IP / 系统(Ubuntu/CentOS) / 规格。没有的话我可以给"买哪款 + 初始化脚本"。 |
| A2 | **域名** | 有没有可用域名（HTTPS + 媒体 CDN 用）；**国内 OSS 绑域名需备案**。没有 → 先用 IP 跑。 |
| A3 | **OSS** | **Region、Bucket 名**；是否需要 CDN；是否要绑自有域名。 |
| A4 | **OSS 访问密钥** | 建议用 **RAM 子账号**（**仅授权该 Bucket**），AK/SK **可随时轮换**。⚠️ 更稳妥：**你自己填进服务器 `.env`**，不必发我。 |
| A5 | **MySQL/Redis** | 按你意见自建（docker-compose）。确认数据盘（ECS 本地盘 or 挂载云盘）；**仅内网访问**（不对外开 3306/6379）。 |
| A6 | **代码下发方式** | 服务器不装 git → 用 **镜像仓库**（ACR）或**打包 tar 上传**。你倾向哪种？ |

## B. 待你拍板（小）

| # | 事项 | 默认（不答就按默认） |
|---|---|---|
| B1 | 人类登录是否要 SSO/2FA | 不要（账号密码） |
| B2 | 角色/权限粒度 | `viewer/editor/publisher/admin` |
| B3 | 初始管理员 | 我建 `admin@…` 随机密码给你，首登改 |
| B4 | 现有 CSV 历史是否一次性导入 | 是（写丢弃=否） |
| B5 | 中台域名 | 后补（先用 IP） |

## C. 现有文件/样本（做字段字典 + 迁移 + 模板）

| # | 文件 | 用途 |
|---|---|---|
| C1 | 1 份 `output/<id>/product.json` 样本（含 `profile`/`detail`/`customization`） | 设计 product 侧字段 |
| C2 | `database/products.csv`、`listing_copy.csv`、`listing_variants.csv`、`designs.csv` 的**表头 + 2~3 行样本**（可脱敏） | 对齐 CSV 列 & 迁移映射 |
| C3 | `amazon_category_table/schema/*.json`（或指定几个品类）+ `config/listing-attrs.json` | 生成 `field_definitions` 种子 |
| C4 | 要上架的 **Amazon xlsm 模板**（品类） | 登记 `templates`（文件型） |
| C5 | `database/templates.csv`（贴字模板） | 迁移 layout 模板（共享库） |
| C6 | 亚马逊**账号清单**（name / seller_id / 主要站点） | 初始化 `accounts` + `marketplaces` |
| C7 | 媒体来源说明（本地图放哪 / 指纹链接规则） | 迁移脚本用 |

## D. 我自备（不用你给）

- Laravel 骨架 + 全部迁移/种子 + Docker Compose（nginx/php-fpm/mysql/redis/horizon）
- API（CRUD + sync push/pull）+ 鉴权 + Vue SPA 骨架
- 字段字典导入器（读 `schema/*.json` → `field_definitions`）
- CSV↔DB 列映射器 + 本地 `hub` CLI（Node）
- Scribe(OpenAPI) / 测试 / CI

---

## 🚀 最小可开工集（只给这些就能先跑）

1. **一台能连的机器**（ECS 或本机 Docker）——我可以先本地/compose 起一版验证。
2. **OSS：Region + Bucket**（AK/SK 你自己填 `.env` 也行）。
3. **C2 + C3**（CSV 表头样本 + schema JSON）——用来做字段字典与列映射。
4. **C6**（账号/站点清单，哪怕先给一个账号 + UK 站点）。

> 有了 1–4，我可以先交付 **M1（骨架+建表+CRUD+鉴权）** 与 **M2（OSS+直传）** 的**可运行版本**，其余边跑边补。

---

## 🔒 安全约定

- 密钥（AK/SK、DB 口令、App Key）**只进服务器 `.env`**，**不入代码/不入库/不入 API 响应**。
- 建议 **RAM 最小权限** + **可轮换**；需要我代填时，用**一次性**下发并随后轮换。
