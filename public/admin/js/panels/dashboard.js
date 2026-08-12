// 全服仪表盘(GET /api/admin/dashboard):运营首页的一屏数字。
//
// 后端是常量 7 条聚合 SQL,与玩家规模无关,所以这一页可以放心当首屏。
// 每个数字都标了口径 —— 数字要能被解释,否则运营对不上账就会来问「这个活跃是怎么算的」。

import { api, errorMessage } from '../core/api.js';
import { escapeHtml, formatCount, formatAmount } from '../core/dom.js';

function card(label, value, note) {
    return `
        <div class="stat-card">
            <div class="stat-value">${escapeHtml(String(value))}</div>
            <div class="stat-label">${escapeHtml(label)}</div>
            ${note ? `<div class="stat-note muted">${escapeHtml(note)}</div>` : ''}
        </div>
    `;
}

export const dashboardPanel = {
    id: 'dashboard',
    label: '仪表盘',
    permission: 'read_player',

    async load(container) {
        container.innerHTML = `
            <div class="panel-header">
                <h2>全服仪表盘</h2>
                <div class="panel-actions">
                    <button type="button" class="btn btn-ghost d-refresh">刷新</button>
                </div>
            </div>
            <div class="status muted d-status"></div>
            <div class="stat-grid d-cards"></div>
            <div class="d-resources"></div>
        `;

        container.querySelector('.d-refresh').addEventListener('click', () => render(container));
        await render(container);
    },
};

async function render(container) {
    const status = container.querySelector('.d-status');
    const cards = container.querySelector('.d-cards');
    const resources = container.querySelector('.d-resources');

    status.textContent = '加载中…';
    try {
        const d = await api.get('/api/admin/dashboard');

        cards.innerHTML = [
            card('玩家总数', formatCount(d.players.total), `含后台人员 ${d.players.staff} 人`),
            card('今日新增', formatCount(d.players.new_today), '应用时区当天 00:00 起'),
            card('24h 活跃城市', formatCount(d.players.active_24h), '按 cities.last_simulated_at'),
            card('已封禁', formatCount(d.players.banned), 'users.banned_at 非空'),
            card('城市总数', formatCount(d.cities.total), ''),
            card('资金总量', formatAmount(d.cities.money_total), '只统计 cities.money'),
            card('建筑实例', formatCount(d.buildings.total), `其中 active ${formatCount(d.buildings.active)}(在建/升级中不算)`),
            card('在职 NPC', formatCount(d.npcs.employed), 'idle + assigned,已离场不计'),
            card('生效事件', formatCount(d.events.active), 'active 且未到期'),
            card('今日后台操作', formatCount(d.audit.admin_actions_today), 'ADMIN.* 全系列'),
        ].join('');

        resources.innerHTML = `
            <div class="panel-subtitle">全服资源存量 Top ${d.resources_top.length}</div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>#</th><th>资源</th><th>code</th><th>全服存量</th></tr></thead>
                <tbody>
                    ${d.resources_top.map((r, i) => `
                        <tr>
                            <td>${i + 1}</td>
                            <td>${escapeHtml(r.name)}</td>
                            <td class="cell-id">${escapeHtml(r.resource_id)}</td>
                            <td>${escapeHtml(formatAmount(r.total))}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table></div>
        `;

        status.textContent = `统计时间 ${d.generated_at} · 今日起点 ${d.window.today_start} · 活跃窗口起点 ${d.window.active_since}`;
    } catch (err) {
        cards.innerHTML = '';
        resources.innerHTML = '';
        status.textContent = errorMessage(err);
    }
}
