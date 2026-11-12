/**
 * 前台游戏档案管理界面的无依赖增强脚本。
 *
 * 功能：
 * 1. 带 data-confirm 属性的提交按钮在提交前弹出确认（用于删除档案）。
 * 2. 选择本地皮肤文件时做 PNG / 大小校验，并生成缩略预览。
 */
( function () {
    'use strict';

    /** 皮肤文件大小上限：1 MiB，与 Yggdrasil_Skins::MAX_FILE_SIZE 保持一致。 */
    var MAX_SKIN_SIZE = 1048576;

    /**
     * 拦截需要二次确认的表单提交。
     *
     * @param {Event} event 提交事件。
     */
    function handleSubmit( event ) {
        var form = event.target;
        if ( ! form || ! form.querySelector ) {
            return;
        }

        var trigger = form.querySelector( '[data-confirm]' );
        if ( ! trigger ) {
            return;
        }

        var message = trigger.getAttribute( 'data-confirm' );
        if ( message && ! window.confirm( message ) ) {
            event.preventDefault();
        }
    }

    /**
     * 向上查找皮肤上传表单容器（.yggdrasil-skin-upload）。
     *
     * @param {Element} element 起始元素。
     * @return {Element|null}
     */
    function findUploadWrapper( element ) {
        var node = element.parentNode;
        while ( node && node !== document ) {
            if ( node.className && String( node.className ).indexOf( 'yggdrasil-skin-upload' ) !== -1 ) {
                return node;
            }
            node = node.parentNode;
        }

        return null;
    }

    /**
     * 显示或清除上传校验错误。
     *
     * @param {Element} wrapper 上传表单容器。
     * @param {string}  message 错误文案，空字符串表示清除。
     */
    function setUploadError( wrapper, message ) {
        var error = wrapper ? wrapper.querySelector( '[data-ygg-skin-error]' ) : null;
        if ( ! error ) {
            return;
        }

        error.textContent = message || '';
        if ( message ) {
            error.removeAttribute( 'hidden' );
        } else {
            error.setAttribute( 'hidden', 'hidden' );
        }
    }

    /**
     * 清除本地预览。
     *
     * @param {Element} wrapper 上传表单容器。
     */
    function clearPreview( wrapper ) {
        var box = wrapper ? wrapper.querySelector( '[data-ygg-skin-local]' ) : null;
        if ( ! box ) {
            return;
        }

        while ( box.firstChild ) {
            box.removeChild( box.firstChild );
        }
        box.setAttribute( 'hidden', 'hidden' );
    }

    /**
     * 用所选文件生成缩略预览。
     *
     * @param {Element} wrapper 上传表单容器。
     * @param {File}    file    所选文件。
     */
    function showPreview( wrapper, file ) {
        var box = wrapper ? wrapper.querySelector( '[data-ygg-skin-local]' ) : null;
        if ( ! box ) {
            return;
        }

        clearPreview( wrapper );

        if ( ! window.URL || typeof window.URL.createObjectURL !== 'function' ) {
            return;
        }

        var objectUrl = window.URL.createObjectURL( file );
        var image = document.createElement( 'img' );
        image.className = 'yggdrasil-skin-preview yggdrasil-skin-local__image';
        image.width = 96;
        image.height = 96;
        image.alt = '待上传皮肤预览';

        image.onload = function () {
            window.URL.revokeObjectURL( objectUrl );
        };
        image.onerror = function () {
            window.URL.revokeObjectURL( objectUrl );
            clearPreview( wrapper );
            setUploadError( wrapper, '无法读取所选图片，请换一个 PNG 文件。' );
        };

        image.src = objectUrl;
        box.appendChild( image );
        box.removeAttribute( 'hidden' );
    }

    /**
     * 处理皮肤文件选择：校验类型与大小，并刷新预览。
     *
     * @param {Event} event change 事件。
     */
    function handleFileChange( event ) {
        var input = event.target;
        if ( ! input || input.type !== 'file' || input.name !== 'ygg_skin' ) {
            return;
        }

        var wrapper = findUploadWrapper( input );
        var file = input.files && input.files.length ? input.files[0] : null;

        setUploadError( wrapper, '' );

        if ( ! file ) {
            clearPreview( wrapper );
            return;
        }

        if ( file.type && 'image/png' !== file.type ) {
            setUploadError( wrapper, '仅支持 PNG 格式的皮肤文件。' );
            clearPreview( wrapper );
            input.value = '';
            return;
        }

        if ( file.size > MAX_SKIN_SIZE ) {
            setUploadError( wrapper, '皮肤文件不能超过 1 MiB。' );
            clearPreview( wrapper );
            input.value = '';
            return;
        }

        showPreview( wrapper, file );
    }

    /**
     * 绑定事件（使用事件委托，兼容动态插入的表单）。
     */
    function init() {
        document.addEventListener( 'submit', handleSubmit, false );
        document.addEventListener( 'change', handleFileChange, false );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
