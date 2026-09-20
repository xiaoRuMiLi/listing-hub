#!/usr/bin/env node
'use strict';
/**
 * hub — 云端中台本地 CLI（零依赖）。
 *
 * 配置（不同用户各自填）：.hicustom/hub.json
 *   { "url": "https://cbc.weixiubang.club", "email": "you@x.com", "password": "..." }
 *   —— 也可用 node hub.js config --url ... --email ... --password ... 写入
 *   优先级：环境变量 HUB_URL > 配置文件 url > 默认
 *
 * 用法：
 *   node hub.js config [--url --email --password]   # 查看/写入配置（写模板）
 *   node hub.js login [email] [password]            # 登录并存 token（也把账号写进配置文件）
 *   node hub.js import [csvDir]                     # 读 CSV → 幂等 upsert 推中台 + 分批推效果图
 *   node hub.js push <file.json>                    # 推一个自定义 payload
 *   node hub.js pull [scope]                        # 拉 JSON（看/给 Agent）
 *   node hub.js pullcsv [csvDir] [--overwrite]      # 拉 CSV 包并【合并】到本地 database/*.csv
 *   node hub.js ossify [csvDir]                     # 本地 designs/listing_variants 图片换 OSS 图床
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');
const { URL } = require('url');

const STATE_DIR = path.join(__dirname, '..', '.hicustom');
const CONFIG = path.join(STATE_DIR, 'hub.json');
const CRED = path.join(STATE_DIR, 'hub-credentials.json');
const DEFAULT_DB = __dirname.includes(path.sep + 'cli')
  ? path.join(__dirname, '..', '..', 'skills', 'hicustom-api', 'database')
  : path.join(__dirname, '..', 'database');

function loadCfg() {
  try { return JSON.parse(fs.readFileSync(CONFIG, 'utf8')); } catch (e) { return {}; }
}
function saveCfg(obj) {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  fs.writeFileSync(CONFIG, JSON.stringify(obj, null, 2));
}
const CFG = loadCfg();
const BASE = (process.env.HUB_URL || CFG.url || 'https://cbc.weixiubang.club').replace(/\/+$/, '');
const API = BASE + '/api/v1';

function readToken() { try { return JSON.parse(fs.readFileSync(CRED, 'utf8')).token || ''; } catch (e) { return ''; } }
function saveToken(token, extra) {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  fs.writeFileSync(CRED, JSON.stringify(Object.assign({ token }, extra || {}), null, 2));
}

function request(method, urlStr, body, token) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const lib = u.protocol === 'https:' ? https : http;
    const data = body == null ? null : (typeof body === 'string' ? body : JSON.stringify(body));
    const headers = { Accept: 'application/json' };
    if (data) headers['Content-Type'] = 'application/json';
    if (token) headers['Authorization'] = 'Bearer ' + token;
    if (data) headers['Content-Length'] = Buffer.byteLength(data);
    const req = lib.request({ method, hostname: u.hostname, port: u.port, path: u.pathname + u.search, headers }, (res) => {
      let buf = '';
      res.on('data', (c) => (buf += c));
      res.on('end', () => {
        let json = null; try { json = JSON.parse(buf); } catch (e) { /* */ }
        resolve({ status: res.statusCode, json, raw: buf });
      });
    });
    req.on('error', reject);
    if (data) req.write(data);
    req.end();
  });
}

/** 取 token：优先已存；否则用配置文件里的账号自动登录 */
async function token() {
  const t = readToken();
  if (t) return t;
  if (CFG.email && CFG.password) {
    const r = await request('POST', API + '/auth/login', { email: CFG.email, password: CFG.password });
    if (r.status === 200 && r.json && r.json.ok) { saveToken(r.json.data.token, { email: CFG.email }); return r.json.data.token; }
    console.error('自动登录失败（检查 .hicustom/hub.json 的 email/password）:', r.status);
  }
  console.error('未登录：先 `node hub.js login <email> <password>`，或在 .hicustom/hub.json 填 email/password');
  process.exit(1);
}

// —— 极简 CSV ——
function parseCsv(text) {
  text = text.replace(/^\uFEFF/, '');
  const rows = []; let row = []; let cell = ''; let i = 0; let q = false;
  while (i < text.length) {
    const ch = text[i];
    if (q) { if (ch === '"') { if (text[i + 1] === '"') { cell += '"'; i += 2; continue; } q = false; i++; continue; } cell += ch; i++; continue; }
    if (ch === '"') { q = true; i++; continue; }
    if (ch === ',') { row.push(cell); cell = ''; i++; continue; }
    if (ch === '\r') { i++; continue; }
    if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; i++; continue; }
    cell += ch; i++;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  if (!rows.length) return [];
  const head = rows[0];
  return rows.slice(1).filter((r) => r.length > 1 || (r[0] || '').trim() !== '').map((r) => { const o = {}; head.forEach((h, k) => { o[h] = r[k] != null ? r[k] : ''; }); return o; });
}
function headerOf(text) { const s = text.replace(/^\uFEFF/, ''); const s2 = s.replace(/\r/g, ''); const nl = s2.indexOf('\n'); return (nl >= 0 ? s2.slice(0, nl) : s2).split(',').map((x) => x.trim()); }
function csvCell(v) { const s = v == null ? '' : String(v); return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
function stringifyCsv(header, rows) { const lines = [header.map(csvCell).join(',')]; for (const r of rows) lines.push(header.map((h) => csvCell(r[h])).join(',')); return lines.join('\r\n') + '\r\n'; }
function num(v) { const n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.\-]/g, '')); return isFinite(n) ? n : null; }
function jsonOrNull(v) { const s = String(v == null ? '' : v).trim(); if (!s || (s[0] !== '{' && s[0] !== '[')) return null; try { return JSON.parse(s); } catch (e) { return null; } }
function readCsv(dir, name) { const f = path.join(dir, name); return fs.existsSync(f) ? parseCsv(fs.readFileSync(f, 'utf8')) : null; }
function backup(dir, file, text) { const bo = path.join(dir, '_hub-backup'); fs.mkdirSync(bo, { recursive: true }); const stamp = new Date().toISOString().replace(/[:.]/g, '-'); const bf = path.join(bo, file + '.' + stamp + '.bak'); if (!fs.existsSync(bf)) fs.writeFileSync(bf, text); }

function mergeCsv(localText, serverText, keySel) {
  const srvH = headerOf(serverText);
  const locH = localText ? headerOf(localText) : srvH;
  const serverRows = parseCsv(serverText);
  const locRows = localText ? parseCsv(localText) : [];
  const keyOf = typeof keySel === 'function' ? keySel : (r) => String(r[keySel] || '').trim();
  const sMap = new Map(); for (const r of serverRows) { const k = keyOf(r); if (k) sMap.set(k, r); }
  const out = []; const seen = new Set(); let updated = 0; let kept = 0;
  for (const lr of locRows) {
    const k = keyOf(lr); seen.add(k);
    if (sMap.has(k)) { const s = sMap.get(k); const m = Object.assign({}, lr); for (const c of srvH) { const v = s[c]; if (v != null && String(v) !== '') m[c] = v; } out.push(m); updated++; }
    else { out.push(lr); kept++; }
  }
  let added = 0; for (const [k, s] of sMap) { if (!seen.has(k)) { out.push(s); added++; } }
  const header = locH.slice(); for (const c of srvH) if (!header.includes(c)) header.push(c);
  return { text: stringifyCsv(header, out), updated, added, kept };
}

// ===== 命令 =====
async function cmdConfig(argv) {
  const args = {};
  for (let i = 0; i < argv.length; i++) { const a = argv[i]; if (a.startsWith('--')) { const k = a.slice(2); args[k] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true; } }
  if (args.url || args.email || args.password) {
    const c = Object.assign({ url: BASE }, CFG);
    if (args.url) c.url = args.url; if (args.email) c.email = args.email; if (args.password) c.password = args.password;
    saveCfg(c);
    console.log('✅ 已写入配置 ' + CONFIG);
  }
  const show = Object.assign({}, loadCfg());
  if (show.password) show.password = '****';
  console.log('配置 ' + CONFIG + ':\n' + JSON.stringify(show, null, 2));
  if (!fs.existsSync(CONFIG)) { saveCfg({ url: BASE, email: '', password: '' }); console.log('（已生成模板，请填 email/password）'); }
}

async function cmdLogin(email, password) {
  const cfg = loadCfg();
  email = email || cfg.email; password = password || cfg.password;
  if (!email || !password) { console.error('缺账号密码：node hub.js login <email> <password>（或先 config）'); process.exit(1); }
  const r = await request('POST', API + '/auth/login', { email, password });
  if (r.status !== 200 || !r.json || !r.json.ok) { console.error('登录失败:', r.status, (r.raw || '').slice(0, 200)); process.exit(1); }
  saveToken(r.json.data.token, { email });
  saveCfg(Object.assign({ url: BASE }, cfg, { email, password }));
  console.log('✅ 已登录并存 token；配置已更新:', CONFIG);
}

async function cmdImport(dir) {
  const tk = await token();
  dir = dir || DEFAULT_DB;
  const products = []; const designs = []; const listings = []; const shipping = [];

  const pRows = readCsv(dir, 'products.csv') || [];
  for (const p of pRows) {
    if (!p.id) continue;
    products.push({
      code: String(p.id), cn_name: p.cn_name || null, en_name: p.en_name || null,
      material_cn: p.material || null, material_en: p.material_en || null,
      min_price: num(p.min_price), currency: p.price_currency || null, status: p.status || 'synced',
      detail_json: { factory: p.factory || '', technology: p.technology || '', variant_code: p.variant_code || '', variants_count: p.variants_count || '' },
      sources: [{ supplier_code: 'hicustom', external_id: String(p.id), supplier_sku: p.spu_code || '', is_primary: true }],
    });
    for (const [col, cc] of [['US', 'US'], ['UK', 'GB'], ['CA', 'CA'], ['DE', 'DE'], ['MX', 'MX'], ['FR', 'FR'], ['ES', 'ES'], ['IT', 'IT']]) {
      const amt = p['shipping_' + col];
      if (amt != null && String(amt).trim() !== '') shipping.push({ product_code: String(p.id), country: cc, amount: num(amt), channel: p['shipping_channel_' + col] || null });
    }
  }
  const dRows = readCsv(dir, 'designs.csv') || [];
  for (const d of dRows) {
    if (!d.design_code) continue;
    designs.push({
      design_code: d.design_code, product_code: d.product_id || null,
      design_key: d.design_key || null, version: d.version || null, parent_code: d.parent_code || null,
      source: d.source || 'import', cn_name: d.cn_name || null, en_name: d.en_name || null,
      pattern: d.design_pattern || null, template: d.design_template || null,
      gallery_codes: d.gallery_codes || null, effect_count: num(d.effect_image_count),
      main_image: d.main_image || null, other_images: d.other_images || null, status: d.status || 'active',
    });
  }
  const lRows = readCsv(dir, 'listing_copy.csv') || [];
  for (const l of lRows) {
    if (!l.sku) continue;
    listings.push({
      account: 'HHY', platform: 'amazon', marketplace: l.marketplace || 'A1F83G8C2ARO7P',
      sku: l.sku, product_code: l.id || null, design_code: l.design_code || null,
      is_parent: true, amazon_product_type: l.product_type || null, status: l.status || 'candidate',
      price: num(l.price), product_price: num(l.product_price), shipping_fee: num(l.shipping_fee),
      currency: l.currency || null, attrs_json: jsonOrNull(l.attrs_json),
    });
  }
  const vRows = readCsv(dir, 'listing_variants.csv') || [];
  for (const v of vRows) {
    if (!v.sku) continue;
    listings.push({
      account: 'HHY', platform: 'amazon', marketplace: v.marketplace || 'A1F83G8C2ARO7P',
      sku: v.sku, parent_sku: v.parent_sku || null, is_parent: false,
      variation_theme: v.variation_theme || null, variant_color: v.variant_color || null, variant_size: v.variant_size || null,
      design_code: v.design_code || null, status: v.status || 'planned',
      price: num(v.price), product_price: num(v.product_price), shipping_fee: num(v.shipping_fee), quantity: num(v.quantity),
      images: [v.main_image, ...String(v.other_images || '').split('|')].map((s) => String(s || '').trim()).filter(Boolean),
    });
  }

  console.log(`读取: products=${products.length} shipping=${shipping.length} designs=${designs.length} listings=${listings.length}（来自 ${dir}）`);
  const r = await request('POST', API + '/sync/push', { machine_id: 'local-ws', products, product_shipping: shipping, designs, listings }, tk);
  if (r.status !== 200 || !r.json || !r.json.ok) { console.error('推送失败:', r.status, (r.raw || '').slice(0, 300)); process.exit(1); }
  console.log('✅ 数据推送完成:\n' + JSON.stringify(r.json.data, null, 2));

  console.log('🖼 推送效果图到 OSS（分批）…');
  let guard = 0;
  for (;;) {
    let ir = null;
    for (let attempt = 0; attempt < 4; attempt++) {
      ir = await request('POST', API + '/listings/oss-images', { limit: 2 }, tk);
      if (ir.json && ir.json.ok) break;
      console.log('  ⚠️ 本批失败(' + ir.status + ')，3s 后重试…');
      await new Promise((res) => setTimeout(res, 3000));
    }
    if (!ir.json || !ir.json.ok) { console.error('  ✗ 推图放弃（可稍后重跑 import 续推）'); break; }
    const p = ir.json.data;
    console.log('  本批 ' + p.processed.length + ' 个，剩余 ' + p.remaining);
    if (p.remaining <= 0 || p.processed.length === 0 || ++guard > 500) break;
  }
  console.log('✅ 效果图推送结束');
}

async function cmdPush(file) {
  const tk = await token();
  const payload = JSON.parse(fs.readFileSync(file, 'utf8'));
  const r = await request('POST', API + '/sync/push', payload, tk);
  console.log(r.status, JSON.stringify(r.json, null, 2));
}
async function cmdPull(scope) {
  const tk = await token();
  const r = await request('GET', API + '/sync/pull?scope=' + encodeURIComponent(scope || 'products,designs,listings'), null, tk);
  console.log(r.status, JSON.stringify(r.json, null, 2).slice(0, 2000));
}

async function cmdPullCsv(dir, overwrite) {
  const tk = await token();
  dir = dir || DEFAULT_DB;
  const sets = [['products', 'products.csv', 'id'], ['listing_copy', 'listing_copy.csv', (r) => String(r.sku || '').trim()], ['listing_variants', 'listing_variants.csv', (r) => String(r.sku || '').trim()], ['designs', 'designs.csv', 'design_code']];
  const summary = {};
  for (const [ds, file, key] of sets) {
    const res = await fetch(API + '/sync/pull?format=csv&dataset=' + ds, { headers: { Authorization: 'Bearer ' + tk } });
    if (!res.ok) { console.error('  ✗ ' + ds + ' 失败 ' + res.status); continue; }
    const serverText = await res.text();
    const localPath = path.join(dir, file);
    const localText = fs.existsSync(localPath) ? fs.readFileSync(localPath, 'utf8') : '';
    if (overwrite) { if (localText) backup(dir, file, localText); fs.mkdirSync(dir, { recursive: true }); fs.writeFileSync(localPath, '\uFEFF' + serverText.replace(/^\uFEFF/, '')); summary[file] = '覆盖'; }
    else { const m = mergeCsv(localText, serverText, key); if (localText) backup(dir, file, localText); fs.mkdirSync(dir, { recursive: true }); fs.writeFileSync(localPath, '\uFEFF' + m.text.replace(/^\uFEFF/, '')); summary[file] = `更新 ${m.updated} · 新增 ${m.added} · 保留本地独有 ${m.kept}`; }
  }
  console.log('✅ 合并完成 → ' + dir);
  for (const k of Object.keys(summary)) console.log('  ' + k + ': ' + summary[k]);
}

async function cmdOssify(dir) {
  const tk = await token();
  dir = dir || DEFAULT_DB;
  const cache = new Map(); const OSS_RE = /oss-cn-hongkong/;
  async function oss(u) {
    u = String(u || '').trim();
    if (!u || !/^https?:\/\//i.test(u) || OSS_RE.test(u)) return u;
    if (cache.has(u)) return cache.get(u);
    let out = u;
    try { const r = await fetch(API + '/assets/mirror', { method: 'POST', headers: { Authorization: 'Bearer ' + tk, 'Content-Type': 'application/json' }, body: JSON.stringify({ url: u }) }); const j = await r.json(); if (j && j.ok && j.data && j.data.public_url) out = j.data.public_url; } catch (e) { /* keep */ }
    cache.set(u, out); return out;
  }
  const specs = [{ file: 'designs.csv', fields: ['main_image', 'other_images'] }, { file: 'listing_variants.csv', fields: ['main_image', 'other_images'] }];
  console.log('换图床 → OSS（目录 ' + dir + '）');
  for (const spec of specs) {
    const p = path.join(dir, spec.file);
    if (!fs.existsSync(p)) { console.log('  (缺) ' + spec.file); continue; }
    const text = fs.readFileSync(p, 'utf8'); const header = headerOf(text); const rows = parseCsv(text); let changed = 0;
    for (const row of rows) for (const f of spec.fields) {
      const v = row[f]; if (!v) continue;
      const outs = []; for (const u of String(v).split('|').map((s) => s.trim()).filter(Boolean)) outs.push(await oss(u));
      const joined = outs.join('|'); if (joined !== String(v)) { row[f] = joined; changed++; }
    }
    if (changed) { backup(dir, spec.file, text); fs.writeFileSync(p, '\uFEFF' + stringifyCsv(header, rows).replace(/^\uFEFF/, '')); }
    console.log('  ' + spec.file + ': 改写 ' + changed + ' 处（备份在 _hub-backup/）');
  }
  console.log('✅ 完成：本地已切到 OSS 图床');
}

(async () => {
  const [cmd, a1, a2] = process.argv.slice(2);
  try {
    if (cmd === 'config') return await cmdConfig(process.argv.slice(3));
    if (cmd === 'login') return await cmdLogin(a1, a2);
    if (cmd === 'import') return await cmdImport(a1);
    if (cmd === 'push') return await cmdPush(a1);
    if (cmd === 'pull') return await cmdPull(a1);
    if (cmd === 'pullcsv') return await cmdPullCsv(a1, process.argv.includes('--overwrite'));
    if (cmd === 'ossify') return await cmdOssify(a1);
    console.log('用法: node hub.js <config|login|import|push|pull|pullcsv|ossify> ...');
  } catch (e) { console.error('ERR', e.message); process.exit(1); }
})();
