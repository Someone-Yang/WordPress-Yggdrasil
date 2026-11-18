<?php
/**
 * 后台用户管理页面的游戏档案管理。
 *
 * 在「用户 → 编辑用户」页面为管理员提供档案增删改、默认档案与皮肤管理，
 * 并在用户列表增加档案数量列。
 *
 * @package WordPress-Yggdrasil
 */

defined( 'ABSPATH' ) || exit;

/**
 * 后台用户档案管理界面与处理。
 */
class Yggdrasil_Admin_Profiles {

    const NONCE_ACTION = 'yggdrasil_admin_profiles';
    const NONCE_FIELD  = 'yggdrasil_admin_nonce';
    const NOTICE_PREFIX = 'yggdrasil_admin_notice_';

    /**
     * 注册钩子。
     */
    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'render_user_section' ] );
        add_action( 'edit_user_profile', [ $this, 'render_user_section' ] );
        add_action( 'admin_post_yggdrasil_admin_profile_action', [ $this, 'handle_action' ] );
        add_filter( 'manage_users_columns', [ $this, 'add_profile_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_profile_column' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * 在用户编辑页面入队皮肤 3D 预览资源。
     *
     * @param string $hook_suffix 当前后台页面。
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( ! in_array( $hook_suffix, [ 'profile.php', 'user-edit.php' ], true ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( class_exists( 'Yggdrasil_Skins' ) ) {
            Yggdrasil_Skins::enqueue_viewer_assets();
        }
    }

    /**
     * 在用户编辑页面渲染档案管理区块。
     *
     * @param WP_User $user 正在编辑的用户。
     */
    public function render_user_section( $user ) {
        if ( ! current_user_can( 'manage_options' ) || ! $user instanceof WP_User ) {
            return;
        }

        $profiles = Yggdrasil_Profiles::get_for_user( $user->ID );
        $max      = Yggdrasil_Profiles::max_profiles( $user->ID );
        $count    = count( $profiles );
        $notice   = $this->get_notice();
        $form_url = admin_url( 'admin-post.php' );
        ?>
        <h2 id="yggdrasil-profiles">Yggdrasil 游戏档案</h2>
        <p>在此管理该用户的 Minecraft 游戏档案。档案 UUID 是玩家身份标识；重命名不会改变 UUID 或玩家数据。</p>
        <p><strong>注意：</strong>修改档案 UUID 会让服务器上按旧 UUID 保存的玩家数据（背包、末影箱、统计等）不再与该玩家关联。
        从离线验证迁移过来的服务器可用「改为离线 UUID」按档案名称生成与原离线 UUID 一致的值。</p>

        <?php if ( is_array( $notice ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['message'] ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( empty( $profiles ) ) : ?>
            <p><em>该用户还没有游戏档案。</em></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">名称 / UUID</th>
                        <th scope="col">默认</th>
                        <th scope="col">模型</th>
                        <th scope="col">皮肤</th>
                        <th scope="col">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $profiles as $profile ) : ?>
                        <?php
                        $profile_id = (int) $profile->id;
                        $skin_url   = Yggdrasil_Skins::get_url( $profile );
                        $model      = ( 'slim' === $profile->model ) ? 'slim' : 'default';
                        $name_id    = 'ygg-admin-name-' . $profile_id;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $profile->name ); ?></strong><br />
                                <code><?php echo esc_html( $this->format_uuid( $profile->uuid ) ); ?></code>
                            </td>
                            <td>
                                <?php if ( $profile->is_default ) : ?>
                                    <span class="dashicons dashicons-yes" aria-label="默认档案"></span>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( 'slim' === $model ? '纤细（Alex）' : '经典（Steve）' ); ?></td>
                            <td>
                                <?php if ( $skin_url ) : ?>
                                    <?php echo Yggdrasil_Skins::get_viewer_html( $profile, 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已在方法内部转义。 ?>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="margin-bottom:8px;">
                                    <?php $this->hidden_fields( 'rename', $user->ID, $profile_id ); ?>
                                    <input type="text" name="admin_profile_name" value="<?php echo esc_attr( $profile->name ); ?>" maxlength="16" pattern="[A-Za-z0-9_]{1,16}" required class="regular-text" style="max-width:140px;" />
                                    <select name="admin_skin_model">
                                        <option value="default" <?php selected( $model, 'default' ); ?>>经典</option>
                                        <option value="slim" <?php selected( $model, 'slim' ); ?>>纤细</option>
                                    </select>
                                    <button type="submit" class="button">保存</button>
                                </form>

                                <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="margin-bottom:8px;" onsubmit="return confirm('<?php echo esc_js( '确定修改该档案的 UUID 吗？修改后服务器上按旧 UUID 保存的玩家数据将不再与该玩家关联。' ); ?>');">
                                    <?php $this->hidden_fields( 'change_uuid', $user->ID, $profile_id ); ?>
                                    <input type="text" name="admin_profile_uuid" value="<?php echo esc_attr( $this->format_uuid( $profile->uuid ) ); ?>" maxlength="36" class="regular-text code" style="max-width:260px;" />
                                    <button type="submit" class="button">保存 UUID</button>
                                </form>

                                <form method="post" action="<?php echo esc_url( $form_url ); ?>" enctype="multipart/form-data" style="margin-bottom:8px;">
                                    <?php $this->hidden_fields( 'upload_skin', $user->ID, $profile_id ); ?>
                                    <input type="file" name="ygg_admin_skin" accept="image/png" required />
                                    <select name="admin_skin_model">
                                        <option value="default" <?php selected( $model, 'default' ); ?>>经典</option>
                                        <option value="slim" <?php selected( $model, 'slim' ); ?>>纤细</option>
                                    </select>
                                    <button type="submit" class="button">上传皮肤</button>
                                </form>

                                <div>
                                    <?php if ( ! $profile->is_default ) : ?>
                                        <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;">
                                            <?php $this->hidden_fields( 'set_default', $user->ID, $profile_id ); ?>
                                            <button type="submit" class="button button-small">设为默认</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ( $skin_url ) : ?>
                                        <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;">
                                            <?php $this->hidden_fields( 'remove_skin', $user->ID, $profile_id ); ?>
                                            <button type="submit" class="button button-small">移除皮肤</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( '确定把该档案的 UUID 改为按名称生成的离线 UUID 吗？' ); ?>');">
                                        <?php $this->hidden_fields( 'offline_uuid', $user->ID, $profile_id ); ?>
                                        <button type="submit" class="button button-small">改为离线 UUID</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( $form_url ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( sprintf( '确定删除档案「%s」吗？此操作不可恢复。', $profile->name ) ); ?>');">
                                        <?php $this->hidden_fields( 'delete', $user->ID, $profile_id ); ?>
                                        <button type="submit" class="button button-small button-link-delete">删除档案</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( $count < $max ) : ?>
            <h3>新建档案</h3>
            <form method="post" action="<?php echo esc_url( $form_url ); ?>">
                <?php $this->hidden_fields( 'create', $user->ID, 0 ); ?>
                <input type="text" name="admin_profile_name" value="" maxlength="16" pattern="[A-Za-z0-9_]{1,16}" placeholder="1-16 位字母、数字或下划线" required class="regular-text" />
                <button type="submit" class="button button-primary">创建档案</button>
                <p class="description">该用户当前有 <?php echo esc_html( (string) $count ); ?> / <?php echo esc_html( (string) $max ); ?> 个档案。</p>
            </form>
        <?php else : ?>
            <p class="description">该用户已达到档案数量上限（<?php echo esc_html( (string) $max ); ?> 个）。</p>
        <?php endif; ?>
        <?php
    }

    /**
     * 输出后台管理表单的公共隐藏字段与 nonce。
     *
     * @param string $task           任务名。
     * @param int    $target_user_id 目标用户 ID。
     * @param int    $profile_id     档案 ID。
     */
    private function hidden_fields( $task, $target_user_id, $profile_id = 0 ) {
        ?>
        <input type="hidden" name="action" value="yggdrasil_admin_profile_action" />
        <input type="hidden" name="yggdrasil_task" value="<?php echo esc_attr( $task ); ?>" />
        <input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) (int) $target_user_id ); ?>" />
        <input type="hidden" name="profile_id" value="<?php echo esc_attr( (string) (int) $profile_id ); ?>" />
        <?php
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /**
     * 处理后台用户档案管理表单。
     */
    public function handle_action() {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '您没有权限管理该用户的档案。', 'wordpress-yggdrasil' ), 403 );
        }

        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
            wp_die( esc_html__( '无效的请求方式。', 'wordpress-yggdrasil' ), 405 );
        }

        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $task           = isset( $_POST['yggdrasil_task'] ) ? sanitize_key( wp_unslash( $_POST['yggdrasil_task'] ) ) : '';
        $target_user_id = isset( $_POST['target_user_id'] ) ? absint( wp_unslash( $_POST['target_user_id'] ) ) : 0;
        $profile_id     = isset( $_POST['profile_id'] ) ? absint( wp_unslash( $_POST['profile_id'] ) ) : 0;
        $target_user    = get_user_by( 'id', $target_user_id );

        if ( ! $target_user ) {
            $this->set_notice( 'error', '目标用户不存在。' );
            $this->redirect_back( $target_user_id );
        }

        $name  = isset( $_POST['admin_profile_name'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_profile_name'] ) ) : '';
        $model = isset( $_POST['admin_skin_model'] ) ? sanitize_key( wp_unslash( $_POST['admin_skin_model'] ) ) : '';
        if ( ! in_array( $model, [ 'default', 'slim' ], true ) ) {
            $model = '';
        }

        $result          = null;
        $success_message = '';

        switch ( $task ) {
            case 'create':
                $result          = Yggdrasil_Profiles::create( $target_user_id, $name );
                $success_message = '档案创建成功。';
                break;

            case 'rename':
                $data = [ 'name' => $name ];
                if ( '' !== $model ) {
                    $data['model'] = $model;
                }
                $result          = Yggdrasil_Profiles::update( $profile_id, $target_user_id, $data );
                $success_message = '档案已更新。';
                break;

            case 'change_uuid':
                $uuid            = isset( $_POST['admin_profile_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_profile_uuid'] ) ) : '';
                $result          = Yggdrasil_Profiles::update_uuid( $profile_id, $target_user_id, $uuid );
                $success_message = '档案 UUID 已更新。';
                break;

            case 'offline_uuid':
                $profile = Yggdrasil_Profiles::get( $profile_id );
                if ( ! $profile || (int) $profile->user_id !== (int) $target_user_id ) {
                    $result = new WP_Error( 'ygg_forbidden', '找不到该档案，或档案不属于该用户。' );
                    break;
                }
                $offline_uuid = Yggdrasil_Profiles::generate_offline_uuid( $profile->name );
                if ( '' === $offline_uuid ) {
                    $result = new WP_Error( 'ygg_invalid_uuid', '无法根据该档案名称生成离线 UUID。' );
                    break;
                }
                $result          = Yggdrasil_Profiles::update_uuid( $profile_id, $target_user_id, $offline_uuid );
                $success_message = '档案 UUID 已改为离线 UUID：' . $offline_uuid;
                break;

            case 'set_default':
                $result          = Yggdrasil_Profiles::set_default( $profile_id, $target_user_id );
                $success_message = '已设为默认档案。';
                break;

            case 'delete':
                $result          = Yggdrasil_Profiles::delete( $profile_id, $target_user_id, true );
                $success_message = '档案已删除。';
                break;

            case 'upload_skin':
                $profile = Yggdrasil_Profiles::get( $profile_id );
                if ( ! $profile || (int) $profile->user_id !== (int) $target_user_id ) {
                    $result = new WP_Error( 'ygg_forbidden', '找不到该档案，或档案不属于该用户。' );
                    break;
                }
                if ( ! isset( $_FILES['ygg_admin_skin'] ) || ! is_array( $_FILES['ygg_admin_skin'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['ygg_admin_skin']['error'] ) {
                    $result = new WP_Error( 'ygg_no_file', '请选择要上传的 PNG 皮肤文件。' );
                    break;
                }
                $upload_model    = '' !== $model ? $model : $profile->model;
                $result          = Yggdrasil_Skins::upload( $profile, $_FILES['ygg_admin_skin'], $upload_model );
                $success_message = '皮肤上传成功。';
                break;

            case 'remove_skin':
                $profile = Yggdrasil_Profiles::get( $profile_id );
                if ( ! $profile || (int) $profile->user_id !== (int) $target_user_id ) {
                    $result = new WP_Error( 'ygg_forbidden', '找不到该档案，或档案不属于该用户。' );
                    break;
                }
                $result          = Yggdrasil_Skins::remove( $profile );
                $success_message = '皮肤已移除。';
                break;

            default:
                $result = new WP_Error( 'ygg_unknown_task', '未知操作。' );
                break;
        }

        if ( is_wp_error( $result ) ) {
            $this->set_notice( 'error', $result->get_error_message() );
        } else {
            $this->set_notice( 'success', $success_message );
        }

        $this->redirect_back( $target_user_id );
    }

    /**
     * 在用户列表添加「Yggdrasil 档案」列。
     *
     * @param string[] $columns 列名。
     * @return string[]
     */
    public function add_profile_column( $columns ) {
        $columns['yggdrasil_profiles'] = 'Yggdrasil 档案';

        return $columns;
    }

    /**
     * 输出用户列表中的档案数量与编辑链接。
     *
     * @param string $value   单元格内容。
     * @param string $column  列名。
     * @param int    $user_id 用户 ID。
     * @return string
     */
    public function render_profile_column( $value, $column, $user_id ) {
        if ( 'yggdrasil_profiles' !== $column ) {
            return $value;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        $count = Yggdrasil_Profiles::count_for_user( (int) $user_id );

        return '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#yggdrasil-profiles' ) ) . '">'
            . esc_html( sprintf( '%d 个', $count ) )
            . '</a>';
    }

    /**
     * 跳回原页面，失败时回退到用户编辑页。
     *
     * @param int $target_user_id 目标用户 ID。
     */
    private function redirect_back( $target_user_id ) {
        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url( 'user-edit.php?user_id=' . (int) $target_user_id . '#yggdrasil-profiles' );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * 写入当前管理员的后台通知。
     *
     * @param string $type    success|error。
     * @param string $message 通知文案。
     */
    private function set_notice( $type, $message ) {
        set_transient(
            self::NOTICE_PREFIX . get_current_user_id(),
            [
                'type'    => ( 'error' === $type ) ? 'error' : 'success',
                'message' => (string) $message,
            ],
            300
        );
    }

    /**
     * 读取并删除当前管理员的后台通知。
     *
     * @return array|null
     */
    private function get_notice() {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        $key    = self::NOTICE_PREFIX . get_current_user_id();
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
     * 32 位 UUID 转带连字符形式（仅展示用）。
     *
     * @param string $uuid 无符号 UUID。
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
}
