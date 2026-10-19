<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { api, downloadFile } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import { apiError } from '../utils/format'
import { i18n } from '../stores/i18n'

interface AnalysisResult {
  analysis_id: string
  summary: { rows_in_file: number; rows_after_filter: number; rows_exported: number; context_columns: string[]; product_count: number }
  sheet_names: string[]
  preview: Array<Array<string | number | null>>
}

interface OllamaModelsResponse {
  data: string[]
  ollama: { available: boolean; message: string }
}

interface AnalysisJob {
  analysis_id: string
  status: 'queued' | 'processing' | 'completed' | 'failed' | 'cancelled'
  progress: number
  message: string
  error?: string
  result?: AnalysisResult
}

const defaultProduct = 'เครื่องมือแพทย์ อุปกรณ์ทางการแพทย์ วัสดุสิ้นเปลืองทางการแพทย์ และระบบสนับสนุนงานโรงพยาบาล'
const models = ref<string[]>([]); const file = ref<File | null>(null); const result = ref<AnalysisResult | null>(null); const selectedSheet = ref(''); const previewLoading = ref(false); const running = ref(false); const elapsed = ref(0); const error = ref(''); const message = ref('')
const ollamaAvailable = ref(false); const ollamaMessage = ref('กำลังตรวจสอบ Ollama…'); const modelsLoading = ref(false)
const jobProgress = ref(0); const jobMessage = ref(''); const activeAnalysisId = ref('')
const form = reactive({ product_desc: defaultProduct, score_threshold: 0.2, max_ai_rows: 8, use_ollama: false, ollama_model: 'scb10x/llama3.1-typhoon2-8b-instruct:latest', ollama_timeout: 180 })
let elapsedTimer: number | undefined
let pollTimer: number | undefined

function pickFile(event: Event) { file.value = (event.target as HTMLInputElement).files?.[0] || null }
async function loadModels() {
  modelsLoading.value = true
  try {
    const response = await api.get<OllamaModelsResponse>('/api/v1/budget/models')
    models.value = response.data.data
    ollamaAvailable.value = response.data.ollama.available
    ollamaMessage.value = response.data.ollama.message
    if (!ollamaAvailable.value || !models.value.length) form.use_ollama = false
    if (models.value.length && !models.value.includes(form.ollama_model)) form.ollama_model = models.value[0]
  } catch (cause) {
    models.value = []; ollamaAvailable.value = false; form.use_ollama = false
    ollamaMessage.value = apiError(cause, 'ตรวจสอบ Ollama ไม่สำเร็จ แต่ยังใช้ Vector Search ได้')
  } finally { modelsLoading.value = false }
}
async function analyze() {
  if (!file.value) { error.value = 'กรุณาเลือกไฟล์ Excel ก่อนเริ่มวิเคราะห์'; return }
  running.value = true; elapsed.value = 0; jobProgress.value = 0; jobMessage.value = 'กำลังอัปโหลดไฟล์ Excel…'; error.value = ''; message.value = ''; result.value = null
  startElapsedTimer()
  try {
    const data = new FormData(); data.append('file', file.value); Object.entries(form).forEach(([key, value]) => data.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value)))
    const response = await api.post<{ data: AnalysisJob }>('/api/v1/budget/analyze', data)
    activeAnalysisId.value = response.data.data.analysis_id
    applyJobStatus(response.data.data)
    schedulePoll()
  } catch (cause) { finishJob(); error.value = apiError(cause) }
}
function startElapsedTimer() {
  if (elapsedTimer) window.clearInterval(elapsedTimer)
  elapsedTimer = window.setInterval(() => elapsed.value++, 1000)
}
function finishJob() {
  running.value = false
  if (elapsedTimer) window.clearInterval(elapsedTimer)
  if (pollTimer) window.clearTimeout(pollTimer)
  elapsedTimer = undefined; pollTimer = undefined
}
function applyJobStatus(job: AnalysisJob) {
  jobProgress.value = Math.max(0, Math.min(100, Number(job.progress || 0)))
  jobMessage.value = job.message || 'กำลังวิเคราะห์…'
  if (job.status === 'completed' && job.result) {
    result.value = { ...job.result, analysis_id: job.analysis_id }; selectedSheet.value = result.value.sheet_names[0] || ''
    message.value = 'วิเคราะห์เสร็จแล้ว สามารถตรวจตัวอย่างและดาวน์โหลด Excel ได้'
    finishJob()
  } else if (job.status === 'failed' || job.status === 'cancelled') {
    error.value = job.error || job.message || 'วิเคราะห์ไม่สำเร็จ'
    finishJob()
  }
}
async function pollJob() {
  if (!activeAnalysisId.value || !running.value) return
  try {
    const response = await api.get<{ data: AnalysisJob }>(`/api/v1/budget/${activeAnalysisId.value}/status`)
    applyJobStatus(response.data.data)
    if (running.value) schedulePoll()
  } catch (cause) {
    error.value = apiError(cause, 'อ่านสถานะการวิเคราะห์ไม่สำเร็จ กำลังลองใหม่…')
    if (running.value) schedulePoll(3000)
  }
}
function schedulePoll(delay = 1200) {
  if (pollTimer) window.clearTimeout(pollTimer)
  pollTimer = window.setTimeout(pollJob, delay)
}
async function clear() { try { await api.delete('/api/v1/budget'); result.value = null; file.value = null; jobProgress.value = 0; jobMessage.value = ''; message.value = 'เคลียร์ไฟล์ผลลัพธ์แล้ว' } catch (cause) { error.value = apiError(cause) } }
async function loadPreview() {
  if (!result.value || !selectedSheet.value) return
  previewLoading.value = true; error.value = ''
  try {
    const response = await api.get<{ data: { sheet: string; preview: AnalysisResult['preview'] } }>(`/api/v1/budget/${result.value.analysis_id}/preview`, { params: { sheet: selectedSheet.value } })
    result.value.preview = response.data.data.preview
  } catch (cause) { error.value = apiError(cause) }
  finally { previewLoading.value = false }
}
onMounted(() => { localStorage.removeItem('valor-budget-active-analysis'); void loadModels() })
onUnmounted(finishJob)
</script>

<template><AppShell title="วิเคราะห์ Book แดง / งบประมาณรัฐบาล" subtitle="วิเคราะห์ Excel ด้วย Python Vector Search และ Ollama แบบ Optional">
  <section class="content-card"><div class="section-heading"><div><p class="eyebrow eyebrow--red">Budget matching</p><h2>ตั้งค่าการวิเคราะห์</h2></div><StatusPill :status="ollamaAvailable ? 'Ollama พร้อมใช้งาน' : 'Vector Search พร้อมใช้งาน'" /></div><form class="form-grid" @submit.prevent="analyze">
    <label class="span-2"><span>คำอธิบายสินค้า/บริการของบริษัท *</span><textarea v-model="form.product_desc" required></textarea><small>ใส่หลายรายการได้ โดยแยก 1 รายการต่อ 1 บรรทัด</small></label>
    <label><span>คะแนนความเหมือนขั้นต่ำ: {{ Number(form.score_threshold).toFixed(2) }}</span><input v-model.number="form.score_threshold" type="range" min="0" max="0.9" step="0.05"></label><label><span>Top N ส่งให้ Ollama</span><input v-model.number="form.max_ai_rows" type="number" min="1" max="100"></label>
    <div class="span-2 ollama-health" :class="ollamaAvailable ? 'ollama-health--success' : 'ollama-health--warning'"><span>{{ ollamaMessage }}</span><button class="secondary-button" type="button" :disabled="modelsLoading || running" @click="loadModels">{{ modelsLoading ? 'กำลังตรวจ…' : 'ตรวจสอบอีกครั้ง' }}</button></div>
    <label class="check-field span-2"><input v-model="form.use_ollama" type="checkbox" :disabled="!ollamaAvailable || !models.length"> ให้ Ollama วิเคราะห์เหตุผลเพิ่มเติม (ปิดไว้จะเร็วกว่า)</label>
    <template v-if="form.use_ollama"><label><span>โมเดล Ollama</span><select v-if="models.length" v-model="form.ollama_model"><option v-for="model in models" :key="model">{{ model }}</option></select><input v-else v-model="form.ollama_model"></label><label><span>Timeout ต่อรายการ (วินาที)</span><input v-model.number="form.ollama_timeout" type="number" min="30" max="600" step="30"></label></template>
    <label class="span-2"><span>ไฟล์ Book แดง / งบประมาณ (.xlsx) *</span><input type="file" accept=".xlsx" required @change="pickFile"></label>
    <div class="span-2 actions"><button class="primary-button" :disabled="running">{{ running ? `กำลังวิเคราะห์… ${elapsed} วินาที` : 'เริ่มวิเคราะห์' }}</button><button type="button" class="secondary-button" :disabled="running" @click="clear">เคลียร์ผลลัพธ์เดิม</button></div>
    <div v-if="running" class="span-2 budget-progress" role="status" aria-live="polite"><div class="budget-progress__meta"><strong>{{ jobProgress }}%</strong><span>{{ jobMessage }}</span></div><div class="budget-progress__track"><div class="budget-progress__bar" :style="{ width: `${jobProgress}%` }"></div></div><small>ใช้เวลาแล้ว {{ i18n.formatNumber(elapsed) }} วินาที — สามารถเปิดหน้าอื่นได้ ระบบจะทำงานต่อเบื้องหลัง</small></div>
  </form></section>
  <p v-if="error" class="form-error section-gap-sm">{{ error }}</p><p v-if="message" class="success-message section-gap-sm">{{ message }}</p>
  <template v-if="result"><div class="metrics-grid metrics-grid--three section-gap"><article class="metric-card"><span>แถวในไฟล์</span><strong>{{ i18n.formatNumber(result.summary.rows_in_file) }}</strong></article><article class="metric-card"><span>แถวที่นำไปวิเคราะห์</span><strong>{{ i18n.formatNumber(result.summary.rows_after_filter) }}</strong></article><article class="metric-card metric-card--primary"><span>ผลลัพธ์ส่งออก</span><strong>{{ i18n.formatNumber(result.summary.rows_exported) }}</strong></article></div>
    <section class="content-card"><div class="section-heading"><div><h2>ตัวอย่างผลลัพธ์</h2><p class="muted">Context: {{ result.summary.context_columns.join(', ') }}</p><label><span>เลือก Sheet ที่ต้องการดู</span><select v-model="selectedSheet" :disabled="previewLoading" @change="loadPreview"><option v-for="sheet in result.sheet_names" :key="sheet">{{ sheet }}</option></select></label></div><button class="primary-button" @click="downloadFile(`/api/v1/budget/${result.analysis_id}/download`, 'สรุปงบประมาณรัฐบาลที่เข้ากับสินค้า.xlsx')">ดาวน์โหลด Excel ผลลัพธ์</button></div><div v-if="previewLoading" class="loading-state">กำลังโหลดตัวอย่าง Sheet…</div><div v-else class="table-wrap"><table><tbody><tr v-for="(row,rowIndex) in result.preview" :key="rowIndex"><component :is="rowIndex===0?'th':'td'" v-for="(cell,colIndex) in row" :key="colIndex">{{ cell ?? '' }}</component></tr></tbody></table></div></section>
  </template>
</AppShell></template>
