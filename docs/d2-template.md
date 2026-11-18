# D2 Webman 项目模板

模板包名为 `z0x/webman-starter-template`，类型为 Composer `project`。它从 [仓库内的 Webman 示例](../examples/webman/) 打包，保留应用目录、Unix 和 Windows 启动入口以及 [上游 MIT 许可和版权](../examples/webman/LICENSE)。骨架源自 [walkor/webman v2.2.3](https://github.com/walkor/webman/tree/v2.2.3)，提交 `394e611efc496eab992fc6447d2c7ef2438b6902`。骨架标签与安装时解析的 Webman 框架版本分别管理。

## 构建和发布

用安装了 `zip` 扩展的 PHP 从源码仓库根目录执行下面的命令。第一个参数是包含 D2 的基座最低版本，第二个参数是待发布的 ZIP 路径。

```bash
php tools/build-template.php "$STARTER_VERSION" "webman-starter-template-$TEMPLATE_VERSION.zip"
```

构建器从当前工作区读取 Git 已跟踪的示例文件。发布时应使用对应版本的干净检出。ZIP 根目录的 Composer 清单显式依赖 `z0x/webman-starter`、`webman/database` 和 `webman/redis`，基座约束基于提供的稳定版本生成。发布包排除工作区路径仓库、`dev-local`、Composer 生命周期脚本、本地锁文件、依赖目录、运行时数据和实际环境文件。

[#23](https://github.com/0xunknownz/webman-starter/issues/23) 使用 GitHub Release 和独立 Composer 索引分发两个项目包。见 [D2 发布流程](release-d2.md)。

## 从发布版本创建项目

准备 PHP 8.1 或以上版本、Composer 2、MySQL 8 和 Redis 6 或以上版本。启用 `pdo_mysql` 和 `redis` 扩展，Unix 运行 Webman 另需 `pcntl` 和 `posix`。使用 `composer check-platform-reqs` 核对当前环境。

D2 模板版本为 `1.0.0`，基座版本为 `1.1.0`。安装和更新时数据库保持原状。

```bash
composer create-project --no-interaction --prefer-dist \
  --repository=https://raw.githubusercontent.com/0xunknownz/webman-starter/composer --add-repository \
  z0x/webman-starter-template my-product 1.0.0
cd my-product
cp .env.example .env
```

在生成的产品中配置环境变量或填写 [示例环境文件](../examples/webman/.env.example)。

| 配置 | 设置 |
| --- | --- |
| MySQL | 填写 `STARTER_DB_HOST`、`STARTER_DB_PORT`、`STARTER_DB_DATABASE`、`STARTER_DB_USERNAME` 和 `STARTER_DB_PASSWORD`。每个产品和环境使用自己的库。CLI 与 Webman 指向同一库。 |
| Redis | 填写 `STARTER_REDIS_HOST`、`STARTER_REDIS_PORT` 和 `STARTER_REDIS_PASSWORD`。`STARTER_REDIS_PREFIX` 采用 `产品:环境` 格式，例如 `orders:staging`。同环境的实例使用同一前缀。 |
| 短信 | 配置供应商和签名后设置各用途的 `STARTER_SMS_TEMPLATE_*` 模板。`STARTER_SMS_TEMPLATE_INVITE` 用于受邀人主动请求的验证短信。测试环境可注入受控发送器。 |
| SMTP | 使用可选邮箱功能时设置 `STARTER_SMTP_DSN` 和 `STARTER_EMAIL_FROM`。供应商凭据由运行环境提供。 |
| 时区 | `STARTER_TIMEZONE` 默认为 `Asia/Shanghai`，用于短信和邮件自然日限额。订阅时间比较统一使用 UTC。 |
| 邀请页面 | `STARTER_INVITATION_PAGE_URL` 设置为产品控制的可信 HTTPS 页面，例如 `https://app.example.com/join`。由有权限的邀请人自行转发链接。产品页面依次调用解析、主动发码、验证和接受接口。 |
| 路由和鉴权 | 示例路由和鉴权默认关闭。初始化后显式设定 `WEBMAN_STARTER_ROUTES_ENABLED=1`、`STARTER_AUTH_ENABLED=1`，以及至少 32 字节的 `STARTER_AUTH_SECRET`。路由默认前缀为 `/saas`，可用 `WEBMAN_STARTER_ROUTE_PREFIX` 覆盖。 |

初次启用短信供应商时，从依赖包复制其示例配置，再填写供应商设置。凭据应从部署环境读取。

```bash
mkdir -p config/plugin/hhink/webman-sms
cp -n vendor/hhink/webman-sms/src/config/plugin/hhink/webman-sms/app.php config/plugin/hhink/webman-sms/app.php
```

由运维从产品根目录执行迁移和一次性初始化。将 `ADMIN_PHONE` 设为获准的首位管理员手机号。

```bash
php vendor/bin/phinx status -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/phinx migrate -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/webman-starter platform:init "$ADMIN_PHONE"
php bin/setup-example.php
php start.php start
```

启用路由后可访问 `http://127.0.0.1:8787/saas/health`。更新基座前备份，更新后显式审查和运行迁移并重启 Webman。环境配置变更也需重启。D1 鉴权和运维命令详见 [D1 接入文档](saas-d1.md)。

## 开通、加入与隔离验收

以下路径相对于 `/saas`，可由部署配置修改。平台和组织入口各使用对应会话。时间使用精确到秒的 RFC 3339。

| 操作 | 请求 |
| --- | --- |
| 手动开通 | `POST /platform/organizations`，输入 `name`、`phone`、`package_id`、`starts_at`、`expires_at`。套餐 ID 取自示例初始化输出 `basic_package_id`。 |
| 普通成员邀请 | `POST /organizations/{organization_id}/invitations`，输入 `phone`。所有者或管理员自行分享 `link`。 |
| 解析 | 从产品页面地址片段读取 `token`，发送 `POST /invitations/resolve`，正文为 `token`。解析只返回名称、后四位掩码、到期时间、邀请类型。 |
| 请求短信 | `POST /invitations/sms/send`，输入 `token`、`phone`。 |
| 验证短信 | `POST /invitations/sms/verify`，输入 `token`、`phone`、`challenge_id`、`code`。 |
| 接受 | `POST /invitations/accept`，输入 `token`、`phone`、`credential`。之后通过 D1 短信登录取得组织用途会话。 |
| 示例记录 | 设置 `STARTER_EXAMPLE_RECORDS_ENABLED=1` 后重启。`GET /organizations/{organization_id}/records` 列表。`POST` 同一路径创建，`PATCH` 记录路径修改，正文均为 `{"title":"示例记录"}`。任意成员可读取，所有者及管理员可写入。 |

邀请发送由受邀人主动请求。持有登录会话也须短信验证。保持签名密钥及页面地址在邀请有效期内一致。在非生产环境可由受控发送器捕获验证码。

开通甲、乙两个组织。各自所有者接受邀请并登录，在各自组织创建和读取一条记录。甲方列表只含甲方记录。甲方组织路径读取或修改乙方记录均返回 HTTP 404 和 `not_found`。乙方重新读取可确认原值。此流程对应 AC-24 和 AC-19。

## 验证候选包

从源码仓库根目录用 PHP 8.1 运行下面的命令。除运行时扩展外，验收工具还需要 `zip`、`git` 和 PATH 上的 `mysqld`、`redis-server`。可通过 `STARTER_TEST_COMPOSER` 指定 Composer 可执行文件。

```bash
php tests/template-candidate.php
```

测试将两个候选 ZIP 加入隔离的临时 Composer 仓库，通过非交互 `create-project` 安装，并检查实际 PHP 和依赖。使用独立 MySQL 验证建项留空、迁移可重复运行、管理员只能显式初始化、更新不访问数据库。最后启动生成的 Webman 项目并检查显式启用的健康路由。候选模板 `1.0.0` 与基座 `1.1.0`、`1.1.1` 只用于本地测试。已使用 PHP 8.1.34、Composer 2.5.1 和 2.11.2、MySQL 8.0.46 与 Redis 8.6.2 验证，安装解析到 Webman 框架 v2.2.5、`webman/database` v2.1.10 和 `webman/redis` v2.1.5。`php tests/d2-example.php --published` 可复现实例，使用干净 Composer 缓存从公共索引安装发布版，并在隔离的 MySQL 和 Redis 上启动 Webman。现场验收记录见 [#24](https://github.com/0xunknownz/webman-starter/issues/24)。
