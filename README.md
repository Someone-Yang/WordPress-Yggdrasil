# WordPress-Yggdrasil

一款 WordPress 插件，帮助你在 WordPress REST API 上提供 Minecraft Yggdrasil 兼容层。

## 插件用途

插件在首次安装后，会在当前 WordPress 实例创建 `ygg_tokens` 和 `ygg_joins` 数据表，用于**模拟** Yggdrasil 会话。

插件启用时，会在 WordPress REST API 提供 `/yggdrasil/v1` 接口，**模拟** Yggdrasil 接入点，提供 AuthServer 和 SessionServer 服务。

使用此 REST API 可完成 Yggdrasil 登录和会话流程。拼接后接口端点示例：

```
https://example.com/wp-json/yggdrasil/v1
```

用户使用此 WordPress 实例上的用户名和密码，可完成 Minecraft 第三方登录。游戏资料名默认为用户名（非昵称），默认只**模拟**一个资料账户，暂不支持增添，暂不支持设置皮肤。

## 插件安装

1. 在 [Releases](https://github.com/Someone-Yang/WordPress-Yggdrasil/releases) 下载插件包

2. 访问 WordPress WP-Admin 管理后台，选择“插件”——“安装插件”

3. 上传插件包

4. 启用插件包

5. 测试插件是否正常运行

## 参考与致谢

* [WordPress Developer Resources](https://developer.wordpress.org/)

* [Minecraft Wiki](https://minecraft.wiki/w/Yggdrasil)