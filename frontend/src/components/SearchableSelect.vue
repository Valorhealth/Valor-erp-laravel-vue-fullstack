<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'

type Choice = { value: string | number; label: string }
const props = withDefaults(defineProps<{
  modelValue: string | number | null | undefined
  options: Choice[]
  placeholder?: string
  required?: boolean
  disabled?: boolean
}>(), { placeholder: 'พิมพ์เพื่อค้นหา', required: false, disabled: false })
const emit = defineEmits<{ 'update:modelValue': [value: string | number | null]; change: []; search: [query: string] }>()
const input = ref<HTMLInputElement | null>(null)
const open = ref(false)
const query = ref('')
const activeIndex = ref(0)
const listboxId = `searchable-select-${useId()}`
const listStyle = ref<Record<string, string>>({})
const selected = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue ?? '')))
const visible = computed(() => {
  const needle = query.value.trim().toLocaleLowerCase()
  return props.options.filter((option) => `${option.label} ${option.value}`.toLocaleLowerCase().includes(needle)).slice(0, 80)
})
watch([() => props.modelValue, selected], () => { if (!open.value) query.value = selected.value?.label || '' }, { immediate: true })
watch(visible, (options) => { activeIndex.value = Math.min(activeIndex.value, Math.max(options.length - 1, 0)) })

function updatePosition() {
  if (!open.value || !input.value) return

  const rect = input.value.getBoundingClientRect()
  const viewportGap = 8
  const preferredHeight = 240
  const spaceBelow = window.innerHeight - rect.bottom - viewportGap
  const spaceAbove = rect.top - viewportGap
  const showAbove = spaceBelow < 160 && spaceAbove > spaceBelow
  const availableHeight = Math.max(96, Math.min(preferredHeight, showAbove ? spaceAbove : spaceBelow))

  listStyle.value = {
    left: `${Math.max(viewportGap, Math.min(rect.left, window.innerWidth - rect.width - viewportGap))}px`,
    top: showAbove ? 'auto' : `${rect.bottom + 4}px`,
    bottom: showAbove ? `${window.innerHeight - rect.top + 4}px` : 'auto',
    width: `${Math.min(rect.width, window.innerWidth - viewportGap * 2)}px`,
    maxHeight: `${availableHeight}px`,
  }
}

function show() {
  open.value = true
  query.value = ''
  activeIndex.value = 0
  emit('search', '')
  void nextTick(updatePosition)
}

function handleInput() {
  open.value = true
  activeIndex.value = 0
  emit('search', query.value)
  updatePosition()
}

function choose(option: Choice) {
  emit('update:modelValue', option.value)
  emit('change')
  query.value = option.label
  open.value = false
}
function close() {
  window.setTimeout(() => { open.value = false; query.value = selected.value?.label || '' }, 120)
}

function reposition() {
  if (open.value) updatePosition()
}

onMounted(() => {
  window.addEventListener('resize', reposition)
  window.addEventListener('scroll', reposition, true)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', reposition)
  window.removeEventListener('scroll', reposition, true)
})
</script>

<template>
  <div class="searchable-select">
    <input
      ref="input"
      v-model="query"
      type="text"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open"
      :aria-controls="listboxId"
      aria-autocomplete="list"
      :placeholder="placeholder"
      :required="required && !modelValue"
      :disabled="disabled"
      @focus="show"
      @input="handleInput"
      @keydown.esc="open = false; query = selected?.label || ''"
      @keydown.down.prevent="activeIndex = Math.min(activeIndex + 1, visible.length - 1)"
      @keydown.up.prevent="activeIndex = Math.max(activeIndex - 1, 0)"
      @keydown.enter.prevent="visible[activeIndex] && choose(visible[activeIndex])"
      @blur="close"
    >
    <Teleport to="body">
      <div v-if="open && !disabled" :id="listboxId" class="searchable-select__list" :style="listStyle" role="listbox">
        <button v-for="(option, index) in visible" :key="option.value" type="button" role="option" :aria-selected="String(option.value) === String(modelValue)" :class="{ active: index === activeIndex }" @pointerdown.prevent="choose(option)">{{ option.label }}</button>
        <span v-if="!visible.length">ไม่พบตัวเลือก</span>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.searchable-select{position:relative;min-width:0;width:100%}.searchable-select>input{width:100%}.searchable-select__list{position:fixed;z-index:5000;overflow:auto;overscroll-behavior:contain;border:1px solid #b8c0cd;border-radius:8px;background:#fff;box-shadow:0 12px 24px #0002}.searchable-select__list button{display:block;width:100%;padding:8px 10px;border:0;background:#fff;color:#172033;text-align:left;white-space:normal;cursor:pointer}.searchable-select__list button:hover,.searchable-select__list button.active,.searchable-select__list button[aria-selected=true]{background:#eef4ff}.searchable-select__list span{display:block;padding:10px;color:#64748b}
</style>
