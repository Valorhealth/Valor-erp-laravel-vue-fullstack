<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ name: string; size?: number }>(), { size: 18 })

const paths: Record<string, string[]> = {
  megaphone: ['M3 11v2a2 2 0 0 0 2 2h2l4 4V5L7 9H5a2 2 0 0 0-2 2Z', 'M11 8.5c3.5 0 6-1.5 9-4v15c-3-2.5-5.5-4-9-4'],
  'file-plus': ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z', 'M14 2v6h6', 'M12 18v-6', 'M9 15h6'],
  'file-pen': ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h8', 'M14 2v6h6', 'm14.5 18.5 5-5a2.1 2.1 0 0 1 3 3l-5 5-4 1Z'],
  folder: ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z'],
  history: ['M3 12a9 9 0 1 0 3-6.7L3 8', 'M3 3v5h5', 'M12 7v5l3 2'],
  'shield-check': ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z', 'm9 12 2 2 4-4'],
  phone: ['M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1.2.4 2.4.8 3.5a2 2 0 0 1-.5 2.1L8.1 10.6a16 16 0 0 0 5.3 5.3l1.3-1.3a2 2 0 0 1 2.1-.5c1.1.4 2.3.7 3.5.8a2 2 0 0 1 1.7 2Z'],
  chart: ['M3 3v18h18', 'M7 16v-5', 'M12 16V7', 'M17 16v-3'],
  settings: ['M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z', 'M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21h-4v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V3h4v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1v4H21a1.7 1.7 0 0 0-1.6 1Z'],
  database: ['M4 6c0-2 3.6-3.5 8-3.5S20 4 20 6s-3.6 3.5-8 3.5S4 8 4 6Z', 'M4 6v6c0 2 3.6 3.5 8 3.5s8-1.5 8-3.5V6', 'M4 12v6c0 2 3.6 3.5 8 3.5s8-1.5 8-3.5v-6'],
  'credit-card': ['M3 5h18v14H3Z', 'M3 10h18', 'M7 15h3'],
  users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M22 21v-2a4 4 0 0 0-3-3.9', 'M16 3.1a4 4 0 0 1 0 7.8'],
  calendar: ['M3 5h18v16H3Z', 'M16 3v4', 'M8 3v4', 'M3 10h18'],
  activity: ['M3 12h4l2-6 4 12 2-6h6'],
  check: ['m5 12 4 4L19 6'],
  x: ['M18 6 6 18', 'm6 6 12 12'],
  list: ['M8 6h13', 'M8 12h13', 'M8 18h13', 'M3 6h.01', 'M3 12h.01', 'M3 18h.01'],
  wallet: ['M3 6h16v14H3Z', 'M3 8V5h14', 'M16 12h5v4h-5Z'],
  dashboard: ['M3 3h7v7H3Z', 'M14 3h7v4h-7Z', 'M14 11h7v10h-7Z', 'M3 14h7v7H3Z'],
  search: ['M21 21l-4.3-4.3', 'M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16Z'],
  command: ['M18 9a3 3 0 1 0-3-3v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3Z'],
  bell: ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
  chevron: ['m9 18 6-6-6-6'],
  menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
  user: ['M20 21a8 8 0 0 0-16 0', 'M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z'],
  logout: ['M10 17l5-5-5-5', 'M15 12H3', 'M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4'],
  panel: ['M3 3h18v18H3Z', 'M9 3v18', 'm15 9-3 3 3 3'],
  more: ['M5 12h.01', 'M12 12h.01', 'M19 12h.01'],
  'arrow-left': ['M19 12H5', 'm12 19-7-7 7-7'],
  download: ['M12 3v12', 'm7 10 5 5 5-5', 'M5 21h14'],
  pin: ['M12 17v5', 'M5 3h14l-2 6 3 3H4l3-3Z'],
  plus: ['M12 5v14', 'M5 12h14'],
  eye: ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
}

const selected = computed(() => paths[props.name] ?? paths.list)
</script>

<template>
  <svg class="ui-icon" :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path v-for="path in selected" :key="path" :d="path" />
  </svg>
</template>

<style scoped>
.ui-icon{display:block;flex:0 0 auto}
</style>
