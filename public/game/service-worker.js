// PWA Service Worker:缓存 /game/ 静态资源,永不缓存 /api/*(玩家数据以服务器为准)
// 版本号:每次静态资源有实质变更时递增,触发旧缓存清理
const CACHE = 'apg-v3';

// 预缓存的静态资源清单(HTML/CSS/JS/vendor/manifest/图标)
const PRECACHE_URLS = [
  '/game/',
  '/game/index.html',
  '/game/manifest.json',
  '/game/vendor/pixi.min.js',
  '/game/css/base.css',
  '/game/css/auth.css',
  '/game/css/hud.css',
  '/game/css/panels.css',
  '/game/css/components.css',
  '/game/js/main.js',
  '/game/js/core/config.js',
  '/game/js/core/api.js',
  '/game/js/core/state.js',
  '/game/js/core/error-messages.js',
  '/game/js/core/idempotency.js',
  '/game/js/utils/format.js',
  '/game/js/ui/auth.js',
  '/game/js/ui/hud.js',
  '/game/js/ui/build-panel.js',
  '/game/js/ui/building-panel.js',
  '/game/js/ui/notification.js',
  '/game/js/renderer/iso.js',
  '/game/js/renderer/pixi-app.js',
  '/game/js/renderer/map.js',
  '/game/js/renderer/buildings.js',
  '/game/js/modules/build.js',
  '/game/js/modules/resources.js',
  '/game/icons/icon-192.png',
  '/game/icons/icon-512.png',
];

// 安装阶段:逐个尝试预缓存,单个资源 404/失败不影响其余资源与安装流程
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => {
      return Promise.allSettled(
        PRECACHE_URLS.map((url) => cache.add(url).catch(() => {}))
      );
    })
  );
  self.skipWaiting();
});

// 激活阶段:清理旧版本缓存
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))
      );
    })
  );
  self.clients.claim();
});

// 请求拦截:
// - /api/* 一律直接走网络,不读不写缓存(玩家数据必须服务器权威)
// - 同源 /game/ 下的静态资源用 cache-first,未命中时回源并写入缓存
self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  if (url.pathname.startsWith('/api/')) {
    event.respondWith(fetch(req));
    return;
  }

  if (req.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith('/game/')) {
    return;
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(req)
        .then((res) => {
          if (res && res.ok) {
            const clone = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, clone));
          }
          return res;
        })
        .catch(() => cached);
    })
  );
});
