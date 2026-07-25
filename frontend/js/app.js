/* ============================================================
 * 海龟汤馆 · 全栈版前端
 * 路由 + API + 页面渲染 + WebSocket
 * ============================================================ */

// ---------- 工具 ----------
const $ = (sel, root = document) => root.querySelector(sel);
const el = (tag, props = {}, children = []) => {
  const n = document.createElement(tag);
  Object.entries(props).forEach(([k, v]) => {
    if (k === "class") n.className = v;
    else if (k === "html") n.innerHTML = v;
    else if (k.startsWith("on")) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v);
  });
  (Array.isArray(children) ? children : [children]).forEach((c) => {
    if (c == null) return;
    n.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
  });
  return n;
};

function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function escapeJs(str) {
  return JSON.stringify(str ?? "").slice(1, -1);
}

/**
 * 安全渲染 Markdown 为 HTML。
 * - 使用 marked 解析表格/加粗/斜体/列表等语法
 * - 保留颜色版汤源的 <span style="color: blue;">（蓝色规则）等安全 HTML
 * - 过滤危险标签（script/iframe/on* 事件），防 XSS
 * - 给"解析"段落自动套楷体（规则怪谈类汤用楷体区分解析内容）
 * @param {string} md 原始 markdown 文本
 * @returns {string} 安全的 HTML
 */
function renderMd(md) {
  if (!md) return "";
  // 初始化 marked（只初始化一次）
  if (typeof marked !== "undefined" && !renderMd._inited) {
    marked.setOptions({
      gfm: true,        // GitHub Flavored Markdown（表格、删除线等）
      breaks: true,     // 单换行也转 <br>
      headerIds: false, // 不给标题加 id
      mangle: false,
    });
    renderMd._inited = true;
  }
  let html;
  if (typeof marked !== "undefined") {
    html = marked.parse(String(md ?? ""));
    // XSS 防护：移除危险标签和事件属性（保留 span/em/img/br 等安全标签）
    html = html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "")
               .replace(/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/gi, "")
               .replace(/<object\b[^>]*>/gi, "").replace(/<\/object>/gi, "")
               .replace(/<embed\b[^>]*>/gi, "")
               .replace(/\son\w+\s*=\s*"[^"]*"/gi, "")
               .replace(/\son\w+\s*=\s*'[^']*'/gi, "")
               .replace(/\son\w+\s*=\s*[^\s>]+/gi, "")
               .replace(/javascript:/gi, "");
  } else {
    // marked 加载失败时回退到纯文本转义
    html = escapeHtml(md).replace(/\n/g, "<br>");
  }

  // 楷体处理：把含"解析"关键词的段落套上 kaiti class
  html = html.replace(/<p>([^<]*(?:解析|梗概|结局)[^<]*)<\/p>/gi, (m, inner) => {
    return `<p class="kaiti">${inner}</p>`;
  });
  html = html.replace(/<p>(怪谈解析[^<]*)<\/p>/gi, '<p class="kaiti">$1</p>');

  return html;
}

function toast(msg, type = "") {
  const t = $("#toast");
  if (!t) return;
  t.textContent = msg;
  t.className = "toast show " + type;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => (t.className = "toast " + type), 2600);
}

// ---------- 全局状态 ----------
const store = {
  user: null,
  soups: [],
  seasons: [],
  filtered: [],
  selected: null,
  search: "",
  season: "",
  aiKey: localStorage.getItem("hgt_deepseek_key") || "",
  csrfToken: "",
  // 单人模式每碗汤的问答历史（按 soup_id 存）
  aiHistory: {},
  pollTimer: null,
  pollLastId: 0,
  currentRoomCode: null,
};

const API = {
  async get(path) {
    const r = await fetch(path, { credentials: "same-origin" });
    return r;
  },
  async json(path, opts = {}) {
    const headers = { "Content-Type": "application/json", ...opts.headers };
    if (store.csrfToken) headers["X-CSRF-Token"] = store.csrfToken;
    const r = await fetch(path, {
      credentials: "same-origin",
      headers,
      ...opts,
    });
    let data;
    try { data = await r.json(); } catch { data = {}; }
    return { ok: r.ok, status: r.status, data };
  },
  post(path, body) {
    return this.json(path, { method: "POST", body: JSON.stringify(body) });
  },
  put(path, body) {
    return this.json(path, { method: "PUT", body: JSON.stringify(body) });
  },
  del(path) {
    return this.json(path, { method: "DELETE" });
  },
};

// ---------- DeepSeek Key 管理 ----------
const KeyMgr = {
  get() { return store.aiKey; },
  set(k) {
    store.aiKey = (k || "").trim();
    if (store.aiKey) localStorage.setItem("hgt_deepseek_key", store.aiKey);
    else localStorage.removeItem("hgt_deepseek_key");
  },
  has() { return !!store.aiKey; },
  async test(key) {
    // 用一个极简请求测试 Key 有效性（向 /api/ai/ask 发一个测试问）
    const k = (key || store.aiKey).trim();
    if (!k) return { ok: false, msg: "请先填写 Key" };
    if (!store.soups.length) await loadSoups();
    if (!store.soups.length) return { ok: false, msg: "汤数据未加载，无法测试" };
    const testSoup = store.soups.find((s) => s.base) || store.soups[0];
    const { ok, data } = await API.post("/api/ai/ask", {
      soup_id: testSoup.id,
      question: "测试",
      api_key: k,
    });
    if (ok && data.answer) return { ok: true, msg: "连接成功" };
    if (data.code === "missing_key") return { ok: false, msg: "Key 为空" };
    if (data.code === "invalid_key") return { ok: false, msg: "Key 无效或已过期" };
    if (data.code === "insufficient_balance") return { ok: false, msg: "账户余额不足" };
    // 即便上游报错，也说明 Key 通到了 DeepSeek（格式正确）
    if (data.code === "upstream_error" || data.code === "parse_error")
      return { ok: true, msg: "Key 格式有效（上游返回：" + (data.error || "").slice(0, 40) + "）" };
    return { ok: false, msg: data.error || "测试失败" };
  },
};

// ---------- 路由 ----------
function route() {
  const hash = location.hash.replace(/^#/, "") || "/";
  // 清空弹窗并恢复滚动，避免 closeAllModals -> closeSettings -> route 递归
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
  document.body.style.overflow = "";
  if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }

  if (hash === "/" || hash === "") return renderHome();
  if (hash === "/auth") return renderAuth();
  if (hash === "/rooms") return renderRooms();
  if (hash.startsWith("/room/")) return renderRoom(hash.slice("/room/".length));
  if (hash.startsWith("/soup/")) return renderSoupPage(hash.slice("/soup/".length));
  if (hash === "/profile") return renderProfile();
  if (hash.startsWith("/admin")) return renderAdmin(hash);
  renderHome();
}

window.addEventListener("hashchange", route);

// ---------- Header ----------
function headerHtml(active = "") {
  const u = store.user;
  const keyOk = KeyMgr.has();
  return `
    <header class="header">
      <div class="container header-inner">
        <a href="#/" class="logo">
          <div class="logo-icon">🍲</div>
          <span>海龟汤馆</span>
        </a>
        <nav class="nav">
          <a href="#/" class="nav-item ${active === "home" ? "active" : ""}">汤馆</a>
          <a href="#/rooms" class="nav-item ${active === "rooms" ? "active" : ""}">房间</a>
          ${u ? `<a href="#/profile" class="nav-item ${active === "profile" ? "active" : ""}">我的</a>` : ""}
          ${u && u.is_admin ? `<a href="#/admin" class="nav-item ${active === "admin" ? "active" : ""}">后台</a>` : ""}
        </nav>
        <div class="header-actions">
          <button class="btn-icon ${keyOk ? "has-key" : ""}" onclick="openSettings()" title="AI 设置">⚙</button>
          ${u
            ? `<a href="#/profile" class="user-chip"><span class="user-avatar">${escapeHtml(u.username.slice(0, 1).toUpperCase())}</span>${escapeHtml(u.username)}</a>`
            : `<a href="#/auth" class="user-chip">登录</a>`}
          <button class="mobile-menu-btn" onclick="toggleMobileNav(event)" aria-label="菜单">☰</button>
        </div>
      </div>
      <div class="mobile-nav" id="mobileNav" onclick="hideMobileNav(event)">
        <a href="#/" class="${active === "home" ? "active" : ""}">🏠 汤馆</a>
        <a href="#/rooms" class="${active === "rooms" ? "active" : ""}">🎮 房间</a>
        ${u ? `<a href="#/profile" class="${active === "profile" ? "active" : ""}">👤 我的</a>` : ""}
        ${u && u.is_admin ? `<a href="#/admin" class="${active === "admin" ? "active" : ""}">⚙ 后台</a>` : ""}
      </div>
    </header>
  `;
}

window.toggleMobileNav = (e) => {
  e.stopPropagation();
  const nav = $("#mobileNav");
  if (!nav) return;
  nav.classList.toggle("open");
};

window.hideMobileNav = (e) => {
  if (e && e.target.tagName === "A") {
    const nav = $("#mobileNav");
    if (nav) nav.classList.remove("open");
  }
};

document.addEventListener("click", (e) => {
  const nav = $("#mobileNav");
  if (!nav || !nav.classList.contains("open")) return;
  if (!nav.contains(e.target) && !e.target.closest(".mobile-menu-btn")) {
    nav.classList.remove("open");
  }
});

// ---------- 首页 ----------
async function renderHome() {
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("home")}
      <section class="hero container">
        <div class="hero-badge">🍲 悬疑推理收录站</div>
        <h1>海龟汤馆</h1>
        <p>每碗汤都是一段离奇的故事。先看汤面，细品线索，再揭开汤底；也可让 AI 当主持人，回答你的提问。</p>
        <div class="curator">整理人：长安</div>
        <div class="search-box">
          <span class="search-icon">🔍</span>
          <input type="text" id="searchInput" placeholder="搜索标题、汤面或系列…" value="${escapeHtml(store.search)}" />
        </div>
      </section>
      <div class="stats-bar container" id="statsBar">
        <div class="stat"><strong>${store.soups.length}</strong>收录汤数</div>
        <div class="stat"><strong>${store.seasons.length}</strong>系列/季</div>
        <div class="stat"><strong>${KeyMgr.has() ? "已配置" : "未配置"}</strong>AI 主持人</div>
      </div>
      <div id="homeContent"></div>
      <footer class="footer container">
        <span>海龟汤馆 · 整理人长安</span>
      </footer>
      <div id="modalRoot"></div>
    </div>
  `;

  const input = $("#searchInput");
  if (input) {
    input.addEventListener("input", (e) => {
      store.search = e.target.value;
      applyFilters();
      renderHomeList();
      const next = $("#searchInput");
      if (next) { next.focus(); next.setSelectionRange(store.search.length, store.search.length); }
    });
  }
  await loadSoups();
  renderStats();
  renderFilters();
  renderHomeList();
}

function applyFilters() {
  const q = store.search.toLowerCase();
  store.filtered = store.soups.filter((s) => {
    const matchesQ = !q ||
      (s.title || "").toLowerCase().includes(q) ||
      (s.excerpt || "").toLowerCase().includes(q) ||
      (s.season || "").toLowerCase().includes(q);
    const matchesSeason = !store.season || s.season === store.season;
    return matchesQ && matchesSeason;
  });
}

function renderSkeletonGrid() {
  return `<div class="container"><div class="grid" style="padding-bottom:28px">
    ${Array.from({ length: 6 }).map(() => `
      <article class="card" style="pointer-events:none;min-height:160px">
        <div class="skeleton" style="width:90px;height:22px;border-radius:999px;margin-bottom:14px"></div>
        <div class="skeleton" style="width:70%;height:22px;margin-bottom:10px"></div>
        <div class="skeleton" style="width:100%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:90%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:60%;height:14px"></div>
      </article>
    `).join("")}
  </div></div>`;
}

function renderStats() {
  const bar = $("#statsBar");
  if (!bar) return;
  bar.innerHTML = `
    <div class="stat"><strong>${store.soups.length}</strong>收录汤数</div>
    <div class="stat"><strong>${store.seasons.length}</strong>系列/季</div>
    <div class="stat"><strong>${KeyMgr.has() ? "已配置" : "未配置"}</strong>AI 主持人</div>
  `;
}

async function loadSoups() {
  if (store.soups.length) return;
  $("#homeContent").innerHTML = renderSkeletonGrid();
  const { ok, data } = await API.json("/api/soups");
  if (!ok) {
    $("#homeContent").innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>加载失败，请确认后端已启动</p></div>`;
    return;
  }
  store.soups = data.soups || [];
  store.seasons = data.seasons || [];
  applyFilters();
  renderStats();
  renderFilters();
}

function renderFilters() {
  const wrap = $(".filters");
  if (wrap) wrap.remove();
  const hero = $(".hero");
  if (!hero) return;
  const f = document.createElement("div");
  f.className = "filters container";
  f.innerHTML = `
    <button class="filter-chip ${store.season === "" ? "active" : ""}" data-season="">全部</button>
    ${store.seasons.map((s) => `
      <button class="filter-chip ${store.season === s ? "active" : ""}" data-season="${escapeHtml(s)}">${escapeHtml(s)}</button>
    `).join("")}
  `;
  f.querySelectorAll("[data-season]").forEach((btn) => {
    btn.addEventListener("click", () => setSeason(btn.dataset.season));
  });
  hero.after(f);
}

function renderHomeList() {
  const c = $("#homeContent");
  if (!c) return;
  const items = store.filtered;
  if (!items.length) {
    c.innerHTML = `<div class="empty"><div class="empty-icon">🍲</div><p>没有找到匹配的海龟汤</p></div>`;
    return;
  }
  // 按季节分组
  const groups = {};
  items.forEach((s) => {
    const k = s.season || "其他";
    (groups[k] = groups[k] || []).push(s);
  });
  const ordered = Object.entries(groups).sort((a, b) =>
    a[0].localeCompare(b[0], undefined, { numeric: true })
  );

  c.innerHTML = ordered.map(([season, list]) => `
    <div class="container">
      <h2 class="section-title">${escapeHtml(season)}</h2>
      <div class="grid">
        ${list.map((s) => `
          <article class="card" onclick="openSoup(${s.id})">
            <span class="card-tag">${escapeHtml(s.season)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</span>
            <h3>${escapeHtml(s.title)}</h3>
            <p>${escapeHtml(s.excerpt || "")}</p>
          </article>
        `).join("")}
      </div>
    </div>
  `).join("");
}

window.setSeason = (s) => { store.season = s; applyFilters(); renderFilters(); renderHomeList(); };

// ---------- 详情页 + 单人 AI ----------
async function openSoup(id) {
  // 改为独立路由全屏页面，浏览器后退也能返回
  location.hash = "#/soup/" + id;
}
window.openSoup = openSoup;

async function renderSoupPage(id) {
  const soupId = parseInt(id, 10);
  if (!soupId || soupId <= 0) { location.hash = "#/"; return; }

  // 先渲染骨架，避免白屏
  $("#app").innerHTML = `
    <div class="page soup-detail-page">
      ${headerHtml("")}
      <div class="container soup-container">
        <div class="skeleton" style="width:60%;height:32px;margin:24px 0 8px"></div>
        <div class="skeleton" style="width:40%;height:14px;margin-bottom:24px"></div>
        <div class="skeleton" style="width:100%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:90%;height:14px;margin-bottom:8px"></div>
        <div class="skeleton" style="width:70%;height:14px"></div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  const { ok, data } = await API.json(`/api/soups/${soupId}`);
  if (!ok) {
    $("#app").innerHTML = `
      <div class="page soup-detail-page">
        ${headerHtml("")}
        <div class="container soup-container">
          <button class="btn btn-ghost back-btn" onclick="history.back()">← 返回</button>
          <div class="empty"><div class="empty-icon">🍲</div><p>${escapeHtml(data.error || "加载失败")}</p></div>
        </div>
      </div>
    `;
    return;
  }
  store.selected = data;
  renderSoupPageContent(data);
}

function renderSoupPageContent(soup) {
  const hist = store.aiHistory[soup.id] || [];
  const keyOk = KeyMgr.has();

  // 空汤面/汤底的友好提示
  const hasSurface = !!(soup.surface && soup.surface.trim());
  const hasBase = !!(soup.base && soup.base.trim());
  const surfaceText = hasSurface
    ? renderMd(soup.surface)
    : `<span class="empty-hint">（本汤暂无独立汤面${soup.host_manual ? "，请直接阅读主持人手册" : ""}）</span>`;
  const baseBlock = hasBase ? `
        <div class="section-label base">
          <span>汤底</span>
          <button class="reveal-toggle" id="revealToggle" onclick="revealBase(event)">▶ 点击展开汤底</button>
        </div>
        <div class="text-block md-body reveal collapsed" id="baseBlock" style="display:none">${renderMd(soup.base)}</div>` : '';

  $("#app").innerHTML = `
    <div class="page soup-detail-page">
      ${headerHtml("")}
      <div class="container soup-container">
        <button class="btn btn-ghost back-btn" onclick="history.back()">← 返回</button>

        <div class="soup-detail-header">
          <span class="card-tag">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""}</span>
          <h1 class="soup-detail-title">${escapeHtml(soup.title)}</h1>
          <div class="modal-meta">${escapeHtml(soup.filename)}</div>
        </div>

        <div class="section-label">汤面</div>
        <div class="text-block md-body">${surfaceText}</div>

        <div class="section-label ai">向 AI 主持人提问</div>
        <div class="ai-area">
          <p class="ai-hint">
            ${!hasBase
              ? `<span class="warn">本汤暂无汤底，AI 主持人无法作答。</span>`
              : keyOk
                ? "AI 只会回答「是」「否」「无关」，猜中汤底会提示。汤底不会泄露给 AI 之外的任何人。"
                : `<span class="warn">尚未配置 DeepSeek API Key，</span>点击右上角 ⚙ 填入你自己的 Key 后即可提问。`}
          </p>
          <div class="ai-history" id="aiHistory">
            ${hist.length === 0
              ? `<div class="ai-empty">${hasBase ? "还没有提问记录。试试问「主角是男性吗？」" : "本汤无汤底，无法提问。"}</div>`
              : hist.map((t) => `
                <div class="ai-turn">
                  <div class="ai-q">${escapeHtml(t.q)}</div>
                  <div class="ai-a ${classifyAnswer(t.a)}">${escapeHtml(t.a)}</div>
                </div>
              `).join("")}
          </div>
          <div class="ai-input-row">
            <input type="text" id="aiQuestionInput" placeholder="问 AI 一个是非题…" ${(keyOk && hasBase) ? "" : "disabled"} onkeydown="if(event.key==='Enter')askAiSingle(${soup.id})" />
            <button onclick="askAiSingle(${soup.id})" ${(keyOk && hasBase) ? "" : "disabled"}>提问</button>
          </div>
        </div>

        ${baseBlock}

        ${soup.host_manual ? `
        <div class="section-label base">
          <span>主持人手册</span>
          <button class="reveal-toggle" id="manualToggle" onclick="revealManual(event)">▶ 点击展开主持人手册</button>
        </div>
        <div class="text-block md-body reveal collapsed" id="manualBlock" style="display:none">${renderMd(soup.host_manual)}</div>` : ''}

        ${soup.extra ? `
        <div class="section-label base">
          <span>其他内容</span>
          <button class="reveal-toggle" id="extraToggle" onclick="revealExtra(event)">▶ 点击展开其他内容</button>
        </div>
        <div class="text-block md-body reveal collapsed" id="extraBlock" style="display:none">${renderMd(soup.extra)}</div>` : ''}

        <div class="soup-detail-actions">
          <button class="btn btn-primary" onclick="newRoomFromSoup(${soup.id})">🎮 开房间</button>
          ${store.user ? `<a class="btn btn-ghost" href="/api/soups/${soup.id}/download" download>⬇ 下载</a>` : `<a href="#/auth" class="btn btn-ghost">⬇ 登录后下载</a>`}
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  window.scrollTo(0, 0);
}

function classifyAnswer(ans) {
  const a = (ans || "").trim();
  if (a.includes("猜中")) return "win";
  if (a === "是" || a.startsWith("是")) return "yes";
  if (a === "否" || a.startsWith("否")) return "no";
  if (a.includes("无关")) return "irrelevant";
  return "";
}

function renderSoupModal(soup, revealed) {
  // 已废弃：汤详情改为独立路由全屏页面，见 renderSoupPage / renderSoupPageContent
  renderSoupPageContent(soup);
}

async function askAiSingle(soupId) {
  const input = $("#aiQuestionInput");
  if (!input) return;
  const q = input.value.trim();
  if (!q) return;
  const key = KeyMgr.get();
  if (!key) { toast("请先在右上角 ⚙ 配置 DeepSeek Key", "err"); return; }

  const soup = store.selected;
  if (!soup || soup.id !== soupId) return;

  input.disabled = true;
  const btn = input.nextElementSibling;
  if (btn) { btn.disabled = true; btn.innerHTML = `<span class="spinner sm"></span>`; }

  // 乐观插入问题
  if (!store.aiHistory[soupId]) store.aiHistory[soupId] = [];
  store.aiHistory[soupId].push({ q, a: "思考中…" });
  refreshAiHistory(soupId);

  const { ok, data } = await API.post("/api/ai/ask", {
    soup_id: soupId,
    question: q,
    api_key: key,
  });

  const last = store.aiHistory[soupId][store.aiHistory[soupId].length - 1];
  if (ok && data.answer) {
    last.a = data.answer;
  } else {
    last.a = "❌ " + (data.error || "提问失败");
  }
  refreshAiHistory(soupId);

  input.value = "";
  input.disabled = false;
  if (btn) { btn.disabled = false; btn.textContent = "提问"; }
  input.focus();
}
window.askAiSingle = askAiSingle;

function refreshAiHistory(soupId) {
  const box = $("#aiHistory");
  if (!box) return;
  const hist = store.aiHistory[soupId] || [];
  box.innerHTML = hist.length === 0
    ? `<div class="ai-empty">还没有提问记录。</div>`
    : hist.map((t) => `
      <div class="ai-turn">
        <div class="ai-q">${escapeHtml(t.q)}</div>
        <div class="ai-a ${classifyAnswer(t.a)}">${escapeHtml(t.a)}</div>
      </div>
    `).join("");
  box.scrollTop = box.scrollHeight;
}

function revealBase(e) {
  e.stopPropagation();
  const block = $("#baseBlock");
  const toggle = $("#revealToggle");
  if (!block) return;
  const collapsed = block.style.display === "none";
  block.style.display = collapsed ? "block" : "none";
  if (toggle) toggle.textContent = collapsed ? "▼ 收起汤底" : "▶ 点击展开汤底";
}
window.revealBase = revealBase;

function revealManual(e) {
  e.stopPropagation();
  const block = $("#manualBlock");
  const toggle = $("#manualToggle");
  if (!block) return;
  const collapsed = block.style.display === "none";
  block.style.display = collapsed ? "block" : "none";
  if (toggle) toggle.textContent = collapsed ? "▼ 收起主持人手册" : "▶ 点击展开主持人手册";
}
window.revealManual = revealManual;

function revealExtra(e) {
  e.stopPropagation();
  const block = $("#extraBlock");
  const toggle = $("#extraToggle");
  if (!block) return;
  const collapsed = block.style.display === "none";
  block.style.display = collapsed ? "block" : "none";
  if (toggle) toggle.textContent = collapsed ? "▼ 收起其他内容" : "▶ 点击展开其他内容";
}
window.revealExtra = revealExtra;

function closeModal(e) {
  if (e) e.stopPropagation();
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
  document.body.style.overflow = "";
}
window.closeModal = closeModal;

async function newRoomFromSoup(soupId) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  const { ok, data } = await API.post("/api/rooms", { soup_id: soupId, ai_enabled: true });
  if (!ok) { toast(data.error || "创建房间失败", "err"); return; }
  closeModal();
  location.hash = "#/room/" + data.code;
}
window.newRoomFromSoup = newRoomFromSoup;

// ---------- 登录注册 ----------
function renderAuth() {
  if (store.user) { location.hash = "#/"; return; }
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml()}
      <div class="container-sm">
        <div class="form-card">
          <div class="logo-icon">🍲</div>
          <h2>海龟汤馆</h2>
          <p class="sub">登录后即可创建房间、向 AI 提问</p>
          <div class="form-tabs">
            <button class="form-tab active" id="tabLogin" onclick="switchAuthTab('login')">登录</button>
          </div>
          <div id="authForm"></div>
          <div class="register-notice">
            <p>注册暂未开放</p>
            <p>如需账号，请前往交流群寻找管理员</p>
          </div>
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  switchAuthTab("login");
}

let _authMode = "login";
window.switchAuthTab = (mode) => {
  _authMode = mode;
  const tabLogin = $("#tabLogin");
  if (tabLogin) tabLogin.classList.toggle("active", mode === "login");
  const f = $("#authForm");
  if (!f) return;
  if (mode === "login") {
    f.innerHTML = `
      <div id="formMsg"></div>
      <div class="field">
        <label>用户名或邮箱</label>
        <input class="input" id="loginAccount" placeholder="输入用户名或邮箱" />
      </div>
      <div class="field">
        <label>密码</label>
        <input class="input" id="loginPassword" type="password" placeholder="至少 6 位" onkeydown="if(event.key==='Enter')doLogin()" />
      </div>
      <button class="btn btn-primary" style="width:100%" onclick="doLogin()">登录</button>
    `;
  }
};

function setFormMsg(msg, type = "err") {
  const m = $("#formMsg");
  if (!m) return;
  m.innerHTML = msg ? `<div class="form-${type === "err" ? "error" : "success"}">${escapeHtml(msg)}</div>` : "";
}

window.doLogin = async () => {
  const account = $("#loginAccount").value.trim();
  const password = $("#loginPassword").value;
  if (!account || !password) { setFormMsg("请填写完整"); return; }
  const { ok, data } = await API.post("/api/auth/login", { account, password });
  if (!ok) { setFormMsg(data.error || "登录失败"); return; }
  store.user = data.user;
  if (data.csrf_token) store.csrfToken = data.csrf_token;
  toast("登录成功", "ok");
  location.hash = "#/";
};

// ---------- 房间大厅 ----------
async function renderRooms() {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("rooms")}
      <div class="container room-hall">
        <div class="hall-head">
          <div>
            <h2>多人房间</h2>
            <p style="margin:6px 0 0;color:var(--text-3);font-size:0.9rem">创建房间邀请好友，或输入房间号加入</p>
          </div>
          <div class="join-box">
            <input id="joinCode" placeholder="输入房间号加入" maxlength="6" />
            <button class="btn btn-secondary" style="min-width:auto;flex:0 0 auto;padding:0 18px" onclick="joinByCode()">加入</button>
          </div>
        </div>
        <div class="side-card" style="animation:fadeInUp 0.45s ease both">
          <h4>创建新房间</h4>
          <div class="field">
            <label>选择一碗汤（可不选，进入后再选）</label>
            <input class="input" id="newRoomSoup" placeholder="点击选择汤" readonly onclick="pickSoupForRoom()" />
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-size:0.9rem;color:var(--text-2);margin-bottom:14px">
            <input type="checkbox" id="newRoomAi" checked /> 启用 AI 主持人
          </label>
          <button class="btn btn-primary" style="width:100%" onclick="createRoom()">创建房间</button>
          ${!KeyMgr.has() ? `<p class="ai-hint" style="margin-top:10px"><span class="warn">提示：</span>启用 AI 需先在右上角 ⚙ 配置 DeepSeek Key</p>` : ""}
        </div>
        <h3 class="section-title" style="margin-top:32px">进行中的房间</h3>
        <div id="roomList"><div class="empty"><div class="spinner"></div></div></div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
  await loadRoomList();
}

async function loadRoomList() {
  const { ok, data } = await API.json("/api/rooms");
  const c = $("#roomList");
  if (!ok) { c.innerHTML = `<div class="empty"><p>加载失败</p></div>`; return; }
  const rooms = data.rooms || [];
  if (!rooms.length) {
    c.innerHTML = `<div class="empty"><div class="empty-icon">🎮</div><p>还没有进行中的房间，创建一个吧</p></div>`;
    return;
  }
  c.innerHTML = rooms.map((r) => `
    <div class="room-card">
      <div>
        <div class="code">${escapeHtml(r.code)}</div>
        <div class="info">房主：${escapeHtml(r.host?.username || "未知")} · ${r.ai_enabled ? "AI 已启用" : "无 AI"}</div>
      </div>
      <button class="btn btn-primary" style="min-width:auto;flex:0 0 auto;padding:8px 18px" onclick="location.hash='#/room/${r.code}'">进入</button>
    </div>
  `).join("");
}

let _pickedSoupId = null;
window.pickSoupForRoom = () => {
  if (!store.soups.length) { toast("汤数据未加载", "err"); return; }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">选择一碗汤</h2></div>
        <button class="modal-close" onclick="closeModal(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="soup-picker" id="soupPickerList">
          ${store.soups.map((s) => `
            <div class="soup-pick-item" data-id="${s.id}" data-title="${escapeHtml(s.title)}">
              <div class="t">${escapeHtml(s.title)}</div>
              <div class="s">${escapeHtml(s.season)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</div>
            </div>
          `).join("")}
        </div>
      </div>
    </div>
  `;
  $("#soupPickerList").querySelectorAll(".soup-pick-item").forEach((item) => {
    item.addEventListener("click", () => confirmPickSoup(+item.dataset.id, item.dataset.title));
  });
  document.body.style.overflow = "hidden";
};

window.confirmPickSoup = (id, title) => {
  _pickedSoupId = id;
  const input = $("#newRoomSoup");
  if (input) input.value = title;
  closeModal();
};

window.createRoom = async () => {
  const ai_enabled = $("#newRoomAi").checked;
  if (ai_enabled && !KeyMgr.has()) {
    toast("启用 AI 需先配置 DeepSeek Key（右上角 ⚙）", "err");
    return;
  }
  const { ok, data } = await API.post("/api/rooms", {
    soup_id: _pickedSoupId || null,
    ai_enabled,
  });
  if (!ok) { toast(data.error || "创建失败", "err"); return; }
  location.hash = "#/room/" + data.code;
};

window.joinByCode = () => {
  const code = ($("#joinCode").value || "").trim().toUpperCase();
  if (!code) { toast("请输入房间号", "err"); return; }
  location.hash = "#/room/" + code;
};

// ---------- 房间页 ----------
async function renderRoom(code) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  const { ok, data } = await API.json(`/api/rooms/${code}`);
  if (!ok) {
    $("#app").innerHTML = `<div class="page">${headerHtml("rooms")}<div class="empty"><div class="empty-icon">🎮</div><p>${escapeHtml(data.error || "房间不存在")}</p><button class="btn btn-secondary" style="margin-top:16px" onclick="location.hash='#/rooms'">返回大厅</button></div></div>`;
    return;
  }
  const room = data.room;
  const soup = data.soup;
  const messages = data.messages || [];
  store.currentRoomCode = code;

  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("rooms")}
      <div class="container room-layout">
        <div class="chat-panel">
          <div class="chat-header">
            <div>
              <div class="chat-title">${escapeHtml(room.code)}</div>
              <div class="chat-code">${room.ai_enabled ? "AI 主持人已启用" : "无 AI"}</div>
            </div>
            <button class="btn-icon" onclick="location.hash='#/rooms'" title="离开">←</button>
          </div>
          <div class="chat-body" id="chatBody"></div>
          ${room.status === "ended" ? `<div class="chat-ended-notice">房间已结束，无法继续发言</div>` : ""}
          <div class="chat-input">
            <input id="chatInput" placeholder="发言…" onkeydown="if(event.key==='Enter')sendChat()" ${room.status === "ended" ? "disabled" : ""} />
            <button class="btn btn-secondary" onclick="sendChat()" title="发送" ${room.status === "ended" ? "disabled" : ""}>💬</button>
            ${room.ai_enabled && room.status !== "ended" ? `<button class="btn btn-primary" onclick="sendAiQuestion()" title="向AI提问">🤖</button>` : ""}
          </div>
        </div>
        <div class="room-side">
          <div class="side-card">
            <h4>当前汤</h4>
            <div id="roomSoupBox">${
              soup
                ? `<div class="soup-mini"><div class="t">${escapeHtml(soup.title)}</div><div class="s">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""}</div><div class="surface">${escapeHtml(soup.surface || "")}</div></div>`
                : `<div class="no-soup">尚未选汤</div>`
            }</div>
            ${room.host?.id === store.user?.id ? `<button class="select-soup-btn" onclick="pickSoupForRoomUpdate('${escapeJs(room.code)}')">${soup ? "换一碗汤" : "选择一碗汤"}</button>` : ""}
          </div>
          <div class="side-card">
            <h4>玩法</h4>
            <p class="ai-hint" style="margin:0">
              看汤面 → 向 AI 提是非题 → AI 只答「是/否/无关」→ 猜出汤底。
              ${room.host?.id === store.user?.id ? "你是房主，可换汤。" : ""}
              ${!KeyMgr.has() && room.ai_enabled ? '<br><span class="warn">提示：AI 已启用但你还没填 DeepSeek Key（右上角 ⚙）</span>' : ""}
            </p>
          </div>
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  // 渲染历史消息
  const body = $("#chatBody");
  body.innerHTML = messages.map(renderMsg).join("");
  body.scrollTop = body.scrollHeight;

  // 启动轮询
  store.pollLastId = messages.length ? messages[messages.length - 1].id : 0;
  connectRoom(code);
}

function renderMsg(m) {
  const mine = store.user && m.username === store.user.username;
  const cls = ["msg"];
  if (mine) cls.push("mine");
  if (m.msg_type) cls.push(m.msg_type);
  const prefix = m.msg_type === "ai_question" ? "🤔 " :
                 m.msg_type === "ai_answer" ? "🤖 " :
                 m.msg_type === "system" ? "" : "";
  const who = m.msg_type === "system" ? "" : (m.username || "游客") + " · ";
  return `<div class="${cls.join(" ")}">
    <div class="meta">${who}${escapeHtml(m.created_at || "")}</div>
    <div class="bubble">${prefix}${escapeHtml(m.content)}</div>
  </div>`;
}

// 用轮询替代 WebSocket
function connectRoom(code) {
  toast("已加入房间 " + code, "ok");
  if (store.pollTimer) clearInterval(store.pollTimer);
  store.pollTimer = setInterval(() => pollMessages(code), 1500);
}

async function pollMessages(code) {
  if (location.hash !== "#/room/" + code) {
    if (store.pollTimer) { clearInterval(store.pollTimer); store.pollTimer = null; }
    return;
  }
  const since = store.pollLastId || 0;
  const { ok, data } = await API.json(`/api/rooms/${code}/messages?since=${since}`);
  if (!ok || !data.messages) return;
  const body = $("#chatBody");
  if (!body) return;
  data.messages.forEach((m) => {
    body.insertAdjacentHTML("beforeend", renderMsg(m));
    if (m.id && m.id > (store.pollLastId || 0)) store.pollLastId = m.id;
  });
  body.scrollTop = body.scrollHeight;
}

async function refreshRoomSoup(code) {
  const { ok, data } = await API.json(`/api/rooms/${code}`);
  if (!ok) return;
  const box = $("#roomSoupBox");
  const soup = data.soup;
  if (!box) return;
  box.innerHTML = soup
    ? `<div class="soup-mini"><div class="t">${escapeHtml(soup.title)}</div><div class="s">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""}</div><div class="surface">${escapeHtml(soup.surface || "")}</div></div>`
    : `<div class="no-soup">尚未选汤</div>`;
}

// 聊天发送
window.sendChat = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  input.value = "";
  const { ok, data } = await API.post(`/api/rooms/${code}/messages`, { content });
  if (!ok) toast(data.error || "发送失败", "err");
};

// 房间内向 AI 提问
window.sendAiQuestion = async () => {
  const input = $("#chatInput");
  if (!input) return;
  const content = input.value.trim();
  if (!content) return;
  const code = store.currentRoomCode;
  if (!code) { toast("未在房间内", "err"); return; }
  if (!KeyMgr.has()) { toast("请先在右上角 ⚙ 配置 DeepSeek Key", "err"); return; }
  input.value = "";
  const { ok, data } = await API.post(`/api/rooms/${code}/ai-question`, {
    content,
    api_key: KeyMgr.get(),
  });
  if (!ok) { toast(data.error || "提问失败", "err"); return; }
  if (data.error) toast(data.error, "err");
};

window.pickSoupForRoomUpdate = (code) => {
  if (!store.soups.length) { toast("汤数据未加载", "err"); return; }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">换一碗汤</h2></div>
        <button class="modal-close" onclick="closeModal(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="soup-picker" id="soupUpdateList" data-code="${escapeHtml(code)}">
          ${store.soups.map((s) => `
            <div class="soup-pick-item" data-id="${s.id}">
              <div class="t">${escapeHtml(s.title)}</div>
              <div class="s">${escapeHtml(s.season)}${s.episode ? " · " + escapeHtml(s.episode) : ""}</div>
            </div>
          `).join("")}
        </div>
      </div>
    </div>
  `;
  const list = $("#soupUpdateList");
  const roomCode = list.dataset.code;
  list.querySelectorAll(".soup-pick-item").forEach((item) => {
    item.addEventListener("click", () => updateRoomSoup(roomCode, +item.dataset.id));
  });
  document.body.style.overflow = "hidden";
};

window.updateRoomSoup = async (code, soupId) => {
  const { ok, data } = await API.post(`/api/rooms/${code}/select-soup`, { soup_id: soupId });
  if (!ok) { toast(data.error || "换汤失败", "err"); return; }
  closeModal();
  await refreshRoomSoup(code);
  toast("已换汤", "ok");
};

// ---------- 个人中心 ----------
async function renderProfile() {
  if (!store.user) { location.hash = "#/auth"; return; }
  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("profile")}
      <div class="container">
        <div class="profile-header">
          <div class="avatar">${escapeHtml(store.user.username.slice(0, 1).toUpperCase())}</div>
          <div class="info">
            <h2>${escapeHtml(store.user.username)}</h2>
            <p>${escapeHtml(store.user.email)} · 账号ID #${store.user.id}</p>
          </div>
        </div>
        <div class="profile-grid">
          <div class="profile-card">
            <h3>账号</h3>
            <div class="profile-stat"><span>用户名</span><span class="v">${escapeHtml(store.user.username)}</span></div>
            <div class="profile-stat"><span>邮箱</span><span class="v">${escapeHtml(store.user.email)}</span></div>
            <div class="profile-stat"><span>账号ID</span><span class="v">#${store.user.id}</span></div>
            <button class="btn btn-danger" style="margin-top:16px;width:100%" onclick="doLogout()">退出登录</button>
          </div>
          <div class="profile-card">
            <h3>AI 主持人</h3>
            <div class="profile-stat"><span>DeepSeek Key</span><span class="v">${KeyMgr.has() ? "已配置" : "未配置"}</span></div>
            <button class="btn btn-secondary" style="margin-top:16px;width:100%" onclick="openSettings()">配置 Key</button>
          </div>
        </div>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;
}

window.doLogout = async () => {
  await API.post("/api/auth/logout", {});
  store.user = null;
  toast("已退出", "ok");
  location.hash = "#/";
};

// ---------- 设置弹窗（Key 管理） ----------
function openSettings() {
  const root = $("#modalRoot");
  if (!root) return;
  const has = KeyMgr.has();
  root.innerHTML = `
    <div class="overlay open" onclick="closeSettings(event)"></div>
    <div class="modal open">
      <div class="modal-header">
        <div><h2 class="modal-title">AI 设置</h2></div>
        <button class="modal-close" onclick="closeSettings(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="warning-box">
          <strong>⚠ 安全提示</strong>
          Key 仅保存在你的浏览器 localStorage 中，每次提问会随请求发到后端并透传给 DeepSeek。
          请勿在公共电脑上保存；后端不存储、不记录你的 Key。
        </div>
        <div class="settings-row">
          <span class="settings-label">当前状态</span>
          <span class="settings-status ${has ? "ok" : "no"}">${has ? "已配置" : "未配置"}</span>
        </div>
        <div class="field" style="margin-top:16px">
          <label>DeepSeek API Key</label>
          <input class="input mono" id="apiKeyInput" type="password" placeholder="sk-..." value="${has ? escapeHtml(KeyMgr.get()) : ""}" />
        </div>
        <div id="testResult"></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" onclick="testKey()" id="testBtn">测试连接</button>
        <button class="btn btn-secondary" onclick="clearKey()">清空</button>
        <button class="btn btn-primary" onclick="saveKey()">保存</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
}
window.openSettings = openSettings;

function closeSettings(e) {
  if (e) e.stopPropagation();
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
  document.body.style.overflow = "";
  // 局部刷新 header 的 Key 状态，不重渲染整页
  const btn = document.querySelector(".header .btn-icon");
  if (btn) {
    btn.classList.toggle("has-key", KeyMgr.has());
  }
  // 如果在首页，刷新统计栏
  if ((location.hash.replace(/^#/, "") === "/" || location.hash === "") && typeof renderStats === "function") {
    renderStats();
  }
}
window.closeSettings = closeSettings;

window.saveKey = () => {
  const v = $("#apiKeyInput").value.trim();
  if (!v) { toast("Key 不能为空", "err"); return; }
  KeyMgr.set(v);
  toast("已保存", "ok");
  closeSettings();
};

window.clearKey = () => {
  KeyMgr.set("");
  toast("已清空", "ok");
  $("#apiKeyInput").value = "";
  closeSettings();
};

window.testKey = async () => {
  const v = $("#apiKeyInput").value.trim();
  if (!v) { toast("请先填写 Key", "err"); return; }
  const btn = $("#testBtn");
  btn.disabled = true;
  btn.innerHTML = `<span class="spinner sm"></span> 测试中…`;
  const res = await KeyMgr.test(v);
  btn.disabled = false;
  btn.textContent = "测试连接";
  const box = $("#testResult");
  box.innerHTML = `<div class="form-${res.ok ? "success" : "error"}">${escapeHtml(res.msg)}</div>`;
  if (res.ok) {
    // 测试通过则保存
    KeyMgr.set(v);
  }
};

// ---------- 管理员后台 ----------
const AdminAPI = {
  async get(path) { return API.json(path); },
  async post(path, body) { return API.post(path, body); },
  async put(path, body) { return API.put(path, body); },
  async del(path) { return API.del(path); },
};

function renderAdmin(hash) {
  if (!store.user) { toast("请先登录", "err"); location.hash = "#/auth"; return; }
  if (!store.user.is_admin) { toast("无管理员权限", "err"); location.hash = "#/"; return; }

  const section = hash.replace(/^\/admin\/?/, "") || "dashboard";
  const sections = [
    { id: "dashboard", label: "📊 仪表盘" },
    { id: "users", label: "👤 用户管理" },
    { id: "soups", label: "🍲 汤管理" },
    { id: "rooms", label: "🎮 房间管理" },
    { id: "settings", label: "⚙️ 系统设置" },
    { id: "logs", label: "📋 操作日志" },
    { id: "system", label: "🖥️ 系统信息" },
  ];

  $("#app").innerHTML = `
    <div class="page">
      ${headerHtml("admin")}
      <div class="admin-layout container">
        <aside class="admin-sidebar">
          ${sections.map(s => `<a href="#/admin/${s.id}" class="admin-nav-item ${section === s.id ? "active" : ""}">${s.label}</a>`).join("")}
        </aside>
        <main class="admin-main" id="adminContent">
          <div class="admin-loading"><div class="spinner"></div></div>
        </main>
      </div>
      <div id="modalRoot"></div>
    </div>
  `;

  if (section === "dashboard") adminDashboard();
  else if (section === "users") adminUsers();
  else if (section === "soups") adminSoups();
  else if (section === "rooms") adminRooms();
  else if (section === "settings") adminSettings();
  else if (section === "logs") adminLogs();
  else if (section === "system") adminSystem();
  else adminDashboard();
}

function fmtSize(bytes) {
  if (bytes < 1024) return bytes + " B";
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
  return (bytes / 1048576).toFixed(2) + " MB";
}

// ---- 仪表盘 ----
async function adminDashboard() {
  const { ok, data } = await AdminAPI.get("/api/admin/stats");
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  const cards = [
    { label: "用户总数", value: data.users_total, sub: `今日 +${data.new_users_today}`, icon: "👤" },
    { label: "汤总数", value: data.soups_total, sub: "收录", icon: "🍲" },
    { label: "房间总数", value: data.rooms_total, sub: `进行中 ${data.rooms_playing} / 已结束 ${data.rooms_ended}`, icon: "🎮" },
    { label: "消息总数", value: data.messages_total, sub: `今日 +${data.messages_today}`, icon: "💬" },
    { label: "管理员", value: data.users_admin, sub: "人", icon: "🔑" },
    { label: "封禁用户", value: data.users_banned, sub: "人", icon: "🚫" },
    { label: "数据库大小", value: fmtSize(data.db_size || 0), sub: "SQLite", icon: "💾" },
    { label: "PHP 版本", value: data.php_version, sub: "", icon: "🐘" },
  ];

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">📊 仪表盘</h2>
      <div class="admin-stat-grid">
        ${cards.map(c => `
          <div class="admin-stat-card">
            <div class="admin-stat-icon">${c.icon}</div>
            <div class="admin-stat-info">
              <div class="admin-stat-value">${escapeHtml(String(c.value))}</div>
              <div class="admin-stat-label">${escapeHtml(c.label)}</div>
              ${c.sub ? `<div class="admin-stat-sub">${escapeHtml(c.sub)}</div>` : ""}
            </div>
          </div>
        `).join("")}
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">最近注册用户</h3>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>管理员</th><th>注册时间</th></tr></thead>
        <tbody>
          ${(data.recent_users || []).map(u => `
            <tr>
              <td>${u.id}</td>
              <td>${escapeHtml(u.username)}${u.is_banned ? ' <span class="tag tag-danger">封禁</span>' : ''}</td>
              <td>${escapeHtml(u.email)}</td>
              <td>${u.is_admin ? '<span class="tag tag-success">管理员</span>' : '-'}</td>
              <td>${escapeHtml(u.created_at)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">最近创建的房间</h3>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>房间号</th><th>房主</th><th>状态</th><th>创建时间</th></tr></thead>
        <tbody>
          ${(data.recent_rooms || []).map(r => `
            <tr>
              <td>${r.id}</td>
              <td><a href="#/room/${escapeHtml(r.code)}">${escapeHtml(r.code)}</a></td>
              <td>${escapeHtml(r.host_name || '-')}</td>
              <td>${r.status === 'playing' ? '<span class="tag tag-success">进行中</span>' : '<span class="tag tag-muted">已结束</span>'}</td>
              <td>${escapeHtml(r.created_at)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  `;
}

// ---- 用户管理 ----
async function adminUsers(page = 1) {
  const q = $("#adminSearch")?.value || "";
  const { ok, data } = await AdminAPI.get(`/api/admin/users?page=${page}&q=${encodeURIComponent(q)}`);
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "加载失败")}</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">👤 用户管理</h2>
        <div class="admin-toolbar-right">
          <input class="input admin-search" id="adminSearch" placeholder="搜索用户名/邮箱…" value="${escapeHtml(q)}" onkeydown="if(event.key==='Enter')adminUsers(1)" />
          <button class="btn btn-primary admin-btn-sm" onclick="adminUsers(1)">搜索</button>
          <button class="btn btn-secondary admin-btn-sm" onclick="adminUserCreateModal()">+ 创建用户</button>
        </div>
      </div>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead>
        <tbody>
          ${data.users.map(u => `
            <tr>
              <td>${u.id}</td>
              <td>${escapeHtml(u.username)}</td>
              <td>${escapeHtml(u.email)}</td>
              <td>${u.is_admin ? '<span class="tag tag-success">管理员</span>' : '普通'}</td>
              <td>${u.is_banned ? '<span class="tag tag-danger">封禁</span>' : '<span class="tag tag-success">正常</span>'}</td>
              <td>${escapeHtml(u.created_at)}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminUserToggleAdmin(${u.id}, ${u.is_admin})">${u.is_admin ? '取消管理' : '设为管理'}</button>
                <button class="admin-act-btn" onclick="adminUserToggleBan(${u.id}, ${u.is_banned})">${u.is_banned ? '解封' : '封禁'}</button>
                <button class="admin-act-btn" onclick="adminUserResetPwdModal(${u.id}, '${escapeJs(u.username)}')">重置密码</button>
                ${u.id !== store.user.id ? `<button class="admin-act-btn danger" onclick="adminUserDelete(${u.id}, '${escapeJs(u.username)}')">删除</button>` : ''}
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminUsers")}
    </div>
  `;
  $("#adminSearch")?.addEventListener("input", () => {});
}
window.adminUsers = adminUsers;

window.adminUserCreateModal = () => {
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">创建用户</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field"><label>用户名</label><input class="input" id="cu_username" /></div>
        <div class="field"><label>邮箱</label><input class="input" id="cu_email" type="email" /></div>
        <div class="field"><label>密码（至少6位）</label><input class="input" id="cu_password" type="password" /></div>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="cu_is_admin" /> 设为管理员</label>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="adminUserCreateDo()">创建</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminUserCreateDo = async () => {
  const username = $("#cu_username").value.trim();
  const email = $("#cu_email").value.trim();
  const password = $("#cu_password").value;
  const is_admin = $("#cu_is_admin").checked;
  if (!username || !email || password.length < 6) { toast("请填写完整，密码至少6位", "err"); return; }
  const { ok, data } = await AdminAPI.post("/api/admin/users", { username, email, password, is_admin });
  if (!ok) { toast(data.error || "创建失败", "err"); return; }
  toast("用户创建成功", "ok");
  closeModal();
  adminUsers(1);
};

window.adminUserToggleAdmin = async (id, current) => {
  const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}`, { is_admin: !current });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("已更新", "ok");
  adminUsers();
};

window.adminUserToggleBan = async (id, current) => {
  if (!current) {
    const reason = prompt("封禁原因（可留空）：");
    if (reason === null) return;
    const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}`, { is_banned: true, banned_reason: reason });
    if (!ok) { toast(data.error || "操作失败", "err"); return; }
    toast("已封禁", "ok");
  } else {
    const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}`, { is_banned: false });
    if (!ok) { toast(data.error || "操作失败", "err"); return; }
    toast("已解封", "ok");
  }
  adminUsers();
};

window.adminUserResetPwdModal = (id, name) => {
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">重置密码 — ${escapeHtml(name)}</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field"><label>新密码（至少6位）</label><input class="input" id="rp_password" type="password" /></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="adminUserResetPwdDo(${id})">重置</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminUserResetPwdDo = async (id) => {
  const password = $("#rp_password").value;
  if (password.length < 6) { toast("密码至少6位", "err"); return; }
  const { ok, data } = await AdminAPI.put(`/api/admin/users/${id}/password`, { password });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("密码已重置", "ok");
  closeModal();
};

window.adminUserDelete = async (id, name) => {
  if (!confirm(`确认删除用户「${name}」？此操作不可撤销。`)) return;
  const { ok, data } = await AdminAPI.del(`/api/admin/users/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  adminUsers();
};

// ---- 汤管理 ----
async function adminSoups(page = 1) {
  const q = $("#adminSearch")?.value || "";
  // 立即显示 loading，避免点击翻页时"无反应"的错觉
  const c = $("#adminContent");
  if (c) c.innerHTML = `<div class="admin-loading"><div class="spinner"></div></div>`;
  const { ok, data } = await AdminAPI.get(`/api/admin/soups?page=${page}&q=${encodeURIComponent(q)}`);
  if (!c) return;
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "加载失败")}</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">🍲 汤管理</h2>
        <div class="admin-toolbar-right">
          <input class="input admin-search" id="adminSearch" placeholder="搜索标题/系列/文件名/汤面/汤底…" value="${escapeHtml(q)}" oninput="adminSoupsSearchDebounced()" onkeydown="if(event.key==='Enter')adminSoups(1)" />
          <button class="btn btn-primary admin-btn-sm" onclick="adminSoups(1)">搜索</button>
          <button class="btn btn-secondary admin-btn-sm" onclick="adminSoupEditModal()">+ 新建汤</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsImport()">📁 批量导入</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsReimport()" title="用最新解析规则重新解析所有汤（增量：更新已有/删除多余/导入新增）">🔄 重新解析</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsRebuild()" title="强制清空所有汤再全量重新导入（换汤源后用这个）">💥 强制重建</button>
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoupsBroken()" title="检测汤面/汤底为空或疑似内容混入的汤">🩺 坏汤检测</button>
        </div>
      </div>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>标题</th><th>系列</th><th>集</th><th>文件名</th><th>操作</th></tr></thead>
        <tbody>
          ${data.soups.map(s => `
            <tr>
              <td>${s.id}</td>
              <td>${escapeHtml(s.title)}</td>
              <td>${escapeHtml(s.season || '-')}</td>
              <td>${escapeHtml(s.episode || '-')}</td>
              <td>${escapeHtml(s.filename)}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminSoupEditModal(${s.id})">编辑</button>
                <button class="admin-act-btn danger" onclick="adminSoupDelete(${s.id}, '${escapeJs(s.title)}')">删除</button>
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminSoups")}
    </div>
  `;
}
window.adminSoups = adminSoups;

window.adminSoupEditModal = async (id) => {
  let soup = { title: '', season: '', episode: '', surface: '', base: '', host_manual: '', extra: '', filename: '' };
  if (id) {
    // 用单条汤接口获取，避免只查第一页导致跨页汤找不到
    const { ok, data } = await API.json(`/api/soups/${id}`);
    if (ok && data) soup = { ...soup, ...data };
  }
  const root = $("#modalRoot");
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">${id ? '编辑汤' : '新建汤'}</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        <div class="field"><label>标题</label><input class="input" id="es_title" value="${escapeHtml(soup.title)}" /></div>
        <div class="admin-row">
          <div class="field"><label>系列/季</label><input class="input" id="es_season" value="${escapeHtml(soup.season || '')}" /></div>
          <div class="field"><label>集</label><input class="input" id="es_episode" value="${escapeHtml(soup.episode || '')}" /></div>
        </div>
        <div class="field"><label>文件名（不含.md，留空自动生成）</label><input class="input" id="es_filename" value="${escapeHtml(soup.filename || '')}" /></div>
        <div class="field"><label>汤面<span class="field-hint">玩家可见的谜面</span></label><textarea class="input" id="es_surface" rows="4">${escapeHtml(soup.surface || '')}</textarea></div>
        <div class="field"><label>汤底<span class="field-hint">仅 AI 可读，不主动透露给玩家</span></label><textarea class="input" id="es_base" rows="5">${escapeHtml(soup.base || '')}</textarea></div>
        <div class="field"><label>主持人手册<span class="field-hint">特殊玩法指令（撒谎策略/回答格式/触发语句等），AI 必须遵守</span></label><textarea class="input" id="es_host_manual" rows="5" placeholder="如：伪人/隐藏主持人玩法、规则触发条件、回答格式约束等">${escapeHtml(soup.host_manual || '')}</textarea></div>
        <div class="field"><label>其他内容<span class="field-hint">故事梗概/怪谈解析/隐藏规则/收容物设定等补充内容，仅用于 AI 理解全貌</span></label><textarea class="input" id="es_extra" rows="4" placeholder="如：故事梗概、怪谈解析、隐藏规则等">${escapeHtml(soup.extra || '')}</textarea></div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" onclick="closeModal(event)">取消</button>
        <button class="btn btn-primary" onclick="adminSoupSave(${id || 0})">保存</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminSoupSave = async (id) => {
  const body = {
    title: $("#es_title").value.trim(),
    season: $("#es_season").value.trim(),
    episode: $("#es_episode").value.trim(),
    filename: $("#es_filename").value.trim(),
    surface: $("#es_surface").value,
    base: $("#es_base").value,
    host_manual: $("#es_host_manual").value,
    extra: $("#es_extra").value,
  };
  if (!body.title || !body.surface || !body.base) { toast("标题、汤面、汤底不能为空", "err"); return; }
  const { ok, data } = id
    ? await AdminAPI.put(`/api/admin/soups/${id}`, body)
    : await AdminAPI.post("/api/admin/soups", body);
  if (!ok) { toast(data.error || "保存失败", "err"); return; }
  toast("已保存", "ok");
  closeModal();
  adminSoups();
};

window.adminSoupDelete = async (id, title) => {
  if (!confirm(`确认删除「${title}」？`)) return;
  const { ok, data } = await AdminAPI.del(`/api/admin/soups/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  adminSoups();
};

window.adminSoupsImport = async () => {
  if (!confirm("从汤源目录批量导入 MD 文件？已存在的会跳过。")) return;
  const { ok, data } = await AdminAPI.post("/api/admin/soups/import", {});
  if (!ok) { toast(data.error || "导入失败", "err"); return; }
  toast(data.msg || "导入完成", "ok");
  adminSoups();
};

window.adminSoupsReimport = async () => {
  if (!confirm("用最新解析规则重新解析所有汤？\n增量模式：更新已有汤、删除源文件不存在的、导入新增的。")) return;
  const { ok, data } = await AdminAPI.post("/api/admin/soups/reimport", {});
  if (!ok) { toast(data.error || "重新解析失败", "err"); return; }
  toast(data.msg || "已重新解析", "ok");
  adminSoups();
};

window.adminSoupsRebuild = async () => {
  if (!confirm("⚠️ 强制重建：删除数据库中所有汤，再从源目录全量重新导入。\n\n这会清空所有汤（包括手动新建的），确定继续？")) return;
  const { ok, data } = await AdminAPI.post("/api/admin/soups/rebuild", {});
  if (!ok) { toast(data.error || "强制重建失败", "err"); return; }
  toast(data.msg || "强制重建完成", "ok");
  adminSoups();
};

// 搜索防抖：输入时实时搜索（300ms 延迟）
let _adminSoupsSearchTimer = null;
window.adminSoupsSearchDebounced = () => {
  clearTimeout(_adminSoupsSearchTimer);
  _adminSoupsSearchTimer = setTimeout(() => adminSoups(1), 300);
};

window.adminSoupsBroken = async () => {
  const c = $("#adminContent");
  c.innerHTML = `<div class="admin-section"><div class="admin-toolbar"><h2 class="admin-title">🩺 坏汤检测</h2></div><div class="admin-loading">检测中…</div></div>`;
  const { ok, data } = await AdminAPI.get("/api/admin/soups/broken");
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "检测失败")}</div>`; return; }

  const broken = data.broken || [];
  if (!broken.length) {
    c.innerHTML = `
      <div class="admin-section">
        <div class="admin-toolbar"><h2 class="admin-title">🩺 坏汤检测</h2></div>
        <div class="admin-empty">
          <p>✅ 全部 ${data.total} 碗汤均正常，未发现汤面/汤底为空或内容混入。</p>
          <button class="btn btn-ghost" onclick="adminSoups()">← 返回汤管理</button>
        </div>
      </div>`;
    return;
  }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">🩺 坏汤检测</h2>
        <div class="admin-toolbar-right">
          <button class="btn btn-ghost admin-btn-sm" onclick="adminSoups()">← 返回汤管理</button>
          <button class="btn btn-primary admin-btn-sm" onclick="adminSoupsReimport()">🔄 重新解析后再测</button>
        </div>
      </div>
      <p class="admin-tip">共 ${data.total} 碗汤，发现 <strong>${broken.length}</strong> 碗需要修复。点击「编辑」可手动修正汤面/汤底/主持人手册/其他内容。</p>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>标题</th><th>系列/集</th><th>问题</th><th>字数(面/底/手册)</th><th>操作</th></tr></thead>
        <tbody>
          ${broken.map(s => `
            <tr>
              <td>${s.id}</td>
              <td>${escapeHtml(s.title)}</td>
              <td>${escapeHtml(s.season || '-')} ${escapeHtml(s.episode || '')}</td>
              <td><span class="admin-tag-warn">${s.issues.map(escapeHtml).join('；')}</span></td>
              <td>${s.surface_len} / ${s.base_len} / ${s.host_manual_len}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminSoupEditModal(${s.id})">编辑</button>
                <a class="admin-act-btn" href="#/soup/${s.id}" target="_blank">预览</a>
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
    </div>
  `;
};

// ---- 房间管理 ----
async function adminRooms(page = 1) {
  const q = $("#adminSearch")?.value || "";
  const status = $("#adminStatusFilter")?.value || "";
  const { ok, data } = await AdminAPI.get(`/api/admin/rooms?page=${page}&q=${encodeURIComponent(q)}&status=${encodeURIComponent(status)}`);
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">${escapeHtml(data.error || "加载失败")}</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <div class="admin-toolbar">
        <h2 class="admin-title">🎮 房间管理</h2>
        <div class="admin-toolbar-right">
          <input class="input admin-search" id="adminSearch" placeholder="搜索房间号/房主…" value="${escapeHtml(q)}" onkeydown="if(event.key==='Enter')adminRooms(1)" />
          <select class="input admin-select" id="adminStatusFilter" onchange="adminRooms(1)">
            <option value="">全部状态</option>
            <option value="playing" ${status === 'playing' ? 'selected' : ''}>进行中</option>
            <option value="ended" ${status === 'ended' ? 'selected' : ''}>已结束</option>
          </select>
          <button class="btn btn-primary admin-btn-sm" onclick="adminRooms(1)">搜索</button>
        </div>
      </div>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>房间号</th><th>房主</th><th>汤</th><th>状态</th><th>AI</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
          ${data.rooms.map(r => `
            <tr>
              <td>${r.id}</td>
              <td><a href="#/room/${escapeHtml(r.code)}">${escapeHtml(r.code)}</a></td>
              <td>${escapeHtml(r.host_name || '-')}</td>
              <td>${escapeHtml(r.soup_title || '-')}</td>
              <td>${r.status === 'playing' ? '<span class="tag tag-success">进行中</span>' : '<span class="tag tag-muted">已结束</span>'}</td>
              <td>${r.ai_enabled ? '✅' : '❌'}</td>
              <td>${escapeHtml(r.created_at)}</td>
              <td class="admin-actions">
                <button class="admin-act-btn" onclick="adminRoomToggleStatus(${r.id}, '${escapeJs(r.status)}')">${r.status === 'playing' ? '结束' : '恢复'}</button>
                <button class="admin-act-btn" onclick="adminRoomMessages(${r.id}, '${escapeJs(r.code)}')">消息</button>
                <button class="admin-act-btn danger" onclick="adminRoomDelete(${r.id}, '${escapeJs(r.code)}')">删除</button>
              </td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminRooms")}
    </div>
  `;
}
window.adminRooms = adminRooms;

window.adminRoomToggleStatus = async (id, status) => {
  const newStatus = status === 'playing' ? 'ended' : 'playing';
  const { ok, data } = await AdminAPI.put(`/api/admin/rooms/${id}/status`, { status: newStatus });
  if (!ok) { toast(data.error || "操作失败", "err"); return; }
  toast("已更新", "ok");
  adminRooms();
};

window.adminRoomDelete = async (id, code) => {
  if (!confirm(`确认删除房间「${code}」？所有消息也会被删除。`)) return;
  const { ok, data } = await AdminAPI.del(`/api/admin/rooms/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  adminRooms();
};

window.adminRoomMessages = async (roomId, code) => {
  const { ok, data } = await AdminAPI.get(`/api/admin/rooms/${roomId}/messages`);
  if (!ok) { toast("加载失败", "err"); return; }
  const root = $("#modalRoot");
  const msgs = data.messages || [];
  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open">
      <div class="modal-header"><div><h2 class="modal-title">房间 ${escapeHtml(code)} 消息</h2></div><button class="modal-close" onclick="closeModal(event)">✕</button></div>
      <div class="modal-body">
        ${msgs.length === 0 ? '<p class="admin-empty">暂无消息</p>' : `
          <table class="admin-table">
            <thead><tr><th>ID</th><th>用户</th><th>类型</th><th>内容</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
              ${msgs.map(m => `
                <tr>
                  <td>${m.id}</td>
                  <td>${escapeHtml(m.username || '系统')}</td>
                  <td>${escapeHtml(m.msg_type)}</td>
                  <td class="admin-msg-content">${escapeHtml(m.content)}</td>
                  <td>${escapeHtml(m.created_at)}</td>
                  <td><button class="admin-act-btn danger" onclick="adminMsgDelete(${m.id})">删除</button></td>
                </tr>
              `).join("")}
            </tbody>
          </table>
        `}
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
};

window.adminMsgDelete = async (id) => {
  const { ok, data } = await AdminAPI.del(`/api/admin/messages/${id}`);
  if (!ok) { toast(data.error || "删除失败", "err"); return; }
  toast("已删除", "ok");
  closeModal();
};

// ---- 系统设置 ----
async function adminSettings() {
  const { ok, data } = await AdminAPI.get("/api/admin/settings");
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  const s = data.settings || {};
  const config = data.config || {};
  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">⚙️ 系统设置</h2>
      <div class="admin-form">
        <div class="admin-form-row">
          <label>
            <input type="checkbox" id="set_allow_submit" ${s.allow_submit === '1' || config.ALLOW_SUBMIT ? 'checked' : ''} />
            允许用户投稿汤（投稿功能）
          </label>
        </div>
        <div class="admin-form-row">
          <label>房间消息保留条数（0=全部）</label>
          <input class="input" type="number" id="set_room_msg_limit" value="${s.room_msg_limit || config.ROOM_MSG_LIMIT || 200}" />
        </div>
        <button class="btn btn-primary" onclick="adminSettingsSave()">保存设置</button>
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">当前配置（只读）</h3>
      <table class="admin-table">
        <thead><tr><th>配置项</th><th>值</th></tr></thead>
        <tbody>
          ${Object.entries(config).map(([k, v]) => `<tr><td>${escapeHtml(k)}</td><td>${escapeHtml(String(v))}</td></tr>`).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">数据备份</h3>
      <p class="admin-hint">点击下载当前数据库完整备份（SQLite 文件）。</p>
      <a class="btn btn-secondary" href="/api/admin/backup" download>💾 下载数据库备份</a>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">🔧 运维工具</h3>
      <p class="admin-hint">一键拉取代码更新、清除缓存、压缩数据库等。</p>
      <a class="btn btn-primary" href="/tool.php" target="_blank">🔧 打开运维工具</a>
    </div>
  `;
}

window.adminSettingsSave = async () => {
  const body = {
    allow_submit: $("#set_allow_submit").checked,
    room_msg_limit: parseInt($("#set_room_msg_limit").value) || 0,
  };
  const { ok, data } = await AdminAPI.put("/api/admin/settings", body);
  if (!ok) { toast(data.error || "保存失败", "err"); return; }
  toast("设置已保存", "ok");
};

// ---- 操作日志 ----
async function adminLogs(page = 1) {
  const { ok, data } = await AdminAPI.get(`/api/admin/logs?page=${page}`);
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">📋 操作日志</h2>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>操作人</th><th>动作</th><th>目标</th><th>详情</th><th>IP</th><th>时间</th></tr></thead>
        <tbody>
          ${data.logs.map(l => `
            <tr>
              <td>${l.id}</td>
              <td>${escapeHtml(l.admin_name || '-')}</td>
              <td><span class="tag tag-info">${escapeHtml(l.action)}</span></td>
              <td>${escapeHtml(l.target || '-')}</td>
              <td>${escapeHtml(l.detail || '-')}</td>
              <td>${escapeHtml(l.ip || '-')}</td>
              <td>${escapeHtml(l.created_at)}</td>
            </tr>
          `).join("")}
        </tbody>
      </table>
      ${adminPagination(data.page, data.total_pages, "adminLogs")}
    </div>
  `;
}
window.adminLogs = adminLogs;

// ---- 系统信息 ----
async function adminSystem() {
  const { ok, data } = await AdminAPI.get("/api/admin/system");
  const c = $("#adminContent");
  if (!ok) { c.innerHTML = `<div class="admin-error">加载失败</div>`; return; }

  c.innerHTML = `
    <div class="admin-section">
      <h2 class="admin-title">🖥️ 系统信息</h2>
      <div class="admin-stat-grid">
        <div class="admin-stat-card"><div class="admin-stat-icon">🐘</div><div><div class="admin-stat-value">${escapeHtml(data.php_version)}</div><div class="admin-stat-label">PHP 版本</div></div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">💾</div><div><div class="admin-stat-value">${fmtSize(data.db_size || 0)}</div><div class="admin-stat-label">数据库大小</div></div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">📂</div><div><div class="admin-stat-value">${data.disk_free ? fmtSize(data.disk_free) : '-'}</div><div class="admin-stat-label">磁盘剩余</div></div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">🕐</div><div><div class="admin-stat-value" style="font-size:1rem">${escapeHtml(data.server_time)}</div><div class="admin-stat-label">服务器时间</div></div></div>
      </div>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">数据表行数</h3>
      <table class="admin-table">
        <thead><tr><th>表名</th><th>行数</th></tr></thead>
        <tbody>
          ${Object.entries(data.table_sizes || {}).map(([t, n]) => `<tr><td>${escapeHtml(t)}</td><td>${n}</td></tr>`).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">PHP 扩展</h3>
      <table class="admin-table">
        <thead><tr><th>扩展</th><th>状态</th></tr></thead>
        <tbody>
          ${Object.entries(data.extensions || {}).map(([e, v]) => `<tr><td>${escapeHtml(e)}</td><td>${v ? '✅ 已加载' : '❌ 未加载'}</td></tr>`).join("")}
        </tbody>
      </table>
    </div>

    <div class="admin-section">
      <h3 class="admin-subtitle">PHP 配置</h3>
      <table class="admin-table">
        <thead><tr><th>配置项</th><th>值</th></tr></thead>
        <tbody>
          <tr><td>SAPI</td><td>${escapeHtml(data.php_sapi || '-')}</td></tr>
          <tr><td>操作系统</td><td>${escapeHtml(data.php_os || '-')}</td></tr>
          <tr><td>时区</td><td>${escapeHtml(data.timezone || '-')}</td></tr>
          <tr><td>最大上传</td><td>${escapeHtml(data.max_upload || '-')}</td></tr>
          <tr><td>最大 POST</td><td>${escapeHtml(data.max_post || '-')}</td></tr>
          <tr><td>内存限制</td><td>${escapeHtml(data.memory_limit || '-')}</td></tr>
          <tr><td>数据库路径</td><td>${escapeHtml(data.db_path || '-')}</td></tr>
          <tr><td>汤源目录</td><td>${escapeHtml(data.soups_dir || '-')} (${data.soups_dir_exists ? '存在' : '不存在'})</td></tr>
        </tbody>
      </table>
    </div>
  `;
}

// ---- 分页组件 ----
function adminPagination(page, totalPages, fnName) {
  if (totalPages <= 1) return '';
  let btns = [];
  if (page > 1) btns.push(`<button class="admin-page-btn" onclick="${fnName}(${page - 1})">上一页</button>`);
  const start = Math.max(1, page - 2);
  const end = Math.min(totalPages, page + 2);
  for (let i = start; i <= end; i++) {
    btns.push(`<button class="admin-page-btn ${i === page ? 'active' : ''}" onclick="${fnName}(${i})">${i}</button>`);
  }
  if (page < totalPages) btns.push(`<button class="admin-page-btn" onclick="${fnName}(${page + 1})">下一页</button>`);
  return `<div class="admin-pagination">${btns.join('')}</div>`;
}

// ---------- 初始化 ----------
async function boot() {
  // 拉取用户状态
  const { ok, data } = await API.json("/api/auth/me");
  if (ok && data.user) store.user = data.user;
  if (data && data.csrf_token) store.csrfToken = data.csrf_token;
  // 预加载汤列表（用于房间选汤）
  API.json("/api/soups").then(({ ok, data }) => {
    if (ok) { store.soups = data.soups || []; store.seasons = data.seasons || []; applyFilters(); }
  });
  route();
}

boot();
