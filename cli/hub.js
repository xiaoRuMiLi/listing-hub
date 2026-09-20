#!/usr/bin/env node
'use strict';
/**
 * hub — 云端中台本地 CLI（零依赖）。
 * 用法：
 *   node hub.js login <email> <password>        # 存 token 到 .hub/credentials.json
 *   node hub.js import <csvDir>                 # 读 products/designs/listing_copy/listing_variants CSV → sync/push
 *   node hub.js push <file.json>                # 推一个自定义 payload
 *   node hub.js pull [scope]                    # 拉取（默认 products,designs,listings）
 * 环境变量：HUB_URL（默认 https://cbc.weixiubang.club）
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');
const { URL } = require('url');

const HUB_URL = (process.env.HUB_URL || 'https://cbc.weixiubang.club').replace(/\/+$/, '');
const API = HUB_URL + '/api/v1';
const CRED = path.join(__dirname, '.hub', 'credentials.json');

function loadToken() {
  try { return JSON.parse(fs.readFileSync(CRED, 'utf8')).token; } catch (e) { return ''; }
}
function saveToken(token, extra) {
  fs.mkdirSync(path.dirname(CRED), { recursive: true });
  fs.writeFileSync(CRED, JSON.stringify(Object.assign({ token }, extra || {}), null, 2));
}

function request(method, urlStr, body, token, isMultipartText) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const lib = u.protocol === 'https:' ? https : http;
    const data = body == null ? null : (typeof body === 'string' ? body : JSON.stringify(body));
    const headers = { Accept: 'application/json' };
    if (data && !isMultipartText) headers['Content-Type'] = 'application/json';
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

// —— 极简 CSV 解析（支持引号内含逗号/换行/双引号转义）——
function parseCsv(text) {
  text = text.replace(/^\uFEFF/, '');
  const rows = []; let row = []; let cell = ''; let i = 0; let q = false;
  while (i < text.length) {
    const ch = text[i];
    if (q) {
      if (ch === '"') { if (text[i + 1] === '"') { cell += '"'; i += 2; continue; } q = false; i++; continue; }
      cell += ch; i++; continue;
    }
    if (ch === '"') { q = true; i++; continue; }
    if (ch === ',') { row.push(cell); cell = ''; i++; continue; }
    if (ch === '\r') { i++; continue; }
    if (ch === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; i++; continue; }
    cell += ch; i++;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  if (!rows.length) return [];
  const head = rows[0];
  return rows.slice(1).filter((r) => r.length > 1 || (r[0] || '').trim() !== '').map((r) => {
    const o = {}; head.forEach((h, k) => { o[h] = r[k] != null ? r[k] : ''; }); return o;
  });
}
function num(v) { const n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.\-]/g, '')); return isFinite(n) ? n : null; }
function jsonOrNull(v) { const s = String(v == null ? '' : v).trim(); if (!s || s[0] !== '{' && s[0] !== '[') return null; try { return JSON.parse(s); } catch (e) { return null; } }

function readCsv(dir, name) {
  const f = path.join(dir, name);
  if (!fs.existsSync(f)) return null;
  return parseCsv(fs.readFileSync(f, 'utf8'));
}

async function cmdLogin(email, password) {
  const r = await request('POST', API + '/auth/login', { email, password });
  if (r.status !== 200 || !r.json || !r.json.ok) { console.error('登录失败:', r.status, r.raw.slice(0, 200)); process.exit(1); }
  saveToken(r.json.data.token, { email });
  console.log('✅ 已登录，token 已存:', CRED);
}

async function cmdImport(dir) {
  const token = loadToken();
  if (!token) { console.error('未登录，先跑: node hub.js login <email> <password>'); process.exit(1); }

  const products = [];
  const designs = [];
  const listings = [];
  const shipping = [];

  const pRows = readCsv(dir, 'products.csv') || [];
  for (const p of pRows) {
    if (!p.id) continue;
    products.push({
      code: String(p.id),
      cn_name: p.cn_name || null, en_name: p.en_name || null,
      material_cn: p.material || null, material_en: p.material_en || null,
      min_price: num(p.min_price), currency: p.price_currency || null,
      status: p.status || 'synced',
      detail_json: { factory: p.factory || '', technology: p.technology || '', variant_code: p.variant_code || '', variants_count: p.variants_count || '' },
      sources: [{ supplier_code: 'hicustom', external_id: String(p.id), supplier_sku: p.spu_code || '', is_primary: true }],
    });
    for (const [col, cc] of [['US', 'US'], ['UK', 'GB'], ['CA', 'CA'], ['DE', 'DE'], ['MX', 'MX'], ['FR', 'FR'], ['ES', 'ES'], ['IT', 'IT']]) {
      const amt = p['shipping_' + col];
      if (amt != null && String(amt).trim() !== '') {
        shipping.push({ product_code: String(p.id), country: cc, amount: num(amt), channel: p['shipping_channel_' + col] || null });
      }
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
      main_image: d.main_image || null, other_images: d.other_images || null,
      status: d.status || 'active',
    });
  }

  const lRows = readCsv(dir, 'listing_copy.csv') || [];
  for (const l of lRows) {
    if (!l.sku) continue;
    listings.push({
      account: 'HHY', platform: 'amazon', marketplace: l.marketplace || 'A1F83G8C2ARO7P',
      sku: l.sku, product_code: l.id || null, design_code: l.design_code || null,
      is_parent: true, amazon_product_type: l.product_type || null,
      status: l.status || 'candidate', price: num(l.price), product_price: num(l.product_price),
      shipping_fee: num(l.shipping_fee), currency: l.currency || null,
      attrs_json: jsonOrNull(l.attrs_json),
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
    });
  }

  console.log(`读取: products=${products.length} shipping=${shipping.length} designs=${designs.length} listings=${listings.length}（来自 ${dir}）`);
  const payload = { machine_id: 'local-ws', products, product_shipping: shipping, designs, listings };
  const r = await request('POST', API + '/sync/push', payload, token);
  if (r.status !== 200 || !r.json || !r.json.ok) { console.error('推送失败:', r.status, r.raw.slice(0, 400)); process.exit(1); }
  console.log('✅ 推送完成:');
  console.log(JSON.stringify(r.json.data, null, 2));
}

async function cmdPush(file) {
  const token = loadToken();
  const payload = JSON.parse(fs.readFileSync(file, 'utf8'));
  const r = await request('POST', API + '/sync/push', payload, token);
  console.log(r.status, JSON.stringify(r.json, null, 2));
}

async function cmdPull(scope) {
  const token = loadToken();
  const r = await request('GET', API + '/sync/pull?scope=' + encodeURIComponent(scope || 'products,designs,listings'), null, token);
  console.log(r.status, JSON.stringify(r.json, null, 2).slice(0, 2000));
}

// —— CSV 小工具 ——
function headerOf(text) {
  const s = text.replace(/^\uFEFF/, '');
  const line = s.slice(0, s.replace(/\r/g, '').indexOf('\n') >= 0 ? s.replace(/\r/g, '').indexOf('\n') : s.length);
  return line.split(',').map((x) => x.trim());
}
function csvCell(v) { const s = v == null ? '' : String(v); return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }
function stringifyCsv(header, rows) {
  const lines = [header.map(csvCell).join(',')];
  for (const r of rows) lines.push(header.map((h) => csvCell(r[h])).join(','));
  return lines.join('\r\n') + '\r\n';
}
/** 按主键合并：服务器行更新本地同键行（仅非空字段覆盖）；本地独有行保留；服务器新增行追加。 */
function mergeCsv(localText, serverText, keySel) {
  const srvH = headerOf(serverText);
  const locH = localText ? headerOf(localText) : srvH;
  const serverRows = parseCsv(serverText);
  const locRows = localText ? parseCsv(localText) : [];
  const keyOf = typeof keySel === 'function' ? keySel : (r) => String(r[keySel] || '').trim();
  const sMap = new Map();
  for (const r of serverRows) { const k = keyOf(r); if (k) sMap.set(k, r); }
  const out = []; const seen = new Set(); let updated = 0; let kept = 0;
  for (const lr of locRows) {
    const k = keyOf(lr); seen.add(k);
    if (sMap.has(k)) {
      const s = sMap.get(k); const m = Object.assign({}, lr);
      for (const c of srvH) { const v = s[c]; if (v != null && String(v) !== '') m[c] = v; }
      out.push(m); updated++;
    } else { out.push(lr); kept++; }
  }
  let added = 0;
  for (const [k, s] of sMap) { if (!seen.has(k)) { out.push(s); added++; } }
  const header = locH.slice();
  for (const c of srvH) if (!header.includes(c)) header.push(c);
  return { text: stringifyCsv(header, out), updated, added, kept };
}

// 拉取并【合并】到本地 database/*.csv（不覆盖本地独有行）；--overwrite 才真覆盖
async function cmdPullCsv(dir, overwrite) {
  const token = loadToken();
  if (!token) { console.error('未登录，先 node hub.js login <email> <password>'); process.exit(1); }
  dir = dir || path.join(__dirname, '..', '..', 'skills', 'hicustom-api', 'database');
  const sets = [
    ['products', 'products.csv', 'id'],
    ['listing_copy', 'listing_copy.csv', (r) => String(r.sku || '').trim()],
    ['listing_variants', 'listing_variants.csv', (r) => String(r.sku || '').trim()],
    ['designs', 'designs.csv', 'design_code'],
  ];
  const summary = {};
  for (const [ds, file, key] of sets) {
    const res = await fetch(API + '/sync/pull?format=csv&dataset=' + ds, { headers: { Authorization: 'Bearer ' + token } });
    if (!res.ok) { console.error('  ✗ ' + ds + ' 失败 ' + res.status + ' ' + (await res.text()).slice(0, 150)); continue; }
    const serverText = await res.text();
    const localPath = path.join(dir, file);
    const localText = fs.existsSync(localPath) ? fs.readFileSync(localPath, 'utf8') : '';
    if (overwrite) {
      if (localText) backup(dir, file, localText);
      fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(localPath, '\uFEFF' + serverText.replace(/^\uFEFF/, ''));
      summary[file] = '覆盖';
    } else {
      const m = mergeCsv(localText, serverText, key);
      if (localText) backup(dir, file, localText);
      fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(localPath, '\uFEFF' + m.text.replace(/^\uFEFF/, ''));
      summary[file] = `更新 ${m.updated} · 新增 ${m.added} · 保留本地独有 ${m.kept}`;
    }
  }
  console.log('✅ 合并完成 → ' + dir);
  for (const k of Object.keys(summary)) console.log('  ' + k + ': ' + summary[k]);
}
function backup(dir, file, text) {
  const bo = path.join(dir, '_hub-backup');
  fs.mkdirSync(bo, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const bf = path.join(bo, file + '.' + stamp + '.bak');
  if (!fs.existsSync(bf)) fs.writeFileSync(bf, text);
}

(async () => {
  const [cmd, a1, a2] = process.argv.slice(2);
  try {
    if (cmd === 'login') return await cmdLogin(a1, a2);
    if (cmd === 'import') return await cmdImport(a1 || path.join(__dirname, '..', '..', 'skills', 'hicustom-api', 'database'));
    if (cmd === 'push') return await cmdPush(a1);
    if (cmd === 'pull') return await cmdPull(a1);
    if (cmd === 'pullcsv') return await cmdPullCsv(a1, a2);
    console.log('用法: node hub.js <login|import|push|pull> ...');
  } catch (e) { console.error('ERR', e.message); process.exit(1); }
})();
