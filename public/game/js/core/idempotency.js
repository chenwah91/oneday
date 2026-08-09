// 幂等键:一次用户操作生成一个 UUID(CLAUDE §49)
// 同一个 key 重复提交,服务器不会重复扣资源/重复建造,用于抵消网络重试与重复点击

// 优先用浏览器原生 UUID;不可用时(非安全上下文/老浏览器)按 v4 规则自行拼装
export function newIdempotencyKey() {
    const c = typeof crypto !== 'undefined' ? crypto : null;

    if (c && typeof c.randomUUID === 'function') {
        return c.randomUUID();
    }

    if (c && typeof c.getRandomValues === 'function') {
        const b = new Uint8Array(16);
        c.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40; // version 4
        b[8] = (b[8] & 0x3f) | 0x80; // variant 10
        let hex = '';
        for (let i = 0; i < b.length; i++) {
            hex += b[i].toString(16).padStart(2, '0');
        }
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
            + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    // 最后兜底:随机性弱于 UUID,但仍足以区分同一用户的不同操作
    return 'k-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 10);
}
