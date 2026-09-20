<template>
  <div class="login-wrap">
    <el-card class="login-card">
      <h2 style="margin:0 0 4px">Listing Hub</h2>
      <div class="muted" style="margin-bottom:16px">上架中台 · 登录</div>
      <el-form @submit.prevent="submit">
        <el-form-item>
          <el-input v-model="email" placeholder="邮箱" size="large" />
        </el-form-item>
        <el-form-item>
          <el-input v-model="password" type="password" placeholder="密码" size="large" show-password @keyup.enter="submit" />
        </el-form-item>
        <el-button type="primary" size="large" style="width:100%" :loading="loading" @click="submit">登录</el-button>
      </el-form>
      <div class="muted" style="margin-top:12px;font-size:12px">API：{{ API_BASE }}</div>
    </el-card>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { Api, API_BASE } from '../api.js';

const email = ref(localStorage.getItem('hub_email') || '');
const password = ref('');
const loading = ref(false);
const router = useRouter();

async function submit() {
  if (!email.value || !password.value) return ElMessage.warning('请填邮箱和密码');
  loading.value = true;
  try {
    const { data } = await Api.login(email.value.trim(), password.value);
    localStorage.setItem('hub_token', data.data.token);
    localStorage.setItem('hub_email', email.value.trim());
    ElMessage.success('登录成功');
    router.push('/dashboard');
  } catch (e) {
    const m = e.response && e.response.data && e.response.data.errors;
    ElMessage.error((m && m.email && m.email[0]) || '登录失败');
  } finally {
    loading.value = false;
  }
}
</script>

<style scoped>
.login-wrap { height: 100%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg,#eef2f7,#dfe7f1); }
.login-card { width: 360px; }
</style>
