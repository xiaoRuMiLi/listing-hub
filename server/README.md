# listing-hub server（云端中台 · Laravel）

> 独立项目（**不并入 skill**）。设计文档在上级目录 `../`（`ARCHITECTURE.md` 等）。
> 目标：MySQL 真源 + 阿里云 OSS 媒体 + REST API（人 + Agent）+ 队列。

## 技术栈
Laravel 11 / PHP 8.3 · MySQL 8 · Redis(Horizon) · 阿里云 OSS(Flysystem) · Sanctum · spatie/permission+activitylog · Vue3(独立前端)

## 本地开发（PHPstudy_pro）
1. **PHP ≥ 8.2 + Composer 2**（PHPstudy 自带的是 8.0.2 / Composer1.8.5，**不够**）：
   - 在 `D:\phpstudy_pro\Extensions\php\` 放一份 PHP 8.3 (nts) 绿色版，切为该版本；
   - 装 Composer 2（`composer self-update --2` 或放 `composer.phar`）。
2. `cp .env.example .env` → 填 DB / OSS / 域名
3. `composer install`
4. `php artisan key:generate`
5. `php artisan migrate --seed`
6. `php artisan serve` → http://127.0.0.1:8000

> 本地 MySQL 5.7 可用（迁移统一用 `utf8mb4` + `utf8mb4_unicode_ci`，5.7/8 通吃）。
> 本地 Redis（PHPstudy 3.0.5）M1 不用；M2/M3 用队列时建议换新版 Redis 或上 ECS。

## 生产部署（阿里云 ECS · docker-compose）
1. 装 Docker：`curl -fsSL https://get.docker.com | bash -s docker --mirror Aliyun`
2. `cp .env.example .env` 填值
3. `docker compose up -d --build`
4. `docker compose exec app php artisan migrate --seed`
5. Nginx 反代 + HTTPS（域名 `hub.weixiubang.club`）

## 目录
```
app/Domain/{Catalog,Design,Listing,Asset,Identity,Sync}/   # 按域
app/Http/{Controllers,Requests,Resources}/
config/{media,sync,listings,catalog}.php
database/migrations/   # 见下
```
