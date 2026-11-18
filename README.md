# WordPress-Yggdrasil

一款 WordPress 插件，帮助你在 WordPress REST API 上提供 Minecraft Yggdrasil 兼容层。

插件把 WordPress 用户系统接入 Minecraft 的认证体系，并提供多游戏档案管理与皮肤上传能力，可用于
自建 Yggdrasil 认证服务器、authlib-injector 外置登录服务器或皮肤站等场景。

## 功能特性

* **Yggdrasil 认证/会话模拟**：提供 AuthServer 与 SessionServer 的
  authenticate、refresh、validate、invalidate、signout、join、hasJoined、profile 接口。
* **多游戏档案**：一个 WordPress 用户可创建多个 Minecraft 角色档案，每个档案拥有独立的
  无符号 UUID、名称、皮肤与默认标记；登录时默认使用「默认档案」，也支持通过刷新接口切换档案。
* **皮肤上传**：登录用户可在前台「游戏档案」页面为档案上传 PNG 皮肤（经典/纤细模型），
  支持预览、替换与移除。
* **皮肤 3D 预览**：档案卡片与后台用户编辑页会用纯 CSS 3D 渲染皮肤模型（支持经典 64x32、64x64 及更高分辨率），
  可用鼠标或触摸拖拽旋转查看，无任何外部依赖与 CDN 请求。
* **皮肤安全处理**：上传文件会做类型/尺寸/大小校验，并使用纯 PHP 重写 PNG 数据块，
  移除文本、EXIF、APNG 等附加数据，按内容 SHA-256 命名存储，避免分发恶意文件与 PNG 炸弹。
* **textures 属性与签名**：按 Yggdrasil 规范生成 Base64 JSON 的 `textures` 属性，
  自动生成 2048 位 RSA 密钥并以 SHA1withRSA 签名；API 元数据中提供 `signaturePublickey`。
* **API 元数据**：提供 authlib-injector 需要的 `meta`、`skinDomains`、`signaturePublickey`。
* **平滑升级**：从 0.2.x 升级时自动把旧的 `ygg_uuid` 用户元数据迁移为默认档案，保留原有玩家身份。
* **后台配置页**：「设置 → Yggdrasil」可直接调整档案/令牌数量、令牌有效期、登录限速、皮肤签名、皮肤域名等参数，无需修改代码。
* **后台用户档案管理**：管理员可在「用户 → 编辑用户」页面直接为用户增删改档案、修改 UUID、设置默认档案、上传/移除皮肤，用户列表会显示档案数量。
* **用户组登录限制**：管理员可多选允许使用 Yggdrasil 登录的用户组，未选择时默认允许所有用户。
* **默认安全卸载**：卸载插件默认保留档案与皮肤数据；需要彻底清理时使用
  `YGGDRASIL_REMOVE_DATA_ON_UNINSTALL` 常量。

## 环境要求

* WordPress 5.8 及以上
* PHP 7.4 及以上
* MySQL 5.6.5+ / MariaDB 10.1+（`DATETIME DEFAULT CURRENT_TIMESTAMP`）
* 可选：PHP `openssl` 扩展（用于皮肤材质签名；未启用时仍可上传皮肤，但部分客户端可能拒绝未签名材质）

> 兼容性：已在 WordPress 6.8.12 + PHP 8.4 + MariaDB 11.8 环境完成集成测试
> （多档案管理、皮肤上传、皮肤 3D 预览、REST 认证/会话、0.2.x 数据升级、用户删除清理、后台配置页、
> 后台用户档案管理、用户组登录限制）。

## 安装与升级

1. 在 [Releases](https://github.com/Someone-Yang/WordPress-Yggdrasil/releases) 下载插件包。
2. 在 WordPress 后台「插件」→「安装插件」→「上传插件」中上传并启用。
3. 启用后插件会：
   * 创建/升级 `ygg_profiles`、`ygg_tokens`、`ygg_joins` 数据表；
   * 迁移旧用户的 `ygg_uuid` 为默认档案；
   * 自动创建一个标题为「游戏档案」、内容为 `[yggdrasil_profiles]` 的页面。
4. 在「设置」→「Yggdrasil」中查看 API 根地址、管理页面状态、统计信息与皮肤签名公钥。

从 0.2.x 升级：直接覆盖插件文件并重新启用即可。升级会保留旧令牌记录，并把旧用户迁移为默认档案；
建议升级前备份数据库。

## 使用说明

### 用户：管理游戏档案与皮肤

访问自动创建的「游戏档案」页面（或在任意页面插入短代码）：

```
[yggdrasil_profiles]
```

登录后可进行：

* 创建档案（名称 1-16 位字母、数字或下划线，全局唯一，默认最多 5 个，可用过滤器调整）
* 重命名档案、切换经典/纤细皮肤模型
* 皮肤上传（PNG；宽度为 64 的倍数且 64-512，高度为 32 的倍数且 32-512，比例 1:1 或 2:1，最大 1 MiB）
* 预览、替换或移除皮肤（任何已登录用户都可为自己档案上传皮肤，不依赖 WordPress 媒体库权限）
  预览会以 3D 模型呈现，可直接拖拽旋转；未加载脚本时自动回退为 2D 图片
* 把某个档案设为默认档案（登录时自动选中）
* 删除档案（至少保留一个）

### 管理员：后台配置

进入「设置 → Yggdrasil」，可直接调整以下配置（保存后立即生效）：

* 每用户档案数量上限（默认 5）
* 每用户令牌数量上限（默认 10）
* 令牌有效期（天，默认 1）
* 登录失败次数上限（默认 20，填 0 可禁用登录限速）
* 每用户进服记录上限（默认 20）
* 多档案登录时是否自动选择默认档案
* 是否对皮肤 `textures` 属性签名
* 签名 RSA 密钥位数（1024/2048/4096，仅影响尚未生成密钥的站点）
* 额外皮肤域名白名单（每行一个，自动并入 API 元数据 `skinDomains`）
* 允许登录的用户组（可多选；未选择时允许所有用户）

这些配置保存在 WordPress options 中；下方「扩展钩子」中的过滤器仍然有效，并会**覆盖**后台设置，
便于代码级定制。

### 管理员：后台用户档案管理

管理员进入「用户 → 所有用户」，点击某个用户的「编辑」后，页面底部会显示「Yggdrasil 游戏档案」区块，可以：

* 为该用户新建档案；
* 重命名档案、切换经典/纤细模型；
* 上传、替换或移除皮肤（皮肤列以 3D 模型预览）；
* 设置默认档案、删除档案（至少保留一个）；
* **修改档案 UUID**：可手动填写 32 位十六进制 UUID（可带连字符），也可一键「改为离线 UUID」
  按档案名称生成与 Minecraft 离线模式一致的值（便于从离线验证服务器迁移）。

用户列表新增「Yggdrasil 档案」列，显示每个用户的档案数量，并可直接跳转到该用户的管理区块。
这些操作仅对具有 `manage_options` 权限的管理员开放。

> **修改 UUID 的风险**：UUID 是玩家身份标识。改动后，服务器上按旧 UUID 保存的玩家数据
> （背包、末影箱、统计等）不再与该玩家关联。迁移已存在的服务器时，请先把「用户数据」目录中的
> `<旧 UUID>.dat` 重命名为 `<新 UUID>.dat`，或优先使用「改为离线 UUID」保持与原离线 UUID 一致。
> UUID 修改后，皮肤、默认标记与令牌绑定会保留，进服记录会自动同步为新 UUID。

### 客户端 / 服务器：接入 Yggdrasil

API 根地址（带不带结尾斜杠均可，建议带斜杠）：

```
https://example.com/wp-json/yggdrasil/v1/
```

* 在支持 authlib-injector 的启动器中填写上述地址，即可使用 WordPress 用户名/密码登录。
* Minecraft 服务器可配合 authlib-injector 与 `online-mode=true` 使用该认证服务。
* 插件会在元数据中返回皮肤域名白名单与签名公钥，客户端会据此加载和校验皮肤。

### 接口列表

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/` | API 元数据（meta、skinDomains、signaturePublickey） |
| POST | `/authserver/authenticate` | 使用用户名/密码登录，返回可用档案与默认档案 |
| POST | `/authserver/refresh` | 刷新令牌，可携带 `selectedProfile` 切换档案 |
| POST | `/authserver/validate` | 校验令牌 |
| POST | `/authserver/invalidate` | 吊销令牌 |
| POST | `/authserver/signout` | 吊销用户的全部令牌 |
| POST | `/sessionserver/session/minecraft/join` | 记录进服会话 |
| GET | `/sessionserver/session/minecraft/hasJoined` | 验证进服会话并返回档案与材质属性 |
| GET | `/sessionserver/session/minecraft/profile/{uuid}` | 按 UUID 查询档案（支持 `unsigned` 参数） |
| GET | `/textures/{sha256}` | 输出净化后的皮肤 PNG（`Content-Type: image/png`） |

拼接后例如：

```
https://example.com/wp-json/yggdrasil/v1/authserver/authenticate
```

## 数据库

| 表 | 说明 |
| --- | --- |
| `{prefix}ygg_profiles` | 游戏档案：UUID、名称、模型、皮肤、默认标记 |
| `{prefix}ygg_tokens` | 访问令牌：绑定的用户与档案 |
| `{prefix}ygg_joins` | 进服会话记录（60 秒过期） |

插件使用 `dbDelta()` 做增量升级，使用 `ygg_db_version` 选项记录结构版本。

## 扩展钩子

### 过滤器

| 过滤器 | 说明 |
| --- | --- |
| `yggdrasil_max_profiles_per_user` | 每个用户可创建的档案数量上限（默认 5） |
| `yggdrasil_max_tokens_per_user` | 每个用户保留的令牌数量上限（默认 10） |
| `yggdrasil_token_expiry` | 令牌有效时长（秒，默认 86400） |
| `yggdrasil_authenticate_selected_profile` | 登录时自动选中的档案；返回 null 可让多档案用户由启动器选择 |
| `yggdrasil_rsa_key_bits` | 皮肤签名 RSA 密钥位数（默认 2048，最低 1024） |
| `yggdrasil_login_max_attempts` | 同一用户名 + IP 在 15 分钟窗口内的登录失败次数上限（默认 20，用于简单限速） |
| `yggdrasil_max_joins_per_user` | 每个用户保留的进服会话记录数上限（默认 20） |
| `yggdrasil_allowed_roles` | 允许使用 Yggdrasil 登录的用户组（空数组/未选择表示允许所有用户） |
| `yggdrasil_user_allowed` | 单个用户是否允许登录的最终布尔结果（可覆盖用户组限制） |
| `yggdrasil_skin_domains` | API 元数据中的皮肤域名白名单 |
| `yggdrasil_api_metadata` | API 元数据中的 `meta` 字段 |
| `yggdrasil_skin_url` | 档案皮肤 URL（例如改用 CDN 时） |
| `yggdrasil_textures_signed` | 是否对 textures 属性签名（返回 false 可关闭签名） |
| `yggdrasil_profile_properties` | 档案属性数组（保留旧版签名：`$properties, $user_id, $profile`） |

### 动作

`yggdrasil_profile_created`、`yggdrasil_profile_updated`、`yggdrasil_profile_deleted`、
`yggdrasil_profile_uuid_changed`（参数：`$profile, $user_id, $old_uuid`）、
`yggdrasil_skin_uploaded`、`yggdrasil_skin_removed`。

## 常见问题

### Minecraft 读取皮肤的逻辑是什么？

1. 登录后，启动器/客户端拿到档案 UUID 和 `textures` 属性（Base64 JSON，包含 `SKIN.url` 与 `metadata.model`）；
2. 进入服务器时，服务器通过 `hasJoined` 获取档案属性并下发给客户端；单人/本地皮肤则通过
   `profile/{uuid}` 接口获取；
3. 客户端下载皮肤 URL 指向的 PNG，检查域名是否在 API 元数据 `skinDomains` 中，
   并用 `signaturePublickey` 验证 `textures` 属性的 SHA1withRSA 签名；
4. 客户端按 URL 最后一段作为材质 hash 缓存图片；`metadata.model` 为 `slim` 时使用 Alex 细手臂模型，
   缺省时为 Steve 经典模型。

**启动器能显示、游戏内却是默认皮肤**的常见原因：

1. **启动器预览通常不校验签名，而原版游戏会校验。** 请确认「设置 → Yggdrasil → 皮肤诊断」中
   PHP `openssl` 可用、材质签名已开启、公钥已生成；未签名材质会被原版客户端拒绝，直接显示默认皮肤。
2. **服务器未开启正版验证（`online-mode=false`）**：离线模式服务器不会调用 `hasJoined`，也就不会把
   签名材质下发给客户端，皮肤自然不显示。服务器需设置 `online-mode=true` 并配合 authlib-injector。
3. **皮肤域名不匹配**：皮肤 URL 的域名必须在 `skinDomains` 中；如果 WordPress 的站点地址与实际访问域名
   不一致（例如站点地址仍为 `localhost`/IP），请修正「设置 → 常规」中的 WordPress 地址，或把实际域名
   加到「额外皮肤域名」。
4. **站点未使用 HTTPS，或皮肤 URL 外网不可访问**：请启用 HTTPS，并确认客户端能直接打开皮肤 URL。
5. **签名密钥被重新生成**：客户端/启动器可能缓存了旧公钥，重启游戏和启动器后再试。
6. **模型选错**：Minecraft 的“性别/体型”只是 Steve（经典）与 Alex（纤细）模型，不保存在 PNG 中。
   上传皮肤时请正确选择「经典/纤细」；选错只会导致手臂/体型不对，不会导致整张皮肤消失。

插件的「设置 → Yggdrasil → 皮肤诊断」会列出 openssl 状态、签名开关、密钥位数、站点协议，并在
未签名、HTTP 或 localhost 等情况下给出警告。

### 卸载会删除档案和皮肤吗？

默认不会。若需要彻底删除数据，请在 `wp-config.php` 中定义：

```php
define( 'YGGDRASIL_REMOVE_DATA_ON_UNINSTALL', true );
```

多站点（Multisite）环境：插件数据按站点独立存储；上述卸载清理仅作用于当前站点，
其他子站的数据需分别处理。

### 支持上传披风吗？

暂不支持，当前仅支持皮肤。

### 皮肤 URL 会使用什么路径？

启用伪静态时，皮肤 URL 为 `/wp-json/yggdrasil/v1/textures/<hash>`（WordPress 内置 REST 路径），
不会被当作文章页处理；未启用伪静态时，WordPress REST 地址会变成 `index.php?rest_route=...` 形式，
插件会自动改用 `/wp-content/uploads/yggdrasil-skins/<hash>.png` 静态地址，保证材质 hash 是 URL 文件名，
避免客户端材质缓存出错。

### 为什么某个用户无法通过 Yggdrasil 登录？

检查「设置 → Yggdrasil → 允许登录的用户组」。如果选择了用户组，只有属于这些组的用户才能登录；
未选择任何用户组时允许所有用户。用户组被移除后，该用户已有的令牌也会在 validate/refresh/join 时失效。

### 管理页面被误删了？

在「设置」→「Yggdrasil」中点击「创建管理页面」，或手动在任意页面插入 `[yggdrasil_profiles]`。

## 目录结构

```
wordpress-yggdrasil.php             插件入口
includes/
  class-yggdrasil-db.php            建表、升级与旧数据迁移
  class-yggdrasil-settings.php      后台配置项（Settings API）
  class-yggdrasil-profiles.php      档案 CRUD 与校验
  class-yggdrasil-skins.php         皮肤上传、PNG 净化、textures 签名
  class-yggdrasil-rest.php          Yggdrasil REST API
  class-yggdrasil-management.php    前台管理界面与后台设置页
  class-yggdrasil-admin-profiles.php 后台用户档案管理与用户列表档案列
assets/
  css/yggdrasil-profiles.css        前台样式
  js/yggdrasil-profiles.js          删除确认与本地皮肤预览
  css/yggdrasil-skin-viewer.css     皮肤 3D 预览样式
  js/yggdrasil-skin-viewer.js       皮肤 3D 预览（纯 CSS 3D，无依赖）
uninstall.php                       卸载清理
```

## 开发说明

修改 PHP 文件后建议使用 `php -l` 逐一检查语法，并在真实 WordPress 环境中验证数据库升级与 REST 路由。
插件发布包仅包含运行所需文件，内部维护文档不随仓库或发布包提供。

## 参考与致谢

* [WordPress Developer Resources](https://developer.wordpress.org/)
* [Minecraft Wiki](https://minecraft.wiki/w/Yggdrasil)
* [authlib-injector Yggdrasil 服务端技术规范](https://yushijinhun.github.io/authlib-injector/)

## 许可证

MIT，详见 [LICENSE.md](LICENSE.md)。
