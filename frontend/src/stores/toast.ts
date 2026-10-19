import { ref } from 'vue'

export type ToastTone = 'success' | 'error' | 'info'
export type ToastMessage = { id: number; text: string; tone: ToastTone }
const messages = ref<ToastMessage[]>([])
let nextId = 1

function show(text: string, tone: ToastTone = 'info', duration = 3800) {
  const item = { id: nextId++, text, tone }
  messages.value.push(item)
  window.setTimeout(() => dismiss(item.id), duration)
}
function dismiss(id: number) { messages.value = messages.value.filter((item) => item.id !== id) }
export const toast = { messages, show, dismiss }
