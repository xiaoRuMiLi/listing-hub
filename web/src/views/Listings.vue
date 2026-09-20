<template>
  <div class="page">
    <div class="toolbar">
      <h2 style="margin:0 12px 0 0">上架 Listings</h2>
      <el-select v-model="f.marketplace" placeholder="站点" clearable style="width:190px" @change="reload">
        <el-option v-for="m in MPS" :key="m.code" :label="m.label" :value="m.code" />
      </el-select>
      <el-select v-model="f.status" placeholder="状态" clearable style="width:130px" @change="reload">
        <el-option v-for="s in STATUS" :key="s" :label="s" :value="s" />
      </el-select>
      <el-select v-model="f.is_parent" placeholder="父/子" clearable style="width:120px" @change="reload">
        <el-option label="父体" :value="1" />
        <el-option label="子体" :value="0" />
      </el-select>
      <el-input v-model="f.sku" placeholder="SKU 搜索" clearable style="width:260px" @keyup.enter="reload" />
      <el-button type="primary" @click="reload">查询</el-button>
      <el-button @click="reset">重置</el-button>
      <el-button type="warning" :loading="cdnBatch" @click="switchCdnAll">🖼 批量换图床(本页)</el-button>
      <el-progress v-if="cdnProgress.total" :percentage="cdnPct" :status="cdnProgress.done>=cdnProgress.total?'success':''" style="width:170px" />
      <span class="muted" v-if="cdnProgress.total">{{ cdnProgress.done }}/{{ cdnProgress.total }}</span>
      <span class="muted">共 {{ total }} 条</span>
    </div>

    <el-table :data="rows" v-loading="loading" size="small" border @row-click="open" style="cursor:pointer">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="sku" label="SKU" min-width="240" show-overflow-tooltip />
      <el-table-column prop="marketplace" label="站点" width="150" />
      <el-table-column label="类型" width="66">
        <template #default="{ row }"><el-tag size="small" :type="row.is_parent ? '' : 'info'">{{ row.is_parent ? '父' : '子' }}</el-tag></template>
      </el-table-column>
      <el-table-column label="变体" width="120">
        <template #default="{ row }">{{ [row.variant_color, row.variant_size].filter(Boolean).join('/') || '-' }}</template>
      </el-table-column>
      <el-table-column prop="status" label="状态" width="110">
        <template #default="{ row }"><el-tag size="small" :type="stType(row.status)">{{ row.status }}</el-tag></template>
      </el-table-column>
      <el-table-column label="价格" width="110">
        <template #default="{ row }">{{ row.price ? (((row.currency || '') + ' ' + row.price).trim()) : '-' }}</template>
      </el-table-column>
      <el-table-column prop="asin" label="ASIN" width="130" />
      <el-table-column label="图床" width="90">
        <template #default="{ row }"><el-button size="small" link type="warning" @click.stop="switchCdnOne(row)">🖼 换床</el-button></template>
      </el-table-column>
    </el-table>

    <el-pagination style="margin-top:12px" layout="prev, pager, next, sizes, total"
      :total="total" v-model:current-page="f.page" v-model:page-size="f.per_page"
      :page-sizes="[20,50,100,200]" @current-change="load" @size-change="reload" />

    <!-- 详情抽屉 -->
    <el-drawer v-model="drawer" size="560px" :title="cur ? cur.sku : ''">
      <template v-if="cur">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="ID">{{ cur.id }}</el-descriptions-item>
          <el-descriptions-item label="站点">{{ cur.marketplace }}</el-descriptions-item>
          <el-descriptions-item label="平台">{{ cur.platform }}</el-descriptions-item>
          <el-descriptions-item label="父/子">{{ cur.is_parent ? '父体' : '子体' }} {{ cur.parent_sku ? ('· 父 ' + cur.parent_sku) : '' }}</el-descriptions-item>
          <el-descriptions-item label="主题">{{ cur.variation_theme || '-' }}</el-descriptions-item>
          <el-descriptions-item label="ASIN">{{ cur.asin || '-' }}</el-descriptions-item>
          <el-descriptions-item label="上架时间">{{ cur.published_at || '-' }}</el-descriptions-item>
          <el-descriptions-item label="revision">{{ cur.revision }}</el-descriptions-item>
        </el-descriptions>

        <h4>可编辑</h4>
        <el-form label-width="90px" size="small">
          <el-form-item label="状态">
            <el-select v-model="edit.status" style="width:200px">
              <el-option v-for="s in STATUS" :key="s" :label="s" :value="s" />
            </el-select>
          </el-form-item>
          <el-form-item label="价格"><el-input v-model="edit.price" style="width:200px" /></el-form-item>
          <el-form-item label="商品价"><el-input v-model="edit.product_price" style="width:200px" /></el-form-item>
          <el-form-item label="运费档"><el-input v-model="edit.shipping_fee" style="width:200px" /></el-form-item>
        </el-form>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
        <span class="muted" style="margin-left:8px">带 revision 乐观锁（冲突会提示）</span>

        <h4 style="margin-top:16px">图片 · 一键换图床</h4>
        <div class="row" style="margin:6px 0 8px">
          <el-button type="warning" size="small" :loading="cdnCur" @click="switchCdnCur">🖼 一键换图床</el-button>
          <span class="muted" v-if="curCdn">{{ curCdn }}</span>
        </div>
        <div class="gallery">
          <a v-for="(u, i) in listImgs" :key="i" :href="u" target="_blank" rel="noopener"><img :src="u" class="shot" /></a>
        </div>
        <div v-if="!listImgs.length" class="muted">本 listing 暂无图片记录（重跑 hub import 会上传）</div>

        <h4 style="margin-top:16px">上架图片（{{ assets.length }}）</h4>
        <div class="gallery">
          <a v-for="a in assets" :key="a.id" :href="(a.blob && a.blob.public_url) || '#'" target="_blank" rel="noopener" :title="a.role">
            <img :src="(a.blob && a.blob.public_url) || ''" class="shot" />
          </a>
        </div>
        <div v-if="!assets.length" class="muted">暂无已推图片（重跑 hub import 时会把该 listing 的效果图一起推上来）</div>

        <h4 style="margin-top:16px">attrs_json（上架字段）</h4>
        <pre class="mono">{{ prettyAttrs }}</pre>
      </template>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Api } from '../api.js';

const MPS = [
  { code: 'A1F83G8C2ARO7P', label: 'UK 英国' }, { code: 'A1PA6795UKMFR9', label: 'DE 德国' },
  { code: 'A13V1IB3VIYZZH', label: 'FR 法国' }, { code: 'APJ6JRA9NG5V4', label: 'IT 意大利' },
  { code: 'A1RKKUPIHCS9HS', label: 'ES 西班牙' }, { code: 'ATVPDKIKX0DER', label: 'US 美国' },
  { code: 'A1AM78C64UM0Y8', label: 'MX 墨西哥' }, { code: 'A2EUQ1WTGCTBG2', label: 'CA 加拿大' },
];
const STATUS = ['draft', 'candidate', 'ready', 'published', 'error', 'archived'];

const rows = ref([]); const total = ref(0); const loading = ref(false);
const f = ref({ marketplace: '', status: '', is_parent: '', sku: '', page: 1, per_page: 50 });

const drawer = ref(false); const cur = ref(null); const edit = ref({}); const saving = ref(false);

function stType(s) { return { published: 'success', ready: 'primary', candidate: 'warning', error: 'danger', archived: 'info' }[s] || 'info'; }

async function load() {
  loading.value = true;
  try {
    const params = { page: f.value.page, per_page: f.value.per_page };
    if (f.value.marketplace) params.marketplace = f.value.marketplace;
    if (f.value.status) params.status = f.value.status;
    if (f.value.is_parent !== '') params.is_parent = f.value.is_parent === 1 ? 1 : 0;
    if (f.value.sku) params.sku = f.value.sku;
    const { data } = await Api.listings(params);
    rows.value = data.data; total.value = data.meta.total;
  } finally { loading.value = false; }
}
function reload() { f.value.page = 1; load(); }
function reset() { f.value = { marketplace: '', status: '', is_parent: '', sku: '', page: 1, per_page: 50 }; load(); }

async function open(row) {
  const { data } = await Api.listing(row.id, { with_assets: 1 });
  cur.value = data.data;
  edit.value = { status: cur.value.status, price: cur.value.price, product_price: cur.value.product_price, shipping_fee: cur.value.shipping_fee };
  drawer.value = true;
}
const assets = computed(() => (cur.value && cur.value.assets) || []);

// 一键换图床
const cdnBatch = ref(false);
const cdnCur = ref(false);
const cdnCurMsg = ref('');
const cdnProgress = ref({ done: 0, total: 0 });
const cdnPct = computed(() => (cdnProgress.value.total ? Math.round((cdnProgress.value.done / cdnProgress.value.total) * 100) : 0));
const curCdn = computed(() => cdnCurMsg.value);
const listImgs = computed(() => {
  const out = [];
  if (cur.value && cur.value.main_image) out.push(cur.value.main_image);
  if (cur.value && cur.value.other_images) String(cur.value.other_images).split('|').map((s) => s.trim()).filter(Boolean).forEach((u) => out.push(u));
  return out;
});

const isOss = (u) => /oss-cn-hongkong|listing-hub\.oss/.test(String(u || ''));
async function switchCdnOne(row) {
  if (isOss(row.main_image)) { ElMessage.info('已是 OSS，跳过：' + row.sku); return; }
  try { const { data } = await Api.switchCdn(row.id); ElMessage.success('换床 ' + row.sku + '：' + data.data.count + ' 张'); load(); }
  catch (e) { ElMessage.error('换床失败：' + row.sku); }
}
async function switchCdnCur() {
  cdnCur.value = true; cdnCurMsg.value = '处理中…';
  try {
    const { data } = await Api.switchCdn(cur.value.id);
    const r = data.data.results || [];
    const ok = r.filter((x) => x.status === 'ok').length;
    const already = r.filter((x) => x.status === 'already').length;
    const err = r.filter((x) => x.status === 'error').length;
    cdnCurMsg.value = `共 ${data.data.count} 张：新换 ${ok} · 已是OSS ${already}${err ? (' · 失败 ' + err) : ''}`;
    ElMessage.success('换图床完成');
    const det = await Api.listing(cur.value.id, { with_assets: 1 }); cur.value = det.data.data;
    load();
  } catch (e) { cdnCurMsg.value = '失败'; ElMessage.error('换图床失败'); }
  finally { cdnCur.value = false; }
}
async function switchCdnAll() {
  const list = rows.value.filter((r) => r.main_image && !isOss(r.main_image));
  const skipped = rows.value.length - list.length;
  if (!list.length) { ElMessage.info('本页都已换过（或没图），无需处理' + (skipped ? '（已跳过 ' + skipped + '）' : '')); return; }
  cdnProgress.value = { done: 0, total: list.length }; cdnBatch.value = true;
  let ok = 0;
  for (const r of list) {
    try { await Api.switchCdn(r.id); ok++; } catch (e) { /* skip */ }
    cdnProgress.value.done++;
  }
  cdnBatch.value = false;
  ElMessage.success('批量换床完成：' + ok + '/' + list.length + (skipped ? ('（跳过已是OSS ' + skipped + '）') : ''));
  load();
}
const prettyAttrs = computed(() => (cur.value && cur.value.attrs_json) ? JSON.stringify(cur.value.attrs_json, null, 2) : '(空)');

async function save() {
  saving.value = true;
  try {
    const patch = Object.assign({ revision: cur.value.revision }, edit.value);
    const { data } = await Api.updateListing(cur.value.id, patch);
    ElMessage.success('已保存');
    Object.assign(cur.value, data.data);
    load();
  } catch (e) {
    if (e.response && e.response.status === 409) ElMessageBox.alert('服务端版本比你这旧/新不一致，已刷新，请重试。', '冲突');
  } finally { saving.value = false; }
}

onMounted(load);
</script>

<style scoped>
pre.mono { background:#0f172a; color:#cbd5e1; padding:10px; border-radius:8px; max-height:280px; overflow:auto; white-space:pre-wrap; }
.gallery { display:flex; flex-wrap:wrap; gap:8px; }
.gallery .shot { width:96px; height:96px; object-fit:cover; border:1px solid #eee; border-radius:8px; display:block; background:#fafafa; }
</style>
