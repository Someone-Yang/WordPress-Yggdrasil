/**
 * Minecraft 皮肤 3D 预览组件（无外部依赖，纯 CSS 3D 实现）。
 *
 * 用法：
 *   <div class="ygg-skin-viewer" data-ygg-skin-viewer
 *        data-skin-url="https://example.com/skin.png" data-skin-model="default">
 *       <img class="ygg-skin-viewer__static" src="..." alt="" />
 *   </div>
 *
 * 说明：
 * - 支持经典（64x32 旧版）与 64x64 及更高分辨率皮肤，旧版会自动补全左臂/左腿；
 * - 鼠标或触摸拖拽可旋转模型；
 * - 脚本加载失败或图片加载失败时保留原始 2D 图片作为回退。
 */
( function () {
    'use strict';

    /** 模型总高（单位：皮肤像素）。头部 8 + 身体 12 + 腿 12 = 32。 */
    var MODEL_HEIGHT = 32;

    /** 模型总宽（单位）。身体 8 + 两侧手臂各 4。 */
    var MODEL_WIDTH = 16;

    /** 各部位 UV（64x64 纹理坐标，[u, v, 宽, 高]）。 */
    var UV = {
        head: {
            top: [ 8, 0, 8, 8 ], bottom: [ 16, 0, 8, 8 ], right: [ 0, 8, 8, 8 ],
            front: [ 8, 8, 8, 8 ], left: [ 16, 8, 8, 8 ], back: [ 24, 8, 8, 8 ]
        },
        hat: {
            top: [ 40, 0, 8, 8 ], bottom: [ 48, 0, 8, 8 ], right: [ 32, 8, 8, 8 ],
            front: [ 40, 8, 8, 8 ], left: [ 48, 8, 8, 8 ], back: [ 56, 8, 8, 8 ]
        },
        body: {
            top: [ 20, 16, 8, 4 ], bottom: [ 28, 16, 8, 4 ], right: [ 16, 20, 4, 12 ],
            front: [ 20, 20, 8, 12 ], left: [ 28, 20, 4, 12 ], back: [ 32, 20, 8, 12 ]
        },
        jacket: {
            top: [ 20, 32, 8, 4 ], bottom: [ 28, 32, 8, 4 ], right: [ 16, 36, 4, 12 ],
            front: [ 20, 36, 8, 12 ], left: [ 28, 36, 4, 12 ], back: [ 32, 36, 8, 12 ]
        },
        rightArm: {
            top: [ 44, 16, 4, 4 ], bottom: [ 48, 16, 4, 4 ], right: [ 40, 20, 4, 12 ],
            front: [ 44, 20, 4, 12 ], left: [ 48, 20, 4, 12 ], back: [ 52, 20, 4, 12 ]
        },
        rightSleeve: {
            top: [ 44, 32, 4, 4 ], bottom: [ 48, 32, 4, 4 ], right: [ 40, 36, 4, 12 ],
            front: [ 44, 36, 4, 12 ], left: [ 48, 36, 4, 12 ], back: [ 52, 36, 4, 12 ]
        },
        leftArm: {
            top: [ 36, 48, 4, 4 ], bottom: [ 40, 48, 4, 4 ], right: [ 32, 52, 4, 12 ],
            front: [ 36, 52, 4, 12 ], left: [ 40, 52, 4, 12 ], back: [ 44, 52, 4, 12 ]
        },
        leftSleeve: {
            top: [ 52, 48, 4, 4 ], bottom: [ 56, 48, 4, 4 ], right: [ 48, 52, 4, 12 ],
            front: [ 52, 52, 4, 12 ], left: [ 56, 52, 4, 12 ], back: [ 60, 52, 4, 12 ]
        },
        rightLeg: {
            top: [ 4, 16, 4, 4 ], bottom: [ 8, 16, 4, 4 ], right: [ 0, 20, 4, 12 ],
            front: [ 4, 20, 4, 12 ], left: [ 8, 20, 4, 12 ], back: [ 12, 20, 4, 12 ]
        },
        rightPants: {
            top: [ 4, 32, 4, 4 ], bottom: [ 8, 32, 4, 4 ], right: [ 0, 36, 4, 12 ],
            front: [ 4, 36, 4, 12 ], left: [ 8, 36, 4, 12 ], back: [ 12, 36, 4, 12 ]
        },
        leftLeg: {
            top: [ 20, 48, 4, 4 ], bottom: [ 24, 48, 4, 4 ], right: [ 16, 52, 4, 12 ],
            front: [ 20, 52, 4, 12 ], left: [ 24, 52, 4, 12 ], back: [ 28, 52, 4, 12 ]
        },
        leftPants: {
            top: [ 4, 48, 4, 4 ], bottom: [ 8, 48, 4, 4 ], right: [ 0, 52, 4, 12 ],
            front: [ 4, 52, 4, 12 ], left: [ 8, 52, 4, 12 ], back: [ 12, 52, 4, 12 ]
        }
    };

    /**
     * 生成模型部件定义。
     *
     * @param {string} model default|slim。
     * @return {Array} 部件数组。
     */
    function buildParts( model ) {
        var slim   = ( 'slim' === model );
        var armW   = slim ? 3 : 4;
        var armX   = 4 + armW / 2;
        var layer  = 0.5;
        var headOv = 1;

        return [
            { uv: UV.body, w: 8, h: 12, d: 4, x: 0, y: -2 },
            { uv: UV.rightArm, w: armW, h: 12, d: 4, x: -armX, y: -2 },
            { uv: UV.leftArm, w: armW, h: 12, d: 4, x: armX, y: -2 },
            { uv: UV.rightLeg, w: 4, h: 12, d: 4, x: -2, y: 10 },
            { uv: UV.leftLeg, w: 4, h: 12, d: 4, x: 2, y: 10 },
            { uv: UV.head, w: 8, h: 8, d: 8, x: 0, y: -12 },

            { uv: UV.jacket, w: 8 + layer, h: 12 + layer, d: 4 + layer, x: 0, y: -2 },
            { uv: UV.rightSleeve, w: armW + layer, h: 12 + layer, d: 4 + layer, x: -armX, y: -2 },
            { uv: UV.leftSleeve, w: armW + layer, h: 12 + layer, d: 4 + layer, x: armX, y: -2 },
            { uv: UV.rightPants, w: 4 + layer, h: 12 + layer, d: 4 + layer, x: -2, y: 10 },
            { uv: UV.leftPants, w: 4 + layer, h: 12 + layer, d: 4 + layer, x: 2, y: 10 },
            { uv: UV.hat, w: 8 + headOv, h: 8 + headOv, d: 8 + headOv, x: 0, y: -12 }
        ];
    }

    /**
     * 生成单个面元素。
     *
     * @param {Object} part      部件。
     * @param {string} faceName  面名称。
     * @param {number} px        每单位像素数。
     * @param {string} url       纹理地址。
     * @return {HTMLElement}
     */
    function createFace( part, faceName, px, url ) {
        var region = part.uv[ faceName ];
        var isSide = ( 'left' === faceName || 'right' === faceName );
        var isCap  = ( 'top' === faceName || 'bottom' === faceName );

        var faceW = isSide ? part.d : part.w;
        var faceH = isCap ? part.d : part.h;

        var el = document.createElement( 'div' );
        el.className = 'ygg-skin-viewer__face';

        var width  = faceW * px;
        var height = faceH * px;

        // 纹理区域按比例铺满该面（纤细手臂等窄于纹理区域的情况会被压缩）。
        var scaleX = faceW / region[ 2 ];
        var scaleY = faceH / region[ 3 ];

        el.style.width            = width + 'px';
        el.style.height           = height + 'px';
        el.style.marginLeft       = ( -width / 2 ) + 'px';
        el.style.marginTop        = ( -height / 2 ) + 'px';
        el.style.backgroundImage  = 'url("' + url + '")';
        el.style.backgroundSize   = ( 64 * px * scaleX ) + 'px ' + ( 64 * px * scaleY ) + 'px';
        el.style.backgroundPosition = ( -region[ 0 ] * px * scaleX ) + 'px ' + ( -region[ 1 ] * px * scaleY ) + 'px';

        var depth = part.d * px / 2;
        var widthHalf = part.w * px / 2;
        var heightHalf = part.h * px / 2;

        if ( 'front' === faceName ) {
            el.style.transform = 'translateZ(' + depth + 'px)';
        } else if ( 'back' === faceName ) {
            // 背面按纹理原样呈现（rotateY(180deg) 已使其朝向观察者）。
            el.style.transform = 'translateZ(' + ( -depth ) + 'px) rotateY(180deg)';
        } else if ( 'right' === faceName ) {
            el.style.transform = 'rotateY(90deg) translateZ(' + widthHalf + 'px)';
        } else if ( 'left' === faceName ) {
            el.style.transform = 'rotateY(-90deg) translateZ(' + widthHalf + 'px)';
        } else if ( 'top' === faceName ) {
            el.style.transform = 'rotateX(90deg) translateZ(' + heightHalf + 'px)';
        } else {
            el.style.transform = 'rotateX(-90deg) translateZ(' + heightHalf + 'px)';
        }

        return el;
    }

    /**
     * 把旧版 64x32（或等比放大）皮肤补全为 64x64。
     *
     * @param {HTMLImageElement} img 已加载的图片。
     * @return {string|null} dataURL，失败返回 null。
     */
    function normalizeLegacy( img ) {
        try {
            var canvas = document.createElement( 'canvas' );
            canvas.width = 64;
            canvas.height = 64;

            var ctx = canvas.getContext( '2d' );
            if ( ! ctx ) {
                return null;
            }

            ctx.imageSmoothingEnabled = false;
            ctx.drawImage( img, 0, 0, 64, 32 );

            // 用右臂/右腿镜像补全左臂/左腿。
            mirrorRegion( ctx, img, 40, 16, 16, 16, 32, 48 );
            mirrorRegion( ctx, img, 0, 16, 16, 16, 16, 48 );

            return canvas.toDataURL( 'image/png' );
        } catch ( e ) {
            return null;
        }
    }

    /**
     * 镜像复制纹理区域。
     *
     * @param {CanvasRenderingContext2D} ctx   画布上下文。
     * @param {HTMLImageElement}         img   源图片。
     * @param {number} sx 源 x。
     * @param {number} sy 源 y。
     * @param {number} sw 源宽。
     * @param {number} sh 源高。
     * @param {number} dx 目标 x。
     * @param {number} dy 目标 y。
     */
    function mirrorRegion( ctx, img, sx, sy, sw, sh, dx, dy ) {
        var temp = document.createElement( 'canvas' );
        temp.width = sw;
        temp.height = sh;

        var tctx = temp.getContext( '2d' );
        if ( ! tctx ) {
            return;
        }

        tctx.imageSmoothingEnabled = false;
        tctx.drawImage( img, sx, sy, sw, sh, 0, 0, sw, sh );

        ctx.save();
        ctx.translate( dx + sw, dy );
        ctx.scale( -1, 1 );
        ctx.drawImage( temp, 0, 0 );
        ctx.restore();
    }

    /**
     * 渲染模型。
     *
     * @param {HTMLElement} root  容器。
     * @param {string}      url   纹理地址。
     * @param {string}      model default|slim。
     */
    function render( root, url, model ) {
        // 优先使用声明尺寸，避免脚本执行早于布局时 clientWidth 为 0 导致模型过小。
        var declared = parseInt( root.getAttribute( 'data-skin-size' ), 10 );
        var size = ( declared > 0 ) ? declared : ( root.clientWidth || 96 );
        // 预留边距，避免透视放大后头部/脚部被容器裁切。
        var px   = Math.min( size / ( MODEL_HEIGHT + 6 ), size / ( MODEL_WIDTH + 8 ) );

        var stage = document.createElement( 'div' );
        stage.className = 'ygg-skin-viewer__stage';

        var parts = buildParts( model );
        var faces = [ 'front', 'back', 'right', 'left', 'top', 'bottom' ];

        for ( var i = 0; i < parts.length; i++ ) {
            var part = parts[ i ];
            var partEl = document.createElement( 'div' );
            partEl.className = 'ygg-skin-viewer__part';
            partEl.style.transform = 'translate3d(' + ( part.x * px ) + 'px,' + ( part.y * px ) + 'px,0)';

            for ( var j = 0; j < faces.length; j++ ) {
                partEl.appendChild( createFace( part, faces[ j ], px, url ) );
            }

            stage.appendChild( partEl );
        }

        root.innerHTML = '';
        root.appendChild( stage );
        root.classList.add( 'ygg-skin-viewer--3d' );
    }

    /**
     * 绑定拖拽旋转。
     *
     * @param {HTMLElement} root  容器。
     * @param {HTMLElement} stage 舞台。
     */
    function bindDrag( root, stage ) {
        var dragging = false;
        var startX = 0;
        var startY = 0;
        var initialX = parseFloat( root.getAttribute( 'data-rotate-x' ) );
        var initialY = parseFloat( root.getAttribute( 'data-rotate-y' ) );

        var rotX = isNaN( initialX ) ? -8 : initialX;
        var rotY = isNaN( initialY ) ? 24 : initialY;
        var baseX = rotX;
        var baseY = rotY;

        function apply() {
            stage.style.transform = 'rotateX(' + rotX + 'deg) rotateY(' + rotY + 'deg)';
        }

        apply();

        root.addEventListener( 'pointerdown', function ( event ) {
            dragging = true;
            startX = event.clientX;
            startY = event.clientY;
            baseX = rotX;
            baseY = rotY;
            root.classList.add( 'ygg-skin-viewer--dragging' );

            if ( root.setPointerCapture ) {
                try {
                    root.setPointerCapture( event.pointerId );
                } catch ( e ) {
                    // 忽略：部分环境不支持指针捕获。
                }
            }
        } );

        root.addEventListener( 'pointermove', function ( event ) {
            if ( ! dragging ) {
                return;
            }

            rotY = baseY + ( event.clientX - startX ) * 0.5;
            rotX = Math.max( -60, Math.min( 60, baseX - ( event.clientY - startY ) * 0.4 ) );
            apply();
        } );

        function stop() {
            if ( ! dragging ) {
                return;
            }
            dragging = false;
            root.classList.remove( 'ygg-skin-viewer--dragging' );
        }

        root.addEventListener( 'pointerup', stop );
        root.addEventListener( 'pointercancel', stop );
        root.addEventListener( 'pointerleave', stop );
    }

    /**
     * 初始化单个容器。
     *
     * @param {HTMLElement} root 容器。
     */
    function initViewer( root ) {
        if ( root.getAttribute( 'data-ygg-initialized' ) ) {
            return;
        }
        root.setAttribute( 'data-ygg-initialized', '1' );

        var url   = root.getAttribute( 'data-skin-url' ) || '';
        var model = root.getAttribute( 'data-skin-model' ) === 'slim' ? 'slim' : 'default';

        if ( ! url ) {
            return;
        }

        var img = new Image();

        img.onload = function () {
            var width  = img.naturalWidth || img.width;
            var height = img.naturalHeight || img.height;
            var src    = url;
            var legacy = ( width > 0 && height > 0 && Math.abs( width - height * 2 ) <= 1 );

            // 旧版比例为 2:1，需要补全左臂/左腿后才能渲染。
            if ( legacy ) {
                var normalized = normalizeLegacy( img );
                if ( ! normalized ) {
                    // 跨域图片无法读取像素时保留 2D 预览，避免渲染出错位的模型。
                    root.classList.add( 'ygg-skin-viewer--failed' );
                    return;
                }
                src = normalized;
            }

            render( root, src, model );

            var stage = root.querySelector( '.ygg-skin-viewer__stage' );
            if ( stage ) {
                bindDrag( root, stage );
            }
        };

        img.onerror = function () {
            root.classList.add( 'ygg-skin-viewer--failed' );
        };

        img.src = url;
    }

    /**
     * 初始化页面中所有容器。
     */
    function initAll() {
        var nodes = document.querySelectorAll( '[data-ygg-skin-viewer]' );
        for ( var i = 0; i < nodes.length; i++ ) {
            initViewer( nodes[ i ] );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initAll );
    } else {
        initAll();
    }

    window.YggdrasilSkinViewer = {
        init: initAll,
        initViewer: initViewer
    };
}() );
