import { computed, ref } from 'vue'

const pending = ref(0)
const visible = ref(false)
let timer: number | null = null

function begin(): void {
  pending.value += 1
  if (pending.value === 1) {
    timer = window.setTimeout(() => { visible.value = pending.value > 0 }, 450)
  }
}

function end(): void {
  pending.value = Math.max(0, pending.value - 1)
  if (pending.value === 0) {
    if (timer !== null) window.clearTimeout(timer)
    timer = null
    visible.value = false
  }
}

export const networkActivity = { begin, end, visible: computed(() => visible.value) }
