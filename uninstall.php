<?php
/**
 * 插件卸载清理。
 *
 * 默认情况下会保留所有档案、令牌与皮肤数据，避免用户误卸载后丢失游戏档案。
 * 如需在卸载时彻底删除数据，请在 wp-config.php 中定义：
 *
 *     define( 'YGGDRASIL_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * @package WordPress-Yggdrasil
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'YGGDRASIL_REMOVE_DATA_ON_UNINSTALL' ) || ! YGGDRASIL_REMOVE_DATA_ON_UNINSTALL ) {
    return;
}

global $wpdb;

// 删除数据表。
$tables = [
    $wpdb->prefix . 'ygg_profiles',
    $wpdb->prefix . 'ygg_tokens',
    $wpdb->prefix . 'ygg_joins',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名由固定前缀拼接。
}

// 先记录管理页面 ID，再删除选项。
$management_page_id = (int) get_option( 'ygg_management_page_id' );

// 删除选项。
$options = [
    'ygg_db_version',
    'ygg_textures_private_key',
    'ygg_textures_public_key',
    'ygg_management_page_id',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// 删除用户账号 UUID 元数据。
delete_metadata( 'user', 0, 'ygg_uuid', '', true );
delete_metadata( 'user', 0, 'ygg_account_uuid', '', true );

// 删除管理页面。
if ( $management_page_id > 0 ) {
    wp_delete_post( $management_page_id, true );
}

// 删除插件产生的 transient（通知、登录限速等）。
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_ygg_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_ygg_' ) . '%',
        $wpdb->esc_like( '_transient_yggdrasil_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_yggdrasil_' ) . '%'
    )
);

// 删除皮肤文件目录。
$uploads = wp_upload_dir();
$skin_dir = trailingslashit( $uploads['basedir'] ) . 'yggdrasil-skins';

if ( is_dir( $skin_dir ) ) {
    $real_uploads = realpath( $uploads['basedir'] );
    $real_skin    = realpath( $skin_dir );

    // 严格确认真实路径位于上传目录内，避免误删。
    if ( false !== $real_uploads && false !== $real_skin
        && 0 === strpos( $real_skin, rtrim( $real_uploads, '/\\' ) . DIRECTORY_SEPARATOR )
        && 'yggdrasil-skins' === basename( $real_skin ) ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $real_skin, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            } else {
                @unlink( $item->getPathname() );
            }
        }

        @rmdir( $real_skin );
    }
}
