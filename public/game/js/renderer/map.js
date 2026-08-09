// 等距地图网格:画 mapWidth×mapHeight 的菱形地格,支持悬停高亮与点击回调
import { CONFIG } from '../core/config.js';
import { gridToScreen } from './iso.js';

const FILL_NORMAL = 0x1b212b;
const FILL_HOVER = 0x2a3345;
const STROKE_NORMAL = 0x2a3140;
const STROKE_HOVER = 0x5b8def;

let mapLayer = null;

function drawTile(g, sx, sy, halfW, halfH, hover) {
    g.clear();
    g.lineStyle(1, hover ? STROKE_HOVER : STROKE_NORMAL, hover ? 1 : 0.7);
    g.beginFill(hover ? FILL_HOVER : FILL_NORMAL, hover ? 0.7 : 0.3);
    g.moveTo(sx, sy - halfH);
    g.lineTo(sx + halfW, sy);
    g.lineTo(sx, sy + halfH);
    g.lineTo(sx - halfW, sy);
    g.closePath();
    g.endFill();
}

// world:pixi-app 返回的世界容器
// onTileClick(gx,gy):点击空地格时触发(拖拽平移中的手势会被自动忽略)
// isDraggingFn():返回当前是否处于拖拽平移中,用于抑制拖拽误触发点击
export function renderMap(world, mapWidth, mapHeight, onTileClick, isDraggingFn) {
    if (mapLayer) {
        world.removeChild(mapLayer);
        mapLayer.destroy({ children: true });
    }
    mapLayer = new PIXI.Container();
    mapLayer.zIndex = 0;
    world.addChild(mapLayer);

    const halfW = CONFIG.tileW / 2;
    const halfH = CONFIG.tileH / 2;

    for (let gy = 0; gy < mapHeight; gy++) {
        for (let gx = 0; gx < mapWidth; gx++) {
            const { sx, sy } = gridToScreen(gx, gy);
            const g = new PIXI.Graphics();
            drawTile(g, sx, sy, halfW, halfH, false);
            g.eventMode = 'static';
            g.cursor = 'pointer';

            g.on('pointerover', () => drawTile(g, sx, sy, halfW, halfH, true));
            g.on('pointerout', () => drawTile(g, sx, sy, halfW, halfH, false));
            g.on('pointertap', () => {
                if (isDraggingFn && isDraggingFn()) return; // 拖拽平移结束时不算点击
                if (onTileClick) onTileClick(gx, gy);
            });

            mapLayer.addChild(g);
        }
    }

    return mapLayer;
}
