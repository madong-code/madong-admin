<div align="center">

<img src="public/logo.png" alt="Madong Logo" width="120" />

# Madong 极速后台开发框架

基于 PHP Webman 的高性能后台开发平台，采用 **前后端分离 + 双前端** 架构

[![PHP](https://img.shields.io/badge/PHP-8.2~8.4-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Webman](https://img.shields.io/badge/Webman-2.2-2488C9)](https://www.workerman.net/doc/webman)
[![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com)
[![Redis](https://img.shields.io/badge/Redis-7.0+-DC382D?logo=redis&logoColor=white)](https://redis.io)
[![License](https://img.shields.io/badge/License-Apache--2.0-blue)](./LICENSE)

[![Docs](https://img.shields.io/badge/文档-madong.tech-7C3AED)](https://madong.tech)
[![Demo](https://img.shields.io/badge/演示-v5.madong.tech-10B981)](http://v5.madong.tech/admin)

**中文** | [English](./README.en.md)

</div>

## 介绍

Madong 是基于 PHP **Webman** 框架开发的极速开发平台，为前端应用提供稳定、高效的 API 服务。后端采用清晰的业务分层架构与插件系统；前端支持 **双前端 UI**——后台提供两套可选 Admin UI（Single / Aura），前台站点（Web）独立部署、与多租户版共用。

> 前后端分离部署，前端目录需单独下载或创建。

## 仓库地址

Madong 支持 **双前端 UI**：管理后台提供两套可选 UI（Single / Aura），前台站点与多租户版共用同一前端。**默认从 GitHub 拉取**，网络受限时可改用 Gitee / GitCode 镜像。

| 模块 | GitHub | Gitee 镜像 | GitCode 镜像 |
| :--- | :--- | :--- | :--- |
| 服务端 | https://github.com/madong-code/madong-admin | https://gitee.com/motion-code/madong-admin | https://gitcode.com/motion-code/madong-admin |
| Admin(single) | https://github.com/madong-code/madong-single | https://gitee.com/motion-code/madong-single | https://gitcode.com/motion-code/madong-single |
| Admin(aura) | https://github.com/madong-code/madong-aura | https://gitee.com/motion-code/madong-aura | https://gitcode.com/motion-code/madong-aura |
| Web | https://github.com/madong-code/web-nuxt | https://gitee.com/motion-code/web-nuxt | https://gitcode.com/motion-code/web-nuxt |
| Skills | https://github.com/madong-code/madong-admin-skills | https://gitee.com/motion-code/madong-admin-skills | https://gitcode.com/motion-code/madong-admin-skills |

> Gitee / GitCode 镜像仓库位于 `motion-code/…` 组织下，与 GitHub 同名同路径，如实际地址不同请自行替换。
> 后台两套 UI 任选其一放入 `template/admin`；前台使用 `web-nuxt`，与多租户版共用。
> 安装 / 下载命令默认从 GitHub 拉取，例如 `php webman madong-download:frontend --admin`；如需镜像，可配置 `dependencies.npm.registry` 等镜像源，或直接指定镜像仓库地址。

## 技术栈

| 技术 | 说明 |
|------|------|
| PHP | 8.2 ~ 8.4，核心开发语言 |
| Webman | 2.2，高性能 PHP 框架 |
| MySQL | 8.0+，数据库 |
| Redis | 7.0+，缓存服务 |
| Composer | 2.x，依赖管理工具 |
| Nginx / Apache | Web 服务器 |

## 项目结构

```
madong/
├── backend/    # 后端（含 CLI 安装向导，一键安装自动配置）
└── template/   # 前端模板（admin / web / install，见上方仓库表单独下载）
```

> 更多模块、目录与开发文档，请查看官方文档：[madong.tech](https://madong.tech)

## 核心功能

- **用户认证**：基于 JWT 的身份验证机制
- **权限管理**：细粒度的权限控制体系
- **缓存服务**：集成 Redis 缓存
- **通信基础设施**：邮件 / 短信 / 通知
- **文件上传**：支持多种存储方式
- **国际化**：支持多语言配置
- **插件系统**：支持第三方扩展（codegen 代码生成、demo 演示等）
- **SSE 实时推送**：插件安装 / 卸载进度实时反馈
- **服务层架构**：清晰的 DAO / Service 分层设计
- **代码生成器**：自动化生成控制器、模型、服务层代码
- **数据迁移**：数据库版本化管理
- **双前端 UI**：后台两套可选 Admin UI，前台站点独立工程（与多租户版共用）

## 运行环境

- PHP 8.2 ~ 8.4
- MySQL 8.0+
- Redis 7.0+
- Nginx / Apache
- Composer 2.x+
- Node.js（前端构建，含 pnpm）

## 安装部署

### 1. 环境准备

确保安装：PHP 8.2 ~ 8.4、MySQL 8.0+、Redis 7.0+、Composer 2.x+、Node.js（含 pnpm）、Git。

### 2. 安装后端依赖

```bash
cd backend
composer install
```

### 3. 下载前端资源

**方式一：命令下载（推荐）**——默认从上方表格的 GitHub 仓库拉取：

```bash
cd backend
php webman madong-download:frontend                 # 下载 admin + web
php webman madong-download:frontend --admin         # 仅后台
php webman madong-download:frontend --web           # 仅前台
php webman madong-download:frontend -b develop      # 指定分支
php webman madong-download:frontend -f              # 强制覆盖
```

**方式二：手动下载**——从「双前端 UI 仓库地址」表格中的 GitHub 地址克隆，后台任选一套 UI 放入 `template/admin`，前台放入 `template/web`。

### 4. 启动安装向导

```
http://127.0.0.1:8500/install
```

### 5. 启动服务

```bash
cd backend
php start.php start        # 开发环境
php start.php start -d     # 生产环境（守护）
```

服务默认运行在 `http://127.0.0.1:8500`。

## 配置说明

数据库、Redis 等连接配置由一键安装向导在安装过程中自动写入，无需手动修改。

> 更多配置项与高级用法，请查看官方文档：[madong.tech](https://madong.tech)

## 插件依赖管理

插件在 `config/info.php` 中声明 `require.composer`（后端）与 `npm_require.admin|web|common`（前端）依赖，由 `core/business/plugin` 依赖流水线统一处理：

- **声明合并**：安装时合并到 `composer.json` 与 `template/{admin,web}/package.json`，冲突按 `dependencies.conflict.strategy`（`skip`/`override`/`fail`，默认 `skip`）
- **真实安装**：`dependencies.auto_install` 默认 `false`，仅合并声明并输出待执行命令；置 `true` 后自动执行 `composer update` 与 `pnpm install`
- **网络容错**：`dependencies.network.retry` 控制重试，`dependencies.composer.mirror` / `dependencies.npm.registry` 配置镜像源
- **引用计数**：多插件共用依赖时，仅最后一个使用者卸载才剥离
- **状态持久化**：依赖状态写入插件 `config/installed.php`，不依赖数据库；失败默认不阻断安装
- **事务回滚**：安装 / 卸载失败自动还原依赖声明与模板目录

安装时若网络不通，可后续补装：

```bash
php webman madong-plugin:install-deps 插件名 --status    # 查看状态
php webman madong-plugin:install-deps 插件名              # 重新安装
```

管理端「模块市场 → 详情」在依赖待安装 / 失败时展示状态与「补装依赖」按钮，也可通过 `GET /adminapi/plugin/{key}/dependencies` 查询、`GET /adminapi/plugin/{key}/dependencies/install`（SSE）触发。

## AI 开发技能（Skills）

面向 AI 编码助手的技能包，覆盖后端、前端与跨端场景：

- **仓库**：[madong-code/madong-admin-skills](https://github.com/madong-code/madong-admin-skills)（见上方仓库表）
- **结构**：`backend/`（后端脚手架）、`frontend/`（前端脚手架）、`cross/`（跨端通用）
- **使用**：克隆到本地，按需加载技能到 AI 助手，按其 SOP 执行

## 官方论坛

产品 BUG、优化建议，欢迎社区反馈：http://www.madong.tech

## 如何贡献

非常欢迎你的加入！[提一个 Issue](https://github.com/madong-code/madong-admin/issues) 或提交 Pull Request。

1. Fork 代码
2. 创建分支：`git checkout -b feature/xxxx`
3. 提交：`git commit -am 'feat(function): add xxxxx'`
4. 推送：`git push origin feature/xxxx`
5. 提交 Pull Request

## Git 提交规范

参考 [Conventional Commits](https://www.conventionalcommits.org/)：`feat` / `fix` / `style` / `perf` / `refactor` / `revert` / `test` / `docs` / `chore` / `ci` / `types` / `wip`。

## 社区支持

- 官方文档：https://madong.tech
- 讨论交流：[QQ 群 690671595](https://qm.qq.com/q/dEfMqYL42c)

## 许可证

Apache License
