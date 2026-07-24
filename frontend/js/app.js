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
    const r = await fetch(path, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
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
    const { ok, data } = await API.post("/api/ai/ask", {
      soup_id: store.soups[0].id,
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
  if (hash === "/profile") return renderProfile();
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

// ---------- 详情弹窗 + 单人 AI ----------
async function openSoup(id) {
  const { ok, data } = await API.json(`/api/soups/${id}`);
  if (!ok) { toast("加载失败", "err"); return; }
  store.selected = data;
  renderSoupModal(data, false);
}
window.openSoup = openSoup;

function classifyAnswer(ans) {
  const a = (ans || "").trim();
  if (a.includes("猜中")) return "win";
  if (a === "是" || a.startsWith("是")) return "yes";
  if (a === "否" || a.startsWith("否")) return "no";
  if (a.includes("无关")) return "irrelevant";
  return "";
}

function renderSoupModal(soup, revealed) {
  const root = $("#modalRoot");
  if (!root) return;
  const hist = store.aiHistory[soup.id] || [];
  const keyOk = KeyMgr.has();

  root.innerHTML = `
    <div class="overlay open" onclick="closeModal(event)"></div>
    <div class="modal open" role="dialog" aria-modal="true">
      <div class="modal-header">
        <div>
          <h2 class="modal-title">${escapeHtml(soup.title)}</h2>
          <div class="modal-meta">${escapeHtml(soup.season)}${soup.episode ? " · " + escapeHtml(soup.episode) : ""} · ${escapeHtml(soup.filename)}</div>
        </div>
        <button class="modal-close" onclick="closeModal(event)">✕</button>
      </div>
      <div class="modal-body">
        <div class="section-label">汤面</div>
        <div class="text-block">${escapeHtml(soup.surface || "（暂无汤面）")}</div>

        <div class="section-label ai">向 AI 主持人提问</div>
        <div class="ai-area">
          <p class="ai-hint">
            ${keyOk
              ? "AI 只会回答「是」「否」「无关」，猜中汤底会提示。汤底不会泄露给 AI 之外的任何人。"
              : `<span class="warn">尚未配置 DeepSeek API Key，</span>点击右上角 ⚙ 填入你自己的 Key 后即可提问。`}
          </p>
          <div class="ai-history" id="aiHistory">
            ${hist.length === 0
              ? `<div class="ai-empty">还没有提问记录。试试问「主角是男性吗？」</div>`
              : hist.map((t) => `
                <div class="ai-turn">
                  <div class="ai-q">${escapeHtml(t.q)}</div>
                  <div class="ai-a ${classifyAnswer(t.a)}">${escapeHtml(t.a)}</div>
                </div>
              `).join("")}
          </div>
          <div class="ai-input-row">
            <input type="text" id="aiQuestionInput" placeholder="问 AI 一个是非题…" ${keyOk ? "" : "disabled"} onkeydown="if(event.key==='Enter')askAiSingle(${soup.id})" />
            <button onclick="askAiSingle(${soup.id})" ${keyOk ? "" : "disabled"}>提问</button>
          </div>
        </div>

        <div class="section-label base">汤底</div>
        <div class="text-block reveal" id="baseBlock">
          <div class="${revealed ? "" : "reveal-blur"}">${escapeHtml(soup.base || "（暂无汤底）")}</div>
          ${!revealed ? `<div class="reveal-cover" onclick="revealBase(event)"><span>👁 点击揭晓汤底</span></div>` : ""}
        </div>
      </div>
      <div class="modal-actions">
        <a class="btn btn-primary" href="/api/soups/${soup.id}/download" download>⬇ 下载 Markdown</a>
        <button class="btn btn-secondary" onclick="newRoomFromSoup(${soup.id})">🎮 用这碗汤开房间</button>
        <button class="btn btn-secondary" onclick="closeModal(event)">✕ 关闭</button>
      </div>
    </div>
  `;
  document.body.style.overflow = "hidden";
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
  if (store.selected) renderSoupModal(store.selected, true);
}
window.revealBase = revealBase;

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
          <div class="chat-input">
            <input id="chatInput" placeholder="发言…" onkeydown="if(event.key==='Enter')sendChat()" />
            <button class="btn btn-secondary" style="min-width:auto;flex:0 0 auto;padding:0 14px" onclick="sendChat()" title="发送">💬</button>
            ${room.ai_enabled ? `<button class="btn btn-primary" style="min-width:auto;flex:0 0 auto;padding:0 14px" onclick="sendAiQuestion()" title="向AI提问">🤖</button>` : ""}
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
            ${room.host?.id === store.user?.id ? `<button class="select-soup-btn" onclick="pickSoupForRoomUpdate('${escapeHtml(room.code)}')">${soup ? "换一碗汤" : "选择一碗汤"}</button>` : ""}
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
  if (location.hash.replace(/^#/, "") === "/" || location.hash === "") {
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

// ---------- 初始化 ----------
async function boot() {
  // 拉取用户状态
  const { ok, data } = await API.json("/api/auth/me");
  if (ok && data.user) store.user = data.user;
  // 预加载汤列表（用于房间选汤）
  API.json("/api/soups").then(({ ok, data }) => {
    if (ok) { store.soups = data.soups || []; store.seasons = data.seasons || []; applyFilters(); }
  });
  route();
}

boot();
