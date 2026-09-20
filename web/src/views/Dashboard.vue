<template>
  <div class="page">
    <h2>概览</h2>
    <el-row :gutter="16">
      <el-col :span="8" v-for="c in cards" :key="c.key">
        <el-card shadow="hover">
          <div class="muted">{{ c.label }}</div>
          <div class="num">{{ c.value }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card style="margin-top:16px">
      <template #header>各站点上架数（listings by marketplace）</template>
      <el-table :data="byMp" size="small">
        <el-table-column prop="marketplace" label="站点" width="220" />
        <el-table-column prop="count" label="SKU 数" />
      </el-table>
      <div v-if="!byMp.length" class="muted">暂无数据</div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Api } from '../api.js';

const cards = ref([
  { key: 'products', label: '商品 Products', value: '…' },
  { key: 'designs', label: '设计 Designs', value: '…' },
  { key: 'listings', label: '上架 Listings', value: '…' },
]);
const byMp = ref([]);

onMounted(async () => {
  const [p, d, l] = await Promise.all([Api.products({ per_page: 1 }), Api.designs({ per_page: 1 }), Api.listings({ per_page: 200 })]);
  cards.value[0].value = p.data.meta.total;
  cards.value[1].value = d.data.meta.total;
  cards.value[2].value = l.data.meta.total;
  const map = {};
  for (const x of l.data.data) map[x.marketplace] = (map[x.marketplace] || 0) + 1;
  byMp.value = Object.entries(map).map(([marketplace, count]) => ({ marketplace, count })).sort((a, b) => b.count - a.count);
});
</script>

<style scoped>
.num { font-size: 30px; font-weight: 700; margin-top: 6px; }
</style>
