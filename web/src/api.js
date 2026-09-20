import axios from 'axios';
import { ElMessage } from 'element-plus';

// 可用 VITE_API_BASE 覆盖；默认连生产中台
export const API_BASE = import.meta.env.VITE_API_BASE || 'https://cbc.weixiubang.club/api/v1';

const http = axios.create({ baseURL: API_BASE, timeout: 30000 });

http.interceptors.request.use((cfg) => {
  const t = localStorage.getItem('hub_token');
  if (t) cfg.headers.Authorization = 'Bearer ' + t;
  cfg.headers.Accept = 'application/json';
  return cfg;
});

http.interceptors.response.use(
  (r) => r,
  (err) => {
    const st = err.response && err.response.status;
    if (st === 401) {
      localStorage.removeItem('hub_token');
      if (location.hash.indexOf('/login') < 0) location.hash = '#/login';
    } else if (st && st !== 422) {
      const m = err.response && err.response.data && (err.response.data.message || err.response.data.err);
      ElMessage.error(m || ('请求失败 ' + st));
    }
    return Promise.reject(err);
  },
);

export default http;
export const Api = {
  login: (email, password) => http.post('/auth/login', { email, password }),
  me: () => http.get('/me'),
  changePassword: (p) => http.post('/me/password', p),

  products: (params) => http.get('/products', { params }),
  product: (id) => http.get('/products/' + id),
  designs: (params) => http.get('/designs', { params }),
  design: (id) => http.get('/designs/' + id),
  listings: (params) => http.get('/listings', { params }),
  listing: (id, params) => http.get('/listings/' + id, { params }),
  updateListing: (id, patch) => http.patch('/listings/' + id, patch),
  updateProduct: (id, patch) => http.patch('/products/' + id, patch),
  revisions: (id) => http.get('/listings/' + id + '/revisions'),
  normalizeListing: (id) => http.post('/listings/' + id + '/normalize-images'),
  normalizeDesign: (id) => http.post('/designs/' + id + '/normalize-images'),

  users: () => http.get('/users'),
  createUser: (p) => http.post('/users', p),
  deleteUser: (id) => http.delete('/users/' + id),
};
