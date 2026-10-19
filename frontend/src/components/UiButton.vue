<script setup lang="ts">
import UiIcon from './UiIcon.vue'

withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'link'
  size?: 'sm' | 'md' | 'lg'
  icon?: string
  loading?: boolean
  disabled?: boolean
  type?: 'button' | 'submit' | 'reset'
}>(), { variant: 'secondary', size: 'md', type: 'button' })
</script>

<template>
  <button class="ui-button" :class="[`ui-button--${variant}`, `ui-button--${size}`]" :type="type" :disabled="disabled || loading" :aria-busy="loading"><span v-if="loading" class="ui-spinner" aria-hidden="true"></span><UiIcon v-else-if="icon" :name="icon" :size="size === 'sm' ? 15 : 17" /><slot /></button>
</template>

<style scoped>
.ui-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:8px 14px;border:1px solid transparent;border-radius:var(--radius-sm);font:600 var(--text-sm)/1.3 var(--font-sans);transition:background var(--motion-fast) var(--ease-out),border-color var(--motion-fast) var(--ease-out),color var(--motion-fast) var(--ease-out)}
.ui-button--primary{background:var(--primary-600);color:var(--gray-0)}.ui-button--primary:hover:not(:disabled){background:var(--primary-700)}
.ui-button--secondary{border-color:var(--gray-300);background:var(--gray-0);color:var(--gray-700)}.ui-button--secondary:hover:not(:disabled),.ui-button--ghost:hover:not(:disabled){background:var(--gray-50);color:var(--gray-900)}
.ui-button--ghost,.ui-button--link{background:transparent;color:var(--gray-600)}.ui-button--link{padding-inline:4px;color:var(--primary-700)}
.ui-button--danger{border-color:var(--danger-200);background:var(--danger-50);color:var(--danger-700)}
.ui-button--sm{min-height:32px;padding:5px 10px;font-size:var(--text-caption)}.ui-button--lg{min-height:44px;padding:10px 18px}
.ui-spinner{width:15px;height:15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
</style>
