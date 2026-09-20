<template>
  <div class="page">
    <div class="toolbar">
      <h2 style="margin:0 12px 0 0">用户账号</h2>
      <el-button type="primary" @click="dlg = true">+ 新增用户</el-button>
      <span class="muted">共 {{ rows.length }}</span>
    </div>

    <el-table :data="rows" v-loading="loading" size="small" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="姓名" width="160" />
      <el-table-column prop="email" label="邮箱" min-width="220" />
      <el-table-column prop="created_at" label="创建时间" width="220" />
      <el-table-column label="操作" width="110">
        <template #default="{ row }">
          <el-button size="small" type="danger" link @click="del(row)" :disabled="row.email === me">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dlg" title="新增用户" width="440px">
      <el-form label-width="80px">
        <el-form-item label="姓名"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="邮箱"><el-input v-model="form.email" /></el-form-item>
        <el-form-item label="密码"><el-input v-model="form.password" type="password" show-password placeholder="至少 8 位" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dlg = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="create">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Api } from '../api.js';

const rows = ref([]); const loading = ref(false);
const dlg = ref(false); const saving = ref(false);
const form = ref({ name: '', email: '', password: '' });
const me = localStorage.getItem('hub_email') || '';

async function load() {
  loading.value = true;
  try { const { data } = await Api.users(); rows.value = data.data; } finally { loading.value = false; }
}
async function create() {
  if (!form.value.name || !form.value.email || form.value.password.length < 8) return ElMessage.warning('请填全，密码≥8位');
  saving.value = true;
  try {
    await Api.createUser(form.value);
    ElMessage.success('已创建');
    dlg.value = false; form.value = { name: '', email: '', password: '' };
    load();
  } catch (e) {
    const errs = e.response && e.response.data && e.response.data.errors;
    ElMessage.error(errs ? Object.values(errs)[0][0] : '创建失败');
  } finally { saving.value = false; }
}
async function del(row) {
  if (!(await ElMessageBox.confirm('删除用户 ' + row.email + '？', '确认').then(() => true).catch(() => false))) return;
  try { await Api.deleteUser(row.id); ElMessage.success('已删除'); load(); } catch (e) { ElMessage.error('删除失败'); }
}
onMounted(load);
</script>
