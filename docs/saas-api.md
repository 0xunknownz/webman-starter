# SaaS API

所有路径以宿主显式注册的前缀为基准，示例使用 `/saas`。输入默认为 JSON，GET 使用查询参数。认证使用 `Authorization: Bearer <access_token>`。下文“本人”允许任一用途的有效会话，“平台”要求平台用途及当前平台资格，“组织”要求组织用途及对应租户角色。路径资源 ID 为正整数。

JSON 响应含 `code`、`message`、`data`、`trace_id`，验证失败可含字段级 `errors`。请求可提供合法的 `X-Trace-Id`，响应会传递该值或生成新的 Trace ID。除标注外成功返回 200，创建资源返回 201，删除返回 200 与空 data。 [响应契约](api-response.md)

## 身份与账号

| 方法与路径 | 权限 | 输入与结果 |
| --- | --- | --- |
| GET `/health` | 公开 | 显式注册后可用。 |
| POST `/auth/sms/send` | 公开 | `phone`，校验和限额通过后统一受理。 |
| POST `/auth/sms/login` | 公开 | `phone`、`challenge_id`、`code`、可选 `purpose`。 |
| POST `/auth/email/login` | 公开 | `email`、`password`、可选 `purpose`。 |
| POST `/auth/refresh` | 刷新令牌 | `refresh_token`，轮换令牌。 |
| POST `/auth/logout` | 本人 | 撤销当前设备会话。 |
| GET、PATCH `/account/profile` | 本人 | 读取本人资料或提交 `display_name`，修剪后最多 50 个字符，禁止控制字符。空串或 null 清空，省略保留。 |
| POST `/account/email/send` | 本人 | `email`，提交验证邮件。 |
| POST `/account/email/bind` | 本人 | `email`、`challenge_id`、`code`，绑定成功写审计。 |
| PUT `/account/password` | 本人 | `password`，须先绑定已验证邮箱。 |
| POST `/auth/password/reset/send` | 公开 | `email`，校验和限额通过后统一受理。 |
| POST `/auth/password/reset/complete` | 公开 | `email`、`challenge_id`、`code`、`password`，成功写审计并撤销全部会话。 |
| POST `/account/email/change` | 本人 | `new_email`、`channel`（`email` 或 `sms`），已验证邮箱的账号可发起更换。 |
| POST `/account/phone/change` | 本人 | `new_phone`，向旧号提交验证码。 |
| POST `/auth/phone/recovery/email/send` | 公开 | `email`，校验和限额通过后统一受理。 |
| POST `/auth/phone/recovery/email/verify` | 公开 | `email`、`new_phone`、`challenge_id`、`code`，返回 `flow_id` 和 `recovery_token`。 |
| POST `/platform/phone-recovery` | 平台 | 整数 `account_id`、`new_phone`、`reason` 和 `evidence`，依据核验通过返回 201 和 `flow_id`。 |
| GET `/platform/me` | 平台 | 返回当前账号 ID。 |
| POST `/platform/admins` | 平台 | `phone`，新建无组织员工账号返回 201，复用已有账号返回 200。写审计。 |
| DELETE `/platform/admins/{accountId}` | 平台 | 撤销平台资格和平台会话。成功及最后一位保护均写审计。 |

手机号必须是合法中国大陆移动号码。密码长度为 8–64 个 Unicode 字符，UTF-8 编码最多 72 字节。`purpose` 可为 `organization`（默认）或 `platform`。短信和邮件发送入口按用途记录失败、超时、限额及适用的重发行为。

以下流程后续步骤均用 POST，`verify` 请求提交对应的 `challenge_id` 和 `code`。各项证明从验证通过起有效 10 分钟。 [身份契约和恢复时序](saas-d1.md)

| 流程前缀 | 后续步骤 |
| --- | --- |
| `/account/email/change/{flow}` | 同一会话调用 `/old/send`、`/old/verify`、`/new/send`、`/new/verify`、`/complete`。 |
| `/account/phone/change/{flow}` | 本人调用 `/old/send`、`/old/verify`、`/new/send`、`/new/verify`、`/complete`。 |
| `/auth/phone/recovery/email/{flow}` | `/new/send`、`/new/verify`、`/complete`，每次在请求正文中提交 `recovery_token`。 |
| `/platform/phone-recovery/{flow}` | 发起流程的有效平台会话调用 `/new/send`、`/new/verify`、`/complete`。 |

邮箱更换、换绑和恢复写入审计后提交。邮箱更换保留当前设备会话，撤销其他会话并作废旧邮箱凭据。换绑和恢复撤销全部会话，清理旧号待接受邀请和相关凭据。人工核验只记录受控引用。

## 组织、邀请与订阅

下表 `O` 表示 `/organizations/{organizationId}`，`P` 表示 `/platform/organizations/{organizationId}`。`I` 代表 `{invitationId}`。

| 方法与路径 | 权限、输入与审计 |
| --- | --- |
| POST `/platform/organizations` | 平台。`name`、`phone`、整数 `package_id`、RFC 3339 秒级 `starts_at` 和 `expires_at`，201 返回组织和首位所有者邀请。写审计。 |
| PATCH `P/name` | 平台。`name`，实际改名时写审计。 |
| POST `P/suspend`、`P/unsuspend`、`P/archive`、`P/restore`、`P/cancel-opening` | 平台。变更写审计。取消开通限待激活的组织。 |
| POST `O/archive` | 组织所有者。归档写审计。 |
| GET `P/owner-invitations/I/link`、DELETE `P/owner-invitations/I` | 平台。在待激活组织获取或撤销首位邀请，写审计。 |
| POST `P/owner-invitations/I/reissue`、`P/owner-invitations/I/change-phone` | 平台。改邀提交 `phone` 和 `reason`（`wrong_number`、`recipient_changed`、`number_unavailable` 或 `other`），201。签发和改邀写审计。 |
| POST `O/invitations`、`O/invitations/I/reissue` | 组织所有者或管理员。创建提交 `phone`，仅允许普通成员角色。201，写审计。 |
| GET `O/invitations/I/link`、DELETE `O/invitations/I` | 组织所有者或管理员。获取链接或撤销，写审计。 |
| DELETE `P/invitations/I` | 平台。组织停用或归档时撤销普通邀请，写审计。 |
| POST `/invitations/resolve`、`/invitations/sms/send`、`/invitations/sms/verify`、`/invitations/accept` | 公开。均提交 `token`，除解析外均提交 `phone`。验证另需 `challenge_id` 和 `code`，接受另需单次 `credential`。记录适用的验证和接受结果。 |
| GET `O/members` | 成员。普通成员看名称和角色，所有者或管理员可见已验证联系方式。 |
| PATCH `O/members/{accountId}/role` | 组织所有者。`role` 为 `admin` 或 `member`，变更写审计。 |
| DELETE `O/members/{accountId}`、`O/members/me` | 所有者或有权移除普通成员的管理员可移除。非所有者可退出。成功写审计。 |
| POST `O/ownership-transfers`、DELETE `O/ownership-transfers/{transferId}` | 组织所有者。发起提交整数 `recipient_account_id` 并返回 201。发起与撤销写审计。 |
| GET `O/ownership-transfers/pending`、POST `O/ownership-transfers/{transferId}/accept` | 组织内指定接收人查看自己的待办或接受。完成及过期写审计。 |
| POST `/platform/packages`、PATCH `/platform/packages/{packageId}`、POST `/platform/packages/{packageId}/retire` | 平台。创建提交 `name` 和整数 `seat_limit` 并返回 201，修改至少提交一项。实际变更写审计。 |
| PATCH `P/subscription/window`、`P/subscription/package` | 平台。分别提交 `starts_at`、`expires_at`，或整数 `package_id`。实际变更写审计。 |

到期时间必须晚于生效时间。已到期组织保留角色允许的查询、导出和必要治理。未生效时允许首位所有者按规则验证和接受邀请。归档优先于停用，二者均阻止组织侧访问。邀请由操作者转发，已登录的受邀人也须短信验证。 [邀请示例](d2-template.md#开通加入与隔离验收) · [生命周期细则](d3-delivery.md)

## 审计查询与 CSV

| 方法与路径 | 权限与响应 |
| --- | --- |
| GET `/platform/audit-logs` | 平台。身份、平台资格、套餐及平台可见的组织运营事件。 |
| GET `O/audit-logs` | 组织所有者。只读取可见且已确认归属本组织的记录。 |
| GET `O/members/export` | 组织所有者或管理员。`members.csv` 列为 `account_id,display_name,role,phone,email`。未绑定或未验证的邮箱为空。 |
| GET `O/audit-logs/export` | 组织所有者。`audit-logs.csv` 列为 `id,organization_id,actor_account_id,target_account_id,action,result,created_at,metadata`。metadata 是脱敏 JSON。 |

查询参数为 `page`（正整数，默认 1）、`per_page`（1–100，默认 20）、`action`（可选，最长 64 字节的小写 snake_case 事件名）。返回 `data.items` 和 `data.pagination`，按 UTC 时间和 ID 倒序。系统事件的账号可为空。查询不写审计。缺少可靠组织归属的历史记录不进入组织侧。缺少平台来源标记的历史普通邀请撤销只在组织侧展示。

下载以 UTF-8 CSV 返回，带 `Content-Type: text/csv; charset=utf-8`、附件下载头、`Cache-Control: private, no-store` 和 `X-Trace-Id`。可能被表格程序解释成公式的文本格加一个前导单引号，含加号的 E.164 号码也作为文本导入。导出成功后记种类、操作者、组织、时间、结果和数据行数。行数不含表头或本次导出审计。生成失败但审计可用时记 `failed` 和 0 行。审计写入失败时返回 503 JSON，下载以审计提交为前提。

平台用途会话和普通成员均无成员联系方式下载权限。平台审计提供 JSON 查询。业务项目实现其自身数据导出，使用 `BusinessQuery`、`BusinessExport`、`BusinessWrite` 或相应安全操作分类，按租户归属和声明的业务权限校验。

## 封闭错误码

通用码为 `success`（200 或 201）、`validation_failed`（422）、`unauthenticated`、`token_invalid`、`token_expired`（401）、`forbidden`（403）、`not_found`（404）和 `internal_error`（500）。组织侧按身份、组织归属、归档、停用、激活阶段、订阅、角色顺序返回首个失败。未知宿主路径沿用 `route_not_found`。 [PRD 错误码](prd-saas-foundation.md#82-错误码)

| 错误码 | 触发条件 | HTTP |
|---|---|---|
| phone_invalid | 号码不是合法的中国大陆移动号码 | 422 |
| email_invalid | 邮箱缺少 @、超过 254 字符或含控制字符 | 422 |
| organization_name_invalid | 组织名称为空、超长或含控制字符、换行 | 422 |
| package_invalid | 套餐名称或席位数不在允许范围 | 422 |
| subscription_window_invalid | 到期时间不晚于生效时间，或时间无法按 RFC 3339 解析 | 422 |
| rate_limit_config_invalid | 限额配置超过允许范围或试图关闭限流。拒绝加载，不是普通请求响应 | 启动失败 |
| credentials_invalid | 邮箱密码错误、邮箱不存在或尚未启用密码登录 | 401 |
| credential_expired | 短期凭据过期、已消费或流程不匹配 | 401 |
| purpose_mismatch | 验证码或凭据的用途、号码与本次流程不一致 | 401 |
| verification_code_invalid | 挑战未耗尽前的验证码错误 | 401 |
| verification_attempts_exceeded | 验证失败次数耗尽，挑战失效 | 401 |
| token_purpose_mismatch | 用组织用途令牌调用平台 API，或相反 | 403 |
| organization_pending | 待激活组织上执行了只对已激活组织开放的操作 | 403 |
| organization_suspended | 组织处于平台停用，调用了组织侧操作 | 403 |
| organization_archived | 组织已归档，调用了组织侧操作 | 403 |
| subscription_inactive | 订阅未生效，执行了被禁止的业务访问或普通成员邀请 | 403 |
| subscription_expired | 订阅已到期，执行了写入、新邀请或接受邀请 | 403 |
| not_found | 组织或资源不存在，或调用者不是该组织成员；邀请令牌无法识别 | 404 |
| member_already_exists | 目标已是本组织有效成员，仍创建或重新签发邀请 | 409 |
| invitation_already_exists | 同组织同号已有有效待接受邀请 | 409 |
| invitation_invalid | 邀请已过期、撤销、消费、被替换，或手机号不匹配 | 409 |
| seat_limit_exceeded | 新预留或更换套餐后的席位占用会超过上限 | 409 |
| phone_already_used | 换绑或恢复的新号码已被其他账号占用 | 409 |
| email_already_used | 邮箱已被其他账号绑定 | 409 |
| platform_admin_already_initialized | 已有有效平台管理员时仍执行初始化 | 409 |
| platform_admin_already_granted | 目标已经是有效平台管理员，再次授予 | 409 |
| last_platform_admin_required | 撤销后将不再有有效平台管理员 | 409 |
| last_owner_required | 所有者在转移前退出，或移除唯一所有者 | 409 |
| ownership_transfer_invalid | 转移请求过期、撤销、已完成，或接受时复核失败 | 409 |
| package_retired | 把已停用的套餐用于新开通或更换套餐 | 409 |
| sms_rate_limited | 短信发送限额 | 429 |
| email_rate_limited | 邮件发送限额 | 429 |
| password_login_locked | 邮箱密码登录失败次数耗尽后的锁定期间 | 429 |
| sms_submission_failed | 除公开登录发码外的短信入口，短信供应商明确拒绝提交 | 502 |
| email_submission_failed | 已登录的邮箱绑定或更换入口，邮件供应商明确拒绝提交 | 502 |
| sms_submission_timeout | 除公开登录发码外的短信入口，短信提交超时，结果不明 | 504 |
| email_submission_timeout | 已登录的邮箱绑定或更换入口，邮件提交超时，结果不明 | 504 |
| audit_unavailable | 要求审计的变更无法写入审计 | 503 |
