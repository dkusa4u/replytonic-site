/**
 * ReplyTonic Chrome Extension — Background Service Worker
 * Handles: auth token storage, context menu, messaging bridge
 */

const API_BASE = 'https://replytonic.com/api';

// ── Context Menu Setup ────────────────────────────────────────────────────────
chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.create({
    id: 'replytonic-open',
    title: '✨ Open in ReplyTonic',
    contexts: ['selection', 'editable'],
  });
  chrome.contextMenus.create({
    id: 'replytonic-rewrite',
    title: '⚡ Rewrite Selected Text',
    contexts: ['selection'],
  });
  chrome.contextMenus.create({
    id: 'replytonic-grammar',
    title: '✅ Check Grammar',
    contexts: ['selection'],
  });
  chrome.contextMenus.create({
    id: 'replytonic-summarize',
    title: '📋 Summarize',
    contexts: ['selection'],
  });
  chrome.contextMenus.create({
    id: 'replytonic-humanize',
    title: '🧑 Humanize AI Text',
    contexts: ['selection'],
  });
});

// ── Context Menu Click ────────────────────────────────────────────────────────
chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  const selectedText = info.selectionText || '';
  const action       = info.menuItemId;

  // Map context menu actions to panel tabs
  const tabMap = {
    'replytonic-open'     : 'rewrite',
    'replytonic-rewrite'  : 'rewrite',
    'replytonic-grammar'  : 'grammar',
    'replytonic-summarize': 'summarize',
    'replytonic-humanize' : 'humanize',
  };
  const targetTab = tabMap[action] || 'rewrite';

  try {
    await chrome.scripting.executeScript({
      target : { tabId: tab.id },
      func   : (text, tab) => {
        // Dispatch a custom event that content.js listens for
        window.dispatchEvent(new CustomEvent('rt:open', {
          detail: { text, tab }
        }));
      },
      args: [selectedText, targetTab],
    });
  } catch (err) {
    console.warn('[ReplyTonic BG] executeScript failed:', err);
  }
});

// ── Message Handler ───────────────────────────────────────────────────────────
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg.type === 'RT_GET_TOKEN') {
    chrome.storage.local.get(['rt_token'], (data) => {
      sendResponse({ token: data.rt_token || null });
    });
    return true; // keep channel open for async response
  }

  if (msg.type === 'RT_SET_TOKEN') {
    chrome.storage.local.set({ rt_token: msg.token }, () => {
      sendResponse({ ok: true });
    });
    return true;
  }

  if (msg.type === 'RT_CLEAR_TOKEN') {
    chrome.storage.local.remove(['rt_token', 'rt_user'], () => {
      sendResponse({ ok: true });
    });
    return true;
  }

  if (msg.type === 'RT_GET_USER') {
    chrome.storage.local.get(['rt_user'], (data) => {
      sendResponse({ user: data.rt_user || null });
    });
    return true;
  }

  if (msg.type === 'RT_SET_USER') {
    chrome.storage.local.set({ rt_user: msg.user }, () => {
      sendResponse({ ok: true });
    });
    return true;
  }

  if (msg.type === 'RT_LOGIN') {
    // Perform login from background (avoids CORS issues in popup)
    loginUser(msg.email, msg.password)
      .then(result => sendResponse({ ok: true, ...result }))
      .catch(err  => sendResponse({ ok: false, error: err.message }));
    return true;
  }

  if (msg.type === 'RT_API_CALL') {
    // Proxy API calls from content script through background
    apiCall(msg.endpoint, msg.data)
      .then(result => sendResponse({ ok: true, data: result }))
      .catch(err   => sendResponse({ ok: false, error: err.message }));
    return true;
  }
});

// ── Auth Functions ────────────────────────────────────────────────────────────
async function loginUser(email, password) {
  const res = await fetch(`${API_BASE}/auth.php`, {
    method : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body   : JSON.stringify({ action: 'login', email, password }),
  });
  const data = await res.json();
  if (!res.ok || data.error) throw new Error(data.error || data.message || 'Login failed');

  // Store token + user info
  await chrome.storage.local.set({
    rt_token: data.token,
    rt_user : {
      id   : data.user?.id,
      email: data.user?.email || email,
      plan : data.user?.plan || 'free',
      name : data.user?.name || email.split('@')[0],
    },
  });
  return { token: data.token, user: data.user };
}

// ── Generic API Proxy ─────────────────────────────────────────────────────────
async function apiCall(endpoint, data) {
  const stored = await chrome.storage.local.get(['rt_token']);
  const token  = stored.rt_token;

  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE}/${endpoint}`, {
    method : 'POST',
    headers,
    body   : JSON.stringify(data),
  });

  const result = await res.json();
  if (!res.ok) throw new Error(result.error || result.message || `API error ${res.status}`);
  return result;
}

// ── Keep service worker alive (Manifest V3 workaround) ────────────────────────
chrome.runtime.onConnect.addListener((port) => {
  if (port.name === 'keepalive') {
    port.onDisconnect.addListener(() => {});
  }
});
