<template>
  <div class="page">
    <div class="toolbar">
      <h2 style="margin:0 12px 0 0">设计 Designs</h2>
      <el-input v-model="f.design_key" placeholder="design_key（如 default）" style="width:200px" clearable @keyup.enter="reload" />
      <el-select v-model="f.status" placeholder="状态" clearable style="width:140px" @change="reload">
        <el-option v-for="s in ['draft','active','superseded','archived']" :key="s" :label="s" :value="s" />
      </el-select>
      <el-button type="primary" @click="reload">查询</el-button>
      <span class="muted">共 {{ total }}</span>
    </div>

    <el-table :data="rows" v-loading="loading" size="small" border @row-click="open" style="cursor:pointer">
      <el-table-column label="效果图" width="80">
        <template #default="{ row }">
          <img v-if="row.main_image" :src="row.main_image" class="thumb" />
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column prop="design_code" label="design_code" width="130" />
      <el-table-column prop="design_key" label="身份" width="100" />
      <el-table-column prop="version" label="版本" width="70" />
      <el-table-column label="商品" min-width="170">
        <template #default="{ row }">{{ row.product ? (row.product.cn_name + ' (' + row.product.code + ')') : '-' }}</template>
      </el-table-column>
      <el-table-column prop="template" label="模板" width="110" />
      <el-table-column prop="status" label="状态" width="100">
        <template #default="{ row }"><el-tag size="small" :type="row.status === 'active' ? 'success' : 'info'">{{ row.status }}</el-tag></template>
      </el-table-column>
      <el-table-column label="效果图数" width="90"><template #default="{ row }">{{ row.effect_count || imagesOf(row).length }}</template></el-table-column>
    </el-table>

    <el-pagination style="margin-top:12px" layout="prev, pager, next, sizes, total"
      :total="total" v-model:current-page="f.page" v-model:page-size="f.per_page"
      :page-sizes="[20,50,100]" @current-change="load" @size-change="reload" />

    <!-- 设计展示（效果图画廊） -->
    <el-drawer v-model="drawer" size="720px" :title="cur ? ('设计 ' + cur.design_code) : ''">
      <template v-if="cur">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="design_code">{{ cur.design_code }}</el-descriptions-item>
          <el-descriptions-item label="身份">{{ cur.design_key || '-' }}</el-descriptions-item>
          <el-descriptions-item label="版本">{{ cur.version || '-' }}</el-descriptions-item>
          <el-descriptions-item label="父码">{{ cur.parent_code || '-' }}</el-descriptions-item>
          <el-descriptions-item label="商品">{{ cur.product ? (cur.product.cn_name + ' (' + cur.product.code + ')') : '-' }}</el-descriptions-item>
          <el-descriptions-item label="模板">{{ cur.template || '-' }}</el-descriptions-item>
          <el-descriptions-item label="图案">{{ cur.pattern || '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ cur.status }}</el-descriptions-item>
        </el-descriptions>

        <div style="margin:12px 0">
          <el-button type="primary" size="small" :loading="norming" @click="norm">🖼 把效果图归一存 OSS</el-button>
          <span class="muted" style="margin-left:8px">把指纹 CDN 链接镜像到我们的图床（只改非自有域名）</span>
        </div>

        <h4>效果图（{{ imagesOf(cur).length }}）</h4>
        <div class="gallery">
          <a v-for="(u, i) in imagesOf(cur)" :key="i" :href="u" target="_blank" rel="noopener">
            <img :src="u" class="shot" />
          </a>
        </div>
        <div v-if="!imagesOf(cur).length" class="muted">该设计暂无效果图（可在设计流程里重出图，或先归一并回填）。</div>
      </template>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { Api } from '../api.js';

const rows = ref([]); const total = ref(0); const loading = ref(false);
const f = ref({ design_key: '', status: '', page: 1, per_page: 50 });
const drawer = ref(false); const cur = ref(null); const norming = ref(false);

function imagesOf(d) {
  const out = [];
  if (d && d.main_image) out.push(d.main_image);
  if (d && d.other_images) String(d.other_images).split('|').map((s) => s.trim()).filter(Boolean).forEach((u) => out.push(u));
  return out;
}

async function load() {
  loading.value = true;
  try {
    const params = { page: f.value.page, per_page: f.value.per_page };
    if (f.value.design_key) params.design_key = f.value.design_key;
    if (f.value.status) params.status = f.value.status;
    const { data } = await Api.designs(params);
    rows.value = data.data; total.value = data.meta.total;
  } finally { loading.value = false; }
}
function reload() { f.value.page = 1; load(); }

async function open(row) { const { data } = await Api.design(row.id); cur.value = data.data; drawer.value = true; }

async function norm() {
  norming.value = true;
  try {
    const { data } = await Api.normalizeDesign(cur.value.id);
    ElMessage.success('已归一：' + JSON.stringify(data.data.mirrored));
    await open(cur.value);
    load();
  } finally { norming.value = false; }
}

onMounted(load);
</script>

<style scoped>
.thumb { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; border: 1px solid #eee; }
.gallery { display: flex; flex-wrap: wrap; gap: 10px; }
.gallery .shot { width: 180px; height: 180px; object-fit: contain; background: #fafafa; border: 1px solid #eee; border-radius: 8px; }
</style>
