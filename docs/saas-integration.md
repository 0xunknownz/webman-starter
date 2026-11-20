# SaaS 基座接入与运维

本版面向国内产品。账号手机号按中国大陆移动号码规则校验，存为 +86 E.164。邮箱可用于已存在账号的验证、登录及恢复。邀请由有权限的操作者转发，受邀人即使已经登录也须短信验证。平台管理员不能查看或导出组织成员的完整联系方式。本期范围不含账号注销、个人数据主体导出、个人信息留存期限和删除权，也不提供任意业务表导出。

## 安装与配置

需要 PHP 8.1+、Composer 2、Webman 2.1+、MySQL 8 和 Redis 6+，开启 `pdo_mysql`、`redis`。Unix 下运行示例还需要 `pcntl` 和 `posix`。已有项目可用 `composer require z0x/webman-starter`，或按 [模板安装说明](d2-template.md#从发布版本创建项目) 创建项目。D4 用候选制品验证时，按 [交付验收记录](d4-delivery.md) 运行对应脚本。源码示例可在仓库根目录执行以下命令。

```bash
cd examples/webman
composer install --no-interaction
cp -n .env.example .env
composer check-platform-reqs
```

在宿主项目中配置 [示例环境变量](../examples/webman/.env.example)，确保迁移、命令与 Webman 使用同一个产品环境。DB 写入凭据和供应商配置由宿主部署注入。分配专用数据库及最小权限运行账号，迁移用具备对应库 DDL 权限的账号。Redis 用 `产品:环境` 前缀隔离。同一环境各实例使用一致的账号会话配置，鉴权密钥至少 32 字节。

- 配置 `STARTER_INVITATION_PAGE_URL` 为产品控制的 HTTPS 页面，配置各用途短信模板。使用邮箱功能时配置 SMTP 提交地址和发件地址。
- `STARTER_TIMEZONE` 默认 `Asia/Shanghai`，用于自然日额度。订阅按统一 UTC 时间轴比较，生效秒可访问，到期秒起按到期处理。
- 短信与邮件默认同一目标同用途间隔 60 秒、自然日上限 10，来源 IP 每连续 60 分钟默认上限 20。IP 上限最高可调到 200，目标日上限只能收紧。超出允许范围或关闭限流的配置拒绝加载。默认按 TCP 对端识别来源，只有明确配置的可信代理才使用转发地址。
- 宿主通过 `Routes::register($prefix, $auth)` 显式接入并选择前缀，按需提供 `AuthFactory`。示例用 `WEBMAN_STARTER_ROUTES_ENABLED=1` 和 `STARTER_AUTH_ENABLED=1` 注册基座入口，默认前缀为 `/saas`。例子另用 `STARTER_EXAMPLE_RECORDS_ENABLED=1` 开启示例记录路由。

## 显式迁移、初始化和启动

在宿主项目根目录执行，`ADMIN_PHONE` 由运维设为获准的首位管理员号码。

```bash
php vendor/bin/phinx status -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/phinx migrate -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/webman-starter platform:init "$ADMIN_PHONE"
php bin/setup-example.php
php start.php start
```

`setup-example.php` 是示例专用命令，建立示例记录表和基础套餐。初始化只在没有有效平台管理员时运行。它创建或复用员工账号，授予平台资格并在同一事务审计。审计中的 CLI 操作者来自进程有效 UID，无 POSIX 时来自受控部署配置 `STARTER_CLI_ACTOR`。首次短信登录使用 `purpose=platform`。启用后用 `GET /saas/health` 验证路由和 Trace ID，再用 [组织开通与隔离流程](d2-template.md#开通加入与隔离验收) 建立组织。各端点的输入、权限及错误码见 [SaaS API](saas-api.md)。

## 升级与数据保留

先备份数据库并确认备份可恢复，核对宿主 Composer 约束和目标制品。按目标版本更新包，运行 `composer check-platform-reqs`，显式检查与运行迁移。迁移版本保存在 `starter_phinxlog`，重复运行跳过已执行版本。更新依赖、环境变量或进程配置后重启 Webman 以加载新依赖和启动配置。普通业务代码可按宿主流程 reload。Composer 的安装、更新和移除会保留数据库结构和数据，DDL 由显式迁移命令执行。D4 复用 D3 的审计表和历史记录。缺少可靠历史归属时按 API 手册的查询范围处理。 [D3 直接构造兼容说明](release-d2.md#d3-source-upgrade-note)

## 恢复与运维权限

日常平台资格授予及撤销使用平台 API。需要人工恢复手机号时，由有当前资格的平台管理员发起保护流程。宿主注入 `OperatorRecoveryEvidenceVerifierInterface`，核验器以已有账号归属资料检查目标账号、预登记联系人或已授权渠道、原因、目标手机号与工单引用。无法核验时拒绝操作。只保留受控引用，避免在工单引用、日志和审计中写入原始核验材料。换绑必须验证新号码，审计持久化失败时回滚变更。

唯一有效平台管理员失去旧号且无已验证邮箱时，受控运维账号可以在宿主根目录执行下列命令。部署须启用 `STARTER_OPERATOR_RECOVERY_CLI_ENABLED=1`，配置核验器、换绑短信模板和供应商。登录服务器及执行命令的权限由部署侧严格分配。

```bash
php vendor/bin/webman-starter platform:recover '<new_phone>' lost_phone registered_contact '<ticket_reference>'
```

运维人员按已有归属资料验证引用，通过标准输入提供新号码接收的验证码。失败时依据命令错误码处理并保留原账号状态。核验、验证码或审计任一环节失败均拒绝恢复。成功后以新号码登录，旧会话和短期凭据失效。`starter_audit_logs` 保留操作者、原因、核验渠道、依据引用和脱敏号码。日常资格变更走平台 API。 [人工及离线恢复细则](saas-d1.md#人工与离线恢复)

## 消息供应商验证

自动化在隔离环境使用受控发送器，检查无需发送、提交接受、拒绝、超时、额度和审计。同一公开入口在限额内返回一致的受理结构。供应商接受只代表提交，实际送达须单独验证。真实 SMTP 和短信供应商联调需由部署侧指定测试收件目标并配置凭据。以 [交付验收记录](d4-delivery.md) 中的执行状态为准。
