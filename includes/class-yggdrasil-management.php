<?php
/**
 * 前台游戏档案管理界面、admin-post 处理与后台设置页。
 *
 * 对应架构文档第 10 节：短代码 [yggdrasil_profiles]、用户级 transient 通知、
 * 激活时自动创建「游戏档案」页面、Settings → Yggdrasil 设置页。
 *
 * @package WordPress-Yggdrasil
 */

defined( 'ABSPATH' ) || exit;

class Yggdrasil_Management {

    /** 前台管理短代码标签。 */
    const SHORTCODE = 'yggdrasil_profiles';

    /** nonce 动作名。 */
    const NONCE_ACTION = 'yggdrasil_manage_profiles';

    /** nonce 字段名。 */
    const NONCE_FIELD = 'yggdrasil_nonce';

    /** 用户通知 transient 前缀（后接用户 ID）。 */
    const NOTICE_TRANSIENT_PREFIX = 'yggdrasil_notice_';

    /** 自动创建的管理页面 ID 选项名。 */
    const OPTION_PAGE_ID = 'ygg_management_page_id';

    /** 用户通知有效期（秒）。 */
    const NOTICE_EXPIRY = 300;

    /** 自动创建的管理页面 slug。 */
    const PAGE_SLUG = 'yggdrasil-profiles';

    /**
     * 注册短代码、admin-post 处理与后台菜单。
     */
    public function __construct() {
        add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
        add_action( 'admin_post_yggdrasil_profile_action', [ $this, 'handle_action' ] );
        add_action( 'admin_post_nopriv_yggdrasil_profile_action', [ $this, 'handle_nopriv' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
    }

    /**
     * 在包含管理短代码的页面上提前入队资源，避免样式在页脚才加载造成闪烁。
     */
    public function maybe_enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_post();
        if ( is_object( $post ) && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            $this->enqueue_assets();
        }
    }

    /**
     * 入队前台管理界面的样式与脚本。
     */
    private function enqueue_assets() {
        wp_enqueue_style(
            'yggdrasil-profiles',
            YGGDRASIL_PLUGIN_URL . 'assets/css/yggdrasil-profiles.css',
            [],
            YGGDRASIL_VERSION
        );
        wp_enqueue_script(
            'yggdrasil-profiles',
            YGGDRASIL_PLUGIN_URL . 'assets/js/yggdrasil-profiles.js',
            [],
            YGGDRASIL_VERSION,
            true
        );
    }

    /**
     * 在「设置」菜单下注册 Yggdrasil 设置页。
     */
    public function register_admin_menu() {
        add_options_page(
            __( 'Yggdrasil', 'wordpress-yggdrasil' ),
            __( 'Yggdrasil', 'wordpress-yggdrasil' ),
            'manage_options',
            'yggdrasil',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * 确保「游戏档案」页面存在，并记录页面 ID。
     *
     * @param bool $force 为 true 时重新校验页面（内容缺失短代码则恢复）。
     * @return int 页面 ID，失败返回 0。
     */
    public static function maybe_create_page( $force = false ) {
        $page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );

        // 选项指向的页面仍然有效时直接复用，避免重复创建。
        if ( $page_id > 0 ) {
            $page = get_post( $page_id );
            if ( self::is_valid_page( $page ) ) {
                if ( $force && false === strpos( (string) $page->post_content, '[' . self::SHORTCODE ) ) {
                    // 强制校验时恢复被误删的短代码内容。
                    wp_update_post(
                        [
                            'ID'           => $page_id,
                            'post_content' => '[' . self::SHORTCODE . ']',
                        ]
                    );
                }
                return $page_id;
            }
        }

        // 按 slug 查找已存在（例如此前手动创建）的页面。
        $existing = get_page_by_path( self::PAGE_SLUG, OBJECT, 'page' );
        if ( self::is_valid_page( $existing ) ) {
            $existing_id = (int) $existing->ID;
            update_option( self::OPTION_PAGE_ID, $existing_id );
            return $existing_id;
        }

        // 创建新页面。
        $new_id = wp_insert_post(
            [
                'post_title'   => '游戏档案',
                'post_content' => '[' . self::SHORTCODE . ']',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => self::PAGE_SLUG,
            ],
            true
        );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            return 0;
        }

        update_option( self::OPTION_PAGE_ID, (int) $new_id );

        return (int) $new_id;
    }

    /**
     * 判断传入对象是否为未删除的页面。
     *
     * @param mixed $page 页面对象或其它值。
     * @return bool
     */
    private static function is_valid_page( $page ) {
        return is_object( $page )
            && isset( $page->post_type, $page->post_status )
            && 'page' === $page->post_type
            && 'publish' === $page->post_status;
    }

    /**
     * 未登录用户访问 admin-post 时的处理：跳转登录页。
     */
    public function handle_nopriv() {
        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = home_url( '/' );
        }
        wp_safe_redirect( wp_login_url( $redirect ) );
        exit;
    }

    /**
     * 处理前台管理表单提交（admin-post.php）。
     */
    public function handle_action() {
        // 只接受 POST 表单提交。
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
            wp_die( esc_html__( '无效的请求方式。', 'wordpress-yggdrasil' ), 405 );
        }

        // 登录校验：未登录时先登录。
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( $this->current_url() ) );
            exit;
        }

        // nonce 校验：失败时由 WordPress 终止请求。
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $task       = isset( $_POST['yggdrasil_task'] ) ? sanitize_key( wp_unslash( $_POST['yggdrasil_task'] ) ) : '';
        $profile_id = isset( $_POST['profile_id'] ) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
        $user_id    = get_current_user_id();

        $profile_name = isset( $_POST['profile_name'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_name'] ) ) : '';
        $model        = isset( $_POST['skin_model'] ) ? sanitize_key( wp_unslash( $_POST['skin_model'] ) ) : '';
        if ( ! in_array( $model, [ 'default', 'slim' ], true ) ) {
            $model = '';
        }

        $result          = null;
        $success_message = '';

        switch ( $task ) {
            case 'create':
                $result          = Yggdrasil_Profiles::create( $user_id, $profile_name );
                $success_message = __( '档案创建成功。', 'wordpress-yggdrasil' );
                break;

            case 'rename':
                $data = [ 'name' => $profile_name ];
                if ( '' !== $model ) {
                    $data['model'] = $model;
                }
                $result          = Yggdrasil_Profiles::update( $profile_id, $user_id, $data );
                $success_message = __( '档案已更新。', 'wordpress-yggdrasil' );
                break;

            case 'set_default':
                $result          = Yggdrasil_Profiles::set_default( $profile_id, $user_id );
                $success_message = __( '已设为默认档案。', 'wordpress-yggdrasil' );
                break;

            case 'delete':
                $result          = Yggdrasil_Profiles::delete( $profile_id, $user_id );
                $success_message = __( '档案已删除。', 'wordpress-yggdrasil' );
                break;

            case 'upload_skin':
                $profile = Yggdrasil_Profiles::get( $profile_id );
                if ( ! $profile || (int) $profile->user_id !== $user_id ) {
                    $result = new WP_Error( 'ygg_forbidden', __( '找不到该档案，或您无权操作。', 'wordpress-yggdrasil' ) );
                    break;
                }
                if ( ! isset( $_FILES['ygg_skin'] ) || ! is_array( $_FILES['ygg_skin'] ) || ! isset( $_FILES['ygg_skin']['error'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['ygg_skin']['error'] ) {
                    $result = new WP_Error( 'ygg_no_file', __( '请选择要上传的 PNG 皮肤文件。', 'wordpress-yggdrasil' ) );
                    break;
                }
                $upload_model = '' !== $model ? $model : $profile->model;
                $result       = Yggdrasil_Skins::upload( $profile, $_FILES['ygg_skin'], $upload_model );
                $success_message = __( '皮肤上传成功。', 'wordpress-yggdrasil' );
                break;

            case 'remove_skin':
                $profile = Yggdrasil_Profiles::get( $profile_id );
                if ( ! $profile || (int) $profile->user_id !== $user_id ) {
                    $result = new WP_Error( 'ygg_forbidden', __( '找不到该档案，或您无权操作。', 'wordpress-yggdrasil' ) );
                    break;
                }
                $result          = Yggdrasil_Skins::remove( $profile );
                $success_message = __( '皮肤已移除。', 'wordpress-yggdrasil' );
                break;

            case 'create_page':
                if ( ! current_user_can( 'manage_options' ) ) {
                    $result = new WP_Error( 'ygg_forbidden', __( '您没有权限创建管理页面。', 'wordpress-yggdrasil' ) );
                    break;
                }
                $created_page_id = self::maybe_create_page( true );
                if ( $created_page_id > 0 ) {
                    $result          = true;
                    $success_message = __( '「游戏档案」页面已就绪。', 'wordpress-yggdrasil' );
                } else {
                    $result = new WP_Error( 'ygg_page_failed', __( '页面创建失败，请检查站点权限或稍后重试。', 'wordpress-yggdrasil' ) );
                }
                break;

            default:
                $result = new WP_Error( 'ygg_unknown_task', __( '未知操作，请刷新页面后重试。', 'wordpress-yggdrasil' ) );
                break;
        }

        if ( is_wp_error( $result ) ) {
            $this->set_notice( 'error', $result->get_error_message() );
        } else {
            $this->set_notice( 'success', $success_message );
        }

        $this->redirect_back();
    }

    /**
     * 短代码 [yggdrasil_profiles]：渲染前台档案管理界面。
     *
     * @param array $atts 短代码属性（暂未使用）。
     * @return string
     */
    public function render_shortcode( $atts = [] ) {
        // 仅在短代码渲染时加载前台资源，避免影响其它页面。
        $this->enqueue_assets();

        if ( ! is_user_logged_in() ) {
            return $this->render_login_notice();
        }

        $user_id  = get_current_user_id();
        $profiles = Yggdrasil_Profiles::get_for_user( $user_id );
        if ( ! is_array( $profiles ) ) {
            $profiles = [];
        }

        $count = count( $profiles );
        $max   = (int) Yggdrasil_Profiles::max_profiles( $user_id );
        if ( $max < 1 ) {
            $max = 1;
        }

        $redirect_to = $this->current_url();
        $form_action = admin_url( 'admin-post.php' );
        $notice      = $this->get_notice();
        $name_length = (int) Yggdrasil_Profiles::MAX_NAME_LENGTH;

        ob_start();
        ?>
        <div class="yggdrasil-profiles">
            <h2 class="yggdrasil-profiles__title"><?php echo esc_html__( '游戏档案', 'wordpress-yggdrasil' ); ?></h2>

            <?php if ( is_array( $notice ) ) : ?>
                <div class="yggdrasil-notice yggdrasil-notice--<?php echo esc_attr( $notice['type'] ); ?>" role="status">
                    <?php echo esc_html( $notice['message'] ); ?>
                </div>
            <?php endif; ?>

            <p class="yggdrasil-profiles__summary">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: 已有档案数量；2: 档案数量上限。 */
                        __( '已创建 %1$d / %2$d 个档案。', 'wordpress-yggdrasil' ),
                        $count,
                        $max
                    )
                );
                ?>
            </p>

            <?php if ( 0 === $count ) : ?>
                <p class="yggdrasil-profiles__empty"><?php echo esc_html__( '还没有游戏档案，请在下方创建第一个档案。', 'wordpress-yggdrasil' ); ?></p>
            <?php else : ?>
                <div class="yggdrasil-profiles__grid">
                    <?php foreach ( $profiles as $profile ) : ?>
                        <?php $this->render_profile_card( $profile, $count, $redirect_to ); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $count < $max ) : ?>
                <section class="yggdrasil-profile-create">
                    <h3 class="yggdrasil-profile-create__title"><?php echo esc_html__( '新建档案', 'wordpress-yggdrasil' ); ?></h3>
                    <p class="yggdrasil-profile-create__hint">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: 还可创建的档案数量。 */
                                __( '还可创建 %d 个档案。', 'wordpress-yggdrasil' ),
                                $max - $count
                            )
                        );
                        ?>
                    </p>
                    <form class="yggdrasil-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
                        <?php $this->render_form_hidden_fields( 'create', 0, $redirect_to ); ?>
                        <p class="yggdrasil-form__row">
                            <label class="yggdrasil-label" for="yggdrasil-new-profile-name"><?php echo esc_html__( '档案名称', 'wordpress-yggdrasil' ); ?></label>
                            <input
                                class="yggdrasil-input"
                                id="yggdrasil-new-profile-name"
                                type="text"
                                name="profile_name"
                                value=""
                                maxlength="<?php echo esc_attr( (string) $name_length ); ?>"
                                pattern="[A-Za-z0-9_]{1,16}"
                                placeholder="<?php echo esc_attr__( '1-16 位字母、数字或下划线', 'wordpress-yggdrasil' ); ?>"
                                autocomplete="off"
                                required
                            />
                        </p>
                        <p class="yggdrasil-form__actions">
                            <button type="submit" class="yggdrasil-button"><?php echo esc_html__( '创建档案', 'wordpress-yggdrasil' ); ?></button>
                        </p>
                    </form>
                </section>
            <?php else : ?>
                <p class="yggdrasil-profiles__limit">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d: 档案数量上限。 */
                            __( '已达到档案数量上限（%d 个）。', 'wordpress-yggdrasil' ),
                            $max
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * 未登录提示与登录链接。
     *
     * @return string
     */
    private function render_login_notice() {
        $login_url = wp_login_url( $this->current_url() );

        return '<div class="yggdrasil-profiles yggdrasil-profiles--login">'
            . '<p class="yggdrasil-notice yggdrasil-notice--info">'
            . esc_html__( '请先登录后再管理游戏档案。', 'wordpress-yggdrasil' )
            . ' <a class="yggdrasil-login-link" href="' . esc_url( $login_url ) . '">'
            . esc_html__( '前往登录', 'wordpress-yggdrasil' )
            . '</a></p></div>';
    }

    /**
     * 渲染单个档案卡片（含重命名、上传皮肤、移除皮肤、设为默认、删除表单）。
     *
     * @param object $profile     档案对象。
     * @param int    $count       当前用户的档案总数。
     * @param string $redirect_to 操作完成后的回跳地址。
     */
    private function render_profile_card( $profile, $count, $redirect_to ) {
        $profile_id = (int) $profile->id;
        $is_default = ! empty( $profile->is_default );
        $skin_url   = Yggdrasil_Skins::get_url( $profile );
        $model      = ( isset( $profile->model ) && 'slim' === $profile->model ) ? 'slim' : 'default';

        $form_action       = admin_url( 'admin-post.php' );
        $name_id           = 'ygg-profile-name-' . $profile_id;
        $rename_model_id   = 'ygg-profile-model-' . $profile_id;
        $upload_model_id   = 'ygg-profile-model-upload-' . $profile_id;
        $name_length       = (int) Yggdrasil_Profiles::MAX_NAME_LENGTH;
        $confirm_message   = sprintf(
            /* translators: %s: 档案名称。 */
            __( '确定要删除档案「%s」吗？此操作不可恢复。', 'wordpress-yggdrasil' ),
            $profile->name
        );
        ?>
        <article class="yggdrasil-profile-card" data-profile-id="<?php echo esc_attr( (string) $profile_id ); ?>">
            <header class="yggdrasil-profile-card__header">
                <h3 class="yggdrasil-profile-card__name"><?php echo esc_html( $profile->name ); ?></h3>
                <?php if ( $is_default ) : ?>
                    <span class="yggdrasil-badge yggdrasil-badge--default"><?php echo esc_html__( '默认档案', 'wordpress-yggdrasil' ); ?></span>
                <?php endif; ?>
            </header>

            <div class="yggdrasil-profile-card__body">
                <div class="yggdrasil-profile-card__skin">
                    <?php if ( $skin_url ) : ?>
                        <img
                            class="yggdrasil-skin-preview"
                            src="<?php echo esc_url( $skin_url ); ?>"
                            alt="<?php echo esc_attr( sprintf( __( '档案「%s」的皮肤预览', 'wordpress-yggdrasil' ), $profile->name ) ); ?>"
                            width="96"
                            height="96"
                            loading="lazy"
                        />
                    <?php else : ?>
                        <div class="yggdrasil-skin-preview yggdrasil-skin-preview--empty"><?php echo esc_html__( '暂无皮肤', 'wordpress-yggdrasil' ); ?></div>
                    <?php endif; ?>
                </div>
                <dl class="yggdrasil-profile-card__meta">
                    <dt><?php echo esc_html__( 'UUID', 'wordpress-yggdrasil' ); ?></dt>
                    <dd><code><?php echo esc_html( $this->format_uuid( $profile->uuid ) ); ?></code></dd>
                    <dt><?php echo esc_html__( '模型', 'wordpress-yggdrasil' ); ?></dt>
                    <dd><?php echo esc_html( $this->model_label( $model ) ); ?></dd>
                </dl>
            </div>

            <div class="yggdrasil-profile-card__forms">
                <form class="yggdrasil-form" method="post" action="<?php echo esc_url( $form_action ); ?>">
                    <?php $this->render_form_hidden_fields( 'rename', $profile_id, $redirect_to ); ?>
                    <p class="yggdrasil-form__row">
                        <label class="yggdrasil-label" for="<?php echo esc_attr( $name_id ); ?>"><?php echo esc_html__( '档案名称', 'wordpress-yggdrasil' ); ?></label>
                        <input
                            class="yggdrasil-input"
                            id="<?php echo esc_attr( $name_id ); ?>"
                            type="text"
                            name="profile_name"
                            value="<?php echo esc_attr( $profile->name ); ?>"
                            maxlength="<?php echo esc_attr( (string) $name_length ); ?>"
                            pattern="[A-Za-z0-9_]{1,16}"
                            autocomplete="off"
                            required
                        />
                    </p>
                    <p class="yggdrasil-form__row">
                        <label class="yggdrasil-label" for="<?php echo esc_attr( $rename_model_id ); ?>"><?php echo esc_html__( '皮肤模型', 'wordpress-yggdrasil' ); ?></label>
                        <?php $this->render_model_select( $rename_model_id, $model ); ?>
                    </p>
                    <p class="yggdrasil-form__actions">
                        <button type="submit" class="yggdrasil-button"><?php echo esc_html__( '保存修改', 'wordpress-yggdrasil' ); ?></button>
                    </p>
                </form>

                <form class="yggdrasil-form yggdrasil-skin-upload" method="post" action="<?php echo esc_url( $form_action ); ?>" enctype="multipart/form-data">
                    <?php $this->render_form_hidden_fields( 'upload_skin', $profile_id, $redirect_to ); ?>
                    <p class="yggdrasil-form__row">
                        <label class="yggdrasil-label" for="<?php echo esc_attr( 'ygg-skin-file-' . $profile_id ); ?>"><?php echo esc_html__( '皮肤文件（PNG，最大 1 MiB）', 'wordpress-yggdrasil' ); ?></label>
                        <input
                            class="yggdrasil-file"
                            id="<?php echo esc_attr( 'ygg-skin-file-' . $profile_id ); ?>"
                            type="file"
                            name="ygg_skin"
                            accept="image/png"
                            data-ygg-skin-input
                        />
                    </p>
                    <div class="yggdrasil-skin-local" data-ygg-skin-local hidden></div>
                    <p class="yggdrasil-skin-error" data-ygg-skin-error hidden></p>
                    <p class="yggdrasil-form__row">
                        <label class="yggdrasil-label" for="<?php echo esc_attr( $upload_model_id ); ?>"><?php echo esc_html__( '皮肤模型', 'wordpress-yggdrasil' ); ?></label>
                        <?php $this->render_model_select( $upload_model_id, $model ); ?>
                    </p>
                    <p class="yggdrasil-form__actions">
                        <button type="submit" class="yggdrasil-button"><?php echo esc_html__( '上传皮肤', 'wordpress-yggdrasil' ); ?></button>
                    </p>
                </form>

                <div class="yggdrasil-profile-card__secondary">
                    <?php if ( ! $is_default ) : ?>
                        <form class="yggdrasil-form yggdrasil-form--inline" method="post" action="<?php echo esc_url( $form_action ); ?>">
                            <?php $this->render_form_hidden_fields( 'set_default', $profile_id, $redirect_to ); ?>
                            <button type="submit" class="yggdrasil-button yggdrasil-button--secondary"><?php echo esc_html__( '设为默认', 'wordpress-yggdrasil' ); ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ( $skin_url ) : ?>
                        <form class="yggdrasil-form yggdrasil-form--inline" method="post" action="<?php echo esc_url( $form_action ); ?>">
                            <?php $this->render_form_hidden_fields( 'remove_skin', $profile_id, $redirect_to ); ?>
                            <button type="submit" class="yggdrasil-button yggdrasil-button--secondary"><?php echo esc_html__( '移除皮肤', 'wordpress-yggdrasil' ); ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ( $count > 1 ) : ?>
                        <form class="yggdrasil-form yggdrasil-form--inline" method="post" action="<?php echo esc_url( $form_action ); ?>">
                            <?php $this->render_form_hidden_fields( 'delete', $profile_id, $redirect_to ); ?>
                            <button
                                type="submit"
                                class="yggdrasil-button yggdrasil-button--danger"
                                data-confirm="<?php echo esc_attr( $confirm_message ); ?>"
                            ><?php echo esc_html__( '删除档案', 'wordpress-yggdrasil' ); ?></button>
                        </form>
                    <?php else : ?>
                        <span class="yggdrasil-hint"><?php echo esc_html__( '至少保留一个档案', 'wordpress-yggdrasil' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }

    /**
     * 渲染皮肤模型下拉框。
     *
     * @param string $field_id 元素 ID。
     * @param string $selected 当前模型（default|slim）。
     */
    private function render_model_select( $field_id, $selected ) {
        $selected = ( 'slim' === $selected ) ? 'slim' : 'default';
        ?>
        <select class="yggdrasil-select" id="<?php echo esc_attr( $field_id ); ?>" name="skin_model">
            <option value="default"<?php echo ( 'default' === $selected ) ? ' selected' : ''; ?>><?php echo esc_html__( '经典（Steve）', 'wordpress-yggdrasil' ); ?></option>
            <option value="slim"<?php echo ( 'slim' === $selected ) ? ' selected' : ''; ?>><?php echo esc_html__( '纤细（Alex）', 'wordpress-yggdrasil' ); ?></option>
        </select>
        <?php
    }

    /**
     * 输出所有管理表单共用的隐藏字段与 nonce。
     *
     * @param string $task        任务名。
     * @param int    $profile_id  档案 ID（新建/创建页面为 0）。
     * @param string $redirect_to 操作完成后的回跳地址。
     */
    private function render_form_hidden_fields( $task, $profile_id, $redirect_to ) {
        ?>
        <input type="hidden" name="action" value="yggdrasil_profile_action" />
        <input type="hidden" name="yggdrasil_task" value="<?php echo esc_attr( $task ); ?>" />
        <input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) (int) $profile_id ); ?>" />
        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
        <?php
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /**
     * 后台设置页：接口地址、短代码、页面状态、统计与公钥状态。
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '您没有权限访问此页面。', 'wordpress-yggdrasil' ) );
        }

        $notice = $this->get_notice();

        $api_root     = rest_url( 'yggdrasil/v1/' );
        $metadata_url = rest_url( 'yggdrasil/v1/' );
        $shortcode    = '[' . self::SHORTCODE . ']';

        $page_id        = (int) get_option( self::OPTION_PAGE_ID, 0 );
        $page           = $page_id > 0 ? get_post( $page_id ) : null;
        $page_exists    = self::is_valid_page( $page );
        $page_url       = $page_exists ? get_permalink( $page_id ) : '';
        $page_edit_url  = $page_exists ? get_edit_post_link( $page_id, 'raw' ) : '';
        $admin_page_url = admin_url( 'options-general.php?page=yggdrasil' );

        global $wpdb;
        $profiles_table = $this->profiles_table();
        // 表名来自插件内部，查询值均通过 prepare() 处理。
        $total_profiles = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$profiles_table}" );
        $total_users    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$profiles_table}" );
        $total_skins    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$profiles_table} WHERE skin_hash <> %s", '' ) );

        $public_key = '';
        if ( class_exists( 'Yggdrasil_Skins' ) && method_exists( 'Yggdrasil_Skins', 'get_public_key' ) ) {
            $public_key = (string) Yggdrasil_Skins::get_public_key();
        }
        $openssl_available = function_exists( 'openssl_pkey_new' );
        ?>
        <div class="wrap yggdrasil-admin">
            <h1><?php echo esc_html__( 'Yggdrasil 设置', 'wordpress-yggdrasil' ); ?></h1>

            <?php $this->render_admin_notice( $notice ); ?>

            <h2><?php echo esc_html__( '接口地址', 'wordpress-yggdrasil' ); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="ygg-api-root"><?php echo esc_html__( 'API 根地址', 'wordpress-yggdrasil' ); ?></label></th>
                        <td>
                            <input type="text" id="ygg-api-root" class="regular-text code" value="<?php echo esc_attr( $api_root ); ?>" readonly="readonly" />
                            <p class="description"><?php echo esc_html__( '填入 Minecraft 客户端的 Yggdrasil 认证服务器地址，可直接选中复制。', 'wordpress-yggdrasil' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ygg-metadata-url"><?php echo esc_html__( '元数据地址', 'wordpress-yggdrasil' ); ?></label></th>
                        <td>
                            <input type="text" id="ygg-metadata-url" class="regular-text code" value="<?php echo esc_attr( $metadata_url ); ?>" readonly="readonly" />
                            <p class="description"><?php echo esc_html__( '对上方地址发送 GET 请求即可获取 Yggdrasil 元数据（含 API 名称与签名公钥）。', 'wordpress-yggdrasil' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ygg-shortcode"><?php echo esc_html__( '短代码', 'wordpress-yggdrasil' ); ?></label></th>
                        <td>
                            <input type="text" id="ygg-shortcode" class="regular-text code" value="<?php echo esc_attr( $shortcode ); ?>" readonly="readonly" />
                            <p class="description"><?php echo esc_html__( '将短代码放入任意页面即可显示游戏档案管理界面。', 'wordpress-yggdrasil' ); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__( '前台管理页面', 'wordpress-yggdrasil' ); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__( '页面状态', 'wordpress-yggdrasil' ); ?></th>
                        <td>
                            <?php if ( $page_exists ) : ?>
                                <p>
                                    <strong><?php echo esc_html__( '已创建', 'wordpress-yggdrasil' ); ?></strong>
                                    （ID：<?php echo esc_html( (string) $page_id ); ?>）
                                </p>
                                <p>
                                    <a class="button" href="<?php echo esc_url( $page_url ); ?>"><?php echo esc_html__( '查看页面', 'wordpress-yggdrasil' ); ?></a>
                                    <?php if ( $page_edit_url ) : ?>
                                        <a class="button" href="<?php echo esc_url( $page_edit_url ); ?>"><?php echo esc_html__( '编辑页面', 'wordpress-yggdrasil' ); ?></a>
                                    <?php endif; ?>
                                </p>
                            <?php else : ?>
                                <p><?php echo esc_html__( '尚未创建「游戏档案」页面，可使用下方按钮自动创建。', 'wordpress-yggdrasil' ); ?></p>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php $this->render_form_hidden_fields( 'create_page', 0, $admin_page_url ); ?>
                                <p>
                                    <button type="submit" class="button button-primary">
                                        <?php
                                        echo esc_html(
                                            $page_exists
                                                ? __( '重新创建管理页面', 'wordpress-yggdrasil' )
                                                : __( '创建管理页面', 'wordpress-yggdrasil' )
                                        );
                                        ?>
                                    </button>
                                </p>
                                <p class="description"><?php echo esc_html__( '页面标题为「游戏档案」，内容为 [yggdrasil_profiles] 短代码。', 'wordpress-yggdrasil' ); ?></p>
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__( '使用统计', 'wordpress-yggdrasil' ); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__( '档案总数', 'wordpress-yggdrasil' ); ?></th>
                        <td><?php echo esc_html( (string) $total_profiles ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( '拥有档案的用户数', 'wordpress-yggdrasil' ); ?></th>
                        <td><?php echo esc_html( (string) $total_users ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( '已上传皮肤的档案数', 'wordpress-yggdrasil' ); ?></th>
                        <td><?php echo esc_html( (string) $total_skins ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__( '皮肤签名密钥', 'wordpress-yggdrasil' ); ?></h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__( '公钥状态', 'wordpress-yggdrasil' ); ?></th>
                        <td>
                            <?php if ( '' !== $public_key ) : ?>
                                <p><?php echo esc_html__( '可用：皮肤纹理将使用 SHA1withRSA 签名。私钥仅保存在数据库中，不会在页面或接口中暴露。', 'wordpress-yggdrasil' ); ?></p>
                                <textarea id="ygg-public-key" class="large-text code" rows="8" readonly="readonly"><?php echo esc_textarea( $public_key ); ?></textarea>
                            <?php elseif ( ! $openssl_available ) : ?>
                                <p><?php echo esc_html__( '不可用：PHP openssl 扩展未启用，皮肤纹理将不带签名，部分客户端可能拒绝加载纹理。', 'wordpress-yggdrasil' ); ?></p>
                            <?php else : ?>
                                <p><?php echo esc_html__( '尚未生成：首次生成纹理属性时会自动创建密钥对。', 'wordpress-yggdrasil' ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 后台页面顶部通知。
     *
     * @param array|null $notice 通知数组。
     */
    private function render_admin_notice( $notice ) {
        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return;
        }
        $type = ( 'error' === $notice['type'] ) ? 'error' : 'success';
        ?>
        <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
            <p><?php echo esc_html( $notice['message'] ); ?></p>
        </div>
        <?php
    }

    /**
     * 读取并删除当前用户的通知 transient。
     *
     * @return array|null
     */
    private function get_notice() {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        $key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient( $key );
        if ( false === $notice ) {
            return null;
        }
        delete_transient( $key );

        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return null;
        }

        return [
            'type'    => ( isset( $notice['type'] ) && 'error' === $notice['type'] ) ? 'error' : 'success',
            'message' => (string) $notice['message'],
        ];
    }

    /**
     * 写入当前用户的通知 transient。
     *
     * @param string $type    success|error。
     * @param string $message 通知文案。
     */
    private function set_notice( $type, $message ) {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $message = is_string( $message ) ? trim( $message ) : '';
        if ( '' === $message ) {
            return;
        }

        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        set_transient(
            $key,
            [
                'type'    => ( 'error' === $type ) ? 'error' : 'success',
                'message' => $message,
            ],
            self::NOTICE_EXPIRY
        );
    }

    /**
     * 按 redirect_to 参数回跳（经过 wp_validate_redirect 校验）。
     */
    private function redirect_back() {
        $redirect_to = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
        $redirect_to = wp_validate_redirect( $redirect_to, home_url( '/' ) );

        wp_safe_redirect( $redirect_to );
        exit;
    }

    /**
     * 获取当前页面 URL，用于表单回跳。
     *
     * @return string
     */
    private function current_url() {
        $permalink = get_permalink();
        if ( is_string( $permalink ) && '' !== $permalink ) {
            return $permalink;
        }

        return home_url( '/' );
    }

    /**
     * 把 32 位无连字符 UUID 格式化为带连字符形式（仅用于展示）。
     *
     * @param string $uuid 档案 UUID。
     * @return string
     */
    private function format_uuid( $uuid ) {
        $uuid = strtolower( (string) $uuid );
        if ( preg_match( '/^[a-f0-9]{32}$/', $uuid ) ) {
            return substr( $uuid, 0, 8 ) . '-' . substr( $uuid, 8, 4 ) . '-' . substr( $uuid, 12, 4 )
                . '-' . substr( $uuid, 16, 4 ) . '-' . substr( $uuid, 20, 12 );
        }

        return $uuid;
    }

    /**
     * 模型标识的中文名称。
     *
     * @param string $model default|slim。
     * @return string
     */
    private function model_label( $model ) {
        return ( 'slim' === $model )
            ? __( '纤细（Alex）', 'wordpress-yggdrasil' )
            : __( '经典（Steve）', 'wordpress-yggdrasil' );
    }

    /**
     * 档案表名；优先使用 Yggdrasil_DB 的接口。
     *
     * @return string
     */
    private function profiles_table() {
        if ( class_exists( 'Yggdrasil_DB' ) && method_exists( 'Yggdrasil_DB', 'profiles_table' ) ) {
            return Yggdrasil_DB::profiles_table();
        }

        global $wpdb;

        return $wpdb->prefix . 'ygg_profiles';
    }
}
