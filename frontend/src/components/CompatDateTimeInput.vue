<script setup lang="ts">
import { computed, reactive, watch } from 'vue'

type InputMode = 'date' | 'month' | 'time' | 'datetime-local'

const props = withDefaults(defineProps<{
  modelValue?: string | number | null
  mode?: InputMode
  min?: string
  max?: string
  required?: boolean
  disabled?: boolean
  ariaLabel?: string
}>(), {
  modelValue: '', mode: 'date', min: '', max: '', required: false, disabled: false, ariaLabel: '',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  change: [value: string]
}>()

function supportsInputType(type: InputMode): boolean {
  if (typeof document === 'undefined') return true
  const input = document.createElement('input')
  input.setAttribute('type', type)
  return input.type === type
}

const nativeSupported = supportsInputType(props.mode)
const currentYear = new Date().getFullYear()
const value = computed(() => String(props.modelValue || ''))
function splitValue(rawValue: string) {
  const datePart = rawValue.split('T')[0] || ''
  const timePart = props.mode === 'time' ? rawValue : (rawValue.split('T')[1] || '')
  const [year = '', month = '', day = ''] = datePart.split('-')
  const [hour = '', minute = ''] = timePart.split(':')
  return { year, month, day, hour, minute }
}

const parts = reactive(splitValue(value.value))
watch(value, (nextValue) => Object.assign(parts, splitValue(nextValue)))
const years = computed(() => {
  const values = new Set<number>()
  for (let year = currentYear - 20; year <= currentYear + 20; year++) values.add(year)
  const selected = Number(parts.year)
  if (selected) values.add(selected)
  return [...values].sort((a, b) => a - b)
})
const months = Array.from({ length: 12 }, (_, index) => String(index + 1).padStart(2, '0'))
const hours = Array.from({ length: 24 }, (_, index) => String(index).padStart(2, '0'))
const minutes = Array.from({ length: 60 }, (_, index) => String(index).padStart(2, '0'))
const days = computed(() => {
  const year = Number(parts.year) || currentYear
  const month = Number(parts.month) || 1
  return Array.from({ length: new Date(year, month, 0).getDate() }, (_, index) => String(index + 1).padStart(2, '0'))
})

function commit(next: Partial<{ year: string; month: string; day: string; hour: string; minute: string }>) {
  Object.assign(parts, next)
  if (parts.year && parts.month && parts.day) {
    const lastDay = new Date(Number(parts.year), Number(parts.month), 0).getDate()
    if (Number(parts.day) > lastDay) parts.day = String(lastDay).padStart(2, '0')
  }

  let result = ''
  if (props.mode === 'month' && parts.year && parts.month) result = `${parts.year}-${parts.month}`
  if (props.mode === 'date' && parts.year && parts.month && parts.day) result = `${parts.year}-${parts.month}-${parts.day}`
  if (props.mode === 'time' && parts.hour && parts.minute) result = `${parts.hour}:${parts.minute}`
  if (props.mode === 'datetime-local' && parts.year && parts.month && parts.day && parts.hour && parts.minute) {
    result = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
  }

  const minValue = props.min.slice(0, result.length)
  const maxValue = props.max.slice(0, result.length)
  if (result && minValue && result < minValue) result = minValue
  if (result && maxValue && result > maxValue) result = maxValue

  emit('update:modelValue', result)
  emit('change', result)
}

function clearValue() {
  Object.assign(parts, splitValue(''))
  emit('update:modelValue', '')
  emit('change', '')
}

function nativeInput(event: Event) {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
}

function nativeChange(event: Event) {
  emit('change', (event.target as HTMLInputElement).value)
}
</script>

<template>
  <input
    v-if="nativeSupported"
    class="compat-native-input"
    :type="mode"
    :value="value"
    :min="min || undefined"
    :max="max || undefined"
    :required="required"
    :disabled="disabled"
    :aria-label="ariaLabel || undefined"
    @input="nativeInput"
    @change="nativeChange"
  >
  <span v-else class="compat-date-fallback" :class="`compat-date-fallback--${mode}`" role="group" :aria-label="ariaLabel || undefined">
    <template v-if="mode !== 'time'">
      <select :value="parts.year" :required="required" :disabled="disabled" aria-label="ปี" @change="commit({ year: ($event.target as HTMLSelectElement).value })">
        <option value="">ปี</option><option v-for="year in years" :key="year" :value="String(year)">{{ year }}</option>
      </select>
      <select :value="parts.month" :required="required" :disabled="disabled" aria-label="เดือน" @change="commit({ month: ($event.target as HTMLSelectElement).value })">
        <option value="">เดือน</option><option v-for="month in months" :key="month" :value="month">{{ month }}</option>
      </select>
      <select v-if="mode !== 'month'" :value="parts.day" :required="required" :disabled="disabled" aria-label="วัน" @change="commit({ day: ($event.target as HTMLSelectElement).value })">
        <option value="">วัน</option><option v-for="day in days" :key="day" :value="day">{{ day }}</option>
      </select>
    </template>
    <template v-if="mode === 'time' || mode === 'datetime-local'">
      <select :value="parts.hour" :required="required" :disabled="disabled" aria-label="ชั่วโมง" @change="commit({ hour: ($event.target as HTMLSelectElement).value })">
        <option value="">ชม.</option><option v-for="hour in hours" :key="hour" :value="hour">{{ hour }}</option>
      </select>
      <span aria-hidden="true">:</span>
      <select :value="parts.minute" :required="required" :disabled="disabled" aria-label="นาที" @change="commit({ minute: ($event.target as HTMLSelectElement).value })">
        <option value="">นาที</option><option v-for="minute in minutes" :key="minute" :value="minute">{{ minute }}</option>
      </select>
    </template>
    <button v-if="value && !required && !disabled" type="button" class="compat-clear" aria-label="ล้างค่า" @click="clearValue">×</button>
  </span>
</template>

<style scoped>
.compat-native-input{width:100%;min-width:0}
.compat-date-fallback{display:flex;align-items:center;gap:5px;width:100%;min-width:0}
.compat-date-fallback select{min-width:0;flex:1;padding:8px 6px;border:1px solid #d5d9e0;border-radius:8px;background:#fff;color:#172033;font:inherit}
.compat-date-fallback--datetime-local{flex-wrap:wrap}
.compat-date-fallback--datetime-local select{flex-basis:64px}
.compat-clear{flex:0 0 28px;width:28px;height:28px;padding:0;border:1px solid #d5d9e0;border-radius:50%;background:#fff;color:#596273;font:inherit;line-height:1;cursor:pointer}
.compat-clear:hover{background:#f3f5f8;color:#172033}
</style>
