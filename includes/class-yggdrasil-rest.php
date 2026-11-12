<?php
/**
 * Yggdrasil REST API 端点。
 *
 * 在 WordPress REST API 上模拟 Yggdrasil 认证服务器与会话服务器，
 * 支持一个 WordPress 用户拥有多个游戏档案，并为档案提供皮肤材质。
 *
 * @package WordPress-Yggdrasil
 */

defined( 'ABSPATH' ) || exit;

/**
 * Yggdrasil 认证/会话 REST 控制器。
 */
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
     * 每个用户最多保留的令牌数量
     */
    const MAX_TOKENS_PER_USER = 10;

    /**
     * 登录失败限速阈值（同一用户名在窗口期内允许的失败次数）
     */
    const LOGIN_MAX_ATTEMPTS = 20;

    /**
     * 登录失败限速窗口（秒）
     */
    const LOGIN_WINDOW = 900;

    /**
     * 初始化插件
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );

        // WordPress 会自动注册命名空间索引路由 /yggdrasil/v1，该路由会先于自定义路由匹配。
        // 因此在分发前拦截命名空间根请求，返回 Yggdrasil API 元数据。
        add_filter( 'rest_pre_dispatch', [ $this, 'maybe_serve_api_metadata' ], 10, 3 );
    }

    /**
     * 注册 REST API 路由。
     */
    public function register_routes() {
        $namespace = 'yggdrasil/v1';

        // API 元数据（authlib-injector 扩展 API）。
        // 实际请求还会由 maybe_serve_api_metadata() 在 rest_pre_dispatch 阶段拦截
        // 命名空间根路径，避免被 WordPress 自动注册的命名空间索引路由抢先匹配。
        register_rest_route( $namespace, '/', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_api_metadata' ],
            'permission_callback' => '__return_true',
        ] );

        // 皮肤文件（直接输出 PNG，不经过 JSON 包装）。
        register_rest_route( $namespace, '/textures/(?P<hash>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_texture' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'hash' => [
                    'validate_callback' => function( $param ) {
                        return (bool) preg_match( '/^[a-f0-9]{64}$/', (string) $param );
                    },
                ],
            ],
        ] );

        // 认证服务器端点。
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

        register_rest_route( $namespace, '/authserver/invalidate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_invalidate' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $namespace, '/authserver/signout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_signout' ],
            'permission_callback' => '__return_true',
        ] );

        // 会话服务器端点。
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

        // 根据 UUID 获取玩家档案（包含皮肤属性）。
        register_rest_route( $namespace, '/sessionserver/session/minecraft/profile/(?P<uuid>[a-fA-F0-9]{32}|[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_profile' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'uuid' => [
                    'validate_callback' => function( $param ) {
                        return '' !== $this->normalize_uuid( $param );
                    },
                ],
            ],
        ] );
    }

    // ---------- API 元数据 ----------

    /**
     * GET /
     *
     * 返回 authlib-injector 所需的 API 元数据，包括皮肤域名白名单与公钥。
     */
    public function handle_api_metadata( $request ) {
        $host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

        $skin_domains = [];
        if ( ! empty( $host ) ) {
            $skin_domains[] = $host;
            $skin_domains[] = '.' . $host;
        }

        // 如果上传目录使用独立域名，也加入白名单。
        $uploads      = wp_upload_dir();
        $upload_host  = wp_parse_url( $uploads['baseurl'], PHP_URL_HOST );
        if ( ! empty( $upload_host ) && $upload_host !== $host ) {
            $skin_domains[] = $upload_host;
            $skin_domains[] = '.' . $upload_host;
        }

        // 后台「设置 → Yggdrasil」中配置的额外皮肤域名。
        if ( class_exists( 'Yggdrasil_Settings' ) ) {
            $skin_domains = array_merge( $skin_domains, Yggdrasil_Settings::get_extra_skin_domains() );
        }

        /**
         * 过滤皮肤域名白名单。
         *
         * @param string[] $skin_domains 域名规则数组。
         */
        $skin_domains = apply_filters( 'yggdrasil_skin_domains', $skin_domains );
        $skin_domains = array_values( array_unique( array_filter( array_map( 'strval', (array) $skin_domains ) ) ) );

        $meta = [
            'serverName'              => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'implementationName'      => 'WordPress-Yggdrasil',
            'implementationVersion'   => YGGDRASIL_VERSION,
            'links'                   => [
                'homepage' => home_url( '/' ),
            ],
            'feature.non_email_login' => true,
        ];

        /**
         * 过滤 API 元数据中的 meta 字段。
         *
         * @param array $meta 元数据。
         */
        $meta = apply_filters( 'yggdrasil_api_metadata', $meta );

        $response = [
            'meta'        => $meta,
            'skinDomains' => $skin_domains,
        ];

        $public_key = Yggdrasil_Skins::get_public_key();
        if ( ! empty( $public_key ) ) {
            $response['signaturePublickey'] = $public_key;
        }

        return rest_ensure_response( $response );
    }

    /**
     * 在 REST 分发前拦截命名空间根请求，返回 API 元数据。
     *
     * WordPress 会自动注册 `/yggdrasil/v1` 命名空间索引路由，且该路由先于本插件注册，
     * 因此命名空间根路径无法通过普通路由匹配到元数据回调。此过滤器在匹配完成后、
     * 执行回调前生效，可稳定返回元数据。
     *
     * @param mixed           $result  预分发结果。
     * @param WP_REST_Server  $server  REST 服务器。
     * @param WP_REST_Request $request 请求对象。
     * @return mixed
     */
    public function maybe_serve_api_metadata( $result, $server, $request ) {
        if ( null !== $result || ! $request instanceof WP_REST_Request ) {
            return $result;
        }

        if ( 'GET' !== strtoupper( (string) $request->get_method() ) ) {
            return $result;
        }

        $route = untrailingslashit( (string) $request->get_route() );

        if ( '/yggdrasil/v1' !== $route ) {
            return $result;
        }

        return $this->handle_api_metadata( $request );
    }

    // ---------- 认证服务器端点处理函数 ----------

    /**
     * POST /authserver/authenticate
     */
    public function handle_authenticate( $request ) {
        $params = $this->get_request_params( $request );

        $username     = isset( $params['username'] ) && is_string( $params['username'] ) ? trim( $params['username'] ) : '';
        $password     = isset( $params['password'] ) && is_string( $params['password'] ) ? $params['password'] : '';
        $client_token = isset( $params['clientToken'] ) && is_string( $params['clientToken'] ) ? $params['clientToken'] : '';

        if ( '' === $username || '' === $password ) {
            return $this->error_response( 'Invalid credentials. Missing username or password.', 403 );
        }

        // 简单的登录失败限速，缓解密码暴力破解（可按用户名过滤调整）。
        if ( $this->is_login_throttled( $username ) ) {
            return $this->error_response( 'Invalid credentials. Invalid username or password.', 403 );
        }

        // 验证 WordPress 用户（支持用户名或邮箱）。
        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            $this->record_login_failure( $username );
            // 固定错误文案，避免暴露用户名是否存在。
            return $this->error_response( 'Invalid credentials. Invalid username or password.', 403 );
        }

        $this->clear_login_failures( $username );

        // 用户组限制：仅允许后台配置的用户组使用 Yggdrasil 登录。
        if ( ! $this->is_user_allowed( $user->ID ) ) {
            return $this->error_response( 'This account is not allowed to use Yggdrasil login.', 403 );
        }

        // 确保用户至少拥有一个档案（兼容旧版本用户）。
        $profiles = Yggdrasil_Profiles::get_for_user( $user->ID );
        if ( empty( $profiles ) ) {
            $created = Yggdrasil_Profiles::get_or_create_for_user( $user->ID, $user->user_login );
            if ( is_wp_error( $created ) ) {
                return $this->error_response( 'Unable to create game profile: ' . $created->get_error_message(), 500 );
            }
            $profiles = Yggdrasil_Profiles::get_for_user( $user->ID );
        }

        $selected = Yggdrasil_Profiles::get_default( $user->ID );
        if ( ! $selected && ! empty( $profiles ) ) {
            $selected = $profiles[0];
        }

        // 多档案登录行为：后台可配置为不自动选择，交由启动器选择。
        $multi_autoselect = class_exists( 'Yggdrasil_Settings' )
            ? Yggdrasil_Settings::get_multi_profile_autoselect()
            : true;
        $multi_autoselect = (bool) apply_filters( 'yggdrasil_multi_profile_autoselect', $multi_autoselect, $profiles, $user->ID );

        if ( ! $multi_autoselect && count( $profiles ) > 1 ) {
            $selected = null;
        }

        /**
         * 允许过滤登录时自动选中的档案。
         *
         * 返回 null 可让多档案用户不自动绑定档案，由启动器选择后再通过 refresh 绑定。
         *
         * @param object|null $selected 默认档案。
         * @param object[]    $profiles 用户全部档案。
         * @param int         $user_id  用户 ID。
         */
        $selected = apply_filters( 'yggdrasil_authenticate_selected_profile', $selected, $profiles, $user->ID );

        if ( ! is_object( $selected ) || empty( $selected->id ) || (int) $selected->user_id !== (int) $user->ID ) {
            $selected = null;
        }

        $access_token = $this->generate_access_token();
        if ( '' === $client_token ) {
            $client_token = $this->generate_client_token();
        }

        if ( ! $this->save_token( $user->ID, $access_token, $client_token, $selected ? (int) $selected->id : 0 ) ) {
            return $this->error_response( 'Unable to store access token', 500 );
        }

        $available_profiles = [];
        foreach ( $profiles as $profile ) {
            $available_profiles[] = $this->profile_summary( $profile );
        }

        $response = [
            'accessToken'       => $access_token,
            'clientToken'       => $client_token,
            'availableProfiles' => $available_profiles,
        ];

        if ( $selected ) {
            $response['selectedProfile'] = $this->profile_summary( $selected );
        }

        $response['user'] = [
            'id'         => $this->get_account_uuid( $user->ID, $selected ),
            'properties' => [],
        ];

        return rest_ensure_response( $response );
    }

    /**
     * POST /authserver/refresh
     */
    public function handle_refresh( $request ) {
        $params = $this->get_request_params( $request );

        $access_token = isset( $params['accessToken'] ) && is_string( $params['accessToken'] ) ? $params['accessToken'] : '';
        $client_token = isset( $params['clientToken'] ) && is_string( $params['clientToken'] ) ? $params['clientToken'] : '';

        if ( '' === $access_token ) {
            return $this->error_response( 'Access token missing', 403 );
        }

        $token_row = $this->get_token_row( $access_token );
        if ( ! $token_row ) {
            return $this->error_response( 'Invalid access token', 403 );
        }

        if ( '' !== $client_token && $token_row->client_token !== $client_token ) {
            return $this->error_response( 'Client token mismatch', 403 );
        }

        $user = get_user_by( 'id', (int) $token_row->user_id );
        if ( ! $user ) {
            return $this->error_response( 'User not found', 500 );
        }

        if ( ! $this->is_user_allowed( $user->ID ) ) {
            return $this->error_response( 'This account is not allowed to use Yggdrasil login.', 403 );
        }

        // 确定新令牌绑定的档案：可选择切换，否则沿用原绑定或默认档案。
        $selected = null;
        if ( isset( $params['selectedProfile'] ) ) {
            $selected_uuid = $this->extract_profile_id( $params['selectedProfile'] );
            if ( '' === $selected_uuid ) {
                return $this->error_response( 'Illegal selected profile', 400 );
            }

            $profile = Yggdrasil_Profiles::get_by_uuid( $selected_uuid );
            if ( ! $profile || (int) $profile->user_id !== (int) $user->ID ) {
                return $this->error_response( 'Selected profile does not belong to user', 403 );
            }
            $selected = $profile;
        } elseif ( (int) $token_row->profile_id > 0 ) {
            $profile = Yggdrasil_Profiles::get( (int) $token_row->profile_id );
            if ( $profile && (int) $profile->user_id === (int) $user->ID ) {
                $selected = $profile;
            }
        }

        // 不再回退绑定默认档案：未绑定令牌刷新后仍保持未绑定，由启动器选择档案。
        // （authenticate 通常已绑定默认档案；过滤 yggdrasil_authenticate_selected_profile 可改为留空。）

        $profiles = Yggdrasil_Profiles::get_for_user( $user->ID );

        // 轮换 access token，旧令牌立即失效。
        $new_access_token = $this->generate_access_token();
        $expires          = gmdate( 'Y-m-d H:i:s', time() + $this->get_token_expiry() );

        global $wpdb;
        $updated = $wpdb->update(
            Yggdrasil_DB::tokens_table(),
            [
                'access_token' => $new_access_token,
                'expires'      => $expires,
                'profile_id'   => $selected ? (int) $selected->id : 0,
            ],
            [ 'id' => (int) $token_row->id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return $this->error_response( 'Unable to refresh access token', 500 );
        }

        $available_profiles = [];
        foreach ( $profiles as $profile ) {
            $available_profiles[] = $this->profile_summary( $profile );
        }

        $response = [
            'accessToken'       => $new_access_token,
            'clientToken'       => $token_row->client_token,
            'availableProfiles' => $available_profiles,
        ];

        if ( $selected ) {
            $response['selectedProfile'] = $this->profile_summary( $selected );
        }

        $response['user'] = [
            'id'         => $this->get_account_uuid( $user->ID, $selected ),
            'properties' => [],
        ];

        return rest_ensure_response( $response );
    }

    /**
     * POST /authserver/validate
     */
    public function handle_validate( $request ) {
        $params = $this->get_request_params( $request );

        $access_token = isset( $params['accessToken'] ) && is_string( $params['accessToken'] ) ? $params['accessToken'] : '';
        $client_token = isset( $params['clientToken'] ) && is_string( $params['clientToken'] ) ? $params['clientToken'] : '';

        if ( '' === $access_token ) {
            return $this->error_response( 'Access token missing', 403 );
        }

        $token_row = $this->get_token_row( $access_token );
        if ( ! $token_row ) {
            return $this->error_response( 'Invalid token', 403 );
        }

        // 用户已被删除时，其令牌视为无效。
        if ( ! get_user_by( 'id', (int) $token_row->user_id ) ) {
            return $this->error_response( 'Invalid token', 403 );
        }

        // 用户不再属于允许登录的用户组时，令牌视为无效。
        if ( ! $this->is_user_allowed( (int) $token_row->user_id ) ) {
            return $this->error_response( 'Invalid token', 403 );
        }

        if ( '' !== $client_token && $token_row->client_token !== $client_token ) {
            return $this->error_response( 'Invalid token', 403 );
        }

        // 204 No Content
        return new WP_REST_Response( null, 204 );
    }

    /**
     * POST /authserver/invalidate
     *
     * 吊销令牌；无论令牌是否存在都返回 204。
     */
    public function handle_invalidate( $request ) {
        $params = $this->get_request_params( $request );

        $access_token = isset( $params['accessToken'] ) && is_string( $params['accessToken'] ) ? $params['accessToken'] : '';

        if ( '' !== $access_token ) {
            global $wpdb;
            $wpdb->delete( Yggdrasil_DB::tokens_table(), [ 'access_token' => $access_token ], [ '%s' ] );
        }

        return new WP_REST_Response( null, 204 );
    }

    /**
     * POST /authserver/signout
     */
    public function handle_signout( $request ) {
        $params = $this->get_request_params( $request );

        $username = isset( $params['username'] ) && is_string( $params['username'] ) ? trim( $params['username'] ) : '';
        $password = isset( $params['password'] ) && is_string( $params['password'] ) ? $params['password'] : '';

        if ( '' === $username || '' === $password ) {
            return $this->error_response( 'Invalid credentials', 403 );
        }

        if ( $this->is_login_throttled( $username ) ) {
            return $this->error_response( 'Invalid credentials', 403 );
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error( $user ) ) {
            $this->record_login_failure( $username );
            return $this->error_response( 'Invalid credentials', 403 );
        }

        $this->clear_login_failures( $username );

        global $wpdb;

        // 删除该用户的所有令牌与加入记录。
        $wpdb->delete( Yggdrasil_DB::tokens_table(), [ 'user_id' => (int) $user->ID ], [ '%d' ] );
        $wpdb->delete( Yggdrasil_DB::joins_table(), [ 'user_id' => (int) $user->ID ], [ '%d' ] );

        // 204 No Content
        return new WP_REST_Response( null, 204 );
    }

    // ---------- 会话服务器端点处理函数 ----------

    /**
     * POST /sessionserver/session/minecraft/join
     */
    public function handle_join( $request ) {
        $params = $this->get_request_params( $request );

        $access_token     = isset( $params['accessToken'] ) && is_string( $params['accessToken'] ) ? $params['accessToken'] : '';
        $selected_profile = isset( $params['selectedProfile'] ) ? $this->extract_profile_id( $params['selectedProfile'] ) : '';
        $server_id        = isset( $params['serverId'] ) && is_string( $params['serverId'] ) ? $params['serverId'] : '';

        if ( '' === $access_token || '' === $selected_profile || '' === $server_id ) {
            return $this->error_response( 'Missing parameters', 403 );
        }

        // 验证 access token。
        $token_row = $this->get_token_row( $access_token );
        if ( ! $token_row ) {
            return $this->error_response( 'Invalid access token', 403 );
        }

        // 用户已被删除时，其令牌视为无效。
        if ( ! get_user_by( 'id', (int) $token_row->user_id ) ) {
            return $this->error_response( 'Invalid access token', 403 );
        }

        // 用户不再属于允许登录的用户组时，令牌视为无效。
        if ( ! $this->is_user_allowed( (int) $token_row->user_id ) ) {
            return $this->error_response( 'Invalid access token', 403 );
        }

        // 档案必须属于令牌对应用户。
        $profile = Yggdrasil_Profiles::get_by_uuid( $selected_profile );
        if ( ! $profile || (int) $profile->user_id !== (int) $token_row->user_id ) {
            return $this->error_response( 'Invalid selected profile', 403 );
        }

        // 令牌已绑定档案时，必须使用同一档案进服。
        if ( (int) $token_row->profile_id > 0 && (int) $token_row->profile_id !== (int) $profile->id ) {
            return $this->error_response( 'Selected profile does not match token', 403 );
        }

        // 令牌尚未绑定档案时，绑定当前选择的档案。
        if ( 0 === (int) $token_row->profile_id ) {
            global $wpdb;
            $wpdb->update(
                Yggdrasil_DB::tokens_table(),
                [ 'profile_id' => (int) $profile->id ],
                [ 'id' => (int) $token_row->id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        global $wpdb;
        $table_joins = Yggdrasil_DB::joins_table();
        $user_id     = (int) $token_row->user_id;

        // 仅覆盖当前用户、当前 serverId 的旧记录，避免未绑定同一 serverId 时误删他人记录。
        $wpdb->delete(
            $table_joins,
            [
                'server_id' => $server_id,
                'user_id'   => $user_id,
            ],
            [ '%s', '%d' ]
        );

        // 顺带清理明显过期的历史记录。
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table_joins WHERE created < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            600
        ) );

        // 限制每用户保留的进服记录数量。
        $max_joins = class_exists( 'Yggdrasil_Settings' )
            ? (int) Yggdrasil_Settings::get_max_joins( $user_id )
            : (int) apply_filters( 'yggdrasil_max_joins_per_user', 20, $user_id );
        if ( $max_joins < 1 ) {
            $max_joins = 1;
        }

        $user_join_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_joins WHERE user_id = %d",
            $user_id
        ) );

        if ( $user_join_count >= $max_joins ) {
            $excess = $user_join_count - $max_joins + 1;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table_joins WHERE user_id = %d ORDER BY created ASC LIMIT %d",
                $user_id,
                $excess
            ) );
        }

        $inserted = $wpdb->insert(
            $table_joins,
            [
                'server_id'  => $server_id,
                'user_id'    => (int) $token_row->user_id,
                'profile_id' => $profile->uuid,
                'created'    => current_time( 'mysql', true ),
            ],
            [ '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return $this->error_response( 'Unable to store session', 500 );
        }

        // 204 No Content
        return new WP_REST_Response( null, 204 );
    }

    /**
     * GET /sessionserver/session/minecraft/hasJoined
     */
    public function handle_has_joined( $request ) {
        $username_param = $request->get_param( 'username' );
        $server_id_param = $request->get_param( 'serverId' );
        $username  = is_string( $username_param ) ? $username_param : '';
        $server_id = is_string( $server_id_param ) ? $server_id_param : '';

        if ( '' === $username || '' === $server_id ) {
            return $this->error_response( 'Missing parameters', 403 );
        }

        global $wpdb;
        $table_joins = Yggdrasil_DB::joins_table();

        $join = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_joins WHERE server_id = %s AND created > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            $server_id,
            self::JOIN_EXPIRY
        ) );

        if ( ! $join ) {
            return new WP_REST_Response( null, 204 );
        }

        $profile = Yggdrasil_Profiles::get_by_uuid( $join->profile_id );
        if ( ! $profile || (int) $profile->user_id !== (int) $join->user_id ) {
            return new WP_REST_Response( null, 204 );
        }

        // 用户已被删除时不再允许进服。
        if ( ! get_user_by( 'id', (int) $join->user_id ) ) {
            return new WP_REST_Response( null, 204 );
        }

        // 用户不再属于允许登录的用户组时不再允许进服。
        if ( ! $this->is_user_allowed( (int) $join->user_id ) ) {
            return new WP_REST_Response( null, 204 );
        }

        // 用户名必须与档案名一致。
        if ( 0 !== strcasecmp( $profile->name, $username ) ) {
            return new WP_REST_Response( null, 204 );
        }

        return rest_ensure_response( [
            'id'         => $profile->uuid,
            'name'       => $profile->name,
            'properties' => $this->get_profile_properties( $profile, true ),
        ] );
    }

    /**
     * GET /sessionserver/session/minecraft/profile/{uuid}
     */
    public function handle_profile( $request ) {
        $uuid = $this->normalize_uuid( $request->get_param( 'uuid' ) );

        if ( '' === $uuid ) {
            return new WP_REST_Response( null, 204 );
        }

        $profile = Yggdrasil_Profiles::get_by_uuid( $uuid );

        if ( ! $profile ) {
            // Yggdrasil 规范：未找到时返回 204 No Content。
            return new WP_REST_Response( null, 204 );
        }

        $unsigned = $request->get_param( 'unsigned' );
        $unsigned = ( null === $unsigned ) ? true : rest_sanitize_boolean( $unsigned );

        return rest_ensure_response( [
            'id'         => $profile->uuid,
            'name'       => $profile->name,
            'properties' => $this->get_profile_properties( $profile, ! $unsigned ),
        ] );
    }

    // ---------- 皮肤文件 ----------

    /**
     * GET /textures/{hash}
     *
     * 直接输出净化后的皮肤 PNG。该接口不经过 JSON 包装，因此在此处直接发送响应头与文件内容。
     */
    public function handle_texture( $request ) {
        $hash = strtolower( (string) $request->get_param( 'hash' ) );
        $path = Yggdrasil_Skins::get_texture_file( $hash );

        if ( '' === $path ) {
            status_header( 404 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Content-Type-Options: nosniff' );
            echo 'Not Found';
            exit;
        }

        $size = @filesize( $path );

        if ( ! headers_sent() ) {
            status_header( 200 );
            header( 'Content-Type: image/png' );
            header( 'Content-Length: ' . ( false === $size ? 0 : (int) $size ) );
            header( 'Cache-Control: public, max-age=31536000, immutable' );
            header( 'X-Content-Type-Options: nosniff' );
        }

        readfile( $path );
        exit;
    }

    // ---------- 辅助函数 ----------

    /**
     * 获取请求参数，兼容 JSON 与表单编码。
     *
     * @param WP_REST_Request $request 请求对象。
     * @return array
     */
    private function get_request_params( $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }
        return is_array( $params ) ? $params : [];
    }

    /**
     * 构建档案摘要（用于 availableProfiles/selectedProfile）。
     *
     * @param object $profile 档案对象。
     * @return array
     */
    private function profile_summary( $profile ) {
        return [
            'id'   => $profile->uuid,
            'name' => $profile->name,
        ];
    }

    /**
     * 获取档案属性（皮肤等），并保留旧版过滤器兼容。
     *
     * @param object $profile 档案对象。
     * @param bool   $signed  是否包含数字签名。
     * @return array
     */
    private function get_profile_properties( $profile, $signed = false ) {
        $properties = Yggdrasil_Skins::get_properties( $profile, $signed );

        /**
         * 允许其他插件添加档案属性（如披风等）。
         *
         * @param array  $properties 属性数组。
         * @param int    $user_id    用户 ID（兼容旧版本）。
         * @param object $profile    档案对象（新参数）。
         */
        return apply_filters( 'yggdrasil_profile_properties', $properties, (int) $profile->user_id, $profile );
    }

    /**
     * 判断用户是否属于允许使用 Yggdrasil 的用户组。
     *
     * @param int $user_id 用户 ID。
     * @return bool
     */
    private function is_user_allowed( $user_id ) {
        if ( class_exists( 'Yggdrasil_Settings' ) ) {
            return Yggdrasil_Settings::is_user_allowed( $user_id );
        }

        return true;
    }

    /**
     * 获取用户账号 UUID（无连字符）。
     *
     * @param int         $user_id 用户 ID。
     * @param object|null $profile 当前选中的档案。
     * @return string
     */
    private function get_account_uuid( $user_id, $profile = null ) {
        // 优先使用与默认档案解耦的账号 UUID。
        $account_uuid = get_user_meta( $user_id, 'ygg_account_uuid', true );

        if ( is_string( $account_uuid ) && '' !== $account_uuid ) {
            $normalized = $this->normalize_uuid( $account_uuid );
            if ( '' !== $normalized ) {
                return $normalized;
            }
        }

        // 兼容旧版本：回退到用户 UUID 元数据。
        $uuid = get_user_meta( $user_id, 'ygg_uuid', true );

        if ( is_string( $uuid ) && '' !== $uuid ) {
            $normalized = $this->normalize_uuid( $uuid );
            if ( '' !== $normalized ) {
                return $normalized;
            }
        }

        if ( $profile && ! empty( $profile->uuid ) ) {
            return $profile->uuid;
        }

        return str_replace( '-', '', wp_generate_uuid4() );
    }

    /**
     * 从 selectedProfile 参数中提取无符号 UUID。
     *
     * @param mixed $selected_profile 字符串或对象/数组。
     * @return string
     */
    private function extract_profile_id( $selected_profile ) {
        if ( is_array( $selected_profile ) && isset( $selected_profile['id'] ) ) {
            return $this->normalize_uuid( $selected_profile['id'] );
        }

        if ( is_object( $selected_profile ) && isset( $selected_profile->id ) ) {
            return $this->normalize_uuid( $selected_profile->id );
        }

        return $this->normalize_uuid( $selected_profile );
    }

    /**
     * 规范化 UUID：接受带/不带连字符、大小写混合，返回 32 位小写十六进制或空串。
     *
     * @param mixed $uuid UUID。
     * @return string
     */
    private function normalize_uuid( $uuid ) {
        if ( ! is_string( $uuid ) ) {
            return '';
        }

        $normalized = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $uuid ) );

        return ( is_string( $normalized ) && 32 === strlen( $normalized ) ) ? $normalized : '';
    }

    /**
     * 生成随机访问令牌。
     *
     * @return string
     */
    private function generate_access_token() {
        return $this->random_hex( 32 ); // 64 字符
    }

    /**
     * 生成随机客户端令牌。
     *
     * @return string
     */
    private function generate_client_token() {
        return $this->random_hex( 16 );
    }

    /**
     * 生成随机十六进制字符串，CSPRNG 不可用时回退。
     *
     * @param int $bytes 随机字节数。
     * @return string
     */
    private function random_hex( $bytes ) {
        $bytes = max( 1, (int) $bytes );

        try {
            return bin2hex( random_bytes( $bytes ) );
        } catch ( \Throwable $e ) {
            // 极端环境下回退到 WordPress 随机数生成器。
            $buffer = '';
            for ( $i = 0; $i < $bytes; $i++ ) {
                $buffer .= chr( wp_rand( 0, 255 ) );
            }

            return bin2hex( $buffer );
        }
    }

    /**
     * 判断用户名是否因失败次数过多被暂时限速。
     *
     * @param string $username 登录名或邮箱。
     * @return bool
     */
    private function is_login_throttled( $username ) {
        // 不存在的用户名不做失败计数，避免匿名请求刷 options 表。
        if ( ! $this->login_user_exists( $username ) ) {
            return false;
        }

        $max = class_exists( 'Yggdrasil_Settings' )
            ? (int) Yggdrasil_Settings::get_login_max_attempts()
            : (int) apply_filters( 'yggdrasil_login_max_attempts', self::LOGIN_MAX_ATTEMPTS );
        if ( $max < 1 ) {
            return false;
        }

        return (int) get_transient( $this->login_failure_key( $username ) ) >= $max;
    }

    /**
     * 记录一次登录失败（仅对已存在用户计数）。
     *
     * @param string $username 登录名或邮箱。
     * @return void
     */
    private function record_login_failure( $username ) {
        if ( ! $this->login_user_exists( $username ) ) {
            return;
        }

        $key   = $this->login_failure_key( $username );
        $count = (int) get_transient( $key );

        set_transient( $key, $count + 1, self::LOGIN_WINDOW );
    }

    /**
     * 判断登录名/邮箱是否存在对应 WordPress 用户。
     *
     * @param string $username 登录名或邮箱。
     * @return bool
     */
    private function login_user_exists( $username ) {
        $username = (string) $username;
        if ( '' === $username ) {
            return false;
        }

        if ( get_user_by( 'login', $username ) ) {
            return true;
        }

        if ( false !== strpos( $username, '@' ) && get_user_by( 'email', $username ) ) {
            return true;
        }

        return false;
    }

    /**
     * 登录成功后清除失败记录。
     *
     * @param string $username 登录名或邮箱。
     * @return void
     */
    private function clear_login_failures( $username ) {
        delete_transient( $this->login_failure_key( $username ) );
    }

    /**
     * 登录失败计数的 transient key（用户名 + 来源 IP，降低互相锁定风险）。
     *
     * @param string $username 登录名或邮箱。
     * @return string
     */
    private function login_failure_key( $username ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return 'ygg_login_fail_' . md5( strtolower( (string) $username ) . '|' . $ip );
    }

    /**
     * 获取令牌有效时长（秒）。
     *
     * @return int
     */
    private function get_token_expiry() {
        if ( class_exists( 'Yggdrasil_Settings' ) ) {
            return max( 1, (int) Yggdrasil_Settings::get_token_expiry_seconds() );
        }

        $expiry = (int) apply_filters( 'yggdrasil_token_expiry', self::TOKEN_EXPIRY );

        return $expiry > 0 ? $expiry : self::TOKEN_EXPIRY;
    }

    /**
     * 保存令牌，并限制每个用户的令牌数量。
     *
     * @param int    $user_id      用户 ID。
     * @param string $access_token 访问令牌。
     * @param string $client_token 客户端令牌。
     * @param int    $profile_id   绑定的档案 ID（0 表示未绑定）。
     */
    private function save_token( $user_id, $access_token, $client_token, $profile_id = 0 ) {
        global $wpdb;

        $table_tokens = Yggdrasil_DB::tokens_table();

        $max_tokens = class_exists( 'Yggdrasil_Settings' )
            ? (int) Yggdrasil_Settings::get_max_tokens( $user_id )
            : (int) apply_filters( 'yggdrasil_max_tokens_per_user', self::MAX_TOKENS_PER_USER, $user_id );
        if ( $max_tokens < 1 ) {
            $max_tokens = 1;
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_tokens WHERE user_id = %d",
            $user_id
        ) );

        // 超出上限时删除最旧的令牌。
        if ( $existing >= $max_tokens ) {
            $excess  = $existing - $max_tokens + 1;
            $old_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM $table_tokens WHERE user_id = %d ORDER BY id ASC LIMIT %d",
                $user_id,
                $excess
            ) );

            foreach ( (array) $old_ids as $old_id ) {
                $wpdb->delete( $table_tokens, [ 'id' => (int) $old_id ], [ '%d' ] );
            }
        }

        $inserted = $wpdb->insert(
            $table_tokens,
            [
                'user_id'      => (int) $user_id,
                'access_token' => $access_token,
                'client_token' => $client_token,
                'profile_id'   => (int) $profile_id,
                'expires'      => gmdate( 'Y-m-d H:i:s', time() + $this->get_token_expiry() ),
            ],
            [ '%d', '%s', '%s', '%d', '%s' ]
        );

        return false !== $inserted;
    }

    /**
     * 根据 access token 查询有效令牌行。
     *
     * @param string $access_token 访问令牌。
     * @return object|null
     */
    private function get_token_row( $access_token ) {
        global $wpdb;

        $table_tokens = Yggdrasil_DB::tokens_table();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_tokens WHERE access_token = %s AND expires > UTC_TIMESTAMP()",
            $access_token
        ) );
    }

    /**
     * 生成符合 Yggdrasil 规范的错误响应。
     *
     * @param string $message 错误信息。
     * @param int    $status  HTTP 状态码。
     * @return WP_REST_Response
     */
    private function error_response( $message, $status = 403 ) {
        $status = (int) $status;

        if ( 403 === $status ) {
            $error_code = 'ForbiddenOperationException';
        } elseif ( 400 === $status ) {
            $error_code = 'IllegalArgumentException';
        } else {
            $error_code = 'InternalServerError';
        }

        // 直接返回 JSON 响应，符合 Yggdrasil 规范的 error/errorMessage 格式。
        return new WP_REST_Response(
            [
                'error'        => $error_code,
                'errorMessage' => $message,
            ],
            $status
        );
    }
}
