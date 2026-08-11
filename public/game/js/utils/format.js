export function fmt(n) { return Math.round(Number(n) || 0).toLocaleString('zh-CN'); }

// 小数展示:价格、费率、工资/口粮这类不能被四舍五入成整数的数
// (fmt 会把 0.5 显示成 1、把 2.06 显示成 2,市场与 NPC 面板都要看小数位)
export function fmtDec(n, digits) {
    const d = typeof digits === 'number' ? digits : 2;
    return (Number(n) || 0).toLocaleString('zh-CN', { minimumFractionDigits: d, maximumFractionDigits: d });
}
