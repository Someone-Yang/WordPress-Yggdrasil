<?php
/**
 * 数据库建表、结构升级与旧数据迁移。
 *
 * 负责创建 {prefix}ygg_profiles 表，并通过 dbDelta 增量升级
 * {prefix}ygg_tokens / {prefix}ygg_joins，随后幂等地迁移旧版本数据。
 *
 * @package WordPress-Yggdrasil
 */

defined( 'ABSPATH' ) || exit;

/**
 * 数据库结构管理类。
 */
class Yggdrasil_DB {

    /**
     * 当前数据库结构版本。
     */
    const DB_VERSION = '3.1';

    /**
     * 获取档案表完整表名。
     *
     * @return string
     */
    public static function profiles_table() {
        global $wpdb;

        return $wpdb->prefix . 'ygg_profiles';
    }

    /**
     * 获取令牌表完整表名。
     *
     * @return string
     */
    public static function tokens_table() {
        global $wpdb;

        return $wpdb->prefix . 'ygg_tokens';
    }

    /**
     * 获取进服记录表完整表名。
     *
     * @return string
     */
    public static function joins_table() {
        global $wpdb;

        return $wpdb->prefix . 'ygg_joins';
    }

    /**
     * 建表、升级表结构并迁移旧数据（可重复执行）。
     *
     * @return void
     */
    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $profiles_table = self::profiles_table();
        $tokens_table   = self::tokens_table();
        $joins_table    = self::joins_table();

        // 档案表：完整建表语句，便于 dbDelta 增量升级。
        $sql_profiles = "CREATE TABLE {$profiles_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            uuid CHAR(32) NOT NULL,
            name VARCHAR(32) NOT NULL,
            model VARCHAR(16) NOT NULL DEFAULT 'default',
            skin_path VARCHAR(255) NOT NULL DEFAULT '',
            skin_hash CHAR(64) NOT NULL DEFAULT '',
            skin_updated BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY name (name),
            KEY user_id (user_id),
            KEY skin_hash (skin_hash)
        ) {$charset_collate};";

        // 令牌表：保留原有字段，新增 profile_id（0 表示未绑定档案）。
        // access_token 有 UNIQUE 索引，使用 191 长度以兼容旧版 MySQL 的 utf8mb4 索引长度限制；
        // client_token 无索引，保留 255 以容纳任意合法客户端令牌。
        $sql_tokens = "CREATE TABLE {$tokens_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            access_token VARCHAR(191) NOT NULL,
            client_token VARCHAR(255) NOT NULL,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            expires DATETIME NOT NULL,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY access_token (access_token),
            KEY user_id (user_id)
        ) {$charset_collate};";

        // 进服记录表：保留原有字段，新增 created 索引。
        $sql_joins = "CREATE TABLE {$joins_table} (
            server_id VARCHAR(191) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            profile_id CHAR(32) NOT NULL,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (server_id),
            KEY user_id (user_id),
            KEY created (created)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_profiles );
        dbDelta( $sql_tokens );
        dbDelta( $sql_joins );

        // 迁移旧数据：任何一步失败都不应产生致命错误，下次可重试。
        $migration_ok = true;
        try {
            self::migrate_legacy_profiles();
            self::backfill_user_uuid_meta();
            self::backfill_account_uuid_meta();
            self::backfill_token_profile_ids();
        } catch ( \Throwable $e ) {
            // 迁移异常时保留原数据，下次安装/升级时继续重试。
            $migration_ok = false;
        }

        // 仅当三张表都存在且迁移未异常时才标记版本，避免升级被误记为完成。
        if (
            $migration_ok
            && self::table_exists( $profiles_table )
            && self::table_exists( $tokens_table )
            && self::table_exists( $joins_table )
        ) {
            update_option( 'ygg_db_version', self::DB_VERSION );
        }
    }

    /**
     * 数据库结构版本与已安装版本不一致时执行升级。
     *
     * @return void
     */
    public static function maybe_upgrade() {
        if ( self::DB_VERSION === self::get_installed_version() ) {
            return;
        }

        self::install();
    }

    /**
     * 获取当前已安装的数据库结构版本。
     *
     * @return string 选项不存在时返回空串。
     */
    public static function get_installed_version() {
        return (string) get_option( 'ygg_db_version', '' );
    }

    /**
     * 迁移步骤 1/2：为拥有 ygg_uuid 元数据但尚无档案的用户创建默认档案。
     *
     * @return void
     */
    private static function migrate_legacy_profiles() {
        global $wpdb;

        // 依赖档案类提供的校验与名称/UUID 生成逻辑。
        if ( ! class_exists( 'Yggdrasil_Profiles' ) ) {
            return;
        }

        $profiles_table = self::profiles_table();
        if ( ! self::table_exists( $profiles_table ) ) {
            return;
        }

        $sql = $wpdb->prepare(
            "SELECT u.ID AS user_id, u.user_login, um.meta_value AS legacy_uuid
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
            LEFT JOIN {$profiles_table} p ON p.user_id = u.ID
            WHERE p.id IS NULL AND um.meta_value <> ''",
            'ygg_uuid'
        );

        $users = $wpdb->get_results( $sql );
        if ( empty( $users ) ) {
            return;
        }

        foreach ( $users as $user ) {
            // 单个用户失败只跳过该用户，不影响其余迁移。
            try {
                $user_id = isset( $user->user_id ) ? (int) $user->user_id : 0;
                if ( $user_id <= 0 ) {
                    continue;
                }

                // 并发或重复执行时可能已插入，重新确认一次。
                $existing = $wpdb->get_var(
                    $wpdb->prepare( "SELECT id FROM {$profiles_table} WHERE user_id = %d LIMIT 1", $user_id )
                );
                if ( ! empty( $existing ) ) {
                    continue;
                }

                $uuid = self::normalize_uuid( isset( $user->legacy_uuid ) ? $user->legacy_uuid : '' );
                if ( '' === $uuid || Yggdrasil_Profiles::get_by_uuid( $uuid ) ) {
                    $uuid = self::generate_profile_uuid();
                }
                if ( '' === $uuid ) {
                    continue;
                }

                $name = self::legacy_profile_name( isset( $user->user_login ) ? $user->user_login : '' );
                if ( '' === $name ) {
                    continue;
                }

                $now = current_time( 'mysql', true );

                $inserted = $wpdb->insert(
                    $profiles_table,
                    [
                        'user_id'      => $user_id,
                        'uuid'         => $uuid,
                        'name'         => $name,
                        'model'        => 'default',
                        'skin_path'    => '',
                        'skin_hash'    => '',
                        'skin_updated' => 0,
                        'is_default'   => 1,
                        'created'      => $now,
                        'updated'      => $now,
                    ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
                );

                if ( false === $inserted ) {
                    continue;
                }
            } catch ( \Throwable $e ) {
                continue;
            }
        }
    }

    /**
     * 迁移步骤 3：为已有档案但缺少 ygg_uuid 元数据的用户写回默认档案 UUID。
     *
     * @return void
     */
    private static function backfill_user_uuid_meta() {
        global $wpdb;

        if ( ! class_exists( 'Yggdrasil_Profiles' ) ) {
            return;
        }

        $profiles_table = self::profiles_table();
        if ( ! self::table_exists( $profiles_table ) ) {
            return;
        }

        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.user_id
            FROM {$profiles_table} p
            WHERE NOT EXISTS (
                SELECT 1 FROM {$wpdb->usermeta} um
                WHERE um.user_id = p.user_id AND um.meta_key = %s AND um.meta_value <> ''
            )",
            'ygg_uuid'
        );

        $user_ids = $wpdb->get_col( $sql );
        if ( empty( $user_ids ) ) {
            return;
        }

        foreach ( $user_ids as $user_id ) {
            try {
                $user_id = (int) $user_id;
                if ( $user_id <= 0 ) {
                    continue;
                }

                $default = Yggdrasil_Profiles::get_default( $user_id );
                if ( ! $default ) {
                    continue;
                }

                $uuid = self::hyphenate_uuid( isset( $default->uuid ) ? $default->uuid : '' );
                if ( '' === $uuid ) {
                    continue;
                }

                update_user_meta( $user_id, 'ygg_uuid', $uuid );
            } catch ( \Throwable $e ) {
                continue;
            }
        }
    }

    /**
     * 迁移步骤 3.5：为旧用户回填稳定的账号 UUID 元数据。
     *
     * 账号 UUID（ygg_account_uuid）与默认档案解耦，切换/删除默认档案时保持不变。
     *
     * @return void
     */
    private static function backfill_account_uuid_meta() {
        global $wpdb;

        $user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
                'ygg_uuid'
            )
        );

        if ( empty( $user_ids ) ) {
            return;
        }

        foreach ( $user_ids as $user_id ) {
            try {
                $user_id = (int) $user_id;
                if ( $user_id <= 0 ) {
                    continue;
                }

                if ( '' !== (string) get_user_meta( $user_id, 'ygg_account_uuid', true ) ) {
                    continue;
                }

                $account_uuid = self::hyphenate_uuid( (string) get_user_meta( $user_id, 'ygg_uuid', true ) );

                if ( '' === $account_uuid && class_exists( 'Yggdrasil_Profiles' ) ) {
                    $default = Yggdrasil_Profiles::get_default( $user_id );
                    if ( $default ) {
                        $account_uuid = self::hyphenate_uuid( $default->uuid );
                    }
                }

                if ( '' !== $account_uuid ) {
                    update_user_meta( $user_id, 'ygg_account_uuid', $account_uuid );
                }
            } catch ( \Throwable $e ) {
                continue;
            }
        }
    }

    /**
     * 迁移步骤 4：把未绑定档案的令牌指向用户的默认档案。
     *
     * @return void
     */
    private static function backfill_token_profile_ids() {
        global $wpdb;

        $profiles_table = self::profiles_table();
        $tokens_table   = self::tokens_table();

        if ( ! self::table_exists( $profiles_table ) || ! self::table_exists( $tokens_table ) ) {
            return;
        }

        $sql = $wpdb->prepare(
            "UPDATE {$tokens_table} t
            INNER JOIN {$profiles_table} p ON p.user_id = t.user_id AND p.is_default = %d
            SET t.profile_id = p.id
            WHERE t.profile_id = %d",
            1,
            0
        );

        $wpdb->query( $sql );
    }

    /**
     * 判断数据表是否存在。
     *
     * @param string $table 完整表名（可信来源）。
     * @return bool
     */
    private static function table_exists( $table ) {
        global $wpdb;

        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) )
        );

        // lower_case_table_names=1 时 MySQL 可能返回小写表名，不能做严格全等比较。
        return ! empty( $found ) && 0 === strcasecmp( (string) $found, (string) $table );
    }

    /**
     * 规范化 UUID：去连字符、转小写，并校验 32 位十六进制。
     *
     * @param string $uuid 原始 UUID。
     * @return string 合法时返回 32 位小写形式，否则返回空串。
     */
    private static function normalize_uuid( $uuid ) {
        $uuid = strtolower( str_replace( '-', '', trim( (string) $uuid ) ) );

        return preg_match( '/^[a-f0-9]{32}$/', $uuid ) ? $uuid : '';
    }

    /**
     * 为 UUID 添加连字符（8-4-4-4-12）。
     *
     * @param string $uuid 32 位 UUID。
     * @return string 非法时返回空串。
     */
    private static function hyphenate_uuid( $uuid ) {
        $uuid = self::normalize_uuid( $uuid );
        if ( '' === $uuid ) {
            return '';
        }

        return substr( $uuid, 0, 8 ) . '-' . substr( $uuid, 8, 4 ) . '-' . substr( $uuid, 12, 4 )
            . '-' . substr( $uuid, 16, 4 ) . '-' . substr( $uuid, 20, 12 );
    }

    /**
     * 生成一个当前档案表中未使用的 UUID。
     *
     * @return string 失败时返回空串。
     */
    private static function generate_profile_uuid() {
        if ( ! class_exists( 'Yggdrasil_Profiles' ) ) {
            return '';
        }

        for ( $i = 0; $i < 10; $i++ ) {
            $uuid = Yggdrasil_Profiles::generate_uuid();
            if ( '' !== $uuid && ! Yggdrasil_Profiles::get_by_uuid( $uuid ) ) {
                return $uuid;
            }
        }

        return '';
    }

    /**
     * 为旧用户挑选迁移用档案名称：优先 user_login，否则生成唯一名称。
     *
     * @param string $user_login WordPress 登录名。
     * @return string 失败时返回空串。
     */
    private static function legacy_profile_name( $user_login ) {
        if ( ! class_exists( 'Yggdrasil_Profiles' ) ) {
            return '';
        }

        $login = trim( (string) $user_login );

        if ( ! is_wp_error( Yggdrasil_Profiles::validate_name( $login ) ) && ! Yggdrasil_Profiles::get_by_name( $login ) ) {
            return $login;
        }

        return Yggdrasil_Profiles::generate_unique_name( $login );
    }
}
