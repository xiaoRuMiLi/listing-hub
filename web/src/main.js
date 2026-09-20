import { createApp } from 'vue';
import ElementPlus from 'element-plus';
import 'element-plus/dist/index.css';
import * as ElIcons from '@element-plus/icons-vue';
import App from './App.vue';
import router from './router';

const app = createApp(App);
for (const [k, v] of Object.entries(ElIcons)) app.component(k, v);
app.use(router);
app.use(ElementPlus);
app.mount('#app');
