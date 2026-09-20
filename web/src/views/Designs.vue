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
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="design_code" label="design_code" width="140" />
      <el-table-column prop="design_key" label="身份" width="110" />
      <el-table-column prop="version" label="版本" width="80" />
      <el-table-column label="商品" min-width="180">
        <template #default="{ row }">{{ row.product ? (row.product.cn_name + ' (' + row.product.code + ')') : '-' }}</template>
      </el-table-column>
      <el-table-column prop="template" label="模板" width="120" />
      <el-table-column prop="status" label="状态" width="110">
        <template #default="{ row }"><el-tag size="small" :type="row.status === 'active' ? 'success' : 'info'">{{ row.status }}</el-tag></template>
      </el-table-column>
      <el-table-column label="效果图数" width="90"><template #default="{ row }">{{ row.effect_count || 0 }}</template></el-table-column>
    </el-table>

    <el-pagination style="margin-top:12px" layout="prev, pager, next, sizes, total"
      :total="total" v-model:current-page="f.page" v-model:page-size="f.per_page"
      :page-sizes="[20,50,100]" @current-change="load" @size-change="reload" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Api } from '../api.js';

const rows = ref([]); const total = ref(0); const loading = ref(false);
const f = ref({ design_key: '', status: '', page: 1, per_page: 50 });

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
function open() { /* 详情页后续加 */ }
onMounted(load);
</script>
