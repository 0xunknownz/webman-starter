# D3 订阅与生命周期开发清单

GitHub 原生依赖栏为空，以下依赖按各工单正文的 Blocked by 与 [PRD 交付计划](prd-saas-foundation.md#d3-订阅与生命周期) 核对。前置 [#23](https://github.com/0xunknownz/webman-starter/issues/23) 和 [#24](https://github.com/0xunknownz/webman-starter/issues/24) 已关闭，依赖图无环。

| 批次 | 工单 | 直接依赖 |
| --- | --- | --- |
| 1 | [#28 套餐模板创建、修改与停用](https://github.com/0xunknownz/webman-starter/issues/28) | #23、#24 |
| 1 | [#29 当前订阅日期调整](https://github.com/0xunknownz/webman-starter/issues/29) | #23、#24 |
| 1 | [#30 组织归档与恢复](https://github.com/0xunknownz/webman-starter/issues/30) | #23、#24 |
| 2 | [#31 更换套餐与席位并发](https://github.com/0xunknownz/webman-starter/issues/31) | #28、#29 |
| 3 | [#32 生命周期联调与 D1/D2 回归](https://github.com/0xunknownz/webman-starter/issues/32) | #30、#31 |

第一批按套餐目录、订阅日期、组织生命周期拆分，三项可同时推进，共用的鉴权工厂和主路由由集成者接入。#28 和 #29 都验收后即可开始 #31。若 #30 仍在进行，可与 #31 并行。#32 等 #30 与 #31 均通过后进入联调。

## 示例 API

从仓库的 [Webman 源码示例](../examples/webman/) 启动，Composer 的路径仓库指向当前源码。在示例目录安装依赖，参考 [D2 的配置说明](d2-template.md#从发布版本创建项目)配置独立 MySQL、Redis 和邀请页面。把 `ADMIN_PHONE` 设置为获准的首位管理员手机号，开启 `WEBMAN_STARTER_ROUTES_ENABLED`、`STARTER_AUTH_ENABLED` 和 `STARTER_EXAMPLE_RECORDS_ENABLED`，然后在示例目录执行。

```bash
composer install
php vendor/bin/phinx migrate -c vendor/z0x/webman-starter/phinx.php
php vendor/bin/webman-starter platform:init "$ADMIN_PHONE"
php bin/setup-example.php
php start.php start
```

示例默认在 `http://127.0.0.1:8787/saas/health` 提供健康检查。更新已运行的产品依赖后重启 Webman。以下路径相对于示例默认前缀 `/saas`。平台操作使用平台用途会话，所有者使用组织用途会话。

| 操作 | 请求 | 输入 |
| --- | --- | --- |
| 创建套餐 | `POST /platform/packages` | `name`、整数 `seat_limit` |
| 修改套餐 | `PATCH /platform/packages/{package_id}` | 至少一个字段 `name` 或 `seat_limit` |
| 停用套餐 | `POST /platform/packages/{package_id}/retire` | 无 |
| 调整订阅日期 | `PATCH /platform/organizations/{organization_id}/subscription/window` | `starts_at`、`expires_at`，均为精确到秒的 RFC 3339 |
| 显式更换套餐 | `PATCH /platform/organizations/{organization_id}/subscription/package` | 整数 `package_id` |
| 平台归档 | `POST /platform/organizations/{organization_id}/archive` | 无 |
| 所有者归档 | `POST /organizations/{organization_id}/archive` | 无 |
| 平台恢复 | `POST /platform/organizations/{organization_id}/restore` | 无 |
| 平台停用或解除停用 | `POST /platform/organizations/{organization_id}/suspend` 或 `/unsuspend` | 无 |
| 平台撤销普通成员邀请 | `DELETE /platform/organizations/{organization_id}/invitations/{invitation_id}` | 无。适用于已停用或归档的组织 |

生效时间与到期时间按同一时间轴比较，有效区间包括生效秒、不包括到期秒。恢复后按原激活阶段、原停用标记和当前订阅判定权限。恢复待激活组织后，用现有的首位所有者邀请 API 另行重新签发。

新开通与套餐切换读取当时的模板定义。模板管理和日期调整各自保留现有订阅快照。归档、恢复和切换套餐使用与邀请、成员写入相同的组织行锁，实际变更与审计在同一数据库事务提交。

复现生命周期时，先创建套餐并开通一个未来生效的组织。首位所有者可通过 [邀请流程](d2-template.md#开通加入与隔离验收) 提前加入。时钟到达生效秒后，可写入示例记录并邀请成员。到期秒起，记录保持可读，业务写入和普通成员邀请被拒绝。成员移除、成员退出、撤销邀请和所有权转移仍按原角色权限执行。

停用会阻止组织数据访问。平台仍可归档，也可撤销普通成员邀请并释放预留席位。已有组织的归档和恢复无需配置邀请页面。恢复保留原停用标记。解除停用后，已激活组织按当前订阅恢复访问，待激活组织需要重新签发邀请。归档前未消费的邀请及预留已撤销。

## 阶段验收

- [x] #28 套餐模板权限、输入、审计、快照隔离和停用后开通回滚
- [x] #29 当前订阅原地调整、秒级时间、即时到期、原套餐快照和事务审计
- [x] #30 组织归档和两种激活阶段的恢复、邀请席位释放、权限与审计
- [x] #31 套餐切换、席位降级、真实 MySQL 末席竞争的两种提交顺序
- [x] #32 真实 Webman HTTP、MySQL、Redis 示例、状态组合、席位审计和 D1/D2 回归

## 验收命令与环境

PHP 8.1.34、Composer 2.5.1、Webman 2.2.5、MySQL 8.0.46 和 Redis 8.6.2 下，D3 各项验收、D1 QA-01、D2 组织工作流、邀请并发、审计及模板安装回归均通过。D3 示例在 Webman 2.1.0 上也已通过。

从仓库根目录，以 PHP 8.1 运行。验收脚本启动隔离的真实 MySQL、Redis 和 Webman HTTP，使用受控短信和邮件发送器，并显式运行迁移与管理员初始化。测试时钟可定位到生效与到期边界的单秒。

```bash
export STARTER_TEST_WEBMAN_VERSION=2.2.5
for test in tests/issue-{28,29,30,31}.php tests/d3-example.php; do
  php "$test" || exit 1
done
```

```bash
export STARTER_TEST_WEBMAN_VERSION=2.2.5
for test in tests/acceptance.php tests/issue-{14,15,17,18,19,20,21,22}.php \
  tests/d2-invitation-races.php tests/organization-audit.php tests/d2-example.php \
  tests/template-candidate.php; do
  php "$test" || exit 1
done
```
