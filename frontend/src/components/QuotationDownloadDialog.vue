<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, downloadFile } from '../api/client'
import { apiError } from '../utils/format'

type TemplateOption = { id: string; name: string; description: string; available: boolean }
const props = defineProps<{
  quotationId?: number
  billId?: number
  billIds?: number[]
  mode?: 'original' | 'copy' | 'revised'
}>()
const emit = defineEmits<{ close: [] }>()
const router = useRouter()
const options = ref<TemplateOption[]>([])
const selected = ref('standard')
const loading = ref(true)
const downloading = ref(false)
const error = ref('')
const dialog = ref<HTMLElement | null>(null)
let previousFocus: HTMLElement | null = null

function keydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && !downloading.value) emit('close')
  if (event.key !== 'Tab' || !dialog.value) return
  const focusable = [...dialog.value.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled)')]
  if (!focusable.length) return
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
  if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}

async function download() {
  if (!options.value.some(option => option.id === selected.value && option.available)) return
  downloading.value = true
  error.value = ''
  try {
    if (props.billIds?.length) {
      await downloadFile('/api/v1/bills/export-quotations.pdf', undefined, 'post', {
        bill_ids: props.billIds,
        template: selected.value,
      }, true)
    } else if (props.billId || props.quotationId) {
      const quotationId = props.billId
        ? (await api.post<{ data: { qt_id: number; qt_no: string } }>(`/api/v1/bills/${props.billId}/quotation-from-po`)).data.data.qt_id
        : props.quotationId
      emit('close')
      await router.push({
        name: 'quotation-editor',
        params: { quotationId },
        query: { template: selected.value, mode: props.mode || 'original' },
      })
      return
    }
    emit('close')
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : apiError(cause, 'ดาวน์โหลดใบเสนอราคาไม่สำเร็จ')
  } finally {
    downloading.value = false
  }
}

onMounted(async () => {
  previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.addEventListener('keydown', keydown)
  await nextTick()
  dialog.value?.focus()
  try {
    const response = await api.get<{ data: TemplateOption[] }>('/api/v1/quotation-templates')
    options.value = response.data.data
    selected.value = options.value.find(option => option.available)?.id || ''
  } catch (cause) {
    error.value = apiError(cause, 'โหลดรูปแบบใบเสนอราคาไม่สำเร็จ')
  } finally {
    loading.value = false
  }
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', keydown)
  previousFocus?.focus()
})
</script>

<template>
  <Teleport to="body">
    <div class="quotation-dialog-overlay" @click.self="!downloading && emit('close')">
      <section ref="dialog" class="quotation-dialog" role="dialog" aria-modal="true" aria-labelledby="quotation-dialog-title" tabindex="-1">
        <div class="quotation-dialog-heading">
          <div><p class="eyebrow">QUOTATION TEMPLATE</p><h2 id="quotation-dialog-title">เลือกรูปแบบใบเสนอราคา</h2></div>
          <button type="button" class="secondary-button" aria-label="ปิด" :disabled="downloading" @click="emit('close')">×</button>
        </div>
        <p class="muted">เลือกรูปแบบตามเอกสารของโรงพยาบาล จากนั้นระบบจะเปิดเอกสารให้คลิกแก้ไขและตรวจ PDF ก่อนยืนยัน</p>
        <p v-if="billId" class="quotation-po-note">เมื่อกดดาวน์โหลด ระบบจะออกเลขใบเสนอราคาใหม่ลงวันที่ปัจจุบันและผูกกับรายการ PO เดิม โดยไม่สร้างบิลซ้ำ</p>
        <p v-if="loading">กำลังโหลดรูปแบบ…</p>
        <p v-if="error" class="form-error" role="alert">{{ error }}</p>
        <div v-for="option in options" :key="option.id" class="quotation-template-option" :class="{ disabled: !option.available }">
          <label><input v-model="selected" type="radio" name="quotation-template" :value="option.id" :disabled="!option.available"><span><strong>{{ option.name }}</strong><small>{{ option.description }}</small></span></label>
          <span v-if="!option.available" class="muted">ยังไม่พร้อมใช้</span>
        </div>
        <div class="quotation-dialog-actions">
          <button type="button" class="secondary-button" :disabled="downloading" @click="emit('close')">ยกเลิก</button>
          <button type="button" class="primary-button" :disabled="loading || downloading || !selected" @click="download">{{ downloading ? 'กำลังเปิด…' : (billIds?.length ? 'ดาวน์โหลด PDF' : 'แก้ไขและตรวจสอบ') }}</button>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.quotation-dialog-overlay{position:fixed;inset:0;z-index:2000;display:grid;place-items:center;padding:20px;background:rgba(16,24,40,.58)}
.quotation-dialog{width:min(100%,560px);max-height:90vh;overflow:auto;padding:24px;border-radius:16px;background:#fff;box-shadow:0 22px 70px rgba(0,0,0,.25)}
.quotation-dialog-heading,.quotation-dialog-actions{display:flex;align-items:center;justify-content:space-between;gap:12px}
.quotation-dialog-heading h2{margin:2px 0 0}.quotation-dialog-heading button{font-size:24px;line-height:1}
.quotation-template-option{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px;margin-top:10px;border:1px solid #d9dee7;border-radius:10px}
.quotation-template-option.disabled{opacity:.65}.quotation-template-option label{display:flex;align-items:center;gap:12px;cursor:pointer}.quotation-template-option small{display:block;margin-top:3px;color:#667085}
.quotation-dialog-actions{justify-content:flex-end;margin-top:22px}
.quotation-po-note{margin:12px 0;padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;font-size:13px;line-height:1.5}
</style>
