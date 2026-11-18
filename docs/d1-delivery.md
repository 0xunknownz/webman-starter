# D1 身份与会话开发清单

依据 [GitHub #1](https://github.com/0xunknownz/webman-starter/issues/1)、各工单正文的 Blocked by 和 [PRD 第 11 节](prd-saas-foundation.md#11-交付计划)。依赖图无环。同一批内的工单可并行开发，由集成者合并共享路由、错误码、配置和验收夹具的改动。

| 批次 | 验收 | 工单 | 直接依赖 |
| --- | --- | --- | --- |
| 1 | 通过 | [#2 首位平台管理员初始化](https://github.com/0xunknownz/webman-starter/issues/2) | 无 |
| 2 | 通过 | [#3 短信登录与平台 API](https://github.com/0xunknownz/webman-starter/issues/3) | #2 |
| 3 | 通过 | [#4 多设备会话、刷新和退出](https://github.com/0xunknownz/webman-starter/issues/4) | #3 |
| 3 | 通过 | [#5 本人显示名称](https://github.com/0xunknownz/webman-starter/issues/5) | #3 |
| 3 | 通过 | [#7 绑定邮箱与密码登录](https://github.com/0xunknownz/webman-starter/issues/7) | #3 |
| 4 | 通过 | [#6 平台资格授予和撤销](https://github.com/0xunknownz/webman-starter/issues/6) | #4 |
| 4 | 通过 | [#8 密码重置](https://github.com/0xunknownz/webman-starter/issues/8) | #4、#7 |
| 4 | 通过 | [#9 正常手机号换绑](https://github.com/0xunknownz/webman-starter/issues/9) | #4 |
| 5 | 通过 | [#10 通过邮箱恢复手机号](https://github.com/0xunknownz/webman-starter/issues/10) | #7、#9 |
| 5 | 通过 | [#11 平台人工与离线恢复](https://github.com/0xunknownz/webman-starter/issues/11) | #6、#9 |
| 6 | 通过 | [#12 更换邮箱](https://github.com/0xunknownz/webman-starter/issues/12) | #8、#10 |

### 集成顺序

第 1 批在同一工单内并行搭建官方 Webman 示例、迁移与 CLI、独立验收环境。第 3 批共用 #3 的登录与会话签发契约。第 4 批共用 #4 的会话撤销能力。#4 通过后可开始 #6 和 #9。#8 以及 #9 的邮箱登录回归还需要 #7。第 5 批共用 #9 的换绑事务。#12 可在 #8、#10 验收后开始，此时 #11 仍可并行收尾。

### 阶段验收

- [x] #2 安装、显式迁移及平台管理员初始化
- [x] #3 真实 HTTP 短信登录及平台鉴权
- [x] #4 会话期限、轮换、退出和令牌用途隔离
- [x] #5 本人显示名称与常驻进程隔离
- [x] #7 邮箱绑定及备用密码登录
- [x] #6 平台授权、撤权与并发最后一位保护
- [x] #8 邮箱密码重置与会话撤销
- [x] #9 正常换绑及邀请与席位清理
- [x] #10 邮箱恢复手机号
- [x] #11 平台人工与离线手机号恢复
- [x] #12 邮箱更换与旧凭据失效
- [x] D1 阶段回归与示例演示

各勾选项以对应工单的验收条件为准。验收使用独立 MySQL 和 Redis、真实 CLI 与 Webman HTTP，邮件和短信使用受控发送器。D1 使用数据库夹具验证邀请联动的持久化边界，完整邀请 HTTP 流程由 D2 交付。
