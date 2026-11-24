/*
 * Sufra GroupOps — offline POS helpers.
 * Bills made while the network / server is down are kept in this browser (localStorage)
 * and sent to the server later (auto every few minutes, or with the Sync button).
 */
window.PosOffline = (function () {
  const K = { outbox: 'sufra-pos-outbox', open: 'sufra-pos-open', lastSync: 'sufra-pos-lastsync' };

  function load(name) {
    try { return JSON.parse(localStorage.getItem(K[name]) || '[]') || []; } catch (e) { return []; }
  }
  function save(name, val) {
    try { localStorage.setItem(K[name], JSON.stringify(val)); return true; } catch (e) { return false; }
  }
  function get(name) { try { return localStorage.getItem(K[name]); } catch (e) { return null; } }
  function set(name, v) { try { localStorage.setItem(K[name], v); } catch (e) {} }

  // works on plain http too (crypto.randomUUID needs https)
  function uuid() {
    const b = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  /** Offline bill number shown on the printed bill: OFF-E2011-261124-003 */
  function nextRef(code) {
    const d = new Date();
    const ymd = String(d.getFullYear()).slice(2) + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
    const key = 'sufra-pos-seq-' + ymd;
    let n = 0;
    try { n = (+localStorage.getItem(key) || 0) + 1; localStorage.setItem(key, n); } catch (e) { n = Date.now() % 1000; }
    return 'OFF-' + String(code || 'POS').replace(/[^A-Za-z0-9]/g, '') + '-' + ymd + '-' + String(n).padStart(3, '0');
  }

  async function ping(url) {
    try {
      const ctl = new AbortController();
      const t = setTimeout(() => ctl.abort(), 6000);
      const r = await fetch(url + '?t=' + Date.now(), { headers: { Accept: 'application/json' }, signal: ctl.signal, cache: 'no-store', credentials: 'same-origin' });
      clearTimeout(t);
      if (r.status === 401 || r.status === 419 || r.redirected) return { ok: false, login: true };
      if (!r.ok) return null;
      return await r.json();
    } catch (e) { return null; }
  }

  async function registerSW() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return false;
    try { await navigator.serviceWorker.register('/pos-sw.js', { scope: '/pos' }); return true; } catch (e) { return false; }
  }

  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const n2 = x => (+x || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const qty = q => String(+(+q).toFixed(2));

  /** Same layout as the server bill (Company settings → Logo & bill). */
  function receiptHtml(o, rc, cashier) {
    const width = { '58': '48mm', '80': '72mm', 'A5': '140mm' }[rc.paper] || '72mm';
    const narrow = rc.paper === '58';
    const unpaid = !o.payments || !o.payments.length;
    const title = unpaid ? 'BILL · not paid yet' : (rc.title || 'INVOICE');
    const paid = (o.payments || []).reduce((s, p) => s + (+p.amount || 0), 0);
    const d = new Date(o.paid_at || o.created_at);
    const when = d.toLocaleDateString('en-GB') + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    const lines = o.lines.map(l => `<tr><td>${esc(l.name)}</td><td class="r">${qty(l.qty)}</td>${narrow ? '' : `<td class="r">${Math.round(l.price)}</td>`}<td class="r">${n2(l.qty * l.price)}</td></tr>`).join('');
    const pays = (o.payments || []).map(p => `<tr><td>${esc(p.name)}${p.reference ? ' · ' + esc(p.reference) : ''}</td><td class="r">${n2(p.amount)}</td></tr>`).join('');
    const change = o.tendered && o.tendered > o.total + 0.009 ? `<tr><td>Cash received</td><td class="r">${n2(o.tendered)}</td></tr><tr class="b"><td>Change to return</td><td class="r">${n2(o.tendered - o.total)}</td></tr>` : '';
    return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(o.ref)}</title><style>
      @page{margin:${rc.paper === 'A5' ? '10mm' : '0'};${rc.paper === 'A5' ? 'size:A5;' : ''}}*{box-sizing:border-box}
      body{font-family:ui-monospace,Consolas,'Courier New',monospace;width:${width};margin:0 auto;padding:${rc.paper === 'A5' ? '0' : '2mm 0'};font-size:${rc.font || 12}px;color:#000;background:#fff;line-height:1.3}
      .c{text-align:center}.r{text-align:right}.b{font-weight:700}.s{font-size:.85em}.xs{font-size:.78em}.hr{border-top:1px dashed #000;margin:4px 0}
      table{width:100%;border-collapse:collapse}td{padding:1px 0;vertical-align:top}td.r{padding-left:5px;white-space:nowrap}
      .logo{display:block;margin:0 auto 3px;max-width:60%;max-height:${narrow ? '18mm' : '24mm'};object-fit:contain;filter:grayscale(1) contrast(1.4)}
      .name{font-size:1.35em;font-weight:800}.title{display:inline-block;border:1px solid #000;padding:1px 8px;margin:3px 0;font-weight:700;letter-spacing:.08em}
      .total td{font-size:1.25em;font-weight:800;padding-top:2px}</style></head><body>
      <div class="c">${rc.logo ? `<img src="${rc.logo}" class="logo">` : ''}<div class="name">${esc(rc.name)}</div>
      ${rc.tagline ? `<div class="xs">${esc(rc.tagline)}</div>` : ''}<div class="s">${esc(rc.branch)}</div>
      ${rc.address ? `<div class="xs">${esc(rc.address)}</div>` : ''}${rc.phone ? `<div class="xs">☎ ${esc(rc.phone)}</div>` : ''}
      ${rc.vat_reg ? `<div class="xs">BIN / VAT Reg: ${esc(rc.vat_reg)}</div>` : ''}<div class="title">${esc(title)}</div></div>
      <table class="s"><tr><td>Bill no: <b>${esc(o.ref)}</b></td><td class="r">${when}</td></tr>
      <tr><td>${esc((o.order_type || '').replace('_', ' '))}${o.table_name ? ' · Table ' + esc(o.table_name) : ''}</td><td class="r">${rc.show_cashier ? 'Cashier: ' + esc(cashier) : ''}</td></tr>
      ${rc.show_customer && o.customer_phone ? `<tr><td colspan="2">Customer: ${esc(o.customer_phone)}</td></tr>` : ''}</table>
      <div class="hr"></div><table><tr class="b s"><td>Item</td><td class="r">Qty</td>${narrow ? '' : '<td class="r">Rate</td>'}<td class="r">Amount</td></tr>${lines}</table>
      <div class="hr"></div><table><tr><td>Subtotal</td><td class="r">${n2(o.subtotal)}</td></tr>
      ${o.discount > 0 ? `<tr><td>Discount</td><td class="r">-${n2(o.discount)}</td></tr>` : ''}${o.sc > 0 ? `<tr><td>Service charge</td><td class="r">${n2(o.sc)}</td></tr>` : ''}
      <tr><td>VAT ${+o.vat_pct}%</td><td class="r">${n2(o.vat)}</td></tr></table>
      <div class="hr"></div><table class="total"><tr><td>TOTAL</td><td class="r">৳${n2(o.total)}</td></tr></table>
      ${pays ? `<div class="hr"></div><table class="s">${pays}${change}</table>` : ''}
      <div class="hr"></div>${rc.footer ? `<div class="c b">${esc(rc.footer)}</div>` : ''}${rc.footer2 ? `<div class="c xs">${esc(rc.footer2)}</div>` : ''}
      <div class="c xs" style="margin-top:4px">Offline bill · will sync to the server</div></body></html>`;
  }

  /** Kitchen ticket when the kitchen screen cannot be reached. */
  function kotHtml(o, rc) {
    const width = { '58': '48mm', '80': '72mm', 'A5': '140mm' }[rc.paper] || '72mm';
    return `<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:0}body{font-family:ui-monospace,Consolas,monospace;width:${width};margin:0 auto;padding:2mm 0;font-size:${(rc.font || 12) + 2}px}
      .c{text-align:center}.hr{border-top:1px dashed #000;margin:4px 0}td{padding:2px 0;vertical-align:top}</style></head><body>
      <div class="c"><b>KITCHEN ORDER (KOT)</b><br>${esc(o.ref)}<br>${esc((o.order_type || '').replace('_', ' '))}${o.table_name ? ' · Table ' + esc(o.table_name) : ''}<br>${new Date(o.created_at).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</div>
      <div class="hr"></div><table>${o.lines.map(l => `<tr><td><b>${qty(l.qty)} ×</b></td><td>${esc(l.name)}${l.name_bn ? '<br><small>' + esc(l.name_bn) + '</small>' : ''}</td></tr>`).join('')}</table>
      <div class="hr"></div><div class="c">OFFLINE · ${esc(rc.branch)}</div></body></html>`;
  }

  /** Print through a hidden frame (no pop-up, works offline). */
  function print(html) {
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(f);
    const d = f.contentWindow.document;
    d.open(); d.write(html); d.close();
    setTimeout(() => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {} setTimeout(() => f.remove(), 60000); }, 300);
  }

  return { load, save, get, set, uuid, nextRef, ping, registerSW, receiptHtml, kotHtml, print, n2 };
})();
