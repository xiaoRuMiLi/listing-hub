<template>
  <div class="page">
    <div class="toolbar">
      <h2 style="margin:0 12px 0 0">商品 Products</h2>
      <el-input v-model="f.q" placeholder="搜索 code / 名称" style="width:240px" @keyup.enter="reload" />
      <el-select v-model="f.status" placeholder="状态" clearable style="width:130px" @change="reload">
        <el-option v-for="s in ['draft','ready','synced','archived']" :key="s" :label="s" :value="s" />
      </el-select>
      <el-button type="primary" @click="reload">查询</el-button>
      <span class="muted">共 {{ total }}</span>
    </div>

    <el-table :data="rows" v-loading="loading" size="small" border @row-click="open" style="cursor:pointer">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="code" label="code(指纹id)" width="120" />
      <el-table-column prop="cn_name" label="中文名" min-width="180" show-overflow-tooltip />
      <el-table-column prop="en_name" label="英文名" min-width="180" show-overflow-tooltip />
      <el-table-column prop="material_cn" label="材质" width="100" />
      <el-table-column prop="min_price" label="最低价" width="90" />
      <el-table-column prop="status" label="状态" width="100">
        <template #default="{ row }"><el-tag size="small">{{ row.status }}</el-tag></template>
      </el-table-column>
    </el-table>

    <el-pagination style="margin-top:12px" layout="prev, pager, next, sizes, total"
      :total="total" v-model:current-page="f.page" v-model:page-size="f.per_page"
      :page-sizes="[20,50,100]" @current-change="load" @size-change="reload" />

    <el-drawer v-model="drawer" size="560px" :title="cur ? (cur.cn_name || cur.code) : ''">
      <template v-if="cur">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="ID">{{ cur.id }}</el-descriptions-item>
          <el-descriptions-item label="code">{{ cur.code }}</el-descriptions-item>
          <el-descriptions-item label="英文名">{{ cur.en_name }}</el-descriptions-item>
          <el-descriptions-item label="材质">{{ cur.material_cn }} / {{ cur.material_en }}</el-descriptions-item>
          <el-descriptions-item label="印刷区">{{ cur.print_face_w }} × {{ cur.print_face_h }}</el-descriptions-item>
          <el-descriptions-item label="最低价">{{ cur.min_price }} {{ cur.currency }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ cur.status }}</el-descriptions-item>
        </el-descriptions>
        <h4>供应商来源</h4>
        <el-table :data="cur.sources || []" size="small" border>
          <el-table-column label="供应商"><template #default="{ row }">{{ row.supplier ? row.supplier.code : '' }}</template></el-table-column>
          <el-table-column prop="external_id" label="外部ID" />
          <el-table-column prop="supplier_sku" label="供应商SKU" />
        </el-table>
        <h4>分类</h4>
        <el-tag v-for="c in (cur.categories || [])" :key="c.id" style="margin-right:6px">{{ c.platform }}:{{ c.code }}</el-tag>
        <h4>变体</h4>
        <el-table :data="cur.variants || []" size="small" border>
          <el-table-column prop="color" label="颜色" />
          <el-table-column prop="size" label="尺码" />
          <el-table-column prop="weight_g" label="重量g" />
        </el-table>

        <h4>物流（各国试算）</h4>
        <el-table :data="cur.shipping || []" size="small" border>
          <el-table-column prop="country" label="国家" width="90" />
          <el-table-column label="运费"><template #default="{ row }">{{ row.amount }} {{ row.currency || '' }}</template></el-table-column>
          <el-table-column prop="channel" label="渠道" />
        </el-table>
        <div v-if="!(cur.shipping && cur.shipping.length)" class="muted">暂无物流数据（可跑本仓 shipping:backfill 后重新 import）</div>

        <h4>设计 / 效果图</h4>
        <div class="gallery">
          <div v-for="d in (cur.designs || [])" :key="d.id" class="dcard">
            <a v-if="d.main_image" :href="d.main_image" target="_blank" rel="noopener"><img :src="d.main_image" class="shot" /></a>
            <div class="mono">{{ d.design_code }} <span class="muted">{{ d.design_key || '' }}</span></div>
          </div>
        </div>
        <div v-if="!(cur.designs && cur.designs.length)" class="muted">该商品暂无设计</div>
      </template>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Api } from '../api.js';

const rows = ref([]); const total = ref(0); const loading = ref(false);
const f = ref({ q: '', status: '', page: 1, per_page: 50 });
const drawer = ref(false); const cur = ref(null);

async function load() {
  loading.value = true;
  try {
    const params = { page: f.value.page, per_page: f.value.per_page };
    if (f.value.q) params.q = f.value.q;
    if (f.value.status) params.status = f.value.status;
    const { data } = await Api.products(params);
    rows.value = data.data; total.value = data.meta.total;
  } finally { loading.value = false; }
}
function reload() { f.value.page = 1; load(); }
async function open(row) { const { data } = await Api.product(row.id); cur.value = data.data; drawer.value = true; }
onMounted(load);
</script>

<style scoped>
.gallery { display: flex; flex-wrap: wrap; gap: 10px; }
.gallery .shot { width: 120px; height: 120px; object-fit: contain; background: #fafafa; border: 1px solid #eee; border-radius: 8px; display:block; }
.dcard { text-align: center; }
</style>
