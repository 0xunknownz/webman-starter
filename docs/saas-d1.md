# D1 身份与会话接入

## 本地示例

D1 示例派生自 [walkor/webman v2.2.3](https://github.com/walkor/webman/tree/v2.2.3)，通过 Composer 路径仓库安装当前工作区的基座。请准备 PHP 8.1 及以上、Composer 2、MySQL 8 和 Redis 6 及以上，并启用 `pdo_mysql` 和 `redis` 扩展。

```bash
cd examples/webman
composer install --no-interaction
cp .env.example .env
```

在宿主项目根目录配置环境变量或填写 `.env`。同一配置项用一种方式提供，确保 Webman 工作进程、迁移和初始化指向同一个环境。MySQL 应指向当前产品和环境的专用库。为迁移账号授予对应库的 DDL 权限，为运行账号分配业务所需的最小权限。

| 变量 | 用途 |
| --- | --- |
| `STARTER_DB_HOST`、`STARTER_DB_PORT` | MySQL 地址，默认 `127.0.0.1:3306` |
| `STARTER_DB_DATABASE`、`STARTER_DB_USERNAME`、`STARTER_DB_PASSWORD` | 目标库、数据库用户名与密码 |
| `STARTER_REDIS_HOST`、`STARTER_REDIS_PORT`、`STARTER_REDIS_PASSWORD` | Redis 连接配置 |
| `STARTER_REDIS_PREFIX` | 产品与环境专用的 Redis 键前缀 |
| `WEBMAN_STARTER_ROUTES_ENABLED`、`WEBMAN_STARTER_ROUTE_PREFIX` | 示例路由开关和前缀。默认关闭，打开后默认前缀为 `/saas` |

## 迁移和初始化

从宿主项目根目录执行。基座使用独立的 `starter_phinxlog` 记录版本，重复迁移会跳过已应用版本。

```bash
php vendor/bin/phinx status -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/phinx migrate -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/webman-starter platform:init '13800138000'
```

将示例号码替换为经批准的首位管理员号码。初始化由具备服务器执行权限和专用库写入权限的运维账号执行。号码按中国大陆移动号码规则校验并存为 E.164。号码已有账号时复用原账号。若已存在有效平台管理员，命令返回 `platform_admin_already_initialized`，包括该管理员尚未首次登录时。资格和 `starter_audit_logs` 中的脱敏审计在同一事务提交。审计记录执行进程的有效 UID。缺少 POSIX 扩展时，由部署环境为受控运维进程注入 `STARTER_CLI_ACTOR`，值为 1–64 个 ASCII 字母、数字、点、连字符或下划线。无法识别操作者或写入审计时返回 `audit_unavailable`。

## 示例 HTTP 验收

将 `.env` 中的 `WEBMAN_STARTER_ROUTES_ENABLED` 设为 `1`。使用环境变量配置时，先在启动环境中设置同名变量。

```bash
php start.php start
curl -i -H 'X-Trace-Id: starter-example' http://127.0.0.1:8787/saas/health
```

示例返回现有 JSON 响应结构，并传递 Trace ID。访问未注册的宿主路径应得到 HTTP 404 和 `route_not_found`。

## 短信登录与平台鉴权

在已经迁移并初始化的宿主项目中启用基座路由，并设定 `STARTER_AUTH_ENABLED=1`。配置至少 32 字节的 `STARTER_AUTH_SECRET`，并用 `产品:环境` 形式的 Redis 前缀隔离部署。各实例须使用相同的环境配置。示例通过 `hhink/webman-sms` 向宿主配置的供应商提交短信，登录模板从 `STARTER_SMS_TEMPLATE_LOGIN` 读取。

所有路径相对于宿主配置的基座前缀。

| 方法和路径 | 请求 | 响应 |
| --- | --- | --- |
| POST `/auth/sms/send` | JSON `phone` | 受理后返回 `accepted` 和 `challenge_id`。提交结果不代表送达。 |
| POST `/auth/sms/login` | JSON `phone`、`challenge_id`、`code` 和可选 `purpose` | 已有账号验证通过后返回访问令牌。用途为 `organization`（默认）或 `platform`。 |
| GET `/platform/me` | `Authorization: Bearer <access_token>` | 返回当前账号 ID。要求平台用途、会话有效以及当前平台资格。 |

未注册且没有有效邀请的号码占用同等发送额度。请求受理结构对各投递结果一致。验证码仅能消费一次，默认 5 分钟到期，第 5 次错误使其失效。发送限额按规范化手机号和来源 IP 计算。同一号码至少间隔 60 秒，自然日最多 10 条。同一来源 IP 默认每连续 60 分钟最多 20 次，可配置到 200。其余上限只能收紧。自然日按 `STARTER_TIMEZONE` 计算，默认 `Asia/Shanghai`。

默认按 TCP 对端 IP 限流。部署在可信代理后时，配置 `STARTER_TRUSTED_PROXIES` 为控制范围内的代理 IP 列表，用逗号分隔，并确保代理规范设置 `X-Forwarded-For`。

## 多设备会话与本人资料

登录返回访问令牌和刷新令牌。访问令牌有效期为 15 分钟，刷新会话有效期最长为登录后的 7 天。客户端通过 POST `/auth/refresh` 提交 `refresh_token`，并用响应中的新令牌替换旧令牌。刷新保留原会话的最长有效期，续发访问令牌的有效期也受剩余会话时间限制。POST `/auth/logout` 使用 Bearer 访问令牌退出当前设备，响应的 `data` 为 null。平台用途刷新仍检查当前平台资格。

GET `/account/profile` 返回本人 `account_id`、`display_name` 和 `display_label`。PATCH 同一路径可以修改 `display_name`。省略该字段保留原值，null 或空串清空。非空名称去掉首尾空白后长度为 1–50 个 Unicode 字符，包含控制字符、换行或仅由空白组成的输入返回 `validation_failed`。清空后 `display_label` 为“未设置姓名”。两个方法均需有效 Bearer 令牌。

## 邮箱绑定与密码登录

配置 `STARTER_SMTP_DSN` 和 `STARTER_EMAIL_FROM` 以提交邮件。验证邮件的地址和 IP 计数按邮件用途隔离，与短信计数独立。默认同一邮箱同一用途至少间隔 60 秒、自然日最多 10 封，来源 IP 每连续一小时最多 20 次。验证码默认 10 分钟到期，第 5 次错误后失效。

| 方法和路径 | 输入 |
| --- | --- |
| POST `/account/email/send` | Bearer 令牌，JSON `email` |
| POST `/account/email/bind` | Bearer 令牌，JSON `email`、`challenge_id`、`code` |
| PUT `/account/password` | Bearer 令牌，JSON `password`。账号须先验证邮箱。 |
| POST `/auth/email/login` | JSON `email`、`password` 和可选 `purpose`。 |

密码限定为 8–64 个 Unicode 字符且 UTF-8 编码最多 72 字节。账号连续 5 次输错密码会锁定密码登录 15 分钟，仍可通过短信登录。邮箱已归属其他账号时返回 `email_already_used`。绑定入口在供应商拒绝和超时时分别返回 `email_submission_failed` 和 `email_submission_timeout`。

## 密码重置

POST `/auth/password/reset/send` 接收 `email`，返回统一受理响应及 `challenge_id`。只有已验证邮箱且设有密码的账号会收到验证码。POST `/auth/password/reset/complete` 接收 `email`、`challenge_id`、`code` 和新 `password`。验证码绑定当前邮箱版本，成功重置后全部设备和用途的会话立即失效。客户端需要重新登录。

## 平台资格管理

平台管理员使用平台用途 Bearer 令牌调用 POST `/platform/admins`，提交 JSON `phone`。成功时返回目标 `account_id`。操作按中国大陆移动号码规则创建或复用账号。DELETE `/platform/admins/{accountId}` 撤销指定账号的平台资格，立即使该账号的平台访问令牌和刷新令牌失效，组织用途会话继续有效。最后一位管理员的撤权请求返回 `last_platform_admin_required`。

平台资格授予、撤销与最后一位保护的审计写入 `starter_audit_logs`。

## 手机号换绑

已登录用户通过 POST `/account/phone/change` 提交 `new_phone`，接口向旧号码提交验证码并返回 `flow_id` 与 `challenge_id`。其余步骤均使用原 `flow_id`，路径以 `/account/phone/change/{flow}` 为前缀。

| POST 后缀 | 输入和作用 |
| --- | --- |
| `/old/send` | 重新向旧号码发送验证码，仍受换绑发送限额约束。 |
| `/old/verify` | 提交旧号的 `challenge_id` 和 `code`。 |
| `/new/send` | 旧号验证有效时发送新号验证码。 |
| `/new/verify` | 提交新号的 `challenge_id` 和 `code`。 |
| `/complete` | 同时检查两项验证结果并提交换绑。 |

验证码默认 5 分钟过期，两项验证结果分别从各自验证成功起有效 10 分钟。换绑成功后旧会话立即失效，旧号待接受的邀请与席位预留在同一事务处理。用户可使用新号码或已启用的邮箱密码重新登录。

## 使用已验证邮箱恢复手机号

不方便使用旧手机号时，用户可通过原来绑定的已验证邮箱启动恢复，包括从未设置过密码的账号。POST `/auth/phone/recovery/email/send` 接收 `email` 并返回统一受理响应。POST `/auth/phone/recovery/email/verify` 接收 `email`、`challenge_id`、`code` 和 `new_phone`，核验通过后返回 `flow_id`、`recovery_token` 和 `verified_until`。

然后对 `/auth/phone/recovery/email/{flow}` 依次 POST `/new/send`、`/new/verify`、`/complete`。每一步在 JSON 中提交 `recovery_token`，新号验证时同时提交 `challenge_id` 和 `code`。恢复凭据只能用于该流程，邮箱证明从核验成功起有效 10 分钟。完成换绑后按正常换绑规则清理旧会话、邀请与凭据。

## 人工与离线恢复

产品需要在 Webman 的 starter 配置中注入实现 `OperatorRecoveryEvidenceVerifierInterface` 的核验器。核验器依据已保存的归属资料检查工单引用、目标账号和新号码，并确认通过预登记联系人或已授权渠道完成核验。只有核验成功才可开始流程，核验器缺失或资料无法查证时返回 `forbidden`。

平台管理员用平台用途 Bearer 令牌向 POST `/platform/phone-recovery` 提交 `account_id`、`new_phone`、`reason` 和 `evidence` 对象。`reason` 可取 `lost_phone`、`number_unavailable` 或 `compromised_phone`，`evidence` 包含 `channel` 和 `reference`。渠道可取 `registered_contact` 或 `authorized_channel`。引用使用产品的工单标识。拿到 `flow_id` 后，在 `/platform/phone-recovery/{flow}` 下依次 POST `/new/send`、`/new/verify` 和 `/complete`。新号码的验证码随 `challenge_id` 传给验证入口。

唯一有效平台管理员无已验证邮箱时，执行服务器端离线恢复。受控运维账号在宿主项目根目录运行以下命令。部署配置需启用 `STARTER_OPERATOR_RECOVERY_CLI_ENABLED`，设置核验器并配置换绑短信模板和供应商。

```bash
php vendor/bin/webman-starter platform:recover '<new_phone>' lost_phone registered_contact '<ticket_reference>'
```

运维人员按已保存的归属资料核验账号，并取得发往新号码的验证码，通过命令的标准输入提交。审计记录保存在 `starter_audit_logs`，包括操作人、原因、核验渠道、依据引用和脱敏号码。核验、验证或审计失败时，检查命令错误码并保留原账号数据。此命令针对唯一管理员的离线恢复场景。日常授权和撤权通过平台管理 API 完成。

## 更换已验证邮箱

已登录并绑定已验证邮箱的用户可以通过 POST `/account/email/change` 提交 `new_email` 和 `channel`。`channel` 为 `email` 时验证现有邮箱，为 `sms` 时使用账号安全短信验证当前手机号。短信渠道使用 `STARTER_SMS_TEMPLATE_ACCOUNT_SECURITY`。接口返回 `flow_id` 和 `challenge_id`。

对 `/account/email/change/{flow}` 的后续 POST 请求使用同一设备会话。`/old/send` 重新提交第一步验证码，`/old/verify` 接收 `challenge_id` 与 `code`。第一步通过后，`/new/send` 向新邮箱提交验证码，`/new/verify` 接收新的 `challenge_id` 与 `code`。`/complete` 要求两项证明均有效。验证期限在响应的 `verified_until` 中以 UTC RFC 3339 表示。

更换成功后当前设备可以继续使用和刷新原会话，其他会话立即失效。旧邮箱上的密码重置和手机号恢复凭据同时失效，换回该邮箱也无法继续使用旧凭据。

## 自动化验收

在仓库根目录以普通 Unix 用户运行 `php tests/acceptance.php`。验收需要 PHP 的 `pdo_mysql`、`redis`、`pcntl` 和 `posix` 扩展，以及 PATH 上的 Composer 2、MySQL 8 的 `mysqld` 和 `redis-server`。脚本在临时目录启动独立的 MySQL、Redis 和 Webman，使用本机回环地址，并在结束时关闭服务和清理目录。`STARTER_TEST_COMPOSER` 可以指定 Composer PHP 可执行文件。

D1 完整验收在 PHP 8.1.34、MySQL 8.0.46、Redis 8.6.2 上通过，分别使用 Webman 2.1.0 和 2.2.5。设置 `STARTER_TEST_WEBMAN_VERSION=2.1.0` 可以重复验证最低框架版本。验收使用受控短信和邮件发送器。

## 升级

升级前按部署流程备份目标库并核对版本。更新 Composer 包后显式检查和应用待执行迁移，再重启 Webman 载入新依赖。普通 PHP 代码变更可使用宿主项目的 reload 流程。Composer 安装、更新和移除会保留数据库结构与数据，DDL 通过显式迁移入口执行。
