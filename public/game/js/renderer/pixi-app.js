// PixiJS 应用初始化:创建画布、世界容器(可拖动平移 + 滚轮缩放)
// 依赖全局 PIXI(由 vendor/pixi.min.js 以普通 <script> 引入)
import { CONFIG } from '../core/config.js';

const MIN_SCALE = 0.4;
const MAX_SCALE = 2.2;
const DRAG_THRESHOLD = 4; // 像素:超过则视为拖拽平移,而非点击

// container:挂载画布的 DOM 容器(#stage)
export function initPixiApp(container) {
    const app = new PIXI.Application({
        resizeTo: container,
        backgroundColor: 0x0d1116,
        antialias: true,
        resolution: window.devicePixelRatio || 1,
        autoDensity: true,
    });
    container.appendChild(app.view);

    // 世界容器:地图 + 建筑都挂在这里,平移/缩放只需要改它的 transform
    const world = new PIXI.Container();
    world.sortableChildren = true;
    app.stage.addChild(world);

    app.stage.eventMode = 'static';
    app.stage.hitArea = app.screen;

    // 容器尺寸变化时同步命中区域(resizeTo 只处理画布本身尺寸,这里补齐 hitArea)
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => { app.stage.hitArea = app.screen; });
        ro.observe(container);
    }

    let pointerDown = false;
    let dragMoved = false; // 本次手势是否已构成拖拽(供地格点击判断是否需要忽略)
    let lastX = 0;
    let lastY = 0;

    app.stage.on('pointerdown', (e) => {
        pointerDown = true;
        dragMoved = false;
        lastX = e.global.x;
        lastY = e.global.y;
    });

    app.stage.on('pointermove', (e) => {
        if (!pointerDown) return;
        const dx = e.global.x - lastX;
        const dy = e.global.y - lastY;
        if (dragMoved || Math.abs(dx) > DRAG_THRESHOLD || Math.abs(dy) > DRAG_THRESHOLD) {
            dragMoved = true;
            world.x += dx;
            world.y += dy;
            lastX = e.global.x;
            lastY = e.global.y;
        }
    });

    function endPointer() { pointerDown = false; }
    app.stage.on('pointerup', endPointer);
    app.stage.on('pointerupoutside', endPointer);

    // 滚轮缩放:以画布中心为基准的简化实现(不做指针锚定)
    app.view.addEventListener('wheel', (e) => {
        e.preventDefault();
        const factor = e.deltaY > 0 ? 0.9 : 1.1;
        const next = Math.min(MAX_SCALE, Math.max(MIN_SCALE, world.scale.x * factor));
        world.scale.set(next);
    }, { passive: false });

    // 把世界容器居中到画布上(按地图整体像素范围计算),放置模式/首次进入调用一次即可
    function centerOn(mapWidth, mapHeight) {
        const halfTileW = CONFIG.tileW / 2;
        const halfTileH = CONFIG.tileH / 2;
        const centerSx = (mapWidth - mapHeight) * (halfTileW / 2);
        const totalSy = (mapWidth + mapHeight) * halfTileH;
        world.x = app.screen.width / 2 - centerSx;
        world.y = Math.max(30, (app.screen.height - totalSy) / 2);
    }

    return {
        app,
        world,
        centerOn,
        isDragging: () => dragMoved,
    };
}
