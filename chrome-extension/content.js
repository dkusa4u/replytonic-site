/**
 * ReplyTonic Chrome Extension — Content Script v1.0
 * Injects the ReplyTonic side panel into any page using Shadow DOM for style isolation.
 * Supports: Gmail, LinkedIn, Slack, Twitter/X, Outlook Web
 */
(function () {
  'use strict';
  if (window.__RT_LOADED) return;
  window.__RT_LOADED = true;

  // ── Constants ────────────────────────────────────────────────────────────────
  const API_BASE    = 'https://replytonic.com/api';
  const HOST_ID     = 'rt-ext-host';
  const BRAND       = '#6366f1';
  const BRAND_LIGHT = 'rgba(99,102,241,0.12)';

  // ── State ─────────────────────────────────────────────────────────────────────
  let shadow        = null;
  let panelEl       = null;
  let triggerBtn    = null;
  let lastFocused   = null;
  let capturedText  = '';
  let activeTab     = 'rewrite';
  let isOpen        = false;
  let settings      = {
    platform: 'Email',
    tone    : 'Professional',
    tone2   : 'None',
    length  : 'Same',
    emoji   : false,
  };
  let toneScoreTimer = null;

  // ── Shadow DOM Host ───────────────────────────────────────────────────────────
  function init() {
    if (document.getElementById(HOST_ID)) return;

    const host = document.createElement('div');
    host.id    = HOST_ID;
    host.style.cssText = 'all:initial!important;position:fixed!important;z-index:2147483647!important;top:0!important;right:0!important;width:0!important;height:0!important;overflow:visible!important;';
    document.body.appendChild(host);

    shadow = host.attachShadow({ mode: 'open' });

    // Inject styles
    const style     = document.createElement('style');
    style.textContent = RT_CSS;
    shadow.appendChild(style);

    // Build panel + trigger
    const panel = document.createElement('div');
    panel.innerHTML = RT_PANEL_HTML;
    shadow.appendChild(panel);
    panelEl = shadow.getElementById('rt-panel');

    const trig = document.createElement('div');
    trig.id    = 'rt-trigger';
    trig.innerHTML = '<span>RT</span>';
    trig.title = 'Open ReplyTonic (Alt+Shift+R)';
    shadow.appendChild(trig);
    triggerBtn = trig;

    bindEvents();
    watchFocus();
    watchSelections();
    setupMutationObserver();

    if (isGmail())   { setTimeout(setupGmail,   800); }
    if (isSlack())   { setTimeout(setupSlack,   800); }
    if (isLinkedIn()){ setTimeout(setupLinkedIn,800); }
  }

  // ── CSS ───────────────────────────────────────────────────────────────────────
  const RT_CSS = `
  *{box-sizing:border-box;margin:0;padding:0}
  #rt-panel{
    position:fixed;top:0;right:0;width:380px;height:100vh;
    background:#0f0f13;border-left:1px solid rgba(255,255,255,.08);
    display:flex;flex-direction:column;
    transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    font-size:13px;color:#e2e8f0;z-index:2147483646;overflow:hidden;
  }
  #rt-panel.open{transform:translateX(0)}
  .rt-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 16px;background:#17171e;border-bottom:1px solid rgba(255,255,255,.07);
    flex-shrink:0;
  }
  .rt-logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:15px}
  .rt-logo-icon{
    width:28px;height:28px;border-radius:8px;
    background:linear-gradient(135deg,#6366f1,#8b5cf6);
    display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:900;color:#fff;
  }
  .rt-close{
    width:28px;height:28px;border:none;cursor:pointer;border-radius:6px;
    background:rgba(255,255,255,.07);color:#94a3b8;display:flex;align-items:center;justify-content:center;
    font-size:16px;line-height:1;transition:all .15s;
  }
  .rt-close:hover{background:rgba(255,255,255,.14);color:#e2e8f0}

  /* Captured text area */
  .rt-capture{margin:12px;padding:10px 12px;background:#1a1a24;border-radius:10px;border:1px solid rgba(255,255,255,.07);flex-shrink:0}
  .rt-capture-label{font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
  .rt-capture-text{font-size:12px;color:#94a3b8;line-height:1.5;max-height:72px;overflow:hidden;position:relative;white-space:pre-wrap;word-break:break-word}
  .rt-capture-text.empty{color:#475569;font-style:italic}
  .rt-capture-text::after{content:'';position:absolute;bottom:0;left:0;right:0;height:20px;background:linear-gradient(transparent,#1a1a24)}
  .rt-capture-row{display:flex;justify-content:space-between;align-items:center;margin-top:8px}
  .rt-capture-count{font-size:10px;color:#475569}
  .rt-refresh-btn{font-size:10px;color:#6366f1;cursor:pointer;border:none;background:none;padding:0;text-decoration:underline}

  /* Tabs */
  .rt-tabs{display:flex;padding:0 12px;gap:4px;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0;overflow-x:auto;scrollbar-width:none}
  .rt-tabs::-webkit-scrollbar{display:none}
  .rt-tab{
    padding:9px 10px;font-size:11px;font-weight:600;color:#64748b;
    cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;
    white-space:nowrap;transition:all .15s;text-transform:uppercase;letter-spacing:.5px;
  }
  .rt-tab:hover{color:#94a3b8}
  .rt-tab.active{color:#6366f1;border-bottom-color:#6366f1}

  /* Settings row */
  .rt-settings{padding:10px 12px;display:flex;flex-wrap:wrap;gap:6px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0}
  .rt-select{
    flex:1;min-width:100px;padding:6px 8px;background:#1a1a24;border:1px solid rgba(255,255,255,.1);
    border-radius:7px;color:#cbd5e1;font-size:11px;cursor:pointer;outline:none;
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 8px center;padding-right:22px;
  }
  .rt-select:focus{border-color:#6366f1}
  .rt-toggle{display:flex;align-items:center;gap:4px;font-size:11px;color:#64748b;cursor:pointer;white-space:nowrap}
  .rt-toggle input{accent-color:#6366f1;cursor:pointer}

  /* Content / scroll area */
  .rt-content{flex:1;overflow-y:auto;padding:12px;scrollbar-width:thin;scrollbar-color:#2a2a3a transparent}
  .rt-content::-webkit-scrollbar{width:4px}
  .rt-content::-webkit-scrollbar-thumb{background:#2a2a3a;border-radius:4px}

  /* Tab pane */
  .rt-pane{display:none}
  .rt-pane.active{display:block}

  /* Result cards */
  .rt-card{background:#1a1a24;border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:12px;margin-bottom:10px;cursor:pointer;transition:all .15s}
  .rt-card:hover{border-color:rgba(99,102,241,.4);background:#1e1e2e}
  .rt-card.selected{border-color:#6366f1;background:rgba(99,102,241,.08)}
  .rt-card-label{font-size:9px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
  .rt-card-text{font-size:12px;color:#cbd5e1;line-height:1.6}
  .rt-card-actions{display:flex;gap:6px;margin-top:8px}

  /* Buttons */
  .rt-btn{
    padding:7px 14px;border-radius:8px;border:none;cursor:pointer;
    font-size:12px;font-weight:600;transition:all .15s;
  }
  .rt-btn-primary{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff}
  .rt-btn-primary:hover{opacity:.88;transform:translateY(-1px)}
  .rt-btn-primary:active{transform:translateY(0)}
  .rt-btn-primary:disabled{opacity:.4;cursor:not-allowed;transform:none}
  .rt-btn-ghost{background:rgba(255,255,255,.07);color:#94a3b8}
  .rt-btn-ghost:hover{background:rgba(255,255,255,.12);color:#e2e8f0}
  .rt-btn-sm{padding:5px 10px;font-size:11px}
  .rt-btn-apply{background:rgba(99,102,241,.18);color:#a5b4fc;border:1px solid rgba(99,102,241,.3)}
  .rt-btn-apply:hover{background:rgba(99,102,241,.28);color:#c7d2fe}

  /* Footer */
  .rt-footer{padding:10px 12px;border-top:1px solid rgba(255,255,255,.07);display:flex;gap:8px;flex-shrink:0;background:#0f0f13}
  .rt-footer .rt-btn-primary{flex:1}

  /* Run button row */
  .rt-run-row{display:flex;gap:8px;margin-bottom:12px}
  .rt-run-row .rt-btn-primary{flex:1}

  /* Loading */
  .rt-loading{text-align:center;padding:32px 16px;color:#475569}
  .rt-spinner{width:28px;height:28px;border:3px solid rgba(99,102,241,.2);border-top-color:#6366f1;border-radius:50%;animation:rt-spin .7s linear infinite;margin:0 auto 12px}
  @keyframes rt-spin{to{transform:rotate(360deg)}}

  /* Error */
  .rt-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:8px;padding:10px 12px;color:#fca5a5;font-size:12px;margin-bottom:10px}

  /* Grammar view */
  .rt-grammar-score{text-align:center;padding:16px;background:#1a1a24;border-radius:10px;margin-bottom:12px}
  .rt-grammar-score .score-num{font-size:36px;font-weight:800;color:#6366f1}
  .rt-grammar-score .score-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px}
  .rt-grammar-verdict{font-size:13px;color:#cbd5e1;text-align:center;margin-bottom:12px}
  .rt-change-item{display:flex;align-items:flex-start;gap:8px;padding:8px;background:#1a1a24;border-radius:8px;margin-bottom:6px;font-size:11px}
  .rt-change-dot{width:6px;height:6px;border-radius:50%;background:#6366f1;flex-shrink:0;margin-top:3px}

  /* AI detector */
  .rt-ai-meter{margin-bottom:16px}
  .rt-ai-meter-bar{height:10px;background:rgba(255,255,255,.07);border-radius:100px;overflow:hidden;margin:8px 0}
  .rt-ai-meter-fill{height:100%;border-radius:100px;transition:width .6s cubic-bezier(.4,0,.2,1);background:linear-gradient(90deg,#22c55e,#eab308,#ef4444)}
  .rt-ai-verdict{font-size:14px;font-weight:700;text-align:center;padding:10px;border-radius:10px;margin-bottom:8px}
  .rt-verdict-human{color:#22c55e;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25)}
  .rt-verdict-mixed{color:#eab308;background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.25)}
  .rt-verdict-ai{color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25)}
  .rt-signal-tag{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:100px;font-size:10px;font-weight:600;margin:3px;border:1px solid}
  .rt-signal-ai{background:rgba(239,68,68,.08);color:#fca5a5;border-color:rgba(239,68,68,.2)}
  .rt-signal-human{background:rgba(34,197,94,.08);color:#86efac;border-color:rgba(34,197,94,.2)}

  /* Humanize */
  .rt-humanize-scores{display:flex;gap:8px;margin-bottom:12px}
  .rt-humanize-score{flex:1;text-align:center;padding:10px;background:#1a1a24;border-radius:10px}
  .rt-humanize-score .num{font-size:24px;font-weight:800}
  .rt-humanize-score .lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
  .before-score{color:#ef4444}
  .after-score{color:#22c55e}

  /* Summarize */
  .rt-summary-text{font-size:13px;color:#cbd5e1;line-height:1.65;padding:12px;background:#1a1a24;border-radius:10px;margin-bottom:10px}
  .rt-bullet-list{list-style:none;padding:0;margin-bottom:10px}
  .rt-bullet-list li{display:flex;align-items:flex-start;gap:8px;padding:7px 12px;background:#1a1a24;border-radius:8px;margin-bottom:5px;font-size:12px;color:#cbd5e1}
  .rt-bullet-list li::before{content:'•';color:#6366f1;flex-shrink:0;font-size:14px;margin-top:-1px}
  .rt-action-item{display:flex;align-items:flex-start;gap:8px;padding:7px 12px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);border-radius:8px;margin-bottom:5px;font-size:12px;color:#a5b4fc}
  .rt-action-item::before{content:'→';flex-shrink:0;color:#6366f1}
  .rt-word-count{font-size:10px;color:#475569;text-align:right;margin-bottom:8px}

  /* Live tone score strip (Gmail-specific) */
  .rt-tone-strip{padding:6px 12px;background:rgba(99,102,241,.06);border-top:1px solid rgba(99,102,241,.15);font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:8px;flex-shrink:0}
  .rt-tone-strip .label{font-weight:600;color:#6366f1}
  .rt-tone-mini{display:flex;gap:6px;align-items:center;flex:1}
  .rt-tone-pill{background:rgba(99,102,241,.18);color:#a5b4fc;padding:2px 7px;border-radius:100px;font-size:10px;font-weight:700}

  /* Plagiarism checker */
  .rt-plag-meter{margin-bottom:16px}
  .rt-plag-ring{display:flex;align-items:center;justify-content:center;gap:16px;padding:16px;background:#1a1a24;border-radius:12px;margin-bottom:12px}
  .rt-plag-circle{position:relative;width:80px;height:80px;flex-shrink:0}
  .rt-plag-circle svg{transform:rotate(-90deg)}
  .rt-plag-circle-num{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center}
  .rt-plag-circle-num .big{font-size:22px;font-weight:800;line-height:1}
  .rt-plag-circle-num .lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
  .rt-plag-info{flex:1}
  .rt-plag-verdict{font-size:13px;font-weight:700;padding:8px 12px;border-radius:8px;margin-bottom:8px;text-align:center}
  .rt-plag-verdict-clean{color:#22c55e;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25)}
  .rt-plag-verdict-low{color:#86efac;background:rgba(34,197,94,.07);border:1px solid rgba(34,197,94,.18)}
  .rt-plag-verdict-moderate{color:#eab308;background:rgba(234,179,8,.1);border:1px solid rgba(234,179,8,.25)}
  .rt-plag-verdict-high{color:#f97316;background:rgba(249,115,22,.1);border:1px solid rgba(249,115,22,.25)}
  .rt-plag-verdict-critical{color:#ef4444;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25)}
  .rt-plag-phrase{padding:8px 10px;background:#1a1a24;border-radius:8px;margin-bottom:5px;font-size:11px}
  .rt-plag-phrase-text{color:#fca5a5;font-style:italic;margin-bottom:3px}
  .rt-plag-phrase-meta{color:#64748b;font-size:10px;display:flex;gap:6px;flex-wrap:wrap}
  .rt-plag-phrase-source{color:#94a3b8}
  .rt-plag-phrase-sev{padding:1px 6px;border-radius:100px;font-size:9px;font-weight:700}
  .sev-low{background:rgba(234,179,8,.15);color:#fde047}
  .sev-medium{background:rgba(249,115,22,.15);color:#fb923c}
  .sev-high{background:rgba(239,68,68,.15);color:#fca5a5}
  .rt-plag-orig{color:#86efac;font-size:11px;padding:6px 10px;background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.15);border-radius:7px;margin-bottom:4px}
  .rt-plag-suggestion{padding:8px 10px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.18);border-radius:8px;margin-bottom:5px;font-size:11px;color:#a5b4fc}
  .rt-plag-suggestion::before{content:'💡 ';}

  /* Upgrade nag */
  .rt-upgrade{background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.25);border-radius:10px;padding:12px;text-align:center;margin-bottom:10px}
  .rt-upgrade p{font-size:12px;color:#94a3b8;margin-bottom:8px}
  .rt-upgrade a{color:#a5b4fc;text-decoration:none;font-weight:600}

  /* Login prompt */
  .rt-login-prompt{padding:16px;text-align:center;color:#64748b;font-size:12px}
  .rt-login-prompt p{margin-bottom:10px}

  /* Floating trigger */
  #rt-trigger{
    position:fixed;padding:5px 10px;background:#6366f1;color:#fff;
    border-radius:8px;font-family:-apple-system,sans-serif;font-size:11px;font-weight:800;
    cursor:pointer;z-index:2147483645;display:none;box-shadow:0 4px 16px rgba(99,102,241,.5);
    transition:opacity .15s;letter-spacing:.5px;user-select:none;
  }
  #rt-trigger:hover{background:#818cf8}
  `;

  // ── Panel HTML ────────────────────────────────────────────────────────────────
  const RT_PANEL_HTML = `
  <div id="rt-panel">
    <div class="rt-header">
      <div class="rt-logo">
        <div class="rt-logo-icon">RT</div>
        <span>ReplyTonic</span>
      </div>
      <button class="rt-close" id="rt-close-btn" title="Close">×</button>
    </div>

    <div class="rt-capture">
      <div class="rt-capture-label">📝 Captured Text</div>
      <div class="rt-capture-text empty" id="rt-captured-text">Focus on a text field or select text to get started…</div>
      <div class="rt-capture-row">
        <span class="rt-capture-count" id="rt-char-count"></span>
        <button class="rt-refresh-btn" id="rt-refresh-btn" title="Re-capture from active field">↺ Refresh</button>
      </div>
    </div>

    <div class="rt-tabs" id="rt-tabs">
      <button class="rt-tab active" data-tab="rewrite">⚡ Rewrite</button>
      <button class="rt-tab" data-tab="reply">💬 Reply</button>
      <button class="rt-tab" data-tab="summarize">📋 Summarize</button>
      <button class="rt-tab" data-tab="grammar">✅ Grammar</button>
      <button class="rt-tab" data-tab="humanize">🧑 Humanize</button>
      <button class="rt-tab" data-tab="detect">🔍 AI Detect</button>
      <button class="rt-tab" data-tab="plagiarism">📄 Plagiarism</button>
    </div>

    <div class="rt-settings" id="rt-settings">
      <select class="rt-select" id="rt-platform">
        <option>Email</option><option>LinkedIn</option><option>Slack</option>
        <option>Twitter/X</option><option>WhatsApp</option><option>SMS</option>
      </select>
      <select class="rt-select" id="rt-tone">
        <option>Professional</option><option>Friendly</option><option>Assertive</option>
        <option>Empathetic</option><option>Formal</option><option>Casual</option>
        <option>Concise</option><option>Enthusiastic</option>
      </select>
      <select class="rt-select" id="rt-length">
        <option>Same</option><option>Shorter</option><option>Longer</option>
      </select>
      <label class="rt-toggle"><input type="checkbox" id="rt-emoji"> Emoji</label>
    </div>

    <div class="rt-content" id="rt-content">

      <!-- REWRITE PANE -->
      <div class="rt-pane active" id="pane-rewrite">
        <div class="rt-run-row">
          <button class="rt-btn rt-btn-primary" id="btn-rewrite">⚡ Rewrite Now</button>
          <button class="rt-btn rt-btn-ghost rt-btn-sm" id="btn-copy-rewrite" style="display:none">📋 Copy</button>
        </div>
        <div id="result-rewrite"></div>
      </div>

      <!-- REPLY PANE -->
      <div class="rt-pane" id="pane-reply">
        <div class="rt-run-row">
          <button class="rt-btn rt-btn-primary" id="btn-reply">💬 Generate Replies</button>
        </div>
        <div id="result-reply"></div>
      </div>

      <!-- SUMMARIZE PANE -->
      <div class="rt-pane" id="pane-summarize">
        <div class="rt-run-row">
          <select class="rt-select" id="rt-sum-length" style="max-width:150px">
            <option value="medium">Summary</option>
            <option value="short">Short (1-2 sentences)</option>
            <option value="bullet">Bullet Points</option>
            <option value="actions">Action Items</option>
          </select>
          <button class="rt-btn rt-btn-primary" id="btn-summarize">📋 Summarize</button>
        </div>
        <div id="result-summarize"></div>
      </div>

      <!-- GRAMMAR PANE -->
      <div class="rt-pane" id="pane-grammar">
        <div class="rt-run-row">
          <button class="rt-btn rt-btn-primary" id="btn-grammar-fix">✅ Fix Grammar</button>
          <button class="rt-btn rt-btn-ghost rt-btn-sm" id="btn-grammar-check">🔎 Check Only</button>
        </div>
        <div id="result-grammar"></div>
      </div>

      <!-- HUMANIZE PANE -->
      <div class="rt-pane" id="pane-humanize">
        <div class="rt-run-row">
          <button class="rt-btn rt-btn-primary" id="btn-humanize">🧑 Humanize Text</button>
          <button class="rt-btn rt-btn-ghost rt-btn-sm" id="btn-copy-humanize" style="display:none">📋 Copy</button>
        </div>
        <div id="result-humanize"></div>
      </div>

      <!-- AI DETECT PANE -->
      <div class="rt-pane" id="pane-detect">
        <div class="rt-run-row">
          <button class="rt-btn rt-btn-primary" id="btn-detect">🔍 Detect AI Content</button>
        </div>
        <div id="result-detect"></div>
      </div>

      <!-- PLAGIARISM PANE -->
      <div class="rt-pane" id="pane-plagiarism">
        <div class="rt-run-row">
          <button class="rt-btn rt-btn-primary" id="btn-plagiarism">📄 Check Plagiarism</button>
        </div>
        <div id="result-plagiarism"></div>
      </div>

    </div><!-- /rt-content -->

    <div class="rt-footer">
      <button class="rt-btn rt-btn-apply" id="btn-apply" style="display:none">Apply to Field ✓</button>
      <a href="https://replytonic.com" target="_blank" style="font-size:10px;color:#475569;text-decoration:none;margin-left:auto;align-self:center">replytonic.com</a>
    </div>
  </div>
  `;

  // ── Bind Events ───────────────────────────────────────────────────────────────
  function bindEvents() {
    const $ = (id) => shadow.getElementById(id);

    // Close button
    $('rt-close-btn').addEventListener('click', closePanel);

    // Tabs
    shadow.getElementById('rt-tabs').addEventListener('click', (e) => {
      const btn = e.target.closest('.rt-tab');
      if (!btn) return;
      activeTab = btn.dataset.tab;
      shadow.querySelectorAll('.rt-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === activeTab));
      shadow.querySelectorAll('.rt-pane').forEach(p => p.classList.toggle('active', p.id === `pane-${activeTab}`));
      toggleSettings();
    });

    // Settings
    $('rt-platform').addEventListener('change', e => { settings.platform = e.target.value; });
    $('rt-tone')    .addEventListener('change', e => { settings.tone     = e.target.value; });
    $('rt-length')  .addEventListener('change', e => { settings.length   = e.target.value; });
    $('rt-emoji')   .addEventListener('change', e => { settings.emoji    = e.target.checked; });

    // Refresh capture
    $('rt-refresh-btn').addEventListener('click', () => { captureText(); });

    // Run buttons
    $('btn-rewrite')      .addEventListener('click', () => runRewrite());
    $('btn-reply')        .addEventListener('click', () => runReply());
    $('btn-summarize')    .addEventListener('click', () => runSummarize());
    $('btn-grammar-fix')  .addEventListener('click', () => runGrammar('fix'));
    $('btn-grammar-check').addEventListener('click', () => runGrammar('check'));
    $('btn-humanize')     .addEventListener('click', () => runHumanize());
    $('btn-detect')       .addEventListener('click', () => runDetectAI());
    $('btn-plagiarism')   .addEventListener('click', () => runPlagiarism());

    // Apply button
    $('btn-apply').addEventListener('click', () => applyToField());

    // Trigger button
    triggerBtn.addEventListener('click', () => {
      captureFromSelection();
      openPanel();
    });

    // Global keyboard shortcut
    document.addEventListener('keydown', (e) => {
      if (e.altKey && e.shiftKey && e.code === 'KeyR') {
        e.preventDefault();
        captureText();
        togglePanel();
      }
    });

    // Listen for context menu events from background
    window.addEventListener('rt:open', (e) => {
      const { text, tab } = e.detail;
      if (text) captureText(text);
      if (tab) switchTab(tab);
      openPanel();
    });

    // Click outside to close trigger
    document.addEventListener('click', (e) => {
      if (!triggerBtn.contains(e.target)) hideTrigger();
    });
  }

  // ── Panel Control ─────────────────────────────────────────────────────────────
  function openPanel() {
    panelEl.classList.add('open');
    isOpen = true;
    if (!capturedText) captureText();
  }

  function closePanel() {
    panelEl.classList.remove('open');
    isOpen = false;
  }

  function togglePanel() {
    isOpen ? closePanel() : openPanel();
  }

  function switchTab(tab) {
    activeTab = tab;
    shadow.querySelectorAll('.rt-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    shadow.querySelectorAll('.rt-pane').forEach(p => p.classList.toggle('active', p.id === `pane-${tab}`));
    toggleSettings();
  }

  function toggleSettings() {
    // Hide settings for detect/plagiarism panes (no platform/tone needed)
    const noSettings = ['detect', 'plagiarism'];
    shadow.getElementById('rt-settings').style.display = noSettings.includes(activeTab) ? 'none' : '';
  }

  // ── Text Capture ──────────────────────────────────────────────────────────────
  function captureText(text) {
    if (text !== undefined) {
      capturedText = text;
    } else if (lastFocused) {
      // Try to get text from the last focused editable element
      const el = lastFocused;
      if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        capturedText = el.value || '';
      } else if (el.isContentEditable) {
        capturedText = el.innerText || el.textContent || '';
      }
    }

    const displayEl  = shadow.getElementById('rt-captured-text');
    const countEl    = shadow.getElementById('rt-char-count');
    if (capturedText) {
      displayEl.textContent = capturedText;
      displayEl.classList.remove('empty');
      countEl.textContent = `${capturedText.length} chars · ${capturedText.trim().split(/\s+/).length} words`;
    } else {
      displayEl.textContent = 'Focus on a text field or select text to get started…';
      displayEl.classList.add('empty');
      countEl.textContent = '';
    }
  }

  function captureFromSelection() {
    const sel = window.getSelection();
    if (sel && sel.toString().trim()) {
      capturedText = sel.toString().trim();
      captureText(capturedText);
    } else {
      captureText();
    }
  }

  // ── Focus Tracking ────────────────────────────────────────────────────────────
  function watchFocus() {
    document.addEventListener('focusin', (e) => {
      const el = e.target;
      if (isEditable(el)) {
        lastFocused = el;
        // Auto-detect platform from URL/element
        autoDetectPlatform();
      }
    });
  }

  function isEditable(el) {
    if (!el) return false;
    return el.tagName === 'TEXTAREA' ||
           (el.tagName === 'INPUT' && !['checkbox','radio','submit','button','file','hidden'].includes(el.type)) ||
           el.isContentEditable ||
           el.role === 'textbox';
  }

  function autoDetectPlatform() {
    const url = window.location.hostname;
    const sel = shadow.getElementById('rt-platform');
    if (!sel) return;
    if (url.includes('mail.google'))  { sel.value = 'Email';     settings.platform = 'Email'; }
    else if (url.includes('linkedin')){ sel.value = 'LinkedIn';  settings.platform = 'LinkedIn'; }
    else if (url.includes('slack'))   { sel.value = 'Slack';     settings.platform = 'Slack'; }
    else if (url.includes('twitter') || url.includes('x.com')){ sel.value = 'Twitter/X'; settings.platform = 'Twitter/X'; }
    else if (url.includes('outlook')) { sel.value = 'Email';     settings.platform = 'Email'; }
  }

  // ── Selection Trigger ─────────────────────────────────────────────────────────
  function watchSelections() {
    document.addEventListener('mouseup', (e) => {
      setTimeout(() => {
        const sel  = window.getSelection();
        const text = sel ? sel.toString().trim() : '';
        if (text && text.length > 10) {
          showTrigger(e.clientX, e.clientY, text);
        } else {
          hideTrigger();
        }
      }, 50);
    });
  }

  function showTrigger(x, y, text) {
    triggerBtn.dataset.text = text;
    const top  = Math.max(8, y - 36);
    const left = Math.min(window.innerWidth - 60, x + 4);
    triggerBtn.style.top    = top  + 'px';
    triggerBtn.style.left   = left + 'px';
    triggerBtn.style.display = 'block';
  }

  function hideTrigger() {
    if (triggerBtn) triggerBtn.style.display = 'none';
  }

  // ── API Helper ────────────────────────────────────────────────────────────────
  async function getToken() {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage({ type: 'RT_GET_TOKEN' }, (res) => {
        resolve(res ? res.token : null);
      });
    });
  }

  async function callAPI(endpoint, data) {
    const token   = await getToken();
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const res = await fetch(`${API_BASE}/${endpoint}`, {
      method : 'POST',
      headers,
      body   : JSON.stringify(data),
    });
    const json = await res.json();
    if (!res.ok || json.error) {
      if (json.error === 'daily_limit') throw new Error(`DAILY_LIMIT:${json.message}`);
      throw new Error(json.error || json.message || `HTTP ${res.status}`);
    }
    return json;
  }

  // ── Loading / Error Helpers ───────────────────────────────────────────────────
  function showLoading(containerId) {
    shadow.getElementById(containerId).innerHTML = `
      <div class="rt-loading">
        <div class="rt-spinner"></div>
        <div>Thinking…</div>
      </div>`;
  }

  function showError(containerId, message) {
    const isLimit = message.startsWith('DAILY_LIMIT:');
    const msg     = isLimit ? message.replace('DAILY_LIMIT:', '') : message;
    shadow.getElementById(containerId).innerHTML = `
      <div class="rt-error">
        ${isLimit ? '⚡ ' : '⚠️ '}${escHtml(msg)}
        ${isLimit ? '<br><br><a href="https://replytonic.com/#pricing" target="_blank" style="color:#fca5a5;font-weight:600">Upgrade to Premium →</a>' : ''}
      </div>`;
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Current Result Storage ────────────────────────────────────────────────────
  let currentResult = '';

  function storeResult(text) {
    currentResult = text;
    shadow.getElementById('btn-apply').style.display = text ? '' : 'none';
  }

  // ── Apply to Field ────────────────────────────────────────────────────────────
  function applyToField() {
    if (!currentResult || !lastFocused) return;
    const el = lastFocused;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.focus();
      const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value') ||
                           Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
      if (nativeSetter && nativeSetter.set) {
        nativeSetter.set.call(el, currentResult);
      } else {
        el.value = currentResult;
      }
      el.dispatchEvent(new Event('input',  { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (el.isContentEditable) {
      el.focus();
      document.execCommand('selectAll', false, null);
      document.execCommand('insertText', false, currentResult);
    }
    closePanel();
  }

  // ── REWRITE ───────────────────────────────────────────────────────────────────
  async function runRewrite() {
    if (!capturedText.trim()) { showError('result-rewrite', 'No text captured. Focus on a text field first.'); return; }
    setRunning('btn-rewrite', true);
    showLoading('result-rewrite');
    shadow.getElementById('btn-copy-rewrite').style.display = 'none';
    try {
      const data = await callAPI('rewrite.php', {
        message : capturedText,
        platform: settings.platform,
        tone    : settings.tone,
        length  : settings.length,
        emoji   : settings.emoji,
        mode    : 'rewrite',
      });
      const result = data.rewritten || data.v1 || '';
      storeResult(result);
      shadow.getElementById('result-rewrite').innerHTML = `
        <div class="rt-card selected">
          <div class="rt-card-text">${escHtml(result)}</div>
        </div>`;
      shadow.getElementById('btn-copy-rewrite').style.display = '';
      shadow.getElementById('btn-copy-rewrite').onclick = () => copyToClipboard(result);
    } catch (err) {
      showError('result-rewrite', err.message);
    } finally {
      setRunning('btn-rewrite', false);
    }
  }

  // ── REPLY ─────────────────────────────────────────────────────────────────────
  async function runReply() {
    if (!capturedText.trim()) { showError('result-reply', 'No text captured. Focus on a text field first.'); return; }
    setRunning('btn-reply', true);
    showLoading('result-reply');
    try {
      const data = await callAPI('rewrite.php', {
        message        : capturedText,
        originalMessage: capturedText,
        platform       : settings.platform,
        tone           : settings.tone,
        length         : settings.length,
        emoji          : settings.emoji,
        mode           : 'reply',
      });
      const replies = [
        { text: data.v1, label: data.v1_label || 'Strategy 1' },
        { text: data.v2, label: data.v2_label || 'Strategy 2' },
        { text: data.v3, label: data.v3_label || 'Strategy 3' },
      ].filter(r => r.text);

      if (!replies.length) { showError('result-reply','No replies generated'); return; }

      let html = '';
      replies.forEach((r, i) => {
        html += `
          <div class="rt-card${i === 0 ? ' selected' : ''}" data-reply="${escHtml(r.text)}" id="reply-card-${i}" style="cursor:pointer">
            <div class="rt-card-label">${escHtml(r.label)}</div>
            <div class="rt-card-text">${escHtml(r.text)}</div>
            <div class="rt-card-actions">
              <button class="rt-btn rt-btn-apply rt-btn-sm" data-action="apply" data-idx="${i}">Apply ✓</button>
              <button class="rt-btn rt-btn-ghost rt-btn-sm" data-action="copy" data-idx="${i}">📋 Copy</button>
            </div>
          </div>`;
      });
      shadow.getElementById('result-reply').innerHTML = html;

      // Bind card events
      shadow.getElementById('result-reply').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const idx = parseInt(btn.dataset.idx, 10);
        const reply = replies[idx];
        if (btn.dataset.action === 'apply') {
          storeResult(reply.text);
          applyToField();
        } else if (btn.dataset.action === 'copy') {
          copyToClipboard(reply.text);
        }
      });

      // Set first as current result
      storeResult(replies[0].text);
    } catch (err) {
      showError('result-reply', err.message);
    } finally {
      setRunning('btn-reply', false);
    }
  }

  // ── SUMMARIZE ─────────────────────────────────────────────────────────────────
  async function runSummarize() {
    if (!capturedText.trim()) { showError('result-summarize', 'No text captured.'); return; }
    setRunning('btn-summarize', true);
    showLoading('result-summarize');
    const sumLength = shadow.getElementById('rt-sum-length').value;
    try {
      const data = await callAPI('summarize.php', { text: capturedText, length: sumLength });
      let html = `<div class="rt-word-count">${data.word_count_original} words → ${data.word_count_summary} words</div>`;
      if (data.summary) {
        html += `<div class="rt-summary-text">${escHtml(data.summary)}</div>`;
      }
      if (data.bullets && data.bullets.length) {
        html += `<ul class="rt-bullet-list">${data.bullets.map(b => `<li>${escHtml(b)}</li>`).join('')}</ul>`;
      }
      if (data.actions && data.actions.length) {
        html += `<div style="font-size:10px;color:#6366f1;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">Action Items</div>`;
        html += data.actions.map(a => `<div class="rt-action-item">${escHtml(a)}</div>`).join('');
      }
      html += `<div style="display:flex;gap:6px;margin-top:10px">
        <button class="rt-btn rt-btn-apply rt-btn-sm" id="sum-apply">Apply to Field ✓</button>
        <button class="rt-btn rt-btn-ghost rt-btn-sm" id="sum-copy">📋 Copy</button>
      </div>`;
      shadow.getElementById('result-summarize').innerHTML = html;
      storeResult(data.summary || '');
      shadow.getElementById('sum-apply').onclick = applyToField;
      shadow.getElementById('sum-copy').onclick  = () => copyToClipboard(data.summary);
    } catch (err) {
      showError('result-summarize', err.message);
    } finally {
      setRunning('btn-summarize', false);
    }
  }

  // ── GRAMMAR ───────────────────────────────────────────────────────────────────
  async function runGrammar(mode) {
    if (!capturedText.trim()) { showError('result-grammar', 'No text captured.'); return; }
    const btn = mode === 'fix' ? 'btn-grammar-fix' : 'btn-grammar-check';
    setRunning(btn, true);
    showLoading('result-grammar');
    try {
      const data = await callAPI('grammar.php', { text: capturedText, mode });
      let html = `
        <div class="rt-grammar-score">
          <div class="score-num">${data.score || 0}</div>
          <div class="score-label">Writing Score</div>
        </div>
        <div class="rt-grammar-verdict">${escHtml(data.verdict || '')}</div>`;
      if (mode === 'fix' && data.corrected) {
        html += `<div class="rt-card selected"><div class="rt-card-text">${escHtml(data.corrected)}</div></div>`;
        if (data.changes && data.changes.length) {
          html += `<div style="font-size:10px;color:#6366f1;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin:8px 0 4px">Changes Made</div>`;
          html += data.changes.map(c => `<div class="rt-change-item"><div class="rt-change-dot"></div><span style="color:#94a3b8">${escHtml(c)}</span></div>`).join('');
        }
        html += `<div style="display:flex;gap:6px;margin-top:10px">
          <button class="rt-btn rt-btn-apply rt-btn-sm" id="gram-apply">Apply to Field ✓</button>
          <button class="rt-btn rt-btn-ghost rt-btn-sm" id="gram-copy">📋 Copy</button>
        </div>`;
        shadow.getElementById('result-grammar').innerHTML = html;
        storeResult(data.corrected);
        shadow.getElementById('gram-apply').onclick = applyToField;
        shadow.getElementById('gram-copy').onclick  = () => copyToClipboard(data.corrected);
      } else if (mode === 'check' && data.issues) {
        if (!data.issues.length) {
          html += `<div class="rt-card" style="border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.05)"><div class="rt-card-text" style="color:#86efac">✅ No issues found! Your text looks great.</div></div>`;
        } else {
          html += data.issues.map(issue => `
            <div class="rt-change-item">
              <div class="rt-change-dot"></div>
              <div>
                <span style="color:#fca5a5">"${escHtml(issue.original || '')}"</span>
                ${issue.suggestion ? `→ <span style="color:#86efac">"${escHtml(issue.suggestion)}"</span>` : ''}
                ${issue.explanation ? `<br><span style="color:#64748b;font-size:10px">${escHtml(issue.explanation)}</span>` : ''}
              </div>
            </div>`).join('');
        }
        shadow.getElementById('result-grammar').innerHTML = html;
      } else {
        shadow.getElementById('result-grammar').innerHTML = html;
      }
    } catch (err) {
      showError('result-grammar', err.message);
    } finally {
      setRunning(btn, false);
    }
  }

  // ── HUMANIZE ──────────────────────────────────────────────────────────────────
  async function runHumanize() {
    if (!capturedText.trim()) { showError('result-humanize', 'No text captured.'); return; }
    setRunning('btn-humanize', true);
    showLoading('result-humanize');
    shadow.getElementById('btn-copy-humanize').style.display = 'none';
    try {
      const data = await callAPI('humanize.php', { text: capturedText });
      const before = data.ai_score_before || 0;
      const after  = data.ai_score_after  || 0;
      let html = `
        <div class="rt-humanize-scores">
          <div class="rt-humanize-score">
            <div class="num before-score">${before}%</div>
            <div class="lbl">AI Score Before</div>
          </div>
          <div style="display:flex;align-items:center;color:#475569;font-size:18px">→</div>
          <div class="rt-humanize-score">
            <div class="num after-score">${after}%</div>
            <div class="lbl">AI Score After</div>
          </div>
        </div>`;
      if (data.changes && data.changes.length) {
        html += `<div style="font-size:10px;color:#6366f1;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">What Was Changed</div>`;
        html += data.changes.map(c => `<div class="rt-change-item"><div class="rt-change-dot"></div><span style="color:#94a3b8">${escHtml(c)}</span></div>`).join('');
      }
      html += `<div style="margin-top:10px;margin-bottom:4px;font-size:10px;color:#6366f1;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Humanized Text</div>`;
      html += `<div class="rt-card selected"><div class="rt-card-text">${escHtml(data.humanized || '')}</div></div>`;
      html += `<div style="display:flex;gap:6px;margin-top:4px">
        <button class="rt-btn rt-btn-apply rt-btn-sm" id="hum-apply">Apply to Field ✓</button>
        <button class="rt-btn rt-btn-ghost rt-btn-sm" id="hum-copy">📋 Copy</button>
      </div>`;
      shadow.getElementById('result-humanize').innerHTML = html;
      storeResult(data.humanized || '');
      shadow.getElementById('hum-apply').onclick = applyToField;
      shadow.getElementById('hum-copy').onclick  = () => copyToClipboard(data.humanized);
      shadow.getElementById('btn-copy-humanize').style.display = '';
      shadow.getElementById('btn-copy-humanize').onclick = () => copyToClipboard(data.humanized);
    } catch (err) {
      showError('result-humanize', err.message);
    } finally {
      setRunning('btn-humanize', false);
    }
  }

  // ── AI DETECT ─────────────────────────────────────────────────────────────────
  async function runDetectAI() {
    if (!capturedText.trim()) { showError('result-detect', 'No text captured.'); return; }
    if (capturedText.length < 50) { showError('result-detect', 'Text too short for AI detection (need 50+ characters).'); return; }
    setRunning('btn-detect', true);
    showLoading('result-detect');
    try {
      const data  = await callAPI('detect-ai.php', { text: capturedText });
      const score = data.score || 0;
      const verdictClass = score < 35 ? 'rt-verdict-human' : score < 65 ? 'rt-verdict-mixed' : 'rt-verdict-ai';
      let html = `
        <div class="rt-ai-meter">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:4px">
            <span>🧑 Human</span>
            <span style="font-weight:700;font-size:13px;color:#e2e8f0">${score}% AI</span>
            <span>🤖 AI</span>
          </div>
          <div class="rt-ai-meter-bar">
            <div class="rt-ai-meter-fill" style="width:${score}%"></div>
          </div>
        </div>
        <div class="rt-ai-verdict ${verdictClass}">${escHtml(data.verdict || '')}</div>
        ${data.confidence ? `<div style="text-align:center;font-size:11px;color:#64748b;margin-bottom:12px">Confidence: <strong>${escHtml(data.confidence)}</strong></div>` : ''}
        ${data.explanation ? `<div style="font-size:12px;color:#94a3b8;margin-bottom:12px;padding:10px;background:#1a1a24;border-radius:8px;line-height:1.5">${escHtml(data.explanation)}</div>` : ''}`;
      if (data.signals && data.signals.length) {
        html += `<div style="font-size:10px;color:#ef4444;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🤖 AI Signals Detected</div>`;
        html += `<div style="margin-bottom:10px">${data.signals.map(s => `<span class="rt-signal-tag rt-signal-ai">${escHtml(s)}</span>`).join('')}</div>`;
      }
      if (data.human_signals && data.human_signals.length) {
        html += `<div style="font-size:10px;color:#22c55e;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🧑 Human Signals Found</div>`;
        html += `<div style="margin-bottom:10px">${data.human_signals.map(s => `<span class="rt-signal-tag rt-signal-human">${escHtml(s)}</span>`).join('')}</div>`;
      }
      if (score >= 50) {
        html += `<button class="rt-btn rt-btn-primary" id="btn-humanize-from-detect" style="width:100%;margin-top:8px">🧑 Humanize This Text Now</button>`;
      }
      shadow.getElementById('result-detect').innerHTML = html;
      if (score >= 50) {
        shadow.getElementById('btn-humanize-from-detect').onclick = () => { switchTab('humanize'); runHumanize(); };
      }
    } catch (err) {
      showError('result-detect', err.message);
    } finally {
      setRunning('btn-detect', false);
    }
  }

  // ── PLAGIARISM ────────────────────────────────────────────────────────────────
  async function runPlagiarism() {
    if (!capturedText.trim()) { showError('result-plagiarism', 'No text captured. Focus on a text field first.'); return; }
    if (capturedText.length < 30) { showError('result-plagiarism', 'Text too short for plagiarism analysis (need 30+ characters).'); return; }
    setRunning('btn-plagiarism', true);
    showLoading('result-plagiarism');
    try {
      const data = await callAPI('plagiarism.php', { text: capturedText });

      const origScore = data.originality_score || 0;
      const plagScore = data.plagiarism_score  || 0;

      // Verdict CSS class
      const risk = (data.risk_level || 'None').toLowerCase();
      const verdictClass =
        risk === 'critical' ? 'rt-plag-verdict-critical' :
        risk === 'high'     ? 'rt-plag-verdict-high'     :
        risk === 'moderate' ? 'rt-plag-verdict-moderate' :
        risk === 'low'      ? 'rt-plag-verdict-low'      : 'rt-plag-verdict-clean';

      // SVG circle for originality
      const radius = 32, circ = 2 * Math.PI * radius;
      const filled = circ * (origScore / 100);
      const circleColor =
        origScore >= 80 ? '#22c55e' :
        origScore >= 60 ? '#eab308' :
        origScore >= 40 ? '#f97316' : '#ef4444';

      let html = `
        <div class="rt-plag-ring">
          <div class="rt-plag-circle">
            <svg width="80" height="80" viewBox="0 0 80 80">
              <circle cx="40" cy="40" r="${radius}" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="8"/>
              <circle cx="40" cy="40" r="${radius}" fill="none" stroke="${circleColor}" stroke-width="8"
                stroke-dasharray="${filled} ${circ}" stroke-linecap="round"/>
            </svg>
            <div class="rt-plag-circle-num">
              <div class="big" style="color:${circleColor}">${origScore}%</div>
              <div class="lbl">Original</div>
            </div>
          </div>
          <div class="rt-plag-info">
            <div style="font-size:12px;color:#94a3b8;margin-bottom:4px">Plagiarism Risk: <strong style="color:${circleColor}">${escHtml(data.risk_level || 'None')}</strong></div>
            <div style="font-size:11px;color:#64748b">Confidence: ${escHtml(data.confidence || '')}</div>
            <div style="font-size:11px;color:#64748b;margin-top:4px">${data.word_count || 0} words analyzed</div>
          </div>
        </div>
        <div class="rt-plag-verdict ${verdictClass}">${escHtml(data.verdict || '')}</div>`;

      if (data.explanation) {
        html += `<div style="font-size:12px;color:#94a3b8;margin-bottom:12px;padding:10px;background:#1a1a24;border-radius:8px;line-height:1.5">${escHtml(data.explanation)}</div>`;
      }

      // Flagged phrases
      if (data.flagged_phrases && data.flagged_phrases.length) {
        html += `<div style="font-size:10px;color:#ef4444;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">⚠️ Flagged Phrases (${data.flagged_phrases.length})</div>`;
        data.flagged_phrases.forEach(fp => {
          const sevClass = fp.severity === 'High' ? 'sev-high' : fp.severity === 'Medium' ? 'sev-medium' : 'sev-low';
          html += `<div class="rt-plag-phrase">
            <div class="rt-plag-phrase-text">"${escHtml(fp.phrase || '')}"</div>
            <div class="rt-plag-phrase-meta">
              <span class="rt-plag-phrase-source">${escHtml(fp.reason || '')}</span>
              <span class="rt-plag-phrase-sev ${sevClass}">${escHtml(fp.severity || '')}</span>
              ${fp.likely_source ? `<span style="color:#475569">— ${escHtml(fp.likely_source)}</span>` : ''}
            </div>
          </div>`;
        });
      }

      // Original phrases
      if (data.original_phrases && data.original_phrases.length) {
        html += `<div style="font-size:10px;color:#22c55e;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin:10px 0 6px">✅ Original Phrases</div>`;
        data.original_phrases.forEach(op => {
          html += `<div class="rt-plag-orig">"${escHtml(op)}"</div>`;
        });
      }

      // Source categories
      if (data.source_categories && data.source_categories.length) {
        html += `<div style="font-size:10px;color:#64748b;margin:10px 0 4px">Likely sources: ${data.source_categories.map(s => `<span style="color:#94a3b8">${escHtml(s)}</span>`).join(' · ')}</div>`;
      }

      // Suggestions
      if (data.suggestions && data.suggestions.length) {
        html += `<div style="font-size:10px;color:#6366f1;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin:10px 0 6px">💡 Suggestions to Improve Originality</div>`;
        data.suggestions.forEach(s => {
          html += `<div class="rt-plag-suggestion">${escHtml(s)}</div>`;
        });
      }

      // Cross-action: humanize if high plagiarism
      if (plagScore >= 40) {
        html += `<button class="rt-btn rt-btn-primary" id="btn-humanize-from-plag" style="width:100%;margin-top:12px">🧑 Humanize to Improve Originality</button>`;
      }

      shadow.getElementById('result-plagiarism').innerHTML = html;

      if (plagScore >= 40) {
        shadow.getElementById('btn-humanize-from-plag').onclick = () => { switchTab('humanize'); runHumanize(); };
      }
    } catch (err) {
      showError('result-plagiarism', err.message);
    } finally {
      setRunning('btn-plagiarism', false);
    }
  }

  // ── Utilities ─────────────────────────────────────────────────────────────────
  function setRunning(btnId, running) {
    const btn = shadow.getElementById(btnId);
    if (!btn) return;
    btn.disabled = running;
    if (running) {
      btn.dataset.origText = btn.textContent;
      btn.textContent = 'Working…';
    } else {
      btn.textContent = btn.dataset.origText || btn.textContent;
    }
  }

  async function copyToClipboard(text) {
    try {
      await navigator.clipboard.writeText(text);
      // Brief visual feedback
      const btn = shadow.getElementById('btn-apply');
      if (btn) { btn.textContent = 'Copied! ✓'; setTimeout(() => { btn.textContent = 'Apply to Field ✓'; }, 1500); }
    } catch (e) {
      // Fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
  }

  // ── Platform Detection ────────────────────────────────────────────────────────
  function isGmail()   { return window.location.hostname.includes('mail.google.com'); }
  function isSlack()   { return window.location.hostname.includes('app.slack.com');  }
  function isLinkedIn(){ return window.location.hostname.includes('linkedin.com');   }
  function isTwitter() { return window.location.hostname.includes('twitter.com') || window.location.hostname.includes('x.com'); }

  // ── Gmail-Specific Integration ────────────────────────────────────────────────
  function setupGmail() {
    // Watch for new compose windows
    const observer = new MutationObserver(() => { injectGmailButtons(); });
    observer.observe(document.body, { childList: true, subtree: true });
    injectGmailButtons();
  }

  function injectGmailButtons() {
    // Find compose windows
    const composeWindows = document.querySelectorAll('[role="dialog"][aria-label*="New message"], [role="dialog"][aria-label*="compose"], .aDh, .aA5');
    composeWindows.forEach(win => {
      if (win.dataset.rtInjected) return;
      win.dataset.rtInjected = 'true';

      const toolbar = win.querySelector('[role="toolbar"]') ||
                      win.querySelector('.wO') ||
                      win.querySelector('.btC');
      if (!toolbar) return;

      // Add ReplyTonic button
      const btn = document.createElement('div');
      btn.className = 'rt-gmail-btn';
      btn.title     = 'Open ReplyTonic (Alt+Shift+R)';
      btn.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;margin:0 4px;user-select:none;border:none;';
      btn.innerHTML = '⚡ RT';
      btn.addEventListener('click', () => {
        // Capture text from compose area
        const composeBody = win.querySelector('[aria-label="Message Body"], [role="textbox"]');
        if (composeBody) {
          captureText(composeBody.innerText || composeBody.textContent || '');
          lastFocused = composeBody;
        }
        openPanel();
      });
      toolbar.appendChild(btn);
    });

    // Add "Summarize" button to email reading view
    const emailBodies = document.querySelectorAll('.a3s.aiL, .AO.J-JN-M-I5-Jm.aZ6:not([data-rt-sum])');
    emailBodies.forEach(body => {
      if (body.dataset.rtSumBtn) return;
      body.dataset.rtSumBtn = 'true';
      const text = body.innerText || '';
      if (text.length < 100) return;

      const btn = document.createElement('button');
      btn.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;margin:8px 0;background:rgba(99,102,241,.12);color:#6366f1;border:1px solid rgba(99,102,241,.3);border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;';
      btn.innerHTML = '📋 Summarize this email';
      btn.addEventListener('click', () => {
        captureText(text);
        switchTab('summarize');
        openPanel();
      });
      body.prepend(btn);
    });
  }

  // ── Slack-Specific ────────────────────────────────────────────────────────────
  function setupSlack() {
    const observer = new MutationObserver(() => { injectSlackButtons(); });
    observer.observe(document.body, { childList: true, subtree: true });
    injectSlackButtons();
  }

  function injectSlackButtons() {
    const editors = document.querySelectorAll('.ql-editor[data-rt-injected!="true"][contenteditable="true"]');
    editors.forEach(editor => {
      editor.dataset.rtInjected = 'true';
      editor.addEventListener('focus', () => { lastFocused = editor; });
    });
  }

  // ── LinkedIn-Specific ─────────────────────────────────────────────────────────
  function setupLinkedIn() {
    const observer = new MutationObserver(() => { injectLinkedInButtons(); });
    observer.observe(document.body, { childList: true, subtree: true });
    injectLinkedInButtons();
  }

  function injectLinkedInButtons() {
    const editors = document.querySelectorAll('.msg-form__contenteditable[data-rt-injected!="true"], .comments-comment-box__form-container .ql-editor[data-rt-injected!="true"]');
    editors.forEach(editor => {
      editor.dataset.rtInjected = 'true';
      editor.addEventListener('focus', () => { lastFocused = editor; });

      // Add small RT button near editor
      const wrapper = editor.closest('.msg-form__message-texteditor') || editor.parentElement;
      if (!wrapper || wrapper.querySelector('.rt-li-btn')) return;
      const btn = document.createElement('button');
      btn.className = 'rt-li-btn';
      btn.style.cssText = 'position:absolute;bottom:8px;right:8px;padding:4px 9px;background:#6366f1;color:#fff;border:none;border-radius:6px;font-size:10px;font-weight:700;cursor:pointer;z-index:100;';
      btn.textContent = 'RT';
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        captureText(editor.innerText || '');
        lastFocused = editor;
        openPanel();
      });
      wrapper.style.position = 'relative';
      wrapper.appendChild(btn);
    });
  }

  // ── SPA MutationObserver ──────────────────────────────────────────────────────
  function setupMutationObserver() {
    // For Twitter/X, Slack, LinkedIn — DOM changes without page loads
    const observer = new MutationObserver(() => {
      if (isGmail())    injectGmailButtons();
      if (isSlack())    injectSlackButtons();
      if (isLinkedIn()) injectLinkedInButtons();
    });
    observer.observe(document.body, {
      childList: true,
      subtree  : true,
    });
  }

  // ── Boot ──────────────────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
