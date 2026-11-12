<?php
/**
 * 皮肤上传与安全处理模块
 *
 * 负责：
 * - 校验并净化用户上传的 Minecraft 皮肤 PNG（纯 PHP 解析 chunk，不依赖 GD / Imagick）；
 * - 按 SHA-256 内容寻址存储到 uploads/yggdrasil-skins/，自动去重并防止目录列表；
 * - 生成 Yggdrasil 的 textures 属性（Base64 JSON），可选 RSA SHA1withRSA 签名；
 * - 档案删除时清理无其他档案引用的皮肤文件。
 *
 * 本文件只定义 Yggdrasil_Skins 静态类，不包含页面输出与 REST 路由逻辑。
 */

defined( 'ABSPATH' ) || exit;

/**
 * 皮肤存储、净化与 textures 属性
 */
class Yggdrasil_Skins {

    /**
     * 皮肤文件最大字节数（1 MiB）
     */
    const MAX_FILE_SIZE = 1048576;

    /**
     * 皮肤单边最大像素
     */
    const MAX_TEXTURE_SIDE = 512;

    /**
     * 上传目录下的子目录名
     */
    const DIRNAME = 'yggdrasil-skins';

    /**
     * 本次请求内缓存的 RSA 密钥对
     *
     * @var array|null
     */
    private static $key_pair = null;

    /**
     * 钩子是否已注册
     *
     * @var bool
     */
    private static $hooks_registered = false;

    /**
     * 注册模块钩子（可重复调用，重复注册会被 WordPress 去重）
     *
     * @return void
     */
    public static function init() {
        if ( self::$hooks_registered ) {
            return;
        }
        self::$hooks_registered = true;

        // 档案被删除后，清理不再被任何档案引用的皮肤文件。
        add_action( 'yggdrasil_profile_deleted', [ 'Yggdrasil_Skins', 'handle_profile_deleted' ], 10, 2 );
    }

    /**
     * 上传并保存皮肤
     *
     * @param object $profile 档案对象（需包含 id、user_id、skin_path、skin_hash）。
     * @param array  $file    $_FILES 单元数组。
     * @param string $model   皮肤模型：default|slim。
     * @return array|WP_Error 成功返回 path/hash/url/width/height/model。
     */
    public static function upload( $profile, $file, $model = 'default' ) {
        if ( ! is_object( $profile ) || empty( $profile->id ) || empty( $profile->user_id ) ) {
            return new WP_Error( 'ygg_invalid_profile', '档案信息无效，无法上传皮肤。' );
        }

        $model = is_string( $model ) ? strtolower( trim( $model ) ) : '';
        if ( '' === $model ) {
            $model = 'default';
        }
        if ( ! in_array( $model, [ 'default', 'slim' ], true ) ) {
            return new WP_Error( 'ygg_invalid_model', '皮肤模型无效，只支持 default 或 slim。' );
        }

        // 1. 校验上传结构、错误码与临时文件。
        if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'] ) ) {
            return new WP_Error( 'ygg_invalid_upload', '未能获取上传文件，请重新选择皮肤图片。' );
        }

        $upload_error = (int) $file['error'];
        if ( UPLOAD_ERR_OK !== $upload_error ) {
            return new WP_Error( 'ygg_invalid_upload', self::upload_error_message( $upload_error ) );
        }

        $tmp_name = (string) $file['tmp_name'];
        if ( '' === $tmp_name || ! is_file( $tmp_name ) ) {
            return new WP_Error( 'ygg_invalid_upload', '上传的临时文件不存在，请重试。' );
        }

        // 2. 大小校验：同时检查客户端声明大小与实际文件大小。
        if ( isset( $file['size'] ) && (int) $file['size'] > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'ygg_invalid_upload', '皮肤文件不能超过 1 MiB。' );
        }
        $file_size = @filesize( $tmp_name );
        if ( false === $file_size || $file_size <= 0 ) {
            return new WP_Error( 'ygg_invalid_upload', '皮肤文件为空，请重新选择。' );
        }
        if ( $file_size > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'ygg_invalid_upload', '皮肤文件不能超过 1 MiB。' );
        }

        // 3. 先通过 getimagesize() 校验类型与尺寸，再读取文件内容（避免先读入大文件）。
        $info = @getimagesize( $tmp_name );
        if ( ! is_array( $info ) || ! isset( $info[0], $info[1], $info[2] ) || IMAGETYPE_PNG !== (int) $info[2] ) {
            return self::invalid_image( '文件不是有效的 PNG 图片。' );
        }
        $width  = (int) $info[0];
        $height = (int) $info[1];
        if ( ! self::is_valid_dimensions( $width, $height ) ) {
            return self::invalid_image( '皮肤尺寸无效：宽度须为 64 的倍数且介于 64-512，高度须为 32 的倍数且介于 32-512。' );
        }

        // 4. 读取原始字节并执行纯 PHP PNG 净化（绝不原样保存上传文件）。
        $raw = @file_get_contents( $tmp_name );
        if ( false === $raw || '' === $raw ) {
            return new WP_Error( 'ygg_upload_failed', '无法读取上传的皮肤文件，请重试。' );
        }

        $sanitized = self::sanitize_png( $raw );
        if ( is_wp_error( $sanitized ) ) {
            return $sanitized;
        }

        // 5. 按净化后内容的 SHA-256 命名保存（内容寻址，天然去重）。
        $hash = hash( 'sha256', $sanitized );

        $uploads = wp_upload_dir();
        if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return new WP_Error( 'ygg_upload_failed', '上传目录不可用，无法保存皮肤文件。' );
        }

        $base_dir = rtrim( self::normalize_path( $uploads['basedir'] ), '/' );
        $skin_dir = $base_dir . '/' . self::DIRNAME;

        if ( ! is_dir( $skin_dir ) && ! wp_mkdir_p( $skin_dir ) ) {
            return new WP_Error( 'ygg_upload_failed', '无法创建皮肤目录。' );
        }
        if ( ! is_dir( $skin_dir ) ) {
            return new WP_Error( 'ygg_upload_failed', '皮肤目录不可用。' );
        }

        // 写入空白 index.html，防止服务器开启目录列表。
        self::protect_directory( $skin_dir );

        $relative = self::DIRNAME . '/' . $hash . '.png';
        $target   = $skin_dir . '/' . $hash . '.png';
        $created  = false;

        if ( ! file_exists( $target ) ) {
            $written = @file_put_contents( $target, $sanitized, LOCK_EX );
            if ( false === $written || strlen( $sanitized ) !== $written ) {
                // 清理可能写入一半的残缺文件。
                wp_delete_file( $target );
                return new WP_Error( 'ygg_upload_failed', '保存皮肤文件失败，请检查上传目录权限。' );
            }
            $created = true;
        } elseif ( @hash_file( 'sha256', $target ) !== $hash ) {
            // 同名文件内容异常时用净化结果覆盖，保证 URL 与实际内容一致。
            $written = @file_put_contents( $target, $sanitized, LOCK_EX );
            if ( false === $written || strlen( $sanitized ) !== $written ) {
                return new WP_Error( 'ygg_upload_failed', '保存皮肤文件失败，请检查上传目录权限。' );
            }
        }

        // 6. realpath 校验：目标文件必须真实位于皮肤目录内。
        $real_dir    = realpath( $skin_dir );
        $real_target = realpath( $target );
        if ( false === $real_dir || false === $real_target ) {
            if ( $created ) {
                wp_delete_file( $target );
            }
            return new WP_Error( 'ygg_upload_failed', '皮肤文件保存校验失败。' );
        }
        $real_dir    = self::normalize_path( $real_dir );
        $real_target = self::normalize_path( $real_target );
        if ( 0 !== strpos( $real_target, $real_dir . '/' ) ) {
            if ( $created ) {
                wp_delete_file( $real_target );
            }
            return new WP_Error( 'ygg_upload_failed', '皮肤文件路径校验失败。' );
        }

        // 7. 写入档案记录。
        if ( ! class_exists( 'Yggdrasil_Profiles' ) || ! method_exists( 'Yggdrasil_Profiles', 'set_skin' ) ) {
            if ( $created && ! self::has_other_profile_using_path( $relative, (int) $profile->id ) ) {
                wp_delete_file( $real_target );
            }
            return new WP_Error( 'ygg_upload_failed', '档案模块不可用，无法绑定皮肤。' );
        }

        $saved = Yggdrasil_Profiles::set_skin(
            (int) $profile->id,
            (int) $profile->user_id,
            $relative,
            $hash,
            $model
        );
        if ( is_wp_error( $saved ) ) {
            // 仅在本次新建且没有其他档案引用时回收文件。
            if ( $created && ! self::has_other_profile_using_path( $relative, (int) $profile->id ) ) {
                wp_delete_file( $real_target );
            }
            return $saved;
        }

        // 替换皮肤后清理旧文件（无其他档案引用时），避免孤儿文件累积。
        $old_skin_path = isset( $profile->skin_path ) && is_string( $profile->skin_path ) ? $profile->skin_path : '';
        if ( '' !== $old_skin_path && $old_skin_path !== $relative ) {
            self::delete_skin_file_if_unused( $profile );
        }

        // 8. 组装返回数据（URL 通过 get_url() 生成，以便应用 yggdrasil_skin_url 过滤器）。
        $updated              = clone $profile;
        $updated->skin_path   = $relative;
        $updated->skin_hash   = $hash;
        $updated->skin_updated = time();
        $updated->model       = $model;

        $url = self::get_url( $updated );

        do_action( 'yggdrasil_skin_uploaded', (int) $profile->id, (int) $profile->user_id, $hash );

        return [
            'path'   => $relative,
            'hash'   => $hash,
            'url'    => $url,
            'width'  => $width,
            'height' => $height,
            'model'  => $model,
        ];
    }

    /**
     * 移除档案皮肤（清空数据库字段，并在无其他档案引用时删除文件）
     *
     * @param object $profile 档案对象。
     * @return bool|WP_Error
     */
    public static function remove( $profile ) {
        if ( ! is_object( $profile ) || empty( $profile->id ) || empty( $profile->user_id ) ) {
            return new WP_Error( 'ygg_invalid_profile', '档案信息无效，无法移除皮肤。' );
        }

        $skin_path = isset( $profile->skin_path ) && is_string( $profile->skin_path ) ? $profile->skin_path : '';
        if ( '' === $skin_path ) {
            return new WP_Error( 'ygg_no_skin', '该档案尚未设置皮肤。' );
        }

        if ( ! class_exists( 'Yggdrasil_Profiles' ) || ! method_exists( 'Yggdrasil_Profiles', 'set_skin' ) ) {
            return new WP_Error( 'ygg_upload_failed', '档案模块不可用，无法清空皮肤记录。' );
        }

        // 先清空数据库记录，成功后再删除文件；避免数据库仍引用已删除文件。
        $old_profile = clone $profile;
        $cleared     = Yggdrasil_Profiles::set_skin( (int) $profile->id, (int) $profile->user_id, '', '', null );
        if ( is_wp_error( $cleared ) ) {
            return $cleared;
        }

        self::delete_skin_file_if_unused( $old_profile );

        do_action( 'yggdrasil_skin_removed', (int) $profile->id, (int) $profile->user_id );

        return true;
    }

    /**
     * 获取档案皮肤文件的绝对路径
     *
     * @param object $profile 档案对象。
     * @return string 绝对路径；文件不存在或路径非法时返回空串。
     */
    public static function get_path( $profile ) {
        if ( ! is_object( $profile ) || empty( $profile->skin_path ) || ! is_string( $profile->skin_path ) ) {
            return '';
        }

        return self::resolve_skin_path( $profile->skin_path );
    }

    /**
     * 获取档案皮肤 URL（经 yggdrasil_skin_url 过滤器）
     *
     * @param object $profile 档案对象。
     * @return string
     */
    public static function get_url( $profile ) {
        if ( ! is_object( $profile ) || empty( $profile->skin_hash ) ) {
            return '';
        }

        $hash = strtolower( trim( (string) $profile->skin_hash ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            return '';
        }

        // 文件不存在时不要下发失效 URL，交由调用方显示“暂无皮肤”。
        if ( '' === self::get_texture_file( $hash ) ) {
            return '';
        }

        $url = rest_url( 'yggdrasil/v1/textures/' . $hash );

        // 未启用伪静态时，rest_url() 会生成 index.php?rest_route=... 形式的 URL，
        // 最后一段不是材质 hash，部分客户端会因此用错缓存键。此时改用上传目录的静态 URL，
        // 保证 URL 最后一段为 <hash>.png。
        $rest_path = wp_parse_url( $url, PHP_URL_PATH );
        $rest_name = is_string( $rest_path ) ? basename( $rest_path ) : '';

        if ( $rest_name !== $hash ) {
            $uploads = wp_upload_dir();
            if ( is_array( $uploads )
                && empty( $uploads['error'] )
                && ! empty( $uploads['baseurl'] )
                && ! empty( $profile->skin_path )
                && is_string( $profile->skin_path )
            ) {
                $url = trailingslashit( $uploads['baseurl'] ) . ltrim( $profile->skin_path, '/' );
            }
        }

        return (string) apply_filters( 'yggdrasil_skin_url', $url, $profile );
    }

    /**
     * 根据 SHA-256 解析皮肤文件真实路径
     *
     * @param string $hash 净化后 PNG 的 SHA-256（64 位小写十六进制）。
     * @return string 绝对路径；非法或不存在时返回空串。
     */
    public static function get_texture_file( $hash ) {
        if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
            return '';
        }

        $skin_dir = self::get_skin_dir_path();
        if ( '' === $skin_dir ) {
            return '';
        }

        $real_dir = realpath( $skin_dir );
        if ( false === $real_dir || ! is_dir( $real_dir ) ) {
            return '';
        }

        $real_file = realpath( $real_dir . '/' . $hash . '.png' );
        if ( false === $real_file || ! is_file( $real_file ) ) {
            return '';
        }

        $real_dir  = self::normalize_path( $real_dir );
        $real_file = self::normalize_path( $real_file );
        if ( 0 !== strpos( $real_file, $real_dir . '/' ) ) {
            return '';
        }

        return $real_file;
    }

    /**
     * 生成 textures 属性（Yggdrasil 档案属性中的一项）
     *
     * @param object $profile 档案对象。
     * @param bool   $signed  是否尝试签名。
     * @return array|null 形如 [ 'name' => 'textures', 'value' => ..., 'signature' => ... ]。
     */
    public static function get_textures_property( $profile, $signed = true ) {
        if ( ! is_object( $profile ) || empty( $profile->skin_hash ) ) {
            return null;
        }

        $hash = strtolower( trim( (string) $profile->skin_hash ) );
        if ( '' === self::get_texture_file( $hash ) ) {
            return null;
        }

        $url = self::get_url( $profile );
        if ( '' === $url ) {
            return null;
        }

        $texture = [ 'url' => $url ];
        if ( isset( $profile->model ) && 'slim' === $profile->model ) {
            $texture['metadata'] = [ 'model' => 'slim' ];
        }

        $payload = [
            'timestamp'   => (int) round( microtime( true ) * 1000 ),
            'profileId'   => isset( $profile->uuid ) ? (string) $profile->uuid : '',
            'profileName' => isset( $profile->name ) ? (string) $profile->name : '',
            'textures'    => [ 'SKIN' => $texture ],
        ];

        // 不转义斜杠，保证 URL 原样进入 JSON。
        $json = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json || null === $json ) {
            return null;
        }

        $property = [
            'name'  => 'textures',
            'value' => base64_encode( $json ),
        ];

        $should_sign = (bool) $signed && (bool) apply_filters(
            'yggdrasil_textures_signed',
            class_exists( 'Yggdrasil_Settings' ) ? Yggdrasil_Settings::get_sign_textures() : true,
            $profile
        );
        if ( $should_sign && function_exists( 'openssl_sign' ) ) {
            $pair = self::get_key_pair();
            if ( ! is_wp_error( $pair ) && ! empty( $pair['private_key'] ) ) {
                $signature = '';
                // 使用 SHA1withRSA 对 Base64 后的 value 签名。
                $ok = @openssl_sign( $property['value'], $signature, $pair['private_key'], OPENSSL_ALGO_SHA1 );
                if ( $ok && '' !== $signature ) {
                    $property['signature'] = base64_encode( $signature );
                }
            }
        }

        return $property;
    }

    /**
     * 获取档案完整的 properties 数组
     *
     * @param object $profile 档案对象。
     * @param bool   $signed  是否尝试签名。
     * @return array 无皮肤时返回空数组。
     */
    public static function get_properties( $profile, $signed = true ) {
        $property = self::get_textures_property( $profile, $signed );

        return ( null === $property ) ? [] : [ $property ];
    }

    /**
     * 获取（必要时惰性生成）RSA 密钥对
     *
     * 密钥保存在选项 ygg_textures_private_key / ygg_textures_public_key（autoload=false）。
     * 无 OpenSSL 扩展时返回 WP_Error，调用方应静默降级为不签名。
     *
     * @return array|WP_Error [ 'private_key' => PEM, 'public_key' => PEM ]
     */
    public static function get_key_pair() {
        if ( null !== self::$key_pair ) {
            return self::$key_pair;
        }

        if ( ! extension_loaded( 'openssl' ) || ! function_exists( 'openssl_pkey_new' ) ) {
            return new WP_Error( 'ygg_openssl_unavailable', '服务器未启用 OpenSSL 扩展，无法生成皮肤签名密钥。' );
        }

        $private_pem = (string) get_option( 'ygg_textures_private_key', '' );

        // 已有私钥可用时直接复用，并让公钥始终由私钥推导，修复选项不一致。
        if ( '' !== $private_pem ) {
            $pair = self::normalize_key_pair( $private_pem );
            if ( null !== $pair ) {
                if ( (string) get_option( 'ygg_textures_public_key', '' ) !== $pair['public_key'] ) {
                    update_option( 'ygg_textures_public_key', $pair['public_key'], false );
                }
                self::$key_pair = $pair;
                return $pair;
            }
        }

        // 惰性生成 RSA 密钥（后台默认 2048 位，可通过设置页或过滤器调整）。
        $key_bits = class_exists( 'Yggdrasil_Settings' )
            ? (int) Yggdrasil_Settings::get_rsa_key_bits()
            : (int) apply_filters( 'yggdrasil_rsa_key_bits', 2048 );
        if ( $key_bits < 1024 ) {
            $key_bits = 1024;
        }

        $resource = @openssl_pkey_new( [
            'private_key_bits' => $key_bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        if ( false === $resource ) {
            return new WP_Error( 'ygg_key_generation_failed', '生成皮肤签名密钥失败。' );
        }

        $generated_private = '';
        if ( ! @openssl_pkey_export( $resource, $generated_private ) || '' === $generated_private ) {
            return new WP_Error( 'ygg_key_generation_failed', '导出皮肤签名私钥失败。' );
        }

        $details = @openssl_pkey_get_details( $resource );
        if ( ! is_array( $details ) || empty( $details['key'] ) ) {
            return new WP_Error( 'ygg_key_generation_failed', '获取皮肤签名公钥失败。' );
        }
        $generated_public = (string) $details['key'];

        // 用 add_option 原子抢占私钥选项；若被并发请求抢先写入，则改用已存在的私钥。
        if ( ! add_option( 'ygg_textures_private_key', $generated_private, '', false ) ) {
            $existing_private = (string) get_option( 'ygg_textures_private_key', '' );
            $pair             = self::normalize_key_pair( $existing_private );
            if ( null !== $pair ) {
                if ( (string) get_option( 'ygg_textures_public_key', '' ) !== $pair['public_key'] ) {
                    update_option( 'ygg_textures_public_key', $pair['public_key'], false );
                }
                self::$key_pair = $pair;
                return $pair;
            }
            // 已存在的私钥损坏，用本次生成的密钥强制覆盖。
            update_option( 'ygg_textures_private_key', $generated_private, false );
        }

        update_option( 'ygg_textures_public_key', $generated_public, false );

        $pair = [
            'private_key' => $generated_private,
            'public_key'  => $generated_public,
        ];

        self::$key_pair = $pair;

        return $pair;
    }

    /**
     * 获取皮肤签名公钥（PEM）；不可用时返回空串
     *
     * @return string
     */
    public static function get_public_key() {
        $pair = self::get_key_pair();
        if ( is_wp_error( $pair ) || empty( $pair['public_key'] ) ) {
            return '';
        }

        return (string) $pair['public_key'];
    }

    /**
     * 档案删除钩子回调：清理无其他档案引用的皮肤文件
     *
     * @param object $profile 被删除的档案对象。
     * @param int    $user_id 用户 ID（钩子传入，此处无需使用）。
     * @return void
     */
    public static function handle_profile_deleted( $profile, $user_id = 0 ) {
        self::delete_skin_file_if_unused( $profile );
    }

    /**
     * 校验 PNG 尺寸是否符合 Minecraft 皮肤规范
     *
     * 宽为 64 的倍数且 64-512，高为 32 的倍数且 32-512。
     *
     * @param int $width  宽度。
     * @param int $height 高度。
     * @return bool
     */
    private static function is_valid_dimensions( $width, $height ) {
        $width  = (int) $width;
        $height = (int) $height;

        // Minecraft 皮肤只接受 1:1（64x64）或 2:1（64x32）比例。
        $valid_ratio = ( $height === $width ) || ( $height * 2 === $width );

        return $valid_ratio
            && $width >= 64
            && $width <= self::MAX_TEXTURE_SIDE
            && $height >= 32
            && $height <= self::MAX_TEXTURE_SIDE
            && 0 === $width % 64
            && 0 === $height % 32;
    }

    /**
     * 纯 PHP PNG 净化
     *
     * 仅保留 IHDR / PLTE / tRNS / IDAT / IEND，丢弃其余所有 chunk（tEXt、zTXt、iTXt、
     * eXIf、gAMA、tIME、acTL、fcTL、fdAT 等），从而剥离附加数据与 APNG 动画。
     * 保留原始 chunk 字节（含 CRC），输出即净化后的 PNG。
     *
     * @param string $data 原始文件内容。
     * @return string|WP_Error 净化后的 PNG 字节。
     */
    private static function sanitize_png( $data ) {
        $signature = "\x89PNG\r\n\x1a\n";

        if ( ! is_string( $data ) || strlen( $data ) < 8 + 13 + 12 + 12 ) {
            return self::invalid_image( '文件不是有效的 PNG 图片。' );
        }
        if ( 0 !== strncmp( $data, $signature, 8 ) ) {
            return self::invalid_image( '文件不是有效的 PNG 图片。' );
        }

        $allowed  = [ 'IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND' ];
        $seen     = [
            'IHDR' => false,
            'PLTE' => false,
            'tRNS' => false,
            'IDAT' => false,
            'IEND' => false,
        ];
        $total    = strlen( $data );
        $offset   = 8;
        $output   = $signature;
        $color_type = -1;
        $has_idat_data = false;
        $palette_entries = 0;

        while ( $offset + 12 <= $total ) {
            // 4 字节大端长度 + 4 字节类型。
            $unpacked = unpack( 'Nlength', substr( $data, $offset, 4 ) );
            $length   = isset( $unpacked['length'] ) ? (int) $unpacked['length'] : -1;
            if ( $length < 0 || $length > self::MAX_FILE_SIZE ) {
                return self::invalid_image( 'PNG 数据块长度异常。' );
            }

            $type = substr( $data, $offset + 4, 4 );
            if ( 4 !== strlen( $type ) || ! preg_match( '/^[A-Za-z]{4}$/', $type ) ) {
                return self::invalid_image( 'PNG 数据块类型异常。' );
            }

            $chunk_size = 12 + $length;
            if ( $offset + $chunk_size > $total ) {
                return self::invalid_image( 'PNG 数据块长度越界。' );
            }

            // 校验数据块 CRC：CRC32(类型 + 数据) 必须与数据块尾部存储值一致。
            $crc_unpacked = unpack( 'Ncrc', substr( $data, $offset + 8 + $length, 4 ) );
            $stored_crc   = isset( $crc_unpacked['crc'] ) ? sprintf( '%u', $crc_unpacked['crc'] ) : '';
            $calc_crc     = sprintf( '%u', crc32( substr( $data, $offset + 4, 4 + $length ) ) );
            if ( '' === $stored_crc || ! hash_equals( $stored_crc, $calc_crc ) ) {
                return self::invalid_image( 'PNG 数据块校验和不正确。' );
            }

            // 第一个数据块必须是 IHDR。
            if ( ! $seen['IHDR'] && 'IHDR' !== $type ) {
                return self::invalid_image( 'PNG 缺少有效的 IHDR 数据块。' );
            }

            // 未知关键数据块（首字母大写且不在白名单）一律拒绝。
            if ( ! in_array( $type, $allowed, true ) ) {
                if ( preg_match( '/^[A-Z]$/', $type[0] ) ) {
                    return self::invalid_image( 'PNG 包含未知的关键数据块。' );
                }
                $offset += $chunk_size;
                continue;
            }

            switch ( $type ) {
                case 'IHDR':
                    if ( $seen['IHDR'] || 8 !== $offset || 13 !== $length ) {
                        return self::invalid_image( 'PNG 的 IHDR 数据块无效。' );
                    }
                    $ihdr = substr( $data, $offset + 8, 13 );
                    if ( 13 !== strlen( $ihdr ) ) {
                        return self::invalid_image( 'PNG 的 IHDR 数据块无效。' );
                    }
                    $dimensions = unpack( 'Nwidth/Nheight', substr( $ihdr, 0, 8 ) );
                    if ( ! self::is_valid_dimensions( $dimensions['width'], $dimensions['height'] ) ) {
                        return self::invalid_image( 'PNG 内声明的皮肤尺寸无效。' );
                    }
                    $bit_depth  = ord( $ihdr[8] );
                    $color_type = ord( $ihdr[9] );
                    $valid_depths = [
                        0 => [ 1, 2, 4, 8, 16 ],
                        2 => [ 8, 16 ],
                        3 => [ 1, 2, 4, 8 ],
                        4 => [ 8, 16 ],
                        6 => [ 8, 16 ],
                    ];
                    if ( ! isset( $valid_depths[ $color_type ] ) || ! in_array( $bit_depth, $valid_depths[ $color_type ], true ) ) {
                        return self::invalid_image( 'PNG 位深或颜色类型无效。' );
                    }
                    // 压缩方法、滤波方法必须为 0；隔行扫描只允许 0 或 1。
                    if ( 0 !== ord( $ihdr[10] ) || 0 !== ord( $ihdr[11] ) ) {
                        return self::invalid_image( 'PNG 压缩或滤波方法无效。' );
                    }
                    if ( ! in_array( ord( $ihdr[12] ), [ 0, 1 ], true ) ) {
                        return self::invalid_image( 'PNG 隔行扫描方式无效。' );
                    }
                    $seen['IHDR'] = true;
                    break;

                case 'PLTE':
                    if ( $seen['PLTE'] || $seen['IDAT'] || $seen['IEND'] ) {
                        return self::invalid_image( 'PNG 的 PLTE 数据块位置无效。' );
                    }
                    if ( 3 !== $length % 3 || $length < 3 || $length > 768 ) {
                        return self::invalid_image( 'PNG 的 PLTE 数据块长度无效。' );
                    }
                    if ( in_array( $color_type, [ 0, 4 ], true ) ) {
                        return self::invalid_image( 'PNG 的 PLTE 数据块与颜色类型不匹配。' );
                    }
                    $palette_entries = (int) ( $length / 3 );
                    $seen['PLTE'] = true;
                    break;

                case 'tRNS':
                    if ( $seen['tRNS'] || $seen['IDAT'] || $seen['IEND'] ) {
                        return self::invalid_image( 'PNG 的 tRNS 数据块位置无效。' );
                    }
                    if ( ! in_array( $color_type, [ 0, 2, 3 ], true ) ) {
                        return self::invalid_image( 'PNG 的 tRNS 数据块与颜色类型不匹配。' );
                    }
                    if ( 0 === $color_type && 2 !== $length ) {
                        return self::invalid_image( 'PNG 的 tRNS 数据块长度无效。' );
                    }
                    if ( 2 === $color_type && 6 !== $length ) {
                        return self::invalid_image( 'PNG 的 tRNS 数据块长度无效。' );
                    }
                    if ( 3 === $color_type && ( ! $seen['PLTE'] || $length < 1 || $length > $palette_entries ) ) {
                        return self::invalid_image( 'PNG 的 tRNS 数据块长度无效。' );
                    }
                    $seen['tRNS'] = true;
                    break;

                case 'IDAT':
                    if ( $seen['IEND'] ) {
                        return self::invalid_image( 'PNG 的 IDAT 数据块位置无效。' );
                    }
                    if ( 3 === $color_type && ! $seen['PLTE'] ) {
                        return self::invalid_image( 'PNG 缺少调色板数据。' );
                    }
                    if ( $length > 0 ) {
                        $has_idat_data = true;
                    }
                    $seen['IDAT'] = true;
                    break;

                case 'IEND':
                    if ( $seen['IEND'] || 0 !== $length ) {
                        return self::invalid_image( 'PNG 的 IEND 数据块无效。' );
                    }
                    $seen['IEND'] = true;
                    break;
            }

            $output .= substr( $data, $offset, $chunk_size );
            $offset += $chunk_size;

            // IEND 之后的所有内容（附加数据等）全部丢弃。
            if ( $seen['IEND'] ) {
                break;
            }
        }

        if ( ! $seen['IHDR'] || ! $seen['IDAT'] || ! $has_idat_data || ! $seen['IEND'] ) {
            return self::invalid_image( 'PNG 数据不完整。' );
        }

        return $output;
    }

    /**
     * 解析 skin_path 为真实绝对路径
     *
     * @param string $skin_path 形如 yggdrasil-skins/<sha256>.png 的相对路径。
     * @return string
     */
    private static function resolve_skin_path( $skin_path ) {
        $skin_path = ltrim( self::normalize_path( $skin_path ), '/' );
        $prefix    = self::DIRNAME . '/';

        if ( 0 !== strpos( $skin_path, $prefix ) ) {
            return '';
        }

        $name = substr( $skin_path, strlen( $prefix ) );
        if ( ! preg_match( '/^([a-f0-9]{64})\.png$/', $name, $matches ) ) {
            return '';
        }

        return self::get_texture_file( $matches[1] );
    }

    /**
     * 获取皮肤目录（uploads/yggdrasil-skins）绝对路径（不保证存在）
     *
     * @return string
     */
    private static function get_skin_dir_path() {
        $uploads = wp_upload_dir();
        if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return '';
        }

        return rtrim( self::normalize_path( $uploads['basedir'] ), '/' ) . '/' . self::DIRNAME;
    }

    /**
     * 删除皮肤文件（仅当没有其他档案引用同一 skin_path 时）
     *
     * @param object $profile 档案对象。
     * @return void
     */
    private static function delete_skin_file_if_unused( $profile ) {
        if ( ! is_object( $profile ) || empty( $profile->skin_path ) || ! is_string( $profile->skin_path ) ) {
            return;
        }

        $skin_path = $profile->skin_path;
        $path      = self::get_path( $profile );
        if ( '' === $path ) {
            return;
        }

        $profile_id = isset( $profile->id ) ? (int) $profile->id : 0;
        if ( self::has_other_profile_using_path( $skin_path, $profile_id ) ) {
            return;
        }

        wp_delete_file( $path );
    }

    /**
     * 查询是否有其他档案引用同一 skin_path
     *
     * @param string $skin_path 相对路径。
     * @param int    $exclude_profile_id 排除的档案 ID（当前档案）。
     * @return bool
     */
    private static function has_other_profile_using_path( $skin_path, $exclude_profile_id ) {
        if ( '' === (string) $skin_path ) {
            return false;
        }

        global $wpdb;

        $table = class_exists( 'Yggdrasil_DB' ) ? Yggdrasil_DB::profiles_table() : $wpdb->prefix . 'ygg_profiles';

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE skin_path = %s AND id <> %d",
                $skin_path,
                (int) $exclude_profile_id
            )
        );

        return ( (int) $count ) > 0;
    }

    /**
     * 在皮肤目录写入空白 index.html，防止目录列表
     *
     * @param string $dir 目录绝对路径。
     * @return void
     */
    private static function protect_directory( $dir ) {
        $index = rtrim( $dir, '/' ) . '/index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' );
        }
    }

    /**
     * 规范化路径为 Unix 风格（用于跨平台前缀比较）
     *
     * @param string $path 路径。
     * @return string
     */
    private static function normalize_path( $path ) {
        $path = str_replace( '\\', '/', (string) $path );

        return (string) preg_replace( '#/+#', '/', $path );
    }

    /**
     * 由私钥 PEM 推导并校验密钥对（避免公私钥选项不一致）
     *
     * @param string $private_pem 私钥 PEM。
     * @return array|null
     */
    private static function normalize_key_pair( $private_pem ) {
        if ( '' === $private_pem ) {
            return null;
        }

        $key = @openssl_pkey_get_private( $private_pem );
        if ( false === $key ) {
            return null;
        }

        $details = @openssl_pkey_get_details( $key );
        if ( ! is_array( $details ) || empty( $details['key'] ) ) {
            return null;
        }

        return [
            'private_key' => $private_pem,
            'public_key'  => (string) $details['key'],
        ];
    }

    /**
     * 生成图片无效的 WP_Error
     *
     * @param string $message 错误消息。
     * @return WP_Error
     */
    private static function invalid_image( $message ) {
        return new WP_Error( 'ygg_invalid_image', $message );
    }

    /**
     * 将 $_FILES 错误码转换为可读消息
     *
     * @param int $error_code 上传错误码。
     * @return string
     */
    private static function upload_error_message( $error_code ) {
        switch ( (int) $error_code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '皮肤文件超过服务器允许的上传大小。';
            case UPLOAD_ERR_PARTIAL:
                return '文件仅部分上传，请重试。';
            case UPLOAD_ERR_NO_FILE:
                return '未选择皮肤文件。';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录，无法接收上传文件。';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器无法写入临时文件。';
            case UPLOAD_ERR_EXTENSION:
                return '文件上传被服务器扩展中断。';
            default:
                return '皮肤文件上传失败，请重试。';
        }
    }
}

// 文件加载时即注册钩子，确保 Yggdrasil_Profiles::delete() 触发时皮肤文件能被清理。
Yggdrasil_Skins::init();
