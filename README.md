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
* **皮肤安全处理**：上传文件会做类型/尺寸/大小校验，并使用纯 PHP 重写 PNG 数据块，
  移除文本、EXIF、APNG 等附加数据，按内容 SHA-256 命名存储，避免分发恶意文件与 PNG 炸弹。
* **textures 属性与签名**：按 Yggdrasil 规范生成 Base64 JSON 的 `textures` 属性，
  自动生成 2048 位 RSA 密钥并以 SHA1withRSA 签名；API 元数据中提供 `signaturePublickey`。
* **API 元数据**：提供 authlib-injector 需要的 `meta`、`skinDomains`、`signaturePublickey`。
* **平滑升级**：从 0.2.x 升级时自动把旧的 `ygg_uuid` 用户元数据迁移为默认档案，保留原有玩家身份。
* **默认安全卸载**：卸载插件默认保留档案与皮肤数据；需要彻底清理时使用
  `YGGDRASIL_REMOVE_DATA_ON_UNINSTALL` 常量。

## 环境要求

* WordPress 5.8 及以上
* PHP 7.4 及以上
* MySQL 5.6.5+ / MariaDB 10.1+（`DATETIME DEFAULT CURRENT_TIMESTAMP`）
* 可选：PHP `openssl` 扩展（用于皮肤材质签名；未启用时仍可上传皮肤，但部分客户端可能拒绝未签名材质）

> 兼容性：已在 WordPress 6.8.12 + PHP 8.4 + MariaDB 11.8 环境完成集成测试
> （多档案管理、皮肤上传、REST 认证/会话、0.2.x 数据升级、用户删除清理）。

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
* 把某个档案设为默认档案（登录时自动选中）
* 删除档案（至少保留一个）

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
| `yggdrasil_skin_domains` | API 元数据中的皮肤域名白名单 |
| `yggdrasil_api_metadata` | API 元数据中的 `meta` 字段 |
| `yggdrasil_skin_url` | 档案皮肤 URL（例如改用 CDN 时） |
| `yggdrasil_textures_signed` | 是否对 textures 属性签名（返回 false 可关闭签名） |
| `yggdrasil_profile_properties` | 档案属性数组（保留旧版签名：`$properties, $user_id, $profile`） |

### 动作

`yggdrasil_profile_created`、`yggdrasil_profile_updated`、`yggdrasil_profile_deleted`、
`yggdrasil_skin_uploaded`、`yggdrasil_skin_removed`。

## 常见问题

### 游戏里看不到皮肤？

1. 确认站点使用 HTTPS，且皮肤 URL 可被外网访问；
2. 确认客户端填写的是正确的 API 根地址（`/wp-json/yggdrasil/v1/`，带不带结尾斜杠均可）；
3. 确认 PHP `openssl` 扩展可用（「设置」→「Yggdrasil」可查看签名公钥状态）；
4. 可能是客户端缓存了旧皮肤，更换皮肤或清理缓存后重试；
5. 若使用安全插件/防火墙，请确认没有拦截 `/wp-json/yggdrasil/v1/*` 与 `/textures/*` 请求。

### 卸载会删除档案和皮肤吗？

默认不会。若需要彻底删除数据，请在 `wp-config.php` 中定义：

```php
define( 'YGGDRASIL_REMOVE_DATA_ON_UNINSTALL', true );
```

多站点（Multisite）环境：插件数据按站点独立存储；上述卸载清理仅作用于当前站点，
其他子站的数据需分别处理。

### 支持上传披风吗？

暂不支持，当前仅支持皮肤。

### 管理页面被误删了？

在「设置」→「Yggdrasil」中点击「创建管理页面」，或手动在任意页面插入 `[yggdrasil_profiles]`。

## 目录结构

```
wordpress-yggdrasil.php             插件入口
includes/
  class-yggdrasil-db.php            建表、升级与旧数据迁移
  class-yggdrasil-profiles.php      档案 CRUD 与校验
  class-yggdrasil-skins.php         皮肤上传、PNG 净化、textures 签名
  class-yggdrasil-rest.php          Yggdrasil REST API
  class-yggdrasil-management.php    前台管理界面与后台设置页
assets/
  css/yggdrasil-profiles.css        前台样式
  js/yggdrasil-profiles.js          删除确认与本地皮肤预览
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
