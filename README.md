# madong 极速后台开发框架

## 介绍

基于 PHP Webman 框架开发的极速开发平台，为前端应用提供稳定、高效的 API 服务。

## 技术栈

| 技术 | 版本        | 说明 |
|------|-----------|------|
| PHP | 8.2 ~ 8.4 | 核心开发语言 |
| Webman | 2.2        | 高性能 PHP 框架 |
| MySQL | 8.0+      | 数据库 |
| Redis | 7.0+      | 缓存服务 |
| Composer | 2.x       | 依赖管理工具 |
| Nginx/Apache | -         | Web 服务器 |

## 项目结构

```
madong/                          # 项目根目录
├── backend/                     # 后端目录（当前目录）
│   ├── app/                    # 应用目录（单体分层结构）
│   │   ├── adminapi/           # 管理后台 API
│   │   │   ├── config/         # 后台 API 配置
│   │   │   ├── controller/     # 后台控制器（按业务域组织文件）
│   │   │   ├── event/          # 后台事件
│   │   │   ├── listener/       # 后台事件监听器
│   │   │   ├── middleware/     # 后台中间件
│   │   │   ├── schema/         # 后台数据结构定义
│   │   │   ├── validate/       # 后台验证器
│   │   │   └── CurrentUser.php # 当前用户上下文
│   │   ├── api/                # 前台 API
│   │   │   ├── config/         # 前台 API 配置
│   │   │   ├── controller/     # 前台控制器（按站点组织文件）
│   │   │   ├── event/          # 前台事件
│   │   │   ├── listener/       # 前台事件监听器
│   │   │   ├── middleware/     # 前台中间件
│   │   │   ├── schema/         # 前台数据结构定义
│   │   │   ├── validate/       # 前台验证器
│   │   │   └── CurrentMember.php # 当前会员上下文
│   │   ├── dao/                # 数据访问层（按业务域：content/member_sign/ops/site/sys_admin_type/system）
│   │   ├── enum/               # 枚举类（content/system）
│   │   ├── model/              # 模型（content/ops/system）
│   │   ├── service/            # 业务服务层（admin/api/core）
│   │   ├── schema/             # 数据结构定义（response/plugin）
│   │   ├── command/            # 命令行工具（plugin 迁移）
│   │   ├── event/              # 事件定义
│   │   ├── listener/           # 事件监听器
│   │   ├── bootstrap/          # 启动引导文件
│   │   ├── middleware/          # 中间件
│   │   ├── process/            # 自定义进程
│   │   ├── queue/              # 队列任务
│   │   ├── scope/              # 模型作用域
│   │   ├── install/            # 安装脚本
│   │   ├── exception/          # 异常处理
│   │   └── functions.php       # 全局函数
│   ├── config/                  # 配置文件
│   │   ├── plugin/              # 插件配置（limiter/validation 等）
│   │   ├── route/               # 路由配置
│   │   └── *.php                # 各类配置文件
│   ├── core/                    # 核心框架（领域分层架构）
│   │   ├── business/           # 业务核心（多租户/审批等）
│   │   ├── communication/      # 通信（sms/email/notify 消息通知）
│   │   ├── foundation/         # 基础组件（cache/db/uuid/jwt/logger）
│   │   ├── infrastructure/     # 基础设施（存储/队列等）
│   │   ├── interface/          # 接口定义（契约/抽象）
│   │   ├── io/                 # IO（upload 上传 / excel 导入导出）
│   │   ├── security/           # 安全（captcha 验证码 / exception 异常）
│   │   └── functions.php        # 核心函数
│   ├── plugin/                   # 插件目录
│   │   ├── codegen/              # 代码生成插件（后端）
│   │   └── demo/                 # 演示插件（运行时 + 前端模板源）
│   ├── public/                   # 静态资源
│   ├── resource/                 # 资源文件（migrations 迁移 / seeds 种子 / menu 菜单 / translations 翻译）
│   ├── runtime/                  # 运行时文件
│   ├── support/                  # 第三方支持库
│   ├── tests/                    # 单元测试
│   ├── vendor/                   # Composer 依赖包
│   ├── composer.json             # Composer 配置
│   ├── composer.lock             # 依赖锁定文件
│   ├── phpunit.xml               # 测试配置
│   └── README.md                 # 项目说明文档
│
└── template/                     # 前端模板目录（需单独下载）
    ├── admin/                    # 管理后台前端
    ├── web/                      # 前台前端
    └── install/                  # 安装器前端
```

> **注意**：前后端分离部署，前端目录需单独下载或创建。

## 核心功能

- **用户认证**：基于 JWT 的身份验证机制
- **权限管理**：细粒度的权限控制体系
- **缓存服务**：集成 Redis 缓存，提升系统性能
- **邮件服务**：支持邮件发送功能
- **文件上传**：支持多种存储方式
- **国际化**：支持多语言配置
- **插件系统**：支持第三方扩展（codegen 代码生成、demo 演示等）
- **命令行工具**：支持更多操作（插件迁移等）
- **SSE 实时推送**：支持 Server-Sent Events 实时进度反馈
- **服务层架构**：清晰的 DAO/Service 分层设计
- **代码生成器**：自动化生成控制器、模型、服务层代码
- **数据迁移**：支持数据库版本化管理
- **单体架构**：标准版与多租户版统一架构，便于按需启用

## 运行环境

- PHP 8.2 ~ 8.4
- MySQL 8.0+
- Redis 7.0+
- Nginx/Apache
- Composer 2.x+

## 安装部署

### 1. 环境准备

确保安装了以下软件：
- PHP 8.2 ~ 8.4
- MySQL 8.0+
- Redis 7.0+
- Nginx/Apache
- Composer 2.x+
- Git（用于下载前端资源）

### 2. 项目安装

```bash
# 进入后端目录
cd backend

# 安装后端依赖
composer install
```

### 3. 下载前端资源

**方式一：使用命令下载（推荐）**

```bash
# 进入后端目录
cd backend

# 下载所有前端代码（管理后台 + 前台）
php webman madong-download:frontend

# 仅下载管理后台
php webman madong-download:frontend --admin

# 仅下载前台
php webman madong-download:frontend --web

# 指定分支下载
php webman madong-download:frontend -b develop

# 强制覆盖更新
php webman madong-download:frontend -f
```

**方式二：手动下载**

如果没有安装 Git 或无法使用命令，可手动下载前端代码：

1. 访问 Gitee 仓库下载：
   - 管理后台：https://gitee.com/motion-code/madong-single（template/admin）
   - 前台：https://gitee.com/motion-code/madong-web（template/web）

2. 解压后将代码放置到项目根目录：
   ```
   madong/
   ├── backend/          # 后端代码
   └── template/         # 前端模板代码
       ├── admin/        # 管理后台前端（来自 madong-single）
       ├── web/          # 前台前端（来自 madong-web）
       └── install/      # 安装器前端
   ```

### 4. 启动安装向导

完成以上步骤后，访问安装向导完成系统配置：

```
http://127.0.0.1:8500/install
```

### 5. 启动服务

```bash
# 进入后端目录
cd backend

# 开发环境启动
php start.php start

# 生产环境启动
php start.php start -d
```

服务默认运行在 `http://127.0.0.1:8500`

## 配置说明

### 数据库配置

修改 `config/database.php` 文件，配置数据库连接：

```php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'madong',
            'username' => 'root',
            'password' => '123456',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
    ],
];
```

### Redis 配置

修改 `config/redis.php` 文件，配置 Redis 连接：

```php
return [
    'default' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => '',
        'db' => 0,
    ],
];
```

## API 文档

启动服务后，可通过以下方式访问 API 文档：
- 查看 `app/adminapi/controller` 与 `app/api/controller` 目录下的控制器文件
- 参考项目文档

## 开发指南

### 代码规范

- 遵循 PSR-4 自动加载规范
- 遵循 PSR-12 代码风格规范
- 使用 PHP 8.2+ 的特性

### 模块开发

1. 创建控制器：在 `app/adminapi/controller`（按业务域组织文件）或 `app/api/controller`（按站点组织文件）目录下创建控制器
2. 创建服务层：在 `app/service` 对应业务域目录下创建业务逻辑
3. 创建 DAO 层：在 `app/dao` 对应业务域目录下创建数据访问对象
4. 创建模型：在 `app/model` 对应业务域目录下创建 Eloquent 模型
5. 配置路由：在 `config/route.php` 或 `config/route/adminapi.php` / `config/route/api.php` 文件中添加路由
6. 测试：使用 Postman 或其他工具测试 API


## 系统演示

管理后台： http://demo.madong.tech 
账号：admin 
密码：123456

## 更新日志

### v5.1.0（当前版本）

- 后端重构为 **madong 单体后台架构**，统一支持标准版与多租户版
- **框架核心分层**：`core` 重构为 business / communication / foundation / infrastructure / interface / io / security 分层，移除旧的扁平 core 模块
- **应用业务层重组**：`app` 重组为 adminapi（controller/事件/监听/验证器按业务域组织）与 api（前台站点）双入口，dao / model / enum / service / schema 按业务域分层，补充 command 插件迁移、event/listener、bootstrap 模块
- **插件清理**：移除 `plugin/example` 示例插件，精简 `plugin/demo` 前端模板源，新增 `plugin/codegen` 代码生成插件
- **数据迁移**：迁移文件重命名（2026_04_01 → 2026_07_01），新增系统/会员/代码生成/站点/审核表迁移，大幅更新菜单与种子数据
- **配置与安装**：新增 `limiter`、重命名 `rate-limiter`，新增 `validation` 插件配置，更新安装器资源与 `composer` 依赖
- **测试**：新增 `phpunit.xml` 单元测试配置

### 5.0 版本

- 重构后端架构，基于 Webman 框架
- 实现 JWT 认证机制
- 集成 Redis 缓存服务
- 优化数据库操作
- 增强插件系统，支持第三方扩展
- 完善配置系统，支持多环境配置
- 新增国际化支持，支持多语言
- 优化错误处理和日志系统
- 新增文件上传功能，支持多种存储方式
- 完善命令行工具，支持更多操作
- 新增 SSE 实时推送，支持插件安装/卸载进度实时反馈
- 新增服务层（Service）和数据访问层（DAO）分层架构
- 新增代码生成器，提升开发效率
- 优化插件安装/卸载流程，统一由插件 Install.php 管理生命周期
- 新增枚举类规范，统一状态码和业务枚举定义

## 官方论坛

产品BUG、优化建议，欢迎社区反馈：http://www.madong.tech

## 如何贡献

非常欢迎你的加入！[提一个 Issue](https://gitee.com/motion-code/madong/issues) 或者提交一个 Pull Request。

**Pull Request:**

1. Fork 代码!
2. 创建自己的分支: `git checkout -b feature/xxxx`
3. 提交你的修改: `git commit -am 'feat(function): add xxxxx'`
4. 推送您的分支: `git push origin feature/xxxx`
5. 提交`pull request`

## Git 贡献提交规范

- 参考规范([Git](https://www.conventionalcommits.org/) [Vue](https://github.com/vuejs/vue/blob/dev/.github/COMMIT_CONVENTION.md) [Angular](https://github.com/conventional-changelog/conventional-changelog/tree/master/packages/conventional-changelog-angular))

  - `feat` 增加新功能
  - `fix` 修复问题/BUG
  - `style` 代码风格相关无影响运行结果的
  - `perf` 优化/性能提升
  - `refactor` 重构
  - `revert` 撤销修改
  - `test` 测试相关
  - `docs` 文档/注释
  - `chore` 依赖更新/脚手架配置修改等
  - `ci` 持续集成
  - `types` 类型定义文件更改
  - `wip` 开发中

## 社区支持
- 官方文档：https://madong.tech
- 讨论交流：[点击链接加入群聊：690671595](https://qm.qq.com/q/dEfMqYL42c)


## 许可证

Apache License