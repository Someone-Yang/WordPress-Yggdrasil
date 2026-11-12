<?php
/**
 * Plugin Name: Minecraft Yggdrasil 兼容层 (WP REST API)
 * Plugin URI: https://github.com/Someone-Yang/WordPress-Yggdrasil
 * Description: 利用 WordPress REST API 提供兼容 Minecraft Yggdrasil 的认证、会话服务，并支持多游戏档案与皮肤上传。
 * Version: 0.3.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: 氧化某人
 * License: MIT
 * License URI: https://github.com/Someone-Yang/WordPress-Yggdrasil/blob/main/LICENSE.md
 * Text Domain: wordpress-yggdrasil
 */

defined( 'ABSPATH' ) || exit;

define( 'YGGDRASIL_VERSION', '0.3.0' );
define( 'YGGDRASIL_PLUGIN_FILE', __FILE__ );
define( 'YGGDRASIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YGGDRASIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once YGGDRASIL_PLUGIN_DIR . 'includes/class-yggdrasil-db.php';
require_once YGGDRASIL_PLUGIN_DIR . 'includes/class-yggdrasil-profiles.php';
require_once YGGDRASIL_PLUGIN_DIR . 'includes/class-yggdrasil-skins.php';
require_once YGGDRASIL_PLUGIN_DIR . 'includes/class-yggdrasil-rest.php';
require_once YGGDRASIL_PLUGIN_DIR . 'includes/class-yggdrasil-management.php';

/**
 * 插件激活：创建/升级数据表，生成档案管理页面。
 */
function yggdrasil_activate() {
    Yggdrasil_DB::install();

    if ( class_exists( 'Yggdrasil_Management' ) ) {
        Yggdrasil_Management::maybe_create_page();
    }
}
register_activation_hook( __FILE__, 'yggdrasil_activate' );

/**
 * 插件停用：REST 路由随插件停用自动失效，无需额外清理。
 */
function yggdrasil_deactivate() {}
register_deactivation_hook( __FILE__, 'yggdrasil_deactivate' );

/**
 * WordPress 用户被删除时清理其游戏档案、令牌、进服记录与皮肤文件。
 *
 * @param int $user_id 被删除的用户 ID。
 */
function yggdrasil_cleanup_deleted_user( $user_id ) {
    if ( class_exists( 'Yggdrasil_Profiles' ) ) {
        Yggdrasil_Profiles::delete_all_for_user( $user_id );
    }
}
add_action( 'deleted_user', 'yggdrasil_cleanup_deleted_user' );

/**
 * 启动插件：升级数据库并注册 REST 路由与前台管理界面。
 */
function yggdrasil_bootstrap() {
    Yggdrasil_DB::maybe_upgrade();

    if ( class_exists( 'Yggdrasil_Auth_REST' ) ) {
        new Yggdrasil_Auth_REST();
    }

    if ( class_exists( 'Yggdrasil_Management' ) ) {
        new Yggdrasil_Management();
    }
}

if ( did_action( 'plugins_loaded' ) ) {
    yggdrasil_bootstrap();
} else {
    add_action( 'plugins_loaded', 'yggdrasil_bootstrap' );
}
