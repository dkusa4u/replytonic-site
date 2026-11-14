/**
 * ReplyTonic Chrome Extension — Popup Script v1.0
 * Handles login/logout, usage display, and quick-action buttons.
 */

const API_BASE = 'https://replytonic.com/api';

// ── DOM helpers ─────────────────────────────────────────────────────────────
const $  = (id) => document.getElementById(id);
const show = (id) => { const el = $(id); if (el) el.style.display = ''; };
const hide = (id) => { const el = $(id); if (el) el.style.display = 'none'; };

// ── Startup ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  const token = await getToken();
  const user  = await getUser();

  if (token && user) {
    renderMainView(user);
    loadUsage(token);
  } else {
    renderLoginView();
  }

  bindButtons();
});

// ── Auth state helpers ────────────────────────────────────────────────────────
function getToken() {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage({ type: 'RT_GET_TOKEN' }, (res) => {
      resolve(res ? res.token : null);
    });
  });
}

function getUser() {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage({ type: 'RT_GET_USER' }, (res) => {
      resolve(res ? res.user : null);
    });
  });
}

function clearAuth() {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage({ type: 'RT_CLEAR_TOKEN' }, () => resolve());
  });
}

// ── Views ─────────────────────────────────────────────────────────────────────
function renderLoginView() {
  show('view-login');
  hide('view-main');
}

function renderMainView(user) {
  hide('view-login');
  show('view-main');
  show('user-badge');

  const plan = (user.plan || 'free').toLowerCase();
  const pill = $('plan-pill');
  if (plan !== 'free') {
    pill.textContent = 'Premium';
    pill.classList.add('premium');
  } else {
    pill.textContent = 'Free';
  }

  $('footer-email').textContent = user.email || '';

  if (plan === 'free') {
    show('upgrade-strip');
  }
}

// ── Usage loader ──────────────────────────────────────────────────────────────
async function loadUsage(token) {
  try {
    const res  = await fetch(`${API_BASE}/usage.php`, {
      method : 'POST',
      headers: {
        'Content-Type' : 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({}),
    });
    if (!res.ok) return;
    const data = await res.json();

    const used  = data.used  || 0;
    const limit = data.limit || 5;
    const pct   = Math.min(100, Math.round((used / limit) * 100));

    $('usage-count').textContent = `${used} / ${limit}`;
    const fill = $('usage-fill');
    fill.style.width = pct + '%';
    if (pct >= 100) fill.classList.add('full');

    if (pct >= 100) {
      $('usage-hint').textContent = 'Daily limit reached. Resets at midnight.';
    } else if (pct >= 80) {
      $('usage-hint').textContent = `${limit - used} rewrites left today`;
    }
  } catch (e) {
    // Non-critical — just skip the usage display
  }
}

// ── Button binding ────────────────────────────────────────────────────────────
function bindButtons() {
  // Login
  $('btn-login')?.addEventListener('click', handleLogin);
  $('login-password')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') handleLogin();
  });

  // Logout
  $('btn-logout')?.addEventListener('click', handleLogout);

  // Quick action grid — open the side panel to the right tab
  document.querySelectorAll('.rt-quick-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tab = btn.dataset.tab;
      await openPanel(tab);
    });
  });
}

// ── Login handler ─────────────────────────────────────────────────────────────
async function handleLogin() {
  const email    = $('login-email')?.value.trim();
  const password = $('login-password')?.value;

  if (!email || !password) {
    showLoginError('Please enter your email and password.');
    return;
  }

  const btn = $('btn-login');
  btn.disabled    = true;
  btn.textContent = 'Signing in…';
  hideLoginError();

  try {
    const res = await fetch(`${API_BASE}/auth.php`, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ action: 'login', email, password }),
    });
    const data = await res.json();

    if (!res.ok || data.error) {
      showLoginError(data.message || data.error || 'Invalid credentials. Please try again.');
      return;
    }

    // Store token + user via background service worker
    await new Promise((resolve) => {
      chrome.runtime.sendMessage({ type: 'RT_SET_TOKEN', token: data.token }, resolve);
    });
    await new Promise((resolve) => {
      chrome.runtime.sendMessage({ type: 'RT_SET_USER', user: data.user }, resolve);
    });

    renderMainView(data.user);
    loadUsage(data.token);
  } catch (err) {
    showLoginError('Could not connect to ReplyTonic. Check your connection.');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Sign In';
  }
}

function showLoginError(msg) {
  const el = $('login-error');
  if (!el) return;
  el.textContent   = msg;
  el.style.display = '';
}

function hideLoginError() {
  const el = $('login-error');
  if (el) el.style.display = 'none';
}

// ── Logout handler ────────────────────────────────────────────────────────────
async function handleLogout() {
  await clearAuth();
  renderLoginView();
}

// ── Open panel in active tab ──────────────────────────────────────────────────
async function openPanel(tab = 'rewrite') {
  try {
    const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!activeTab?.id) return;

    // Dispatch custom event that content.js listens for
    await chrome.scripting.executeScript({
      target : { tabId: activeTab.id },
      args   : [tab],
      func   : (tabName) => {
        window.dispatchEvent(new CustomEvent('rt:open', { detail: { tab: tabName } }));
      },
    });

    // Close the popup
    window.close();
  } catch (err) {
    // Page may not have content script (chrome://, new tab page etc.)
    // Open replytonic.com as fallback
    chrome.tabs.create({ url: 'https://replytonic.com' });
  }
}
