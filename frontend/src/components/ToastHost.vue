<script setup lang="ts">
import { toast } from '../stores/toast'
import UiIcon from './UiIcon.vue'
</script>

<template>
  <Teleport to="body"><div class="toast-host" aria-live="polite"><article v-for="item in toast.messages.value" :key="item.id" class="toast-item" :class="`toast-item--${item.tone}`"><UiIcon :name="item.tone === 'success' ? 'check' : item.tone === 'error' ? 'x' : 'bell'" :size="18" /><span>{{ item.text }}</span><button type="button" aria-label="ปิดข้อความ" @click="toast.dismiss(item.id)"><UiIcon name="x" :size="15" /></button></article></div></Teleport>
</template>

<style scoped>
.toast-host{position:fixed;z-index:4000;top:16px;right:16px;display:grid;width:min(380px,calc(100vw - 32px));gap:8px}.toast-item{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid var(--gray-200);border-radius:var(--radius-md);background:var(--gray-0);color:var(--gray-700);box-shadow:var(--shadow-md);font-size:var(--text-sm)}.toast-item>span{flex:1}.toast-item>button{display:grid;width:24px;height:24px;place-items:center;padding:0;border:0;background:transparent;color:var(--gray-500)}.toast-item--success>svg{color:var(--success-600)}.toast-item--error>svg{color:var(--danger-600)}.toast-item--info>svg{color:var(--info-700)}
</style>
