<script setup lang="ts">
import { computed } from 'vue'
import { statusTone } from '../utils/status'
import { i18n } from '../stores/i18n'

const props = withDefaults(defineProps<{
  status?: string | null
}>(), {
  status: null,
})

const displayStatus = computed(() => String(props.status || '-').trim() || '-')
const localizedStatus = computed(() => i18n.status(displayStatus.value))
const tone = computed(() => statusTone(props.status))
</script>

<template>
  <span
    class="status-pill"
    :class="`status-pill--${tone}`"
    :title="`${i18n.isEnglish.value ? 'Status' : 'สถานะ'}: ${localizedStatus}`"
    :aria-label="`${i18n.isEnglish.value ? 'Status' : 'สถานะ'} ${localizedStatus}`"
  >
    <i aria-hidden="true"></i>
    {{ localizedStatus }}
  </span>
</template>
