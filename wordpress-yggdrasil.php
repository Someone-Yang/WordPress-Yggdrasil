<?php
/**
 * Plugin Name: Minecraft Yggdrasil 兼容层 (WP REST API)
 * Plugin URI: https://github.com/Someone-Yang/WordPress-Yggdrasil
 * Description: 利用 WordPress REST API 提供兼容 Minecraft Yggdrasil 的认证和会话服务。
 * Version: 0.2.0
 * Author: 氧化某人
 * License: MIT
 */

defined( 'ABSPATH' ) || exit;

class Yggdrasil_Auth_REST {

    /**
     * 令牌过期时间（秒）
     */
    const TOKEN_EXPIRY = 86400; // 24 小时

    /**
     * 加入会话记录过期时间（秒）
     */
    const JOIN_EXPIRY = 60; // 1 分钟

    /**
     * 初始化插件
     */
    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * 插件激活：创建数据库表
     */
    public function activate() {
        $this->create_tables();
    }

    /**
     * 插件停用：清理（无需额外操作）
     */
    public function deactivate() {}

    /**
     * 创建数据库表
     */
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
    
        $table_tokens = $wpdb->prefix . 'ygg_tokens';
        $table_joins  = $wpdb->prefix . 'ygg_joins';
    
        $sql_tokens = "CREATE TABLE $table_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            access_token VARCHAR(255) NOT NULL UNIQUE,
            client_token VARCHAR(255) NOT NULL,
            expires DATETIME NOT NULL,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset_collate;";
    
        $sql_joins = "CREATE TABLE $table_joins (
            server_id VARCHAR(255) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            profile_id CHAR(32) NOT NULL,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (server_id),
            KEY user_id (user_id)
        ) $charset_collate;";
    
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_tokens );
        dbDelta( $sql_joins );
    
        add_option( 'ygg_db_version', '2.1' );
    }

    /**
     * 注册 REST API 路由
     */
    public function register_routes() {
        $namespace = 'yggdrasil/v1';

        // 认证服务器端点
        register_rest_route( $namespace, '/authserver/authenticate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_authenticate' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/authserver/refresh', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_refresh' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/authserver/validate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_validate' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/authserver/signout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_signout' ],
            'permission_callback' => '__return_true',
        ] );

        // 会话服务器端点
        register_rest_route( $namespace, '/sessionserver/session/minecraft/join', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_join' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/sessionserver/session/minecraft/hasJoined', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_has_joined' ],
            'permission_callback' => '__return_true',
        ] );

        // ========== 新增端点：根据 UUID 获取玩家资料 ==========
        register_rest_route( $namespace, '/sessionserver/session/minecraft/profile/(?P<uuid>[a-f0-9]{32})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_profile' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'uuid' => [
                    'validate_callback' => function( $param ) {
                        return preg_match( '/^[a-f0-9]{32}$/i', $param );
                    }
                ]
            ],
        ] );
    }

    // ---------- 认证服务器端点处理函数 ----------

    /**
     * POST /authserver/authenticate
     */
    public function handle_authenticate( $request ) {
        $params = $request->get_json_params();

        $username = isset( $params['username'] ) ? trim( $params['username'] ) : '';
        $password = isset( $params['password'] ) ? $params['password'] : '';
        $client_token = isset( $params['clientToken'] ) ? $params['clientToken'] : '';

        if ( empty( $username ) || empty( $password ) ) {
            return $this->error_response( 'Invalid credentials. Missing username or password.', 403 );
        }

        // 验证用户
        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            return $this->error_response( 'Invalid credentials. ' . $user->get_error_message(), 403 );
        }

        // 检查用户名是否符合 Minecraft 规范 (可选)
        if ( ! $this->is_valid_minecraft_username( $user->user_login ) ) {
            return $this->error_response( 'Username contains invalid characters or length for Minecraft.', 403 );
        }

        $user_id = $user->ID;

        // 获取或生成 UUID
        $uuid = $this->get_user_uuid( $user_id );

        // 生成访问令牌
        $access_token = $this->generate_access_token();
        $client_token = $this->generate_client_token( $client_token );

        // 保存令牌
        $this->save_token( $user_id, $access_token, $client_token );

        // 构建响应
        $profile = [
            'id'   => str_replace( '-', '', $uuid ), // 无连字符
            'name' => $user->user_login,
        ];

        $response = [
            'accessToken'       => $access_token,
            'clientToken'       => $client_token,
            'availableProfiles' => [ $profile ],
            'selectedProfile'   => $profile,
            'user'              => [
                'id'         => str_replace( '-', '', $uuid ),
                'properties' => []
            ]
        ];

        return rest_ensure_response( $response );
    }

    /**
     * POST /authserver/refresh
     */
    public function handle_refresh( $request ) {
        $params = $request->get_json_params();

        $access_token = isset( $params['accessToken'] ) ? $params['accessToken'] : '';
        $client_token = isset( $params['clientToken'] ) ? $params['clientToken'] : '';

        if ( empty( $access_token ) ) {
            return $this->error_response( 'Access token missing', 403 );
        }

        global $wpdb;
        $table_tokens = $wpdb->prefix . 'ygg_tokens';

        // 查找令牌
        $token_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_tokens WHERE access_token = %s AND expires > NOW()",
            $access_token
        ) );

        if ( ! $token_row ) {
            return $this->error_response( 'Invalid access token', 403 );
        }

        // 验证 client token (如果提供)
        if ( ! empty( $client_token ) && $token_row->client_token !== $client_token ) {
            return $this->error_response( 'Client token mismatch', 403 );
        }

        // 生成新访问令牌
        $new_access_token = $this->generate_access_token();
        $new_expires = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_EXPIRY );

        $wpdb->update(
            $table_tokens,
            [
                'access_token' => $new_access_token,
                'expires'      => $new_expires,
            ],
            [ 'id' => $token_row->id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // 获取用户信息
        $user = get_user_by( 'id', $token_row->user_id );
        if ( ! $user ) {
            return $this->error_response( 'User not found', 500 );
        }

        $uuid = $this->get_user_uuid( $user->ID );
        $profile = [
            'id'   => str_replace( '-', '', $uuid ),
            'name' => $user->user_login,
        ];

        $response = [
            'accessToken'       => $new_access_token,
            'clientToken'       => $token_row->client_token, // 保持不变
            'availableProfiles' => [ $profile ],
            'selectedProfile'   => $profile,
            'user'              => [
                'id'         => str_replace( '-', '', $uuid ),
                'properties' => []
            ]
        ];

        return rest_ensure_response( $response );
    }

    /**
     * POST /authserver/validate
     */
    public function handle_validate( $request ) {
        $params = $request->get_json_params();

        $access_token = isset( $params['accessToken'] ) ? $params['accessToken'] : '';

        if ( empty( $access_token ) ) {
            return $this->error_response( 'Access token missing', 403 );
        }

        $valid = $this->validate_access_token( $access_token );

        if ( ! $valid ) {
            return $this->error_response( 'Invalid token', 403 );
        }

        // 204 No Content
        return new WP_REST_Response( null, 204 );
    }

    /**
     * POST /authserver/signout
     */
    public function handle_signout( $request ) {
        $params = $request->get_json_params();

        $username = isset( $params['username'] ) ? trim( $params['username'] ) : '';
        $password = isset( $params['password'] ) ? $params['password'] : '';

        if ( empty( $username ) || empty( $password ) ) {
            return $this->error_response( 'Invalid credentials', 403 );
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            return $this->error_response( 'Invalid credentials', 403 );
        }

        // 删除该用户的所有令牌
        $this->delete_user_tokens( $user->ID );

        // 204 No Content
        return new WP_REST_Response( null, 204 );
    }

    // ---------- 会话服务器端点处理函数 ----------

    /**
     * POST /sessionserver/session/minecraft/join
     */
    public function handle_join( $request ) {
        $params = $request->get_json_params();

        $access_token    = isset( $params['accessToken'] ) ? $params['accessToken'] : '';
        $selected_profile = isset( $params['selectedProfile'] ) ? $params['selectedProfile'] : '';
        $server_id        = isset( $params['serverId'] ) ? $params['serverId'] : '';

        if ( empty( $access_token ) || empty( $selected_profile ) || empty( $server_id ) ) {
            return $this->error_response( 'Missing parameters', 403 );
        }

        // 验证 access token
        $user_id = $this->validate_access_token( $access_token, true );
        if ( ! $user_id ) {
            return $this->error_response( 'Invalid access token', 403 );
        }

        // 获取用户的 UUID 并比对 selectedProfile
        $uuid = $this->get_user_uuid( $user_id );
        if ( str_replace( '-', '', $uuid ) !== $selected_profile ) {
            return $this->error_response( 'Selected profile does not match user', 403 );
        }

        // 存储加入记录
        global $wpdb;
        $table_joins = $wpdb->prefix . 'ygg_joins';

        // 先删除该用户旧的加入记录（可选）
        $wpdb->delete( $table_joins, [ 'user_id' => $user_id ], [ '%d' ] );

        $wpdb->insert(
            $table_joins,
            [
                'server_id'  => $server_id,
                'user_id'    => $user_id,
                'profile_id' => $selected_profile,
                'created'    => current_time( 'mysql', true ),
            ],
            [ '%s', '%d', '%s', '%s' ]
        );

        // 204 No Content
        return new WP_REST_Response( null, 204 );
    }

    /**
     * GET /sessionserver/session/minecraft/hasJoined
     */
    public function handle_has_joined( $request ) {
        $username = $request->get_param( 'username' );
        $server_id = $request->get_param( 'serverId' );

        if ( empty( $username ) || empty( $server_id ) ) {
            return $this->error_response( 'Missing parameters', 403 );
        }

        global $wpdb;
        $table_joins = $wpdb->prefix . 'ygg_joins';

        // 查找 server_id 对应的记录（并检查未过期）
        $join = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_joins WHERE server_id = %s AND created > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            $server_id,
            self::JOIN_EXPIRY
        ) );

        if ( ! $join ) {
            return new WP_REST_Response( null, 204 );
        }

        // 验证用户名是否一致
        $user = get_user_by( 'id', $join->user_id );
        if ( ! $user || $user->user_login !== $username ) {
            return new WP_REST_Response( null, 204 );
        }

        // 返回 profile
        $profile = [
            'id'         => $join->profile_id, // 已经是无连字符格式
            'name'       => $user->user_login,
            'properties' => []
        ];

        return rest_ensure_response( $profile );
    }

    /**
     * 根据 UUID 获取玩家资料（用于皮肤站等）
     */
    public function handle_profile( $request ) {
        $uuid = $request->get_param( 'uuid' ); // 无连字符的 32 位 UUID

        // 将无连字符 UUID 转换为带连字符格式（用于查询 user meta）
        $uuid_hyphen = preg_replace( '/([a-f0-9]{8})([a-f0-9]{4})([a-f0-9]{4})([a-f0-9]{4})([a-f0-9]{12})/i', '$1-$2-$3-$4-$5', $uuid );

        // 查找拥有此 UUID 的用户
        $users = get_users( [
            'meta_key'   => 'ygg_uuid',
            'meta_value' => $uuid_hyphen,
            'number'     => 1,
            'fields'     => 'ID',
        ] );

        if ( empty( $users ) ) {
            // Yggdrasil 规范：未找到时返回 204 No Content
            return new WP_REST_Response( null, 204 );
        }

        $user_id = $users[0];
        $user = get_user_by( 'id', $user_id );

        if ( ! $user ) {
            return new WP_REST_Response( null, 204 );
        }

        // 构建基础 profile
        $profile = [
            'id'         => $uuid, // 保持传入的无连字符格式
            'name'       => $user->user_login,
            'properties' => [],
        ];

        /**
         * 允许其他插件通过过滤器添加 properties（如皮肤、披风等）
         * 示例用法：
         * add_filter( 'yggdrasil_profile_properties', function( $properties, $user_id ) {
         *     $properties[] = [
         *         'name'  => 'textures',
         *         'value' => base64_encode( json_encode( [ 'timestamp' => ..., 'profileId' => ..., 'profileName' => ..., 'textures' => ... ] ) )
         *     ];
         *     return $properties;
         * }, 10, 2 );
         */
        $profile['properties'] = apply_filters( 'yggdrasil_profile_properties', $profile['properties'], $user_id );

        return rest_ensure_response( $profile );
    }

    // ---------- 辅助函数 ----------

    /**
     * 生成符合 Yggdrasil 规范的错误响应
     */
    private function error_response( $message, $status = 403 ) {
        $error = [
            'error' => $status === 403 ? 'ForbiddenOperationException' : 'IllegalArgumentException',
            'errorMessage' => $message,
        ];
        return new WP_Error( $error['error'], $error['errorMessage'], [ 'status' => $status ] );
    }

    /**
     * 验证 Minecraft 用户名格式
     */
    private function is_valid_minecraft_username( $name ) {
        return (bool) preg_match( '/^[a-zA-Z0-9_]{1,16}$/', $name );
    }

    /**
     * 获取或生成用户 UUID (v4)
     */
    private function get_user_uuid( $user_id ) {
        $uuid = get_user_meta( $user_id, 'ygg_uuid', true );
        if ( empty( $uuid ) ) {
            $uuid = wp_generate_uuid4(); // WordPress 4.7+
            update_user_meta( $user_id, 'ygg_uuid', $uuid );
        }
        return $uuid;
    }

    /**
     * 生成随机访问令牌
     */
    private function generate_access_token() {
        return bin2hex( random_bytes( 32 ) ); // 64 字符
    }

    /**
     * 处理客户端令牌：若无则生成
     */
    private function generate_client_token( $provided = '' ) {
        if ( ! empty( $provided ) ) {
            return $provided;
        }
        return bin2hex( random_bytes( 16 ) );
    }

    /**
     * 保存令牌到数据库
     */
    private function save_token( $user_id, $access_token, $client_token ) {
        global $wpdb;
        $table_tokens = $wpdb->prefix . 'ygg_tokens';

        $wpdb->insert(
            $table_tokens,
            [
                'user_id'      => $user_id,
                'access_token' => $access_token,
                'client_token' => $client_token,
                'expires'      => gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_EXPIRY ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * 验证访问令牌有效性，若 $return_uid 为 true 则返回用户 ID
     */
    private function validate_access_token( $access_token, $return_uid = false ) {
        global $wpdb;
        $table_tokens = $wpdb->prefix . 'ygg_tokens';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id FROM $table_tokens WHERE access_token = %s AND expires > NOW()",
            $access_token
        ) );

        if ( ! $row ) {
            return false;
        }

        return $return_uid ? (int) $row->user_id : true;
    }

    /**
     * 删除用户所有令牌
     */
    private function delete_user_tokens( $user_id ) {
        global $wpdb;
        $table_tokens = $wpdb->prefix . 'ygg_tokens';
        $wpdb->delete( $table_tokens, [ 'user_id' => $user_id ], [ '%d' ] );
    }
}

// 初始化插件
new Yggdrasil_Auth_REST();