<?php
/**
 * 游戏档案（Profile）的增删改查、校验与默认档案管理。
 *
 * 所有返回的档案对象字段与 {prefix}ygg_profiles 表列同名，
 * 其中 id / user_id / skin_updated / is_default 为 int，其余为 string。
 *
 * @package WordPress-Yggdrasil
 */

defined( 'ABSPATH' ) || exit;

/**
 * 档案仓储类。
 */
class Yggdrasil_Profiles {

    /**
     * 档案名称最大长度。
     */
    const MAX_NAME_LENGTH = 16;

    /**
     * 获取档案表名。
     *
     * @return string
     */
    private static function profiles_table() {
        if ( class_exists( 'Yggdrasil_DB' ) ) {
            return Yggdrasil_DB::profiles_table();
        }

        global $wpdb;

        return $wpdb->prefix . 'ygg_profiles';
    }

    /**
     * 获取令牌表名。
     *
     * @return string
     */
    private static function tokens_table() {
        if ( class_exists( 'Yggdrasil_DB' ) ) {
            return Yggdrasil_DB::tokens_table();
        }

        global $wpdb;

        return $wpdb->prefix . 'ygg_tokens';
    }

    /**
     * 获取进服记录表名。
     *
     * @return string
     */
    private static function joins_table() {
        if ( class_exists( 'Yggdrasil_DB' ) ) {
            return Yggdrasil_DB::joins_table();
        }

        global $wpdb;

        return $wpdb->prefix . 'ygg_joins';
    }

    /**
     * 按 ID 获取档案。
     *
     * @param int $profile_id 档案 ID。
     * @return object|null
     */
    public static function get( $profile_id ) {
        global $wpdb;

        $profile_id = absint( $profile_id );
        if ( $profile_id <= 0 ) {
            return null;
        }

        $table = self::profiles_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $profile_id )
        );

        return $row ? self::format( $row ) : null;
    }

    /**
     * 按 UUID 获取档案（接受带/不带连字符、任意大小写）。
     *
     * @param string $uuid UUID。
     * @return object|null
     */
    public static function get_by_uuid( $uuid ) {
        global $wpdb;

        $uuid = self::normalize_uuid( $uuid );
        if ( '' === $uuid ) {
            return null;
        }

        $table = self::profiles_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s LIMIT 1", $uuid )
        );

        return $row ? self::format( $row ) : null;
    }

    /**
     * 按名称获取档案（数据库排序规则不区分大小写）。
     *
     * @param string $name 档案名称。
     * @return object|null
     */
    public static function get_by_name( $name ) {
        global $wpdb;

        $name = trim( (string) $name );
        if ( '' === $name ) {
            return null;
        }

        $table = self::profiles_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s LIMIT 1", $name )
        );

        return $row ? self::format( $row ) : null;
    }

    /**
     * 获取用户的全部档案，按 id 升序。
     *
     * @param int $user_id 用户 ID。
     * @return object[]
     */
    public static function get_for_user( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return [];
        }

        $table = self::profiles_table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC", $user_id )
        );

        if ( empty( $rows ) ) {
            return [];
        }

        return array_map( [ __CLASS__, 'format' ], $rows );
    }

    /**
     * 统计用户的档案数量。
     *
     * @param int $user_id 用户 ID。
     * @return int
     */
    public static function count_for_user( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return 0;
        }

        $table = self::profiles_table();
        $count = $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id )
        );

        return (int) $count;
    }

    /**
     * 获取用户的默认档案；没有标记默认时退回 id 最小的档案。
     *
     * @param int $user_id 用户 ID。
     * @return object|null
     */
    public static function get_default( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return null;
        }

        $table = self::profiles_table();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND is_default = 1 ORDER BY id ASC LIMIT 1",
                $user_id
            )
        );

        if ( ! $row ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT 1",
                    $user_id
                )
            );
        }

        return $row ? self::format( $row ) : null;
    }

    /**
     * 获取用户可拥有的档案数量上限。
     *
     * @param int $user_id 用户 ID。
     * @return int 至少为 1。
     */
    public static function max_profiles( $user_id = 0 ) {
        $user_id = absint( $user_id );

        if ( class_exists( 'Yggdrasil_Settings' ) ) {
            return max( 1, (int) Yggdrasil_Settings::get_max_profiles( $user_id ) );
        }

        $max = (int) apply_filters( 'yggdrasil_max_profiles_per_user', 5, $user_id );

        return max( 1, $max );
    }

    /**
     * 创建档案。
     *
     * @param int    $user_id 用户 ID。
     * @param string $name    档案名称。
     * @param array  $args    可选参数：uuid、model、is_default、skin_path、skin_hash、skin_updated。
     * @return object|WP_Error
     */
    public static function create( $user_id, $name, $args = [] ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
            return new WP_Error( 'ygg_invalid_user', '用户不存在。' );
        }

        $args = is_array( $args ) ? $args : [];

        $count = self::count_for_user( $user_id );
        if ( $count >= self::max_profiles( $user_id ) ) {
            return new WP_Error( 'ygg_profile_limit', '档案数量已达上限，无法继续创建。' );
        }

        // 名称合法性。
        $valid_name = self::validate_name( $name );
        if ( is_wp_error( $valid_name ) ) {
            return $valid_name;
        }
        $name = trim( (string) $name );

        // 名称全局唯一。
        if ( self::get_by_name( $name ) ) {
            return new WP_Error( 'ygg_name_taken', '该名称已被其他档案使用。' );
        }

        // 模型合法性（默认 default）。
        $model = isset( $args['model'] ) ? $args['model'] : 'default';
        $valid_model = self::validate_model( $model );
        if ( is_wp_error( $valid_model ) ) {
            return $valid_model;
        }
        $model = strtolower( trim( (string) $model ) );

        // UUID 合法性 / 未占用；未提供时生成并重试。
        if ( ! empty( $args['uuid'] ) ) {
            $uuid = self::normalize_uuid( $args['uuid'] );
            if ( '' === $uuid ) {
                return new WP_Error( 'ygg_invalid_uuid', 'UUID 格式不合法。' );
            }
            if ( self::get_by_uuid( $uuid ) ) {
                return new WP_Error( 'ygg_uuid_taken', '该 UUID 已被其他档案使用。' );
            }
        } else {
            $uuid = '';
            for ( $i = 0; $i < 10; $i++ ) {
                $candidate = self::generate_uuid();
                if ( ! self::get_by_uuid( $candidate ) ) {
                    $uuid = $candidate;
                    break;
                }
            }
            if ( '' === $uuid ) {
                return new WP_Error( 'ygg_db_error', '生成唯一 UUID 失败，请重试。' );
            }
        }

        // 用户第一个档案自动设为默认；显式要求默认时先清除其他默认。
        $is_default          = ( 0 === $count ) ? true : ! empty( $args['is_default'] );
        $previous_default_id = 0;

        $table = self::profiles_table();
        if ( $is_default && $count > 0 ) {
            $previous_default = self::get_default( $user_id );
            if ( $previous_default ) {
                $previous_default_id = (int) $previous_default->id;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET is_default = 0 WHERE user_id = %d AND is_default = 1",
                    $user_id
                )
            );
        }

        // 皮肤字段：无路径时哈希与时间一并清空。
        $skin_path    = isset( $args['skin_path'] ) ? (string) $args['skin_path'] : '';
        $skin_hash    = isset( $args['skin_hash'] ) ? strtolower( trim( (string) $args['skin_hash'] ) ) : '';
        $skin_updated = isset( $args['skin_updated'] ) ? absint( $args['skin_updated'] ) : 0;

        if ( '' === $skin_path ) {
            $skin_hash    = '';
            $skin_updated = 0;
        } elseif ( $skin_updated <= 0 ) {
            $skin_updated = time();
        }

        $now = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'      => $user_id,
                'uuid'         => $uuid,
                'name'         => $name,
                'model'        => $model,
                'skin_path'    => $skin_path,
                'skin_hash'    => $skin_hash,
                'skin_updated' => $skin_updated,
                'is_default'   => $is_default ? 1 : 0,
                'created'      => $now,
                'updated'      => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            // 插入失败时恢复被清除的默认标记，避免用户失去默认档案。
            if ( $previous_default_id > 0 ) {
                self::set_default( $previous_default_id, $user_id );
            }

            // 并发写入时尽量给出更精确的错误码。
            if ( self::get_by_name( $name ) ) {
                return new WP_Error( 'ygg_name_taken', '该名称已被其他档案使用。' );
            }
            if ( self::get_by_uuid( $uuid ) ) {
                return new WP_Error( 'ygg_uuid_taken', '该 UUID 已被其他档案使用。' );
            }

            return new WP_Error( 'ygg_db_error', '创建档案失败，请稍后重试。' );
        }

        $profile = self::get( (int) $wpdb->insert_id );
        if ( ! $profile ) {
            return new WP_Error( 'ygg_db_error', '读取新建档案失败。' );
        }

        // 兼容旧版本：用户尚无 ygg_uuid 元数据时写入默认档案的带连字符 UUID。
        if ( '' === (string) get_user_meta( $user_id, 'ygg_uuid', true ) ) {
            $default = $profile->is_default ? $profile : self::get_default( $user_id );
            if ( $default && '' !== (string) $default->uuid ) {
                update_user_meta( $user_id, 'ygg_uuid', self::hyphenate_uuid( $default->uuid ) );
            }
        }

        // 账号 UUID 与默认档案解耦，仅在首次创建档案时写入。
        if ( '' === (string) get_user_meta( $user_id, 'ygg_account_uuid', true ) ) {
            $account_profile = $profile->is_default ? $profile : self::get_default( $user_id );
            if ( $account_profile && '' !== (string) $account_profile->uuid ) {
                update_user_meta( $user_id, 'ygg_account_uuid', self::hyphenate_uuid( $account_profile->uuid ) );
            }
        }

        do_action( 'yggdrasil_profile_created', $profile, $user_id );

        return $profile;
    }

    /**
     * 更新档案（仅允许 name、model 两个键）。
     *
     * @param int   $profile_id 档案 ID。
     * @param int   $user_id    用户 ID。
     * @param array $data       待更新数据。
     * @return true|WP_Error
     */
    public static function update( $profile_id, $user_id, $data ) {
        global $wpdb;

        $profile_id = absint( $profile_id );
        $user_id    = absint( $user_id );
        $profile    = self::get( $profile_id );

        if ( ! $profile || (int) $profile->user_id !== $user_id || $user_id <= 0 ) {
            return new WP_Error( 'ygg_forbidden', '无权操作该档案。' );
        }

        $data    = is_array( $data ) ? $data : [];
        $fields  = [];
        $formats = [];

        if ( array_key_exists( 'name', $data ) ) {
            $valid_name = self::validate_name( $data['name'] );
            if ( is_wp_error( $valid_name ) ) {
                return $valid_name;
            }

            $name     = trim( (string) $data['name'] );
            $existing = self::get_by_name( $name );
            if ( $existing && (int) $existing->id !== $profile_id ) {
                return new WP_Error( 'ygg_name_taken', '该名称已被其他档案使用。' );
            }

            $fields['name'] = $name;
            $formats[]      = '%s';
        }

        if ( array_key_exists( 'model', $data ) ) {
            $valid_model = self::validate_model( $data['model'] );
            if ( is_wp_error( $valid_model ) ) {
                return $valid_model;
            }

            $fields['model'] = strtolower( trim( (string) $data['model'] ) );
            $formats[]       = '%s';
        }

        // 没有可更新字段时视为成功，不触发更新钩子。
        if ( empty( $fields ) ) {
            return true;
        }

        $fields['updated'] = current_time( 'mysql', true );
        $formats[]         = '%s';

        $table   = self::profiles_table();
        $updated = $wpdb->update(
            $table,
            $fields,
            [
                'id'      => $profile_id,
                'user_id' => $user_id,
            ],
            $formats,
            [ '%d', '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'ygg_db_error', '更新档案失败，请稍后重试。' );
        }

        $profile = self::get( $profile_id );
        if ( $profile ) {
            do_action( 'yggdrasil_profile_updated', $profile, $user_id );
        }

        return true;
    }

    /**
     * 删除某用户的全部档案（用于 WordPress 用户被删除时清理数据）。
     *
     * 与 delete() 不同，此处不受“至少保留一个档案”限制，并逐条触发
     * yggdrasil_profile_deleted 钩子，以便皮肤模块清理文件。
     *
     * @param int $user_id 用户 ID。
     * @return bool
     */
    public static function delete_all_for_user( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return false;
        }

        $profiles = self::get_for_user( $user_id );

        // 先清理令牌与进服记录，避免触发档案删除钩子后仍残留引用。
        $wpdb->delete( self::tokens_table(), [ 'user_id' => $user_id ], [ '%d' ] );
        $wpdb->delete( self::joins_table(), [ 'user_id' => $user_id ], [ '%d' ] );

        foreach ( $profiles as $profile ) {
            $wpdb->delete(
                self::profiles_table(),
                [
                    'id'      => (int) $profile->id,
                    'user_id' => $user_id,
                ],
                [ '%d', '%d' ]
            );

            do_action( 'yggdrasil_profile_deleted', $profile, $user_id );
        }

        return true;
    }

    /**
     * 删除档案（默认至少保留一个；管理员维护时可传 $force 删除最后一个）。
     *
     * @param int  $profile_id 档案 ID。
     * @param int  $user_id    用户 ID。
     * @param bool $force      是否允许删除最后一个档案。
     * @return true|WP_Error
     */
    public static function delete( $profile_id, $user_id, $force = false ) {
        global $wpdb;

        $profile_id = absint( $profile_id );
        $user_id    = absint( $user_id );
        $profile    = self::get( $profile_id );

        if ( ! $profile || (int) $profile->user_id !== $user_id || $user_id <= 0 ) {
            return new WP_Error( 'ygg_forbidden', '无权操作该档案。' );
        }

        if ( ! $force && self::count_for_user( $user_id ) <= 1 ) {
            return new WP_Error( 'ygg_last_profile', '至少需要保留一个档案。' );
        }

        $deleted = $wpdb->delete(
            self::profiles_table(),
            [
                'id'      => $profile_id,
                'user_id' => $user_id,
            ],
            [ '%d', '%d' ]
        );

        if ( false === $deleted ) {
            return new WP_Error( 'ygg_db_error', '删除档案失败，请稍后重试。' );
        }

        // 清理该档案相关的进服记录（profile_id 存 32 位 UUID 文本）。
        $wpdb->delete(
            self::joins_table(),
            [
                'user_id'    => $user_id,
                'profile_id' => $profile->uuid,
            ],
            [ '%d', '%s' ]
        );

        // 只重置指向该档案的令牌绑定。
        $wpdb->update(
            self::tokens_table(),
            [ 'profile_id' => 0 ],
            [
                'user_id'    => $user_id,
                'profile_id' => $profile_id,
            ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        // 删除默认档案后，把剩余 id 最小的档案设为默认。
        if ( $profile->is_default ) {
            $remaining = self::get_for_user( $user_id );
            if ( ! empty( $remaining ) ) {
                self::set_default( (int) $remaining[0]->id, $user_id );
            }
        }

        do_action( 'yggdrasil_profile_deleted', $profile, $user_id );

        return true;
    }

    /**
     * 设置用户的默认档案。
     *
     * @param int $profile_id 档案 ID。
     * @param int $user_id    用户 ID。
     * @return true|WP_Error
     */
    public static function set_default( $profile_id, $user_id ) {
        global $wpdb;

        $profile_id = absint( $profile_id );
        $user_id    = absint( $user_id );
        $profile    = self::get( $profile_id );

        if ( ! $profile || (int) $profile->user_id !== $user_id || $user_id <= 0 ) {
            return new WP_Error( 'ygg_forbidden', '无权操作该档案。' );
        }

        $table = self::profiles_table();

        // 先清除该用户的既有默认，再设置目标档案。
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET is_default = 0 WHERE user_id = %d AND is_default = 1",
                $user_id
            )
        );

        $updated = $wpdb->update(
            $table,
            [ 'is_default' => 1 ],
            [
                'id'      => $profile_id,
                'user_id' => $user_id,
            ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'ygg_db_error', '设置默认档案失败，请稍后重试。' );
        }

        return true;
    }

    /**
     * 设置或清空档案皮肤信息。
     *
     * @param int         $profile_id 档案 ID。
     * @param int         $user_id    用户 ID。
     * @param string      $skin_path  相对于上传目录的皮肤路径；空串表示清除皮肤。
     * @param string      $skin_hash  净化后 PNG 的 SHA-256。
     * @param string|null $model      皮肤模型 default|slim；null 表示不修改。
     * @return true|WP_Error
     */
    public static function set_skin( $profile_id, $user_id, $skin_path, $skin_hash = '', $model = null ) {
        global $wpdb;

        $profile_id = absint( $profile_id );
        $user_id    = absint( $user_id );
        $profile    = self::get( $profile_id );

        if ( ! $profile || (int) $profile->user_id !== $user_id || $user_id <= 0 ) {
            return new WP_Error( 'ygg_forbidden', '无权操作该档案。' );
        }

        $skin_path = (string) $skin_path;
        $skin_hash = strtolower( trim( (string) $skin_hash ) );

        $data = [];

        if ( '' === $skin_path ) {
            $data['skin_path']    = '';
            $data['skin_hash']    = '';
            $data['skin_updated'] = 0;
        } else {
            $data['skin_path']    = $skin_path;
            $data['skin_hash']    = $skin_hash;
            $data['skin_updated'] = time();
        }

        $formats = [ '%s', '%s', '%d' ];

        if ( null !== $model ) {
            $valid_model = self::validate_model( $model );
            if ( is_wp_error( $valid_model ) ) {
                return $valid_model;
            }

            $data['model'] = strtolower( trim( (string) $model ) );
            $formats[]     = '%s';
        }

        $data['updated'] = current_time( 'mysql', true );
        $formats[]       = '%s';

        $updated = $wpdb->update(
            self::profiles_table(),
            $data,
            [
                'id'      => $profile_id,
                'user_id' => $user_id,
            ],
            $formats,
            [ '%d', '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'ygg_db_error', '更新皮肤信息失败，请稍后重试。' );
        }

        return true;
    }

    /**
     * 校验档案名称：1-16 位字母、数字或下划线。
     *
     * @param string $name 档案名称。
     * @return true|WP_Error
     */
    public static function validate_name( $name ) {
        if ( ! is_string( $name ) && ! is_numeric( $name ) ) {
            return new WP_Error( 'ygg_invalid_name', '档案名称必须是文本。' );
        }

        $name = trim( (string) $name );

        if ( '' === $name ) {
            return new WP_Error( 'ygg_invalid_name', '档案名称不能为空。' );
        }

        if ( strlen( $name ) > self::MAX_NAME_LENGTH ) {
            return new WP_Error( 'ygg_invalid_name', '档案名称最多 16 个字符。' );
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
            return new WP_Error( 'ygg_invalid_name', '档案名称只能包含字母、数字和下划线。' );
        }

        return true;
    }

    /**
     * 校验皮肤模型：default 或 slim。
     *
     * @param string $model 模型。
     * @return true|WP_Error
     */
    public static function validate_model( $model ) {
        if ( ! is_string( $model ) ) {
            return new WP_Error( 'ygg_invalid_model', '皮肤模型不合法，只能是 default 或 slim。' );
        }

        $model = strtolower( trim( $model ) );

        if ( ! in_array( $model, [ 'default', 'slim' ], true ) ) {
            return new WP_Error( 'ygg_invalid_model', '皮肤模型不合法，只能是 default 或 slim。' );
        }

        return true;
    }

    /**
     * 获取用户默认档案，没有则按旧数据兼容规则创建。
     *
     * @param int    $user_id    用户 ID。
     * @param string $user_login 可选登录名，用于生成档案名称。
     * @return object|WP_Error
     */
    public static function get_or_create_for_user( $user_id, $user_login = '' ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return new WP_Error( 'ygg_invalid_user', '用户不存在。' );
        }

        $profiles = self::get_for_user( $user_id );
        if ( ! empty( $profiles ) ) {
            $default = self::get_default( $user_id );

            return $default ? $default : $profiles[0];
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return new WP_Error( 'ygg_invalid_user', '用户不存在。' );
        }

        $login = ( '' !== trim( (string) $user_login ) ) ? trim( (string) $user_login ) : (string) $user->user_login;

        $args = [ 'is_default' => true ];

        // 旧版本用户元数据里的 UUID 若仍然可用则沿用。
        $legacy_uuid = self::normalize_uuid( get_user_meta( $user_id, 'ygg_uuid', true ) );
        if ( '' !== $legacy_uuid && ! self::get_by_uuid( $legacy_uuid ) ) {
            $args['uuid'] = $legacy_uuid;
        }

        $name = $login;
        if ( is_wp_error( self::validate_name( $name ) ) || self::get_by_name( $name ) ) {
            $name = self::generate_unique_name( $login );
        }

        return self::create( $user_id, $name, $args );
    }

    /**
     * 生成 32 位小写十六进制、无连字符的 UUID。
     *
     * @return string
     */
    public static function generate_uuid() {
        $uuid = strtolower( str_replace( '-', '', (string) wp_generate_uuid4() ) );

        if ( preg_match( '/^[a-f0-9]{32}$/', $uuid ) ) {
            return $uuid;
        }

        // 兜底：直接使用 16 字节随机数。
        try {
            return bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $e ) {
            return md5( uniqid( (string) wp_rand(), true ) );
        }
    }

    /**
     * 基于基准名称生成合法且未被占用的档案名称。
     *
     * @param string $base 基准名称（可能包含非法字符）。
     * @return string
     */
    public static function generate_unique_name( $base ) {
        $base = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $base );
        $base = (string) $base;

        if ( '' === $base ) {
            $base = 'Player';
        }

        $base = substr( $base, 0, self::MAX_NAME_LENGTH );

        if ( ! self::get_by_name( $base ) ) {
            return $base;
        }

        // 追加 _2、_3……保持总长度不超过 16。
        for ( $i = 2; $i <= 50; $i++ ) {
            $suffix    = '_' . $i;
            $candidate = substr( $base, 0, self::MAX_NAME_LENGTH - strlen( $suffix ) ) . $suffix;

            if ( '' !== $candidate && ! self::get_by_name( $candidate ) ) {
                return $candidate;
            }
        }

        // 仍冲突时追加随机数字。
        for ( $i = 0; $i < 20; $i++ ) {
            $suffix    = (string) wp_rand( 100, 99999 );
            $candidate = substr( $base, 0, self::MAX_NAME_LENGTH - strlen( $suffix ) ) . $suffix;

            if ( '' !== $candidate && ! self::get_by_name( $candidate ) ) {
                return $candidate;
            }
        }

        // 最后兜底：以字母开头的随机十六进制串。
        return 'P' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, self::MAX_NAME_LENGTH - 1 );
    }

    /**
     * 把数据库行转换为规范的档案对象（字段类型转换）。
     *
     * @param object|array $row 数据库行。
     * @return object
     */
    public static function format( $row ) {
        if ( ! is_object( $row ) && ! is_array( $row ) ) {
            return (object) [];
        }

        $data = (array) $row;

        $int_fields = [ 'id', 'user_id', 'skin_updated', 'is_default' ];
        foreach ( $int_fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $data[ $field ] = (int) $data[ $field ];
            }
        }

        $string_fields = [ 'uuid', 'name', 'model', 'skin_path', 'skin_hash', 'created', 'updated' ];
        foreach ( $string_fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $data[ $field ] = (string) $data[ $field ];
            }
        }

        return (object) $data;
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
}
