<template>
  <el-container style="height:100%">
    <el-header class="hd">
      <div class="brand">🗂️ Listing Hub</div>
      <el-menu :default-active="active" mode="horizontal" router class="nav" :ellipsis="false">
        <el-menu-item index="/dashboard">概览</el-menu-item>
        <el-menu-item index="/listings">上架</el-menu-item>
        <el-menu-item index="/products">商品</el-menu-item>
        <el-menu-item index="/designs">设计</el-menu-item>
        <el-menu-item index="/users">用户</el-menu-item>
      </el-menu>
      <div class="right">
        <span class="muted">{{ email }}</span>
        <el-button link type="primary" @click="pwdOpen = true">改密码</el-button>
        <el-button link type="danger" @click="logout">退出</el-button>
      </div>
    </el-header>
    <el-main style="padding:0">
      <router-view />
    </el-main>
  </el-container>

  <el-dialog v-model="pwdOpen" title="修改密码" width="420px">
    <el-form label-width="90px">
      <el-form-item label="当前密码"><el-input v-model="pw.current" type="password" show-password /></el-form-item>
      <el-form-item label="新密码"><el-input v-model="pw.next" type="password" show-password /></el-form-item>
      <el-form-item label="确认新密码"><el-input v-model="pw.confirm" type="password" show-password /></el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="pwdOpen=false">取消</el-button>
      <el-button type="primary" :loading="pwLoading" @click="changePwd">确定</el-button>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { Api } from '../api.js';

const route = useRoute();
const router = useRouter();
const active = computed(() => route.path);
const email = ref(localStorage.getItem('hub_email') || '');

const pwdOpen = ref(false);
const pwLoading = ref(false);
const pw = ref({ current: '', next: '', confirm: '' });

function logout() { localStorage.removeItem('hub_token'); location.hash = '#/login'; }

async function changePwd() {
  if (!pw.value.current || pw.value.next.length < 8) return ElMessage.warning('新密码至少 8 位');
  if (pw.value.next !== pw.value.confirm) return ElMessage.warning('两次新密码不一致');
  pwLoading.value = true;
  try {
    await Api.changePassword({ current_password: pw.value.current, new_password: pw.value.next, new_password_confirmation: pw.value.confirm });
    ElMessage.success('密码已更新，请重新登录');
    pwdOpen.value = false;
    setTimeout(logout, 800);
  } catch (e) {
    const errs = e.response && e.response.data && e.response.data.errors;
    ElMessage.error((errs && errs.current_password && errs.current_password[0]) || '修改失败');
  } finally { pwLoading.value = false; }
}
</script>

<style scoped>
.hd { display:flex; align-items:center; gap:16px; background:#131921; color:#fff; }
.brand { font-weight:700; white-space:nowrap; }
.nav { flex:1; background:transparent; border-bottom:none; }
.nav :deep(.el-menu-item) { color:#c9d1dc; }
.nav :deep(.el-menu-item.is-active) { color:#fff; border-bottom-color:#e8590c; }
.right { display:flex; align-items:center; gap:8px; white-space:nowrap; }
.right .muted { color:#9aa7b5; font-size:13px; }
</style>
