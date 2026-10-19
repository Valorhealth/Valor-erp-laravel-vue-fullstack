<script setup lang="ts">
import UiIcon from './UiIcon.vue'
import UiButton from './UiButton.vue'
defineProps<{ type: 'loading' | 'empty' | 'error'; title?: string; message?: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="ui-state" :class="`ui-state--${type}`" role="status">
    <span v-if="type === 'loading'" class="ui-state__skeleton" aria-hidden="true"></span>
    <UiIcon v-else :name="type === 'error' ? 'x' : 'folder'" :size="24" />
    <strong>{{ title || (type === 'loading' ? 'กำลังโหลดข้อมูล' : type === 'error' ? 'เกิดข้อผิดพลาด' : 'ยังไม่มีข้อมูล') }}</strong>
    <p v-if="message">{{ message }}</p>
    <UiButton v-if="type === 'error'" variant="secondary" size="sm" @click="$emit('retry')">ลองอีกครั้ง</UiButton>
    <slot />
  </div>
</template>

<style scoped>
.ui-state{display:grid;min-height:180px;place-items:center;align-content:center;gap:8px;padding:32px;color:var(--gray-500);text-align:center}.ui-state strong{color:var(--gray-700);font-size:var(--text-body)}.ui-state p{max-width:440px;margin:0;font-size:var(--text-sm)}.ui-state--error>svg{color:var(--danger-600)}.ui-state__skeleton{width:38px;height:38px;border:3px solid var(--gray-200);border-right-color:var(--primary-600);border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
</style>
