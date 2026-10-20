<script setup lang="ts">
import ToastHost from './components/ToastHost.vue'
import { networkActivity } from './stores/networkActivity'
const globalLoading = networkActivity.visible
</script>

<template>
  <RouterView />
  <ToastHost />
  <Transition name="network-fade"><div v-if="globalLoading" class="network-overlay" role="status" aria-live="polite"><div class="network-overlay__card"><span class="network-overlay__spinner" aria-hidden="true"></span><strong>ระบบกำลังประมวลผล</strong><small>กรุณารอสักครู่และอย่ากดซ้ำ</small></div></div></Transition>
</template>

<style scoped>
.network-overlay{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:rgba(15,23,42,.3);backdrop-filter:blur(2px)}.network-overlay__card{display:grid;justify-items:center;gap:8px;min-width:230px;padding:22px 28px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 18px 60px rgba(15,23,42,.24)}.network-overlay__card small{color:#64748b}.network-overlay__spinner{width:38px;height:38px;border:4px solid #e2e8f0;border-right-color:#c72d3a;border-radius:50%;animation:network-spin .75s linear infinite}@keyframes network-spin{to{transform:rotate(360deg)}}.network-fade-enter-active,.network-fade-leave-active{transition:opacity .16s ease}.network-fade-enter-from,.network-fade-leave-to{opacity:0}
</style>
