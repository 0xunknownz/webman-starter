# D2 组织与邀请开发清单

依据 [PRD 第 11 节](prd-saas-foundation.md#11-交付计划)及各 GitHub 工单的 Blocked by。依赖图无环。D1 前置已通过[阶段验收](d1-delivery.md#阶段验收)。

| 批次 | 验收 | 工单 | 直接依赖 |
| --- | --- | --- | --- |
| 1 | 通过 | [#14 组织状态、订阅快照与席位事务](https://github.com/0xunknownz/webman-starter/issues/14) | #9、#10、#11（D1） |
| 1 | 通过 | [#15 邀请短信与单次短期凭据](https://github.com/0xunknownz/webman-starter/issues/15) | #3、#9（D1） |
| 1 | 通过 | [#16 派生 Composer 模板准备](https://github.com/0xunknownz/webman-starter/issues/16) | #2（D1） |
| 2 | 通过 | [#17 组织鉴权与订阅访问策略](https://github.com/0xunknownz/webman-starter/issues/17) | #14 |
| 3 | 通过 | [#18 普通成员邀请与链接生命周期](https://github.com/0xunknownz/webman-starter/issues/18) | #14、#17 |
| 3 | 通过 | [#21 成员列表、角色与治理](https://github.com/0xunknownz/webman-starter/issues/21) | #17 |
| 4 | 通过 | [#19 平台开通、改邀与运营恢复](https://github.com/0xunknownz/webman-starter/issues/19) | #18 |
| 4 | 通过 | [#20 邀请解析、验证与接受](https://github.com/0xunknownz/webman-starter/issues/20) | #15、#18 |
| 4 | 通过 | [#22 所有权转移与接收人待办](https://github.com/0xunknownz/webman-starter/issues/22) | #21 |
| 5 | 通过 | [#23 示例联调、自动化验收与发布](https://github.com/0xunknownz/webman-starter/issues/23) | #16、#19、#20、#22 |
| 6 | 通过 | [#24 发布版模板的独立双组织验收](https://github.com/0xunknownz/webman-starter/issues/24) | #23 |

## 并行与集成顺序

首批并行开发席位底座、邀请验证和模板候选包。#14 验收后即可开始 #17。#17 验收后，邀请管理 #18 与成员治理 #21 可并行。#18 通过后可开始 #19，#20 还需 #15。#22 只等 #21，可以与 #19、#20 并行。

邀请创建、重新签发、接受、运营取消和 D1 换绑共享锁顺序与席位事务契约。约定成员退出或移除时在同一事务失效接收人待办。共享路由、错误码、配置和验收入口由集成者合并。

## 组织写入的事务约定

涉及新增或接受邀请的写入先锁定目标手机号对应的账号，再锁定组织行，然后重新读取并锁定成员和邀请。查不到账号时使用唯一手机号索引上的锁定读，数据库唯一约束仍是账号创建的最终约束。涉及多个目标号码的事务按规范化号码顺序锁定。平台管理已持有平台锁时，沿用 D1 的平台锁先于账号锁顺序。

席位检查与邀请写入共用一个事务，竞争同一个组织行锁。D1 换绑先锁定旧号账号，再失效该号码的待接受邀请。取消开通仅锁定组织及其邀请。获取组织锁之后重新检查订阅窗口、状态、成员、邀请有效期和席位。切换邀请到已接受和建立成员关系原子提交，预留在同一事务内转为成员占用。事务回调可重试，只执行可回滚的数据库写入和无副作用的校验，消息发送等外部调用在事务外进行。

## 阶段验收

- [x] [#14 组织状态、订阅快照与席位事务](https://github.com/0xunknownz/webman-starter/issues/14)
- [x] [#15 邀请短信与单次短期凭据](https://github.com/0xunknownz/webman-starter/issues/15)
- [x] [#16 派生 Composer 模板准备](https://github.com/0xunknownz/webman-starter/issues/16)
- [x] [#17 组织鉴权与订阅访问策略](https://github.com/0xunknownz/webman-starter/issues/17)
- [x] [#18 普通成员邀请与链接生命周期](https://github.com/0xunknownz/webman-starter/issues/18)
- [x] [#21 成员列表、角色与治理](https://github.com/0xunknownz/webman-starter/issues/21)
- [x] [#19 平台开通、改邀与运营恢复](https://github.com/0xunknownz/webman-starter/issues/19)
- [x] [#20 邀请解析、验证与接受](https://github.com/0xunknownz/webman-starter/issues/20)
- [x] [#22 所有权转移与接收人待办](https://github.com/0xunknownz/webman-starter/issues/22)
- [x] [#23 示例联调、自动化验收与发布](https://github.com/0xunknownz/webman-starter/issues/23)
- [x] [#24 发布版模板的独立双组织验收](https://github.com/0xunknownz/webman-starter/issues/24)

以各工单的验收条件为准。使用独立 MySQL、Redis、真实 Webman HTTP 及适用的 CLI，消息由受控发送器捕获。发布版模板须经另一名开发者从干净 PHP 8.1 环境按文档安装，通过双组织 HTTP 隔离验收后进入 D3。
