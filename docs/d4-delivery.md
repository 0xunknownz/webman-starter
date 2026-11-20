# D4 审计与导出验收

[工单与验收清单](d4-issue-drafts.md)记录开发顺序与直接依赖。验证示例前，先完成[宿主安装、显式迁移与初始化](saas-integration.md)。测试环境可注入受控短信与邮件发送器。

## 示例流程

使用平台用途会话创建套餐并开通两个独立的测试组织。分别接受首位所有者邀请并登录。为其中一个组织邀请普通成员。由平台管理员改名或调整该组织的订阅。[开通与加入步骤](d2-template.md#开通加入与隔离验收) · [生命周期示例](d3-delivery.md)

以平台用途会话查询平台审计，以组织所有者会话查询组织审计，再分别下载成员和审计 CSV。示例使用默认前缀。将 `ORGANIZATION_ID` 设为目标组织 ID，令牌变量由该次演示的登录响应设置。

```bash
base=http://127.0.0.1:8787/saas
org="$base/organizations/$ORGANIZATION_ID"
curl --fail-with-body -H "Authorization: Bearer $PLATFORM_ACCESS" "$base/platform/audit-logs?action=organization_renamed"
curl --fail-with-body -H "Authorization: Bearer $OWNER_ACCESS" "$org/audit-logs?per_page=20"
curl --fail-with-body -H "Authorization: Bearer $OWNER_ACCESS" "$org/members/export" -o members.csv
curl --fail-with-body -H "Authorization: Bearer $OWNER_ACCESS" "$org/audit-logs/export" -o audit-logs.csv
```

再次查询审计时会看到两条导出记录。确认各自的行数与文件相同，行数不计标题。使用组织管理员会话只能下载成员 CSV。普通成员不能下载。使用另一个组织所有者的令牌访问原路径返回 `not_found`。订阅到期后验证原权限，随后停用组织，验证查询和导出均被拒绝。[API 路径与响应契约](saas-api.md#审计查询与-csv)

## 自动化

从基座仓库根目录运行。验收脚本启动隔离的 MySQL、Redis 及 Webman HTTP。需提供 PHP CLI 对应扩展、Composer、`mysqld` 和 `redis-server`。制品验收另需 ZIP 扩展及历史发布文件。脚本可自动下载两个历史 ZIP，也可传入包含原始 ZIP 的目录。[候选制品验收脚本](../tests/d4-release-candidate.php)核对发布 ZIP 的校验和并在隔离 Composer 仓库安装本地 D4 候选包。

```bash
export STARTER_TEST_WEBMAN_VERSION=2.2.5
php tests/issue-33.php
php tests/issue-34.php
php tests/d4-release-candidate.php
php tests/acceptance.php
php tests/template-candidate.php
php tests/d3-release-upgrade.php
for test in tests/issue-{14,15,17,18,19,20,21,22,28,29,30,31}.php \
  tests/d2-invitation-races.php tests/organization-audit.php tests/d2-example.php tests/d3-example.php; do
  php "$test" || exit 1
done
```

验证待发布的 D4 ZIP 时，将 `STARTER_TEST_D4_ZIP` 设为待上传文件的路径后运行同一脚本。脚本输出该文件的 SHA-256，并使用校验和相同的 ZIP 分别验证全新安装与 D3 升级。

## 本次验证记录

D4 的 2.1.0 基座包和模板包已正式发布，来源为 `92090f3`。基座 [Release v2.1.0](https://github.com/0xunknownz/webman-starter/releases/tag/v2.1.0)：[ZIP](https://github.com/0xunknownz/webman-starter/releases/download/v2.1.0/webman-starter-2.1.0.zip) · [SHA-256](https://github.com/0xunknownz/webman-starter/releases/download/v2.1.0/webman-starter-2.1.0.zip.sha256)。模板 [Release template-v2.1.0](https://github.com/0xunknownz/webman-starter/releases/tag/template-v2.1.0)：[ZIP](https://github.com/0xunknownz/webman-starter/releases/download/template-v2.1.0/webman-starter-template-2.1.0.zip) · [SHA-256](https://github.com/0xunknownz/webman-starter/releases/download/template-v2.1.0/webman-starter-template-2.1.0.zip.sha256)。

使用从 `92090f3` 构建并发布的同一份 ZIP，在 PHP 8.1.34、Composer 2.5.1、Webman 2.2.5、MySQL 8.0.46、Redis 8.6.2 下，验证了 Composer 全新建项、D3 基座到 D4 升级、显式及重复迁移、首次初始化、历史业务数据、审计脱敏及会话保留。另以公共索引从全新 Composer 缓存创建模板项目，安装了基座 2.1.0 并通过 `composer check-platform-reqs`。D1 QA-01、D2 组织与邀请、D3 生命周期以及 D4 查询和导出专项均通过；D4 专项亦在 Webman 2.1.0 下通过。已从公开 Release 重新下载 ZIP 和 SHA-256 文件，确认与验收制品逐字节一致。

Composer 索引已将两个 2.1.0 版本加入 `composer` 分支并保留既有条目：[索引提交 4840508](https://github.com/0xunknownz/webman-starter/commit/4840508e7c474194dbbc7daf0a2b8ecc531191ae) · [公开 packages.json](https://raw.githubusercontent.com/0xunknownz/webman-starter/composer/packages.json)。

PHP 语法检查及 `git diff --check` 通过。真实 SMTP 与短信供应商的提交及实际送达联调仍待完成；本次验收使用隔离环境中的受控发送器。

受控短信与邮件发送器验证无需发送、提交接受、拒绝、超时、计数及受限审计。真实 SMTP 和短信供应商联调未验证，当前验收使用隔离环境测试发送器。真实提交和实际送达由部署侧使用其供应商配置和指定测试接收目标单独验证。
