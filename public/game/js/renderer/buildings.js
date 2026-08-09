// 建筑渲染:按 city.buildings 在对应格画建筑占位色块(无美术资源,PIXI.Graphics 几何体代替)
import { gridToScreen } from './iso.js';
import { state } from '../core/state.js';

// 按分类上色;未在表中的分类统一用灰色兜底
const CATEGORY_COLOR = {
    '居住': 0x5b8def,
    '粮食生产': 0x8bd17c,
    '仓储': 0xffb84d,
    '原料采集': 0x8a5a3b,
    '行政': 0x9b6bd6,
    '国防': 0xff6b6b,
};
const DEFAULT_COLOR = 0x9aa4b2;
const BLOCK_HEIGHT = 14; // 伪 3D 建筑体的侧面高度(像素)

let buildingLayer = null;
let clickHandler = null; // 建筑点击回调(main.js 注入),参数为对应的 city.buildings 记录
let interactive = true; // false 时整层不参与命中,点击直接穿透到下面的地格

// fn(building):点击已建建筑时触发;传 null 可解除
export function setBuildingClickHandler(fn) {
    clickHandler = typeof fn === 'function' ? fn : null;
}

// 放置模式优先:进入放置模式时关掉建筑层命中,避免建筑侧面挡住相邻地格的点击
export function setBuildingsInteractive(enabled) {
    interactive = !!enabled;
    applyInteractive();
}

function applyInteractive() {
    if (!buildingLayer) return;
    // interactiveChildren 是关键:false 时 Pixi 直接跳过整层子节点的命中测试
    buildingLayer.eventMode = interactive ? 'passive' : 'none';
    buildingLayer.interactiveChildren = interactive;
}

// 深色化:侧面用比顶面更暗的同色系,做出简单的立体感
function shade(color, factor) {
    const r = Math.round(((color >> 16) & 0xff) * factor);
    const g = Math.round(((color >> 8) & 0xff) * factor);
    const b = Math.round((color & 0xff) * factor);
    return (r << 16) | (g << 8) | b;
}

function defsById() {
    const map = {};
    (state.definitions || []).forEach((d) => { map[d.buildingId] = d; });
    return map;
}

function makeBuildingSprite(b, footprint, color) {
    const container = new PIXI.Container();

    // 建筑占地矩形的四个格角,分别投影到世界坐标,连成一个"菱形块"覆盖对应格子
    const nCorner = gridToScreen(b.x, b.y);            // 顶角(北)
    const eCorner = gridToScreen(b.x + footprint.w, b.y); // 右角(东)
    const sCorner = gridToScreen(b.x + footprint.w, b.y + footprint.h); // 底角(南,最靠近视角)
    const wCorner = gridToScreen(b.x, b.y + footprint.h); // 左角(西)

    const g = new PIXI.Graphics();

    // 侧面(伪 3D):左侧面、右侧面用更暗的色调,营造出方块的立体感
    const sideColorL = shade(color, 0.55);
    const sideColorR = shade(color, 0.7);

    g.lineStyle(1, 0x0d1116, 0.8);
    g.beginFill(sideColorL, 1);
    g.moveTo(wCorner.sx, wCorner.sy);
    g.lineTo(sCorner.sx, sCorner.sy);
    g.lineTo(sCorner.sx, sCorner.sy + BLOCK_HEIGHT);
    g.lineTo(wCorner.sx, wCorner.sy + BLOCK_HEIGHT);
    g.closePath();
    g.endFill();

    g.beginFill(sideColorR, 1);
    g.moveTo(eCorner.sx, eCorner.sy);
    g.lineTo(sCorner.sx, sCorner.sy);
    g.lineTo(sCorner.sx, sCorner.sy + BLOCK_HEIGHT);
    g.lineTo(eCorner.sx, eCorner.sy + BLOCK_HEIGHT);
    g.closePath();
    g.endFill();

    // 顶面:分类主色
    g.beginFill(color, 1);
    g.moveTo(nCorner.sx, nCorner.sy);
    g.lineTo(eCorner.sx, eCorner.sy);
    g.lineTo(sCorner.sx, sCorner.sy);
    g.lineTo(wCorner.sx, wCorner.sy);
    g.closePath();
    g.endFill();

    // 命中:色块提供命中几何(Container 自身没有形状),事件冒泡到 Container 统一处理
    g.eventMode = 'static';

    container.addChild(g);

    // 等级标签
    const label = new PIXI.Text('Lv' + (b.level || 1), {
        fontSize: 11,
        fill: 0xffffff,
        fontFamily: 'sans-serif',
        fontWeight: 'bold',
    });
    label.anchor.set(0.5);
    const center = gridToScreen(b.x + footprint.w / 2, b.y + footprint.h / 2);
    label.position.set(center.sx, center.sy);
    // 标签不参与命中:它盖在色块正中央,若参与命中会先被命中并吃掉点击,
    // 建筑中心(最顺手的点击位置)反而点不动
    label.eventMode = 'none';
    container.addChild(label);

    // 非活跃状态(如已拆除但未清理等)降低透明度,便于区分
    if (b.status && b.status !== 'active') {
        container.alpha = 0.45;
    }

    // 点击回调挂在 Container 上:子节点(色块/标签)的命中都会冒泡上来,
    // 不依赖具体是哪个子节点被命中,后续加图标/进度条等子元素也不会漏点
    container.eventMode = 'static';
    container.cursor = 'pointer';
    container.on('pointertap', () => {
        if (clickHandler) clickHandler(b);
    });

    // 按格坐标简单排序,越靠"前"(x+y 越大)越晚绘制,避免遮挡穿帮
    container.zIndex = b.x + b.y;
    return container;
}

// world:pixi-app 返回的世界容器;buildings:city.buildings 数组
export function render(world, buildings) {
    if (buildingLayer) {
        world.removeChild(buildingLayer);
        buildingLayer.destroy({ children: true });
    }
    buildingLayer = new PIXI.Container();
    buildingLayer.zIndex = 10;
    buildingLayer.sortableChildren = true;
    world.addChild(buildingLayer);
    applyInteractive(); // 每次重建图层都要把当前命中开关同步上去

    const defs = defsById();
    (buildings || []).forEach((b) => {
        const def = defs[b.buildingId];
        const footprint = (def && def.footprint) || { w: 1, h: 1 };
        const color = (def && CATEGORY_COLOR[def.category]) || DEFAULT_COLOR;
        buildingLayer.addChild(makeBuildingSprite(b, footprint, color));
    });

    return buildingLayer;
}
