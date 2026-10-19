<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import UiIcon from './UiIcon.vue'
const props = defineProps<{ open: boolean; title: string; description?: string }>()
const emit = defineEmits<{ close: [] }>()
function onKeydown(event: KeyboardEvent) { if (props.open && event.key === 'Escape') emit('close') }
onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body"><div v-if="open" class="ui-drawer-overlay" @click.self="$emit('close')"><aside class="ui-drawer" role="dialog" aria-modal="true" :aria-label="title"><header><div><h2>{{ title }}</h2><p v-if="description">{{ description }}</p></div><button type="button" aria-label="ปิด" @click="$emit('close')"><UiIcon name="x" :size="18" /></button></header><div class="ui-drawer__body"><slot /></div><footer v-if="$slots.footer"><slot name="footer" /></footer></aside></div></Teleport>
</template>

<style scoped>
.ui-drawer-overlay{position:fixed;inset:0;z-index:2500;display:flex;justify-content:flex-end;background:rgba(15,17,21,.5)}.ui-drawer{display:grid;grid-template-rows:auto minmax(0,1fr) auto;width:min(560px,100%);height:100%;background:var(--gray-0);box-shadow:var(--shadow-xl)}.ui-drawer header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 24px;border-bottom:1px solid var(--gray-200)}.ui-drawer h2{margin:0;font-size:var(--text-h2)}.ui-drawer header p{margin:4px 0 0;color:var(--gray-600);font-size:var(--text-sm)}.ui-drawer header button{display:grid;width:34px;height:34px;place-items:center;border:1px solid var(--gray-200);border-radius:var(--radius-sm);background:var(--gray-0);color:var(--gray-600)}.ui-drawer__body{overflow:auto;padding:24px}.ui-drawer footer{display:flex;justify-content:flex-end;gap:8px;padding:14px 24px;border-top:1px solid var(--gray-200)}
</style>
