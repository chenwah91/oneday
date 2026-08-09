// 简单客户端状态 + 订阅
// resourceNames:资源 code → 中文显示名,由 modules/resources.js 启动时拉取
export const state = { user: null, city: null, definitions: null, resourceNames: null };
const subs = [];
export function onChange(cb) { subs.push(cb); }
export function setState(patch) { Object.assign(state, patch); subs.forEach((cb) => cb(state)); }
