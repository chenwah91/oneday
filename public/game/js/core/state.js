// 简单客户端状态 + 订阅
export const state = { user: null, city: null, definitions: null };
const subs = [];
export function onChange(cb) { subs.push(cb); }
export function setState(patch) { Object.assign(state, patch); subs.forEach((cb) => cb(state)); }
