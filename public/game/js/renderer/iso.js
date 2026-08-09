// 等距(菱形)投影:格子坐标 <-> 世界(屏幕)像素坐标
// 用 CONFIG.tileW/tileH 定义单格菱形的宽高;整数格坐标对应菱形中心点,
// 相邻格心之间正好错开半格,拼接起来即为无缝的等距地格网。
import { CONFIG } from '../core/config.js';

// (gx,gy) 格坐标 -> {sx,sy} 世界像素坐标(菱形中心)
export function gridToScreen(gx, gy) {
    return {
        sx: (gx - gy) * (CONFIG.tileW / 2),
        sy: (gx + gy) * (CONFIG.tileH / 2),
    };
}

// {sx,sy} 世界像素坐标 -> (gx,gy) 格坐标(浮点,调用方按需 Math.floor)
export function screenToGrid(sx, sy) {
    const halfW = CONFIG.tileW / 2;
    const halfH = CONFIG.tileH / 2;
    const a = sx / halfW; // gx - gy
    const b = sy / halfH; // gx + gy
    return {
        gx: (a + b) / 2,
        gy: (b - a) / 2,
    };
}
