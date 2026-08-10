<script setup lang="ts">
import { computed } from 'vue'
import { statusTone } from '../utils/status'

const props = withDefaults(defineProps<{
  status?: string | null
}>(), {
  status: null,
})

const displayStatus = computed(() => String(props.status || '-').trim() || '-')
const tone = computed(() => statusTone(props.status))
</script>

<template>
  <span
    class="status-pill"
    :class="`status-pill--${tone}`"
    :title="`สถานะ: ${displayStatus}`"
    :aria-label="`สถานะ ${displayStatus}`"
  >
    <i aria-hidden="true"></i>
    {{ displayStatus }}
  </span>
</template>
