# 宝塔部署手册 · listing-hub（CentOS 8 + 宝塔）

> 目标：把 `server/` 部署到 ECS，站点 `hub.weixiubang.club`。
> **前提**：老项目继续用 PHP 7.4 + MySQL 5.7；**本项目用新增的 PHP 8.3**，两者并存互不影响。

---

## 0. 现状与原则

| 项 | 值 |
|---|---|
| 面板 | 宝塔（CentOS 8.2） |
| 老项目 | **PHP 7.4**（保留，别动！）+ MySQL 5.7（共用实例） |
| 本项目 PHP | **新增 PHP 8.3**（Laravel 13 要求 ≥8.2） |
| 数据库 | 共用 5.7 实例，**独立库** `listing-hub`（已建） |
| 域名 | `hub.weixiubang.club`（已备案） |
| OSS | `listing-hub`（香港，public-read） |

---

## 1. 宝塔装软件

**软件商店 → 安装**：
- **Nginx**（一般已有）
- **PHP 8.3**（★ 新增；**不要动 7.4**）
- **Redis**（M3 队列用，可后装）
- MySQL 已用现成 5.7，**不用再装**

**PHP 8.3 → 设置 → 安装扩展**：勾选
```
pdo_mysql  mbstring  openssl  fileinfo  zip  bcmath  curl  gd  intl  opcache
```

**PHP 8.3 → 设置 → 禁用函数**：确保移除/放行（Laravel 需要）：
`putenv getenv proc_open proc_get_status symlink`（宝塔默认会禁 `proc_open`，Laravel 不强制要，但放开更省事）

---

## 2. 建站点

宝塔 → 网站 → 添加站点：
- 域名：`hub.weixiubang.club`
- 根目录：`/www/wwwroot/hub.weixiubang.club`
- **PHP 版本：8.3**（★ 关键，别选 7.4）
- 数据库：可勾选创建（或复用已有 `listing-hub` 库）

站点设置里：
- **网站目录 → 运行目录 = `/public`**（★ Laravel 入口在 public）
- **伪静态 → 选 `laravel5`**（或粘贴）：
  ```nginx
  location / { try_files $uri $uri/ /index.php?$query_string; }
  ```

---

## 3. 上传代码

本地把工程打包（**排除 vendor / node_modules / .env / storage 日志**）：

```powershell
cd C:\Users\Administrator\.openclaw\workspace-dev\listing-hub
tar --exclude=server/vendor --exclude=server/node_modules --exclude=server/.env --exclude=server/storage/logs/* -czf hub.tar.gz server
```

然后用**宝塔「文件」上传** `hub.tar.gz` 到 `/www/wwwroot/hub.weixiubang.club/`，解压后把 `server/` 里的内容放到站点根：
即站点根 = 原 `server/` 内容（`app/ config/ public/ .env.example …`）。

> 目录最终：`/www/wwwroot/hub.weixiubang.club/{app,config,public,database,...}`，运行目录 `public`。

---

## 4. 配 `.env`（生产）

在站点根复制 `.env.example` 为 `.env`，填：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # 下面 artisan key:generate 生成
APP_URL=https://hub.weixiubang.club
APP_TIMEZONE=Asia/Shanghai

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=listing-hub
DB_USERNAME=listing-hub
DB_PASSWORD=<你的数据库密码>

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync          # M3 上 Redis 后改 redis

OSS_ACCESS_KEY_ID=<RAM-AK>
OSS_ACCESS_KEY_SECRET=<RAM-SK>
OSS_BUCKET=listing-hub
OSS_ENDPOINT=oss-cn-hongkong.aliyuncs.com
OSS_INTERNAL_ENDPOINT=
OSS_PATH_PREFIX=blobs
MEDIA_DISK=oss
MEDIA_PUBLIC_BASE_URL=https://listing-hub.oss-cn-hongkong.aliyuncs.com
MEDIA_ALLOWED_HOSTS=listing-hub.oss-cn-hongkong.aliyuncs.com

SANCTUM_STATEFUL_DOMAINS=hub.weixiubang.club
```

---

## 5. 装依赖 + 初始化（用 PHP 8.3！）

宝塔 PHP 8.3 的二进制路径：`/www/server/php/83/bin/php`

```bash
cd /www/wwwroot/hub.weixiubang.club

# Composer（若宝塔未带，装一个全局的）
curl -sS https://getcomposer.org/installer | /www/server/php/83/bin/php -- --install-dir=/usr/local/bin --filename=composer

# 安装依赖（生产：不装 dev）
/www/server/php/83/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader

# 生成 key / 建表 / 种子
/www/server/php/83/bin/php artisan key:generate
/www/server/php/83/bin/php artisan migrate --force
/www/server/php/83/bin/php artisan db:seed --force

# 缓存（生产）
/www/server/php/83/bin/php artisan config:cache
/www/server/php/83/bin/php artisan route:cache

# 目录权限 + storage 软链
chown -R www:www storage bootstrap/cache
/www/server/php/83/bin/php artisan storage:link
```

> ⚠️ 别用系统默认 `php`（可能是 7.4）——**一律用 `/www/server/php/83/bin/php`**。

---

## 6. CA 证书（HTTPS 抓图/传 OSS 必需）

若 PHP 8.3 报 `SSL certificate problem`：
```bash
mkdir -p /www/server/php/83/etc/ca
curl -sS https://curl.se/ca/cacert.pem -o /www/server/php/83/etc/ca/cacert.pem
```
在 PHP 8.3 的 `php.ini` 末尾加：
```ini
curl.cainfo="/www/server/php/83/etc/ca/cacert.pem"
openssl.cafile="/www/server/php/83/etc/ca/cacert.pem"
```
然后重载 PHP 8.3。

---

## 7. HTTPS

宝塔 → 站点 → SSL → **Let's Encrypt** 一键申请（`hub.weixiubang.club` 已备案，可签），或上传证书。开启**强制 HTTPS**。

---

## 8. 验证

```bash
# 健康检查（应 200）
curl -I https://hub.weixiubang.club/api/v1/products
# → 401（未鉴权是正常的，说明 API 通了）

# 登录取 token
curl -s -X POST https://hub.weixiubang.club/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"admin@weixiubang.club","password":"admin123456"}'
```
默认管理员 `admin@weixiubang.club / admin123456` → **登录后立刻改密**。

---

## 9. 队列 / 定时（M3 起）

- 宝塔 → 软件商店装 **Redis** → `.env` 改 `QUEUE_CONNECTION=redis`、`CACHE_STORE=redis`
- 宝塔 → **Supervisor 管理器** 添加进程：
  `php /www/wwwroot/hub.weixiubang.club/artisan horizon`（用户 `www`，目录=站点根）
- 宝塔 → 计划任务：每日备份（数据库 + `storage`）

---

## 10. 常见坑（对齐我们本地踩过的）

1. **PHP 版本**：站点必须绑 **8.3**，`artisan`/`composer` 也用 8.3 → 否则报语法/版本错。
2. **MySQL 5.7**：`engine=InnoDB` + `defaultStringLength(191)` 已在代码里兜住，无需额外设置。
3. **不要改 MySQL 全局参数**（共用实例，会影响老项目）。
4. **禁用函数**：宝塔默认禁 `proc_open` 等；如报"函数被禁用"，去 PHP 设置里放行。
5. **伪静态**：必须是 Laravel 规则，否则 404。
6. **运行目录 = public**：否则静态资源/入口不对。
7. **权限**：`storage`、`bootstrap/cache` 必须 `www` 可写。
