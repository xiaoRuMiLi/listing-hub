# 配置填写清单 · 需要你提供的项目

> 配套文件：`env.example`（直接复制成 `.env` 填）。下面按"**你给 / 我生成 / 用默认**"分类。
> 带 ★ 的必须你提供；🔧 我可以代生成/代填（你确认即可）；⚪ 有默认，可后补。

---

## 一、服务器与域名

| 项 | 说明 | 归属 |
|---|---|---|
| ECS 公网 IP / SSH | 是否已有阿里云 ECS？地域 + 系统(Ubuntu/CentOS) | ★ |
| ECS 规格 | 建议起步：2vCPU / 4GB / 40GB+ 云盘（够 M1–M3） | ⚪ |
| **自有域名**（已备案✅） | 给我域名本身，例如 `example.com` | ★ |
| 子域：中台 | `hub.example.com`（Web/API） | ★ |
| 子域：媒体 | `media.example.com`（OSS/CDN 图床） | ★ |
| HTTPS 证书 | 阿里云免费证书即可；或我用 acme 自动签 | ⚪ |

> **OSS 域名策略**：优先 **自有域名 + CDN 回源 OSS**（`media.example.com`）；OSS 自带域名作**回退/内网直传**。二者可随时切（存 `storage_key`，URL 读取时渲染，**零改库**）。

## 二、阿里云 OSS（媒体）

| 项 | 说明 | 归属 |
|---|---|---|
| Region | ✅ **`oss-cn-hongkong`**（中国香港） | ★ |
| Bucket 名 | ✅ **`listing-hub`** | ★ |
| RAM 子账号 AK/SK | **仅授权该 Bucket**；只给一次、可轮换 | ★（也可你自己填 `.env`） |
| 内网 endpoint | 跨区（大陆 ECS↔香港 OSS）→ **留空/不用** | — |
| 是否开 CDN | 建议开（回源 OSS） | ⚪ |
| Bucket 读权限 | **当前方案A**：关闭"阻止公共访问" + 设**公共读**（Amazon 直抓 OSS 默认域）；方案B（后续）：私有 + CDN 回源 | 🔧 部署时设 |

> **★ 建桶注意事项（建后不可改，别踩）**
> - **Bucket 名全局唯一**：`listing-hub` 太通用可能被占，占用就换 `hhy-listing-hub` 等。
> - **地域**：选**中国香港**（或其它）→ **ECS 必须同区**，否则**内网不通**（直传/回源走公网）。香港为非大陆地域，**域名指向无需备案**。
> - **冗余**：**LRS（本地冗余）通常够**（媒体类）；ZRS 更稳但更贵，且**不可转回 LRS**。
> - **阻止公共访问**：默认**开**（图不公共→Amazon 抓不到）。两条路：**(A)** 建后关闭「阻止公共访问」(Bucket 级 + 账号级) + Bucket 设**公共读**；**(B·推荐)** Bucket **私有** + **自有域名 + CDN 回源**（可加回源鉴权/防盗链），**无需公共读**。**先默认建，建后按 B 配。**

> **异地部署注意（图床 vs 抓图）**：Amazon 在海外抓图，**图床放香港/海外更稳**；大陆 OSS 被海外抓可能复现"抓不到"。若 ECS 在大陆 + OSS 在香港 → **跨区无内网**，`OSS_ENDPOINT` 用**公网**、`OSS_INTERNAL_ENDPOINT` **留空**。

## 三、数据库 / 缓存（自建）

| 项 | 说明 | 归属 |
|---|---|---|
| MySQL 口令 | 仅内网；不对公网开 3306 | 🔧 可生成 |
| Redis 口令 | 仅内网；不对公网开 6379 | 🔧 可生成 |
| 数据盘 | ECS 云盘（推荐）或本地盘 + 每日备份到 OSS | ⚪ |

## 四、鉴权 / 账号

| 项 | 说明 | 归属 |
|---|---|---|
| 初始管理员 | 邮箱 + 密码（或我生成随机、首登改） | ★/🔧 |
| 是否需要 SSO/2FA | 默认：不需要 | ⚪ |
| 角色方案 | 默认 `viewer/editor/publisher/admin` | ⚪ |

## 五、业务字典（初始种子·可后补）

| 项 | 说明 | 归属 |
|---|---|---|
| 亚马逊账号清单 | `name / seller_id / 区域`（哪怕先 1 个） | ★ |
| 主站点 | 如 UK `A1F83G8C2ARO7P`（先一个即可） | ★ |
| 供应商 | 默认 `hicustom`（指纹科技） | ⚪ |
| 品类/字段 | 从你的 `schema/*.json` 导入（见下） | ★（文件） |

## 六、需要你给的**文件/样本**（做字段字典 + 迁移 + 模板）

| 项 | 用途 |
|---|---|
| `product.json` 一份样本 | 商品侧字段设计 |
| `products.csv / listing_copy.csv / listing_variants.csv / designs.csv` 的表头 + 2~3 行 | CSV 列映射 & 导入 |
| `amazon_category_table/schema/*.json` + `config/listing-attrs.json` | 生成 `field_definitions` |
| 要上架的 Amazon xlsm 模板 | 登记 `templates`（文件型） |
| `database/templates.csv` | 迁移贴字模板（共享库） |

## 七、代码下发方式

| 项 | 说明 | 归属 |
|---|---|---|
| 镜像仓库 or 打包上传 | 服务器不装 git → 你偏爱哪种？（ACR 镜像 / tar 包） | ★ |

---

## 我能先"空跑"的部分（等你资料齐之前）
- 本机/compose 起一版 → 建表 + CRUD + 鉴权 + OSS 直传（用**示例桶/占位域名**）跑通
- 字段字典导入器（读 schema JSON）、CSV↔DB 映射器、`hub` CLI 骨架

## 优先级（先行给这 4 样我就能动 M1）
1. **域名**（`example.com` + `hub.` / `media.` 子域）
2. **OSS**：Region + Bucket（AK/SK 你填 `.env` 也行）
3. **一台机器**（ECS 或本机 Docker）
4. **C2 + C3**（CSV 表头样本 + schema JSON）

---

## 八、阿里云控制台速查（Region / Bucket / AK-SK 在哪）

**看 Region + Bucket 名**：控制台 →「对象存储 OSS」→「Bucket 列表」→ 进你的 bucket →「Bucket 概览」：
- 地域「中国香港」→ **Region ID `oss-cn-hongkong`**
- 访问域名 `<bucket>.oss-cn-hongkong.aliyuncs.com`
- Bucket 名 = 创建时填的

**拿 AK/SK（用 RAM 子账号，别用主账号）**：
1. 头像 →「访问控制 RAM」→「用户」→ **创建用户**（登录名 `listing-hub-bot`；**只勾「OpenAPI 调用访问」**）
2. 创建后弹 **AccessKey ID + Secret** → ⚠️ Secret 只显示一次，立刻保存
3. 授权（推荐**只授权该 bucket** 的自定义策略；把 `listing-hub` 换/留作你的 bucket 名）：
   ```json
   {"Version":"1","Statement":[{"Effect":"Allow",
     "Action":["oss:GetObject","oss:PutObject","oss:HeadObject","oss:DeleteObject","oss:ListObjects"],
     "Resource":["acs:oss:*:*:listing-hub","acs:oss:*:*:listing-hub/*"]}]}
   ```
   （省事也可给 `AliyunOSSFullAccess`，但权限偏大）
4. 填进 `.env`：`OSS_ACCESS_KEY_ID=` / `OSS_ACCESS_KEY_SECRET=`（**建议自己填，别外发**）

> ⚠️ 主账号 AK/SK（头像→AccessKey 管理）权限=账号全部，**不要用于本项目**。
