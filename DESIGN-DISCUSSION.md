# 讨论记录 / 决策日志（已归档）

> ⚠️ 本文是**早期讨论稿**，内容**已被取代** —— 正式设计见：
> - `ARCHITECTURE.md`（系统架构）
> - `DATABASE.md`（数据库字段及表设置）
> - `API.md`（接口设计）
>
> 若本文与上述三份冲突，**以三份正式文档为准**。保留本文仅作**决策溯源**。

---

## 决策日志（2026-09-20）

| # | 议题 | 结论 |
|---|---|---|
| 1 | 发布位置 | **本地发 SP-API**；中台只存"数据 + 图"，**不托管卖家凭证、不发 Amazon** |
| 2 | 对象存储 | **阿里云 OSS**（内容寻址去重；公共读 + CDN；自有域名后补；**预留视频**） |
| 3 | 框架 | **Laravel 11 / PHP 8.3**（中台）；出图/定价/xlsm/SP-API 等重活**仍在本地 Node/Python** |
| 4 | 前端 | **Vue 3 + Vite（SPA）+ Element Plus**，简单起步 |
| 5 | 队列 | 上 **Redis + Horizon** |
| 6 | 命名 | 云端正交服务简称**「中台」**（≠ GitHub） |
| 7 | 主键 | **所有表加 `id` BIGINT 自增 PK**；业务键另设 UNIQUE |
| 8 | 供应商维度 | 引入 `suppliers`；商品来源 = **多对多**（`product_suppliers`） |
| 9 | 分类 | `categories`(多平台树) + `product_categories` = **多对多** |
| 10 | 商品 JSON | 变体规格 / 各国物流 / 其余 fact **一并上云**（可查的落表，其余 JSON） |
| 11 | 同步粒度 | 默认**增量**（`since`）+ 可**手动全量** |
| 12 | CSV 出口 | 列**对齐现有** `products.csv` / `listing_copy.csv` / `listing_variants.csv`（**拉取即用**） |
| 13 | 同步方向 | **双向**（本地 push 上传 / 本地 pull 下载） |
| 14 | 账号隔离 | **暂不隔离**（保留 `account_id`；将来只加 Policy，不改表） |
| 15 | 平台维度 | `listings.platform`（默认 `amazon`），**为多平台预留** |
| 16 | 部署 | **自建 MySQL / Redis**（docker-compose on 阿里云 ECS） |
| 17 | 上架时间 | 加 **`first_published_at`**（首次）+ **`published_at`**（最近一次） |
| 18 | 定制信息 | 先放 **`customization_json`** 桶（结构未定）；研究完 Amazon Custom 字段再按需结构化 |
| 19 | 图片去重 | **内容 sha256** 硬去重 + **url_hash** 免重复下载 + `phash` 近似提示 |
| 20 | 图片 URL | 统一**归一到自有图床**（OSS 稳定 URL）；换域名只改配置 |

## 正式文档
- 架构 → `ARCHITECTURE.md`
- 表结构 → `DATABASE.md`
- 接口 → `API.md`

## 里程碑（详见 `ARCHITECTURE.md §13`）
M1 骨架（建表+CRUD+鉴权） · M2 资产（OSS+直传+回源校验） · M3 同步（push/pull+导入+CLI） · M4 打磨（Web/校验/OpenAPI/运维）
