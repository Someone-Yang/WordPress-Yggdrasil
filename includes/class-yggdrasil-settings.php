<?php
/**
 * 插件后台配置项（Settings API）。
 *
 * 将原先只能通过过滤器调整的常用参数暴露到「设置 → Yggdrasil」页面，
 * 同时保留原有过滤器作为最终覆盖手段。
 *
 * @package WordPress-Yggdrasil
 */

defined( 'ABSPATH' ) || exit;

/**
 * 后台配置注册、渲染与读取。
 */
class Yggdrasil_Settings {

    /**
     * 设置页面 slug（与 Yggdrasil_Management 的 add_options_page 一致）。
     */
    const PAGE = 'yggdrasil';

    /**
     * 设置分组。
     */
    const GROUP = 'yggdrasil_settings';

    const OPTION_MAX_PROFILES            = 'yggdrasil_max_profiles';
    const OPTION_MAX_TOKENS              = 'yggdrasil_max_tokens';
    const OPTION_TOKEN_EXPIRY_DAYS       = 'yggdrasil_token_expiry_days';
    const OPTION_LOGIN_MAX_ATTEMPTS      = 'yggdrasil_login_max_attempts';
    const OPTION_MAX_JOINS               = 'yggdrasil_max_joins';
    const OPTION_SIGN_TEXTURES           = 'yggdrasil_sign_textures';
    const OPTION_RSA_KEY_BITS            = 'yggdrasil_rsa_key_bits';
    const OPTION_EXTRA_SKIN_DOMAINS      = 'yggdrasil_extra_skin_domains';
    const OPTION_MULTI_PROFILE_AUTOSELECT = 'yggdrasil_multi_profile_autoselect';

    /**
     * 注册钩子。
     */
    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
    }

    /**
     * 在后台注册设置项、分区与字段。
     */
    public static function register() {
        // 档案与令牌。
        register_setting( self::GROUP, self::OPTION_MAX_PROFILES, [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_profiles' ],
            'default'           => 5,
        ] );
        register_setting( self::GROUP, self::OPTION_MAX_TOKENS, [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_tokens' ],
            'default'           => 10,
        ] );
        register_setting( self::GROUP, self::OPTION_TOKEN_EXPIRY_DAYS, [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_token_expiry_days' ],
            'default'           => 1,
        ] );

        // 登录与进服。
        register_setting( self::GROUP, self::OPTION_LOGIN_MAX_ATTEMPTS, [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_login_attempts' ],
            'default'           => 20,
        ] );
        register_setting( self::GROUP, self::OPTION_MAX_JOINS, [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_max_joins' ],
            'default'           => 20,
        ] );
        register_setting( self::GROUP, self::OPTION_MULTI_PROFILE_AUTOSELECT, [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => '1',
        ] );

        // 皮肤与签名。
        register_setting( self::GROUP, self::OPTION_SIGN_TEXTURES, [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_checkbox' ],
            'default'           => '1',
        ] );
        register_setting( self::GROUP, self::OPTION_RSA_KEY_BITS, [
            'type'              => 'integer',
            'sanitize_callback' => [ __CLASS__, 'sanitize_rsa_bits' ],
            'default'           => 2048,
        ] );
        register_setting( self::GROUP, self::OPTION_EXTRA_SKIN_DOMAINS, [
            'type'              => 'string',
            'sanitize_callback' => [ __CLASS__, 'sanitize_skin_domains' ],
            'default'           => '',
        ] );

        add_settings_section(
            'yggdrasil_section_profiles',
            '档案与令牌',
            [ __CLASS__, 'render_profiles_section_intro' ],
            self::PAGE
        );
        add_settings_section(
            'yggdrasil_section_login',
            '登录与进服',
            [ __CLASS__, 'render_login_section_intro' ],
            self::PAGE
        );
        add_settings_section(
            'yggdrasil_section_skin',
            '皮肤与签名',
            [ __CLASS__, 'render_skin_section_intro' ],
            self::PAGE
        );

        add_settings_field(
            self::OPTION_MAX_PROFILES,
            '每用户档案数量上限',
            [ __CLASS__, 'render_number_field' ],
            self::PAGE,
            'yggdrasil_section_profiles',
            [
                'option'      => self::OPTION_MAX_PROFILES,
                'min'         => 1,
                'max'         => 100,
                'default'     => 5,
                'description' => '每个 WordPress 用户最多可创建的游戏档案数量。',
            ]
        );
        add_settings_field(
            self::OPTION_MAX_TOKENS,
            '每用户令牌数量上限',
            [ __CLASS__, 'render_number_field' ],
            self::PAGE,
            'yggdrasil_section_profiles',
            [
                'option'      => self::OPTION_MAX_TOKENS,
                'min'         => 1,
                'max'         => 100,
                'default'     => 10,
                'description' => '同一用户保留的登录令牌数量，超出时自动吊销最旧的令牌。',
            ]
        );
        add_settings_field(
            self::OPTION_TOKEN_EXPIRY_DAYS,
            '令牌有效期（天）',
            [ __CLASS__, 'render_number_field' ],
            self::PAGE,
            'yggdrasil_section_profiles',
            [
                'option'      => self::OPTION_TOKEN_EXPIRY_DAYS,
                'min'         => 1,
                'max'         => 365,
                'default'     => 1,
                'description' => '登录令牌的有效天数，过期后需要重新登录。',
            ]
        );

        add_settings_field(
            self::OPTION_LOGIN_MAX_ATTEMPTS,
            '登录失败次数上限',
            [ __CLASS__, 'render_number_field' ],
            self::PAGE,
            'yggdrasil_section_login',
            [
                'option'      => self::OPTION_LOGIN_MAX_ATTEMPTS,
                'min'         => 0,
                'max'         => 100,
                'default'     => 20,
                'description' => '同一用户名 + IP 在 15 分钟内允许的失败次数；填 0 表示禁用登录限速。',
            ]
        );
        add_settings_field(
            self::OPTION_MAX_JOINS,
            '每用户进服记录上限',
            [ __CLASS__, 'render_number_field' ],
            self::PAGE,
            'yggdrasil_section_login',
            [
                'option'      => self::OPTION_MAX_JOINS,
                'min'         => 1,
                'max'         => 100,
                'default'     => 20,
                'description' => '同一用户保留的进服会话记录数量，超出时清理最旧的记录。',
            ]
        );
        add_settings_field(
            self::OPTION_MULTI_PROFILE_AUTOSELECT,
            '多档案登录行为',
            [ __CLASS__, 'render_checkbox_field' ],
            self::PAGE,
            'yggdrasil_section_login',
            [
                'option'      => self::OPTION_MULTI_PROFILE_AUTOSELECT,
                'default'     => 1,
                'label'       => '登录时自动选择默认档案',
                'description' => '关闭后，拥有多个档案的用户登录时不自动绑定档案，交由启动器选择（单档案用户仍会自动选定）。',
            ]
        );

        add_settings_field(
            self::OPTION_SIGN_TEXTURES,
            '皮肤材质签名',
            [ __CLASS__, 'render_checkbox_field' ],
            self::PAGE,
            'yggdrasil_section_skin',
            [
                'option'      => self::OPTION_SIGN_TEXTURES,
                'default'     => 1,
                'label'       => '为 textures 属性生成 SHA1withRSA 签名',
                'description' => '需要 PHP openssl 扩展；关闭后客户端可能拒绝加载皮肤。',
            ]
        );
        add_settings_field(
            self::OPTION_RSA_KEY_BITS,
            '签名密钥位数',
            [ __CLASS__, 'render_rsa_bits_field' ],
            self::PAGE,
            'yggdrasil_section_skin',
            [
                'option'      => self::OPTION_RSA_KEY_BITS,
                'default'     => 2048,
                'description' => '仅影响尚未生成密钥的站点；已生成的密钥不会被自动替换。',
            ]
        );
        add_settings_field(
            self::OPTION_EXTRA_SKIN_DOMAINS,
            '额外皮肤域名',
            [ __CLASS__, 'render_skin_domains_field' ],
            self::PAGE,
            'yggdrasil_section_skin',
            [
                'option'      => self::OPTION_EXTRA_SKIN_DOMAINS,
                'default'     => '',
                'description' => '每行一个域名，会自动附加到 API 元数据的 skinDomains。站点主域名与上传域名已自动包含。',
            ]
        );
    }

    // ---------- 渲染 ----------

    /**
     * 渲染配置表单（在「设置 → Yggdrasil」页面调用）。
     */
    public static function render_form() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
            <?php
            settings_fields( self::GROUP );
            do_settings_sections( self::PAGE );
            submit_button( '保存配置' );
            ?>
        </form>
        <?php
    }

    /**
     * 档案与令牌分区说明。
     */
    public static function render_profiles_section_intro() {
        echo '<p>控制每个用户可拥有的游戏档案数量、登录令牌数量与令牌有效期。</p>';
    }

    /**
     * 登录与进服分区说明。
     */
    public static function render_login_section_intro() {
        echo '<p>控制登录失败限速、进服会话记录与多档案登录时的默认选择行为。</p>';
    }

    /**
     * 皮肤与签名分区说明。
     */
    public static function render_skin_section_intro() {
        echo '<p>控制皮肤材质属性签名与 API 元数据中的皮肤域名白名单。</p>';
    }

    /**
     * 数字输入框。
     *
     * @param array $args 字段参数。
     */
    public static function render_number_field( $args ) {
        $option = isset( $args['option'] ) ? $args['option'] : '';
        $min    = isset( $args['min'] ) ? (int) $args['min'] : 0;
        $max    = isset( $args['max'] ) ? (int) $args['max'] : 100;
        $value  = self::get_int_option( $option, isset( $args['default'] ) ? (int) $args['default'] : 0, $min, $max );
        ?>
        <input
            type="number"
            id="<?php echo esc_attr( $option ); ?>"
            name="<?php echo esc_attr( $option ); ?>"
            value="<?php echo esc_attr( (string) $value ); ?>"
            min="<?php echo esc_attr( (string) $min ); ?>"
            max="<?php echo esc_attr( (string) $max ); ?>"
            step="1"
            class="small-text"
        />
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * 复选框。
     *
     * @param array $args 字段参数。
     */
    public static function render_checkbox_field( $args ) {
        $option = isset( $args['option'] ) ? $args['option'] : '';
        $value  = self::get_bool_option( $option, ! empty( $args['default'] ) );
        ?>
        <input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0" />
        <label for="<?php echo esc_attr( $option ); ?>">
            <input
                type="checkbox"
                id="<?php echo esc_attr( $option ); ?>"
                name="<?php echo esc_attr( $option ); ?>"
                value="1"
                <?php checked( $value ); ?>
            />
            <?php echo esc_html( isset( $args['label'] ) ? $args['label'] : '' ); ?>
        </label>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * RSA 位数下拉框。
     *
     * @param array $args 字段参数。
     */
    public static function render_rsa_bits_field( $args ) {
        $option = isset( $args['option'] ) ? $args['option'] : self::OPTION_RSA_KEY_BITS;
        $value  = self::get_choice_option( $option, [ 1024, 2048, 4096 ], 2048 );
        $labels = [
            1024 => '1024 位（兼容性最好，安全性较低）',
            2048 => '2048 位（推荐）',
            4096 => '4096 位（安全性最高，生成较慢）',
        ];
        ?>
        <select id="<?php echo esc_attr( $option ); ?>" name="<?php echo esc_attr( $option ); ?>">
            <?php foreach ( $labels as $bits => $label ) : ?>
                <option value="<?php echo esc_attr( (string) $bits ); ?>" <?php selected( $value, $bits ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * 额外皮肤域名文本域。
     *
     * @param array $args 字段参数。
     */
    public static function render_skin_domains_field( $args ) {
        $option = isset( $args['option'] ) ? $args['option'] : self::OPTION_EXTRA_SKIN_DOMAINS;
        $value  = (string) get_option( $option, '' );
        ?>
        <textarea
            id="<?php echo esc_attr( $option ); ?>"
            name="<?php echo esc_attr( $option ); ?>"
            rows="4"
            class="large-text code"
            placeholder="example.com&#10;.example.com&#10;*.cdn.example.com"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    // ---------- 读取（供其他模块使用，保留过滤器覆盖） ----------

    /**
     * 每用户档案数量上限。
     *
     * @param int $user_id 用户 ID。
     * @return int
     */
    public static function get_max_profiles( $user_id = 0 ) {
        $value = self::get_int_option( self::OPTION_MAX_PROFILES, 5, 1, 100 );

        return (int) apply_filters( 'yggdrasil_max_profiles_per_user', $value, absint( $user_id ) );
    }

    /**
     * 每用户令牌数量上限。
     *
     * @param int $user_id 用户 ID。
     * @return int
     */
    public static function get_max_tokens( $user_id = 0 ) {
        $value = self::get_int_option( self::OPTION_MAX_TOKENS, 10, 1, 100 );

        return (int) apply_filters( 'yggdrasil_max_tokens_per_user', $value, absint( $user_id ) );
    }

    /**
     * 令牌有效期（秒）。
     *
     * @return int
     */
    public static function get_token_expiry_seconds() {
        $days    = self::get_int_option( self::OPTION_TOKEN_EXPIRY_DAYS, 1, 1, 365 );
        $seconds = $days * DAY_IN_SECONDS;

        return (int) apply_filters( 'yggdrasil_token_expiry', $seconds );
    }

    /**
     * 登录失败次数上限；0 表示禁用限速。
     *
     * @return int
     */
    public static function get_login_max_attempts() {
        $value = self::get_int_option( self::OPTION_LOGIN_MAX_ATTEMPTS, 20, 0, 100 );

        return (int) apply_filters( 'yggdrasil_login_max_attempts', $value );
    }

    /**
     * 每用户进服记录上限。
     *
     * @param int $user_id 用户 ID。
     * @return int
     */
    public static function get_max_joins( $user_id = 0 ) {
        $value = self::get_int_option( self::OPTION_MAX_JOINS, 20, 1, 100 );

        return (int) apply_filters( 'yggdrasil_max_joins_per_user', $value, absint( $user_id ) );
    }

    /**
     * 皮肤签名 RSA 位数。
     *
     * @return int
     */
    public static function get_rsa_key_bits() {
        $value = self::get_choice_option( self::OPTION_RSA_KEY_BITS, [ 1024, 2048, 4096 ], 2048 );

        return (int) apply_filters( 'yggdrasil_rsa_key_bits', $value );
    }

    /**
     * 是否对皮肤材质签名（不应用过滤器，由调用方带档案参数应用）。
     *
     * @return bool
     */
    public static function get_sign_textures() {
        return self::get_bool_option( self::OPTION_SIGN_TEXTURES, true );
    }

    /**
     * 多档案登录时是否自动选择默认档案（不应用过滤器，由调用方应用）。
     *
     * @return bool
     */
    public static function get_multi_profile_autoselect() {
        return self::get_bool_option( self::OPTION_MULTI_PROFILE_AUTOSELECT, true );
    }

    /**
     * 额外皮肤域名列表。
     *
     * @return string[]
     */
    public static function get_extra_skin_domains() {
        $raw = (string) get_option( self::OPTION_EXTRA_SKIN_DOMAINS, '' );
        if ( '' === trim( $raw ) ) {
            return [];
        }

        $lines = preg_split( '/[\r\n,]+/', $raw );
        if ( ! is_array( $lines ) ) {
            return [];
        }

        $domains = [];
        foreach ( $lines as $line ) {
            $domain = strtolower( trim( (string) $line ) );
            if ( '' !== $domain ) {
                $domains[] = $domain;
            }
        }

        return array_values( array_unique( $domains ) );
    }

    // ---------- 清理回调 ----------

    public static function sanitize_max_profiles( $value ) {
        return self::clamp_int( $value, 1, 100, 5 );
    }

    public static function sanitize_max_tokens( $value ) {
        return self::clamp_int( $value, 1, 100, 10 );
    }

    public static function sanitize_token_expiry_days( $value ) {
        return self::clamp_int( $value, 1, 365, 1 );
    }

    public static function sanitize_login_attempts( $value ) {
        return self::clamp_int( $value, 0, 100, 20 );
    }

    public static function sanitize_max_joins( $value ) {
        return self::clamp_int( $value, 1, 100, 20 );
    }

    public static function sanitize_rsa_bits( $value ) {
        $value = (int) $value;

        return in_array( $value, [ 1024, 2048, 4096 ], true ) ? $value : 2048;
    }

    public static function sanitize_checkbox( $value ) {
        return ( '1' === (string) $value || 'on' === $value || true === $value ) ? '1' : '0';
    }

    public static function sanitize_skin_domains( $value ) {
        $lines = preg_split( '/[\r\n,]+/', (string) $value );
        if ( ! is_array( $lines ) ) {
            return '';
        }

        $domains = [];
        foreach ( $lines as $line ) {
            $domain = strtolower( trim( sanitize_text_field( (string) $line ) ) );
            if ( '' === $domain ) {
                continue;
            }

            // 允许 *、example.com、.example.com、*.cdn.example.com 这类规则。
            if ( '*' !== $domain && ! preg_match( '/^\.?[a-z0-9*.-]{1,253}$/', $domain ) ) {
                continue;
            }

            $domains[] = $domain;
        }

        $domains = array_values( array_unique( $domains ) );

        return implode( "\n", array_slice( $domains, 0, 50 ) );
    }

    // ---------- 内部辅助 ----------

    /**
     * 读取整数选项并限制范围。
     *
     * @param string $option  选项名。
     * @param int    $default 默认值。
     * @param int    $min     最小值。
     * @param int    $max     最大值。
     * @return int
     */
    private static function get_int_option( $option, $default, $min, $max ) {
        $value = get_option( $option, $default );
        if ( ! is_numeric( $value ) ) {
            $value = $default;
        }

        return max( (int) $min, min( (int) $max, (int) $value ) );
    }

    /**
     * 读取布尔选项。
     *
     * @param string $option  选项名。
     * @param bool   $default 默认值。
     * @return bool
     */
    private static function get_bool_option( $option, $default ) {
        $value = get_option( $option, $default ? '1' : '0' );

        return in_array( (string) $value, [ '1', 'true', 'on', 'yes' ], true );
    }

    /**
     * 读取枚举型整数选项。
     *
     * @param string $option   选项名。
     * @param int[]  $allowed  允许值。
     * @param int    $default  默认值。
     * @return int
     */
    private static function get_choice_option( $option, $allowed, $default ) {
        $value = (int) get_option( $option, $default );

        return in_array( $value, $allowed, true ) ? $value : (int) $default;
    }

    /**
     * 整数范围清理。
     *
     * @param mixed $value   输入值。
     * @param int   $min     最小值。
     * @param int   $max     最大值。
     * @param int   $default 非法输入时的默认值。
     * @return int
     */
    private static function clamp_int( $value, $min, $max, $default ) {
        if ( ! is_numeric( $value ) ) {
            return (int) $default;
        }

        return max( (int) $min, min( (int) $max, (int) $value ) );
    }
}
