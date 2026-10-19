<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import { auth } from '../stores/auth'
import { i18n } from '../stores/i18n'
import type { User, UserPreferences } from '../types/api'
import { apiError } from '../utils/format'

const defaults: UserPreferences = {
  language: 'th', document_language: 'th', date_format: 'DD/MM/YYYY', calendar_system: 'buddhist',
  timezone: 'Asia/Bangkok', time_format: '24h', number_format: 'en-US',
  default_path: '/notice-board', table_density: 'comfortable', rows_per_page: 50,
  sidebar_collapsed: false, font_scale: 'normal', high_contrast: false, reduce_motion: false,
  in_app_notifications: true, email_notifications: true,
  notification_categories: { billing: true, credit: true, booking: true, tasks: true, call_sale: true },
}
const form = reactive<UserPreferences>({ ...defaults })
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' })
const profileForm = reactive({
  username: '', first_name: '', last_name: '', email: '', phone: '', job_title: '',
  representative_address: '', national_id: '', national_id_issued_at: '', national_id_expires_at: '', current_password: '',
})
const savingProfile = ref(false)
const signatureFile = ref<File | null>(null)
const signaturePassword = ref('')
const signaturePreview = ref('')
const savingSignature = ref(false)
const loading = ref(true)
const saving = ref(false)
const changingPassword = ref(false)
const error = ref('')
const message = ref('')
const english = computed(() => form.language === 'en')
const accountSensitiveChanged = computed(() => profileForm.username !== (auth.user.value?.username || '')
  || profileForm.email.toLocaleLowerCase() !== (auth.user.value?.email || '').toLocaleLowerCase())
const authorizationSensitiveChanged = computed(() => profileForm.national_id !== (auth.user.value?.national_id || '')
  || profileForm.representative_address !== (auth.user.value?.representative_address || ''))
const regionalPreview = computed(() => `${i18n.formatDateTime(new Date().toISOString())} · ${i18n.formatNumber(1234.56, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`)
const pageOptions = computed(() => [
  { path: '/notice-board', label: english.value ? 'Notice Board' : 'บอร์ดประกาศ' },
  ...(auth.user.value?.menu ?? []).map(item => ({ path: item.path, label: i18n.menu(item.key, item.label) })),
])
const ui = (thai: string, en: string) => english.value ? en : thai

function applyPreview() {
  i18n.applyPreferences(form)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<{ data: UserPreferences }>('/api/v1/settings')
    Object.assign(form, defaults, response.data.data)
    Object.assign(profileForm, {
      username: auth.user.value?.username || '', first_name: auth.user.value?.first_name || '',
      last_name: auth.user.value?.last_name || '', email: auth.user.value?.email || '',
      phone: auth.user.value?.phone || '', job_title: auth.user.value?.job_title || '',
      representative_address: auth.user.value?.representative_address || '', national_id: auth.user.value?.national_id || '',
      national_id_issued_at: auth.user.value?.national_id_issued_at || '', national_id_expires_at: auth.user.value?.national_id_expires_at || '',
      current_password: '',
    })
    form.notification_categories = { ...defaults.notification_categories, ...response.data.data.notification_categories }
    applyPreview()
    await loadSignature()
  } catch (cause) {
    error.value = apiError(cause, 'โหลดการตั้งค่าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function loadSignature() {
  if (signaturePreview.value) URL.revokeObjectURL(signaturePreview.value)
  signaturePreview.value = ''
  try {
    const response = await api.get<Blob>('/api/v1/settings/signature', { responseType: 'blob' })
    signaturePreview.value = URL.createObjectURL(response.data)
  } catch { /* A new account may not have a signature yet. */ }
}

async function saveSignature() {
  if (!signatureFile.value || !signaturePassword.value) return
  savingSignature.value = true
  error.value = ''
  message.value = ''
  try {
    const formData = new FormData()
    formData.append('file', signatureFile.value)
    formData.append('current_password', signaturePassword.value)
    await api.post('/api/v1/settings/signature', formData)
    signatureFile.value = null
    signaturePassword.value = ''
    await loadSignature()
    message.value = ui('บันทึกลายเซ็นฉบับใหม่แล้ว ใบเสนอราคาที่จะยืนยันต่อจากนี้จะใช้ลายเซ็นนี้', 'Your new signature was saved for future documents.')
  } catch (cause) { error.value = apiError(cause, ui('บันทึกลายเซ็นไม่สำเร็จ', 'Unable to save signature.')) }
  finally { savingSignature.value = false }
}

async function save() {
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    const response = await api.put<{ data: User; message: string }>('/api/v1/settings', form)
    auth.replaceUser(response.data.data)
    applyPreview()
    message.value = ui('บันทึกการตั้งค่าเรียบร้อยแล้ว', 'Settings saved successfully.')
  } catch (cause) {
    error.value = apiError(cause, ui('บันทึกการตั้งค่าไม่สำเร็จ', 'Unable to save settings.'))
  } finally {
    saving.value = false
  }
}

async function changePassword() {
  if (passwordForm.password !== passwordForm.password_confirmation) {
    error.value = ui('รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน', 'The new passwords do not match.')
    return
  }
  changingPassword.value = true
  error.value = ''
  message.value = ''
  try {
    await api.put<{ message: string }>('/api/v1/settings/password', passwordForm)
    Object.assign(passwordForm, { current_password: '', password: '', password_confirmation: '' })
    message.value = ui('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว', 'Password changed successfully.')
  } catch (cause) {
    error.value = apiError(cause, ui('เปลี่ยนรหัสผ่านไม่สำเร็จ', 'Unable to change password.'))
  } finally {
    changingPassword.value = false
  }
}

async function saveProfile() {
  savingProfile.value = true
  error.value = ''
  message.value = ''
  try {
    const response = await api.put<{ data: User }>('/api/v1/settings/profile', profileForm)
    auth.replaceUser(response.data.data)
    profileForm.current_password = ''
    message.value = ui('บันทึกข้อมูลบัญชีเรียบร้อยแล้ว', 'Account information saved.')
  } catch (cause) {
    error.value = apiError(cause, ui('บันทึกข้อมูลบัญชีไม่สำเร็จ', 'Unable to save account information.'))
  } finally {
    savingProfile.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => { if (signaturePreview.value) URL.revokeObjectURL(signaturePreview.value) })
</script>

<template>
  <AppShell title="ตั้งค่าบัญชีและการแสดงผล" subtitle="กำหนดภาษา เอกสาร วันที่ ตาราง และการแจ้งเตือนสำหรับบัญชีนี้">
    <p v-if="error" class="form-error">{{ error }}</p>
    <p v-if="message" class="success-message">{{ message }}</p>
    <div v-if="loading" class="content-card loading-box">{{ ui('กำลังโหลดการตั้งค่า…', 'Loading settings…') }}</div>
    <div v-else class="settings-layout">
      <section class="content-card settings-section">
        <div class="section-heading"><div><p class="eyebrow">ACCOUNT</p><h2>{{ ui('ข้อมูลบัญชี', 'Account information') }}</h2></div><small>{{ ui('Role และแผนกยังคงจัดการโดยผู้ดูแลระบบ', 'Role and department remain administrator-managed.') }}</small></div>
        <div class="settings-grid profile-grid">
          <label><span>{{ ui('ชื่อ', 'First name') }}</span><input v-model.trim="profileForm.first_name" maxlength="255" autocomplete="given-name" required></label>
          <label><span>{{ ui('นามสกุล', 'Last name') }}</span><input v-model.trim="profileForm.last_name" maxlength="255" autocomplete="family-name" required></label>
          <label><span>{{ ui('ชื่อผู้ใช้', 'Username') }}</span><input v-model.trim="profileForm.username" minlength="3" maxlength="255" autocomplete="username" required><small>{{ ui('ใช้เข้าสู่ระบบและต้องไม่ซ้ำกับบัญชีอื่น', 'Used to sign in and must be unique.') }}</small></label>
          <label><span>Email</span><input v-model.trim="profileForm.email" type="email" maxlength="255" autocomplete="email" required></label>
          <label><span>{{ ui('เบอร์โทรศัพท์', 'Phone number') }}</span><input v-model.trim="profileForm.phone" type="tel" inputmode="tel" maxlength="30" placeholder="08X-XXX-XXXX"><small>{{ ui('ใบเสนอราคาใหม่จะดึงเบอร์นี้เป็นค่าเริ่มต้น', 'New quotations use this as their default phone number.') }}</small></label>
          <label v-if="accountSensitiveChanged"><span>{{ ui('รหัสผ่านปัจจุบัน', 'Current password') }}</span><input v-model="profileForm.current_password" type="password" autocomplete="current-password" maxlength="255" required><small>{{ ui('จำเป็นเมื่อเปลี่ยน Username หรือ Email', 'Required when changing username or email.') }}</small></label>
        </div>
        <div class="account-managed"><div><span>Role</span><strong>{{ auth.user.value?.role || '-' }}</strong></div><div><span>{{ ui('แผนก', 'Department') }}</span><strong>{{ auth.user.value?.department || '-' }}</strong></div></div>
        <div class="profile-actions"><button class="secondary-button" type="button" :disabled="savingProfile || !profileForm.first_name || !profileForm.last_name || !profileForm.username || !profileForm.email || (accountSensitiveChanged && !profileForm.current_password)" @click="saveProfile">{{ savingProfile ? ui('กำลังบันทึก…', 'Saving…') : ui('บันทึกข้อมูลบัญชี', 'Save account information') }}</button></div>
      </section>

      <section class="content-card settings-section authorization-identity-section">
        <div class="section-heading"><div><p class="eyebrow">POWER OF ATTORNEY</p><h2>{{ ui('ข้อมูลผู้รับมอบอำนาจ', 'Attorney-in-fact information') }}</h2></div><small>{{ ui('ระบบจะดึงข้อมูลชุดนี้ไปกรอกในหนังสือมอบอำนาจอัตโนมัติ', 'These values automatically prefill powers of attorney.') }}</small></div>
        <div class="identity-notice">{{ ui('กรุณากรอกข้อมูลให้ตรงกับบัตรประชาชน ข้อมูลที่อยู่และเลขบัตรจะถูกเข้ารหัสในฐานข้อมูล', 'Enter the information exactly as shown on the national ID. Address and ID number are encrypted in the database.') }}</div>
        <div class="settings-grid profile-grid">
          <label><span>{{ ui('ตำแหน่งงานของผู้แทน', 'Representative job title') }}</span><input v-model.trim="profileForm.job_title" maxlength="255" autocomplete="organization-title" placeholder="เช่น ผู้แทนฝ่ายขาย"></label>
          <label><span>{{ ui('เลขบัตรประชาชน 13 หลัก', 'National ID (13 digits)') }}</span><input v-model="profileForm.national_id" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13" autocomplete="off" placeholder="1234567890123" @input="profileForm.national_id = profileForm.national_id.replace(/\D/g, '').slice(0, 13)"></label>
          <label class="profile-wide"><span>{{ ui('ที่อยู่ตามบัตรประชาชน', 'Address on national ID') }}</span><textarea v-model.trim="profileForm.representative_address" rows="3" maxlength="2000" autocomplete="street-address" placeholder="บ้านเลขที่ ถนน แขวง/ตำบล เขต/อำเภอ จังหวัด รหัสไปรษณีย์"></textarea></label>
          <label><span>{{ ui('วันที่ออกบัตร', 'ID issue date') }}</span><input v-model="profileForm.national_id_issued_at" type="date"></label>
          <label><span>{{ ui('วันหมดอายุบัตร', 'ID expiry date') }}</span><input v-model="profileForm.national_id_expires_at" type="date"></label>
          <label v-if="authorizationSensitiveChanged" class="profile-wide"><span>{{ ui('ยืนยันรหัสผ่านปัจจุบัน', 'Confirm current password') }}</span><input v-model="profileForm.current_password" type="password" autocomplete="current-password" maxlength="255" required><small>{{ ui('จำเป็นสำหรับการบันทึกที่อยู่หรือเลขบัตรประชาชน', 'Required to save an address or national ID change.') }}</small></label>
        </div>
        <div class="profile-actions"><button class="primary-button" type="button" :disabled="savingProfile || (authorizationSensitiveChanged && !profileForm.current_password)" @click="saveProfile">{{ savingProfile ? ui('กำลังบันทึก…', 'Saving…') : ui('บันทึกข้อมูลผู้รับมอบอำนาจ', 'Save attorney-in-fact information') }}</button></div>
      </section>

      <section class="content-card settings-section">
        <div class="section-heading"><div><p class="eyebrow">SIGNATURE</p><h2>{{ ui('ลายเซ็นประจำบัญชี', 'Account signature') }}</h2></div></div>
        <p class="muted">{{ ui('ใช้เฉพาะบัญชีที่ได้รับสิทธิ์ลงนามอนุมัติใบเสนอราคา หนังสือมอบอำนาจจะไม่ใช้ลายเซ็นอิเล็กทรอนิกส์', 'Used only by accounts authorized to approve quotations. Powers of attorney do not use electronic signatures.') }}</p>
        <img v-if="signaturePreview" :src="signaturePreview" class="settings-signature-preview" :alt="ui('ลายเซ็นปัจจุบัน', 'Current signature')">
        <div class="settings-grid"><label><span>{{ ui('ภาพลายเซ็น PNG', 'Signature PNG') }}</span><input type="file" accept="image/png,.png" @change="signatureFile = ($event.target as HTMLInputElement).files?.[0] || null"><small>{{ ui('ไฟล์ PNG ไม่เกิน 1 MB ขนาดไม่เกิน 2000 × 1000 พิกเซล', 'PNG up to 1 MB and 2000 × 1000 pixels.') }}</small></label><label><span>{{ ui('รหัสผ่านปัจจุบัน', 'Current password') }}</span><input v-model="signaturePassword" type="password" autocomplete="current-password" maxlength="255"></label></div>
        <div class="profile-actions"><button class="secondary-button" type="button" :disabled="savingSignature || !signatureFile || !signaturePassword" @click="saveSignature">{{ savingSignature ? ui('กำลังบันทึก…', 'Saving…') : ui('บันทึกลายเซ็น', 'Save signature') }}</button></div>
      </section>

      <section class="content-card settings-section">
        <div class="section-heading"><div><p class="eyebrow">LANGUAGE & REGION</p><h2>{{ ui('ภาษาและภูมิภาค', 'Language and region') }}</h2></div></div>
        <div class="settings-grid">
          <label><span>{{ ui('ภาษาของเว็บไซต์', 'Website language') }}</span><select v-model="form.language" @change="applyPreview"><option value="th">ไทย</option><option value="en">English</option></select><small>{{ ui('เปลี่ยนเมนูและส่วนติดต่อที่รองรับทันที', 'Changes supported navigation and interface text immediately.') }}</small></label>
          <div class="regional-preview"><span>{{ ui('ภาษาเอกสารทางการ', 'Official document language') }}</span><strong>{{ ui('ใช้ภาษาตาม Template ของโรงพยาบาล', 'Defined by each hospital template') }}</strong><small>{{ ui('การเปลี่ยนภาษาของเว็บไซต์จะไม่แก้ข้อความในเอกสารที่โรงพยาบาลกำหนด', 'Changing the website language does not alter hospital-mandated document wording.') }}</small></div>
          <label><span>{{ ui('รูปแบบวันที่', 'Date format') }}</span><select v-model="form.date_format" @change="applyPreview"><option>DD/MM/YYYY</option><option>MM/DD/YYYY</option><option>YYYY-MM-DD</option></select></label>
          <label><span>{{ ui('ระบบปี', 'Calendar year') }}</span><select v-model="form.calendar_system" @change="applyPreview"><option value="buddhist">{{ ui('พุทธศักราช (พ.ศ.)', 'Buddhist Era') }}</option><option value="gregorian">{{ ui('คริสต์ศักราช (ค.ศ.)', 'Gregorian') }}</option></select></label>
          <label><span>{{ ui('เขตเวลา', 'Time zone') }}</span><select v-model="form.timezone" @change="applyPreview"><option value="Asia/Bangkok">Asia/Bangkok (UTC+7)</option><option value="UTC">UTC</option></select></label>
          <label><span>{{ ui('รูปแบบเวลา', 'Time format') }}</span><select v-model="form.time_format" @change="applyPreview"><option value="24h">24 {{ ui('ชั่วโมง', 'hours') }}</option><option value="12h">12 {{ ui('ชั่วโมง (AM/PM)', 'hours (AM/PM)') }}</option></select></label>
          <label><span>{{ ui('รูปแบบตัวเลข', 'Number format') }}</span><select v-model="form.number_format" @change="applyPreview"><option value="en-US">1,234.56 — {{ ui('เลขอารบิก (มาตรฐาน)', 'International digits') }}</option><option value="th-TH-u-nu-thai">๑,๒๓๔.๕๖ — {{ ui('เลขไทย', 'Thai digits') }}</option><option value="de-DE">1.234,56 — {{ ui('จุดคั่นหลักพัน', 'European separators') }}</option></select><small>{{ ui('มีผลกับตัวเลขและจำนวนเงินบนหน้าจอ ไม่เปลี่ยนข้อมูลที่บันทึกในฐานข้อมูลหรือรูปแบบเอกสารทางการ', 'Affects on-screen numbers and amounts only; stored values and official document templates are unchanged.') }}</small></label>
          <div class="regional-preview"><span>{{ ui('ตัวอย่าง', 'Preview') }}</span><strong>{{ regionalPreview }}</strong></div>
        </div>
      </section>

      <section class="content-card settings-section">
        <div class="section-heading"><div><p class="eyebrow">WORKSPACE</p><h2>{{ ui('การแสดงผลและการนำทาง', 'Display and navigation') }}</h2></div></div>
        <div class="settings-grid">
          <label><span>{{ ui('หน้าแรกหลังเข้าสู่ระบบ', 'Default page after sign-in') }}</span><select v-model="form.default_path"><option v-for="page in pageOptions" :key="page.path" :value="page.path">{{ page.label }}</option></select></label>
          <label><span>{{ ui('ความหนาแน่นของตาราง', 'Table density') }}</span><select v-model="form.table_density" @change="applyPreview"><option value="comfortable">{{ ui('ปกติ', 'Comfortable') }}</option><option value="compact">{{ ui('กระชับ', 'Compact') }}</option></select></label>
          <label><span>{{ ui('จำนวนรายการต่อหน้า', 'Rows per page') }}</span><select v-model.number="form.rows_per_page"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label>
          <label><span>{{ ui('ขนาดตัวอักษร', 'Font size') }}</span><select v-model="form.font_scale" @change="applyPreview"><option value="small">{{ ui('เล็ก', 'Small') }}</option><option value="normal">{{ ui('ปกติ', 'Normal') }}</option><option value="large">{{ ui('ใหญ่', 'Large') }}</option></select></label>
          <label class="settings-toggle"><input v-model="form.sidebar_collapsed" type="checkbox"><span>{{ ui('เริ่มต้นโดยย่อ Sidebar บนหน้าจอคอมพิวเตอร์', 'Start with the sidebar collapsed on desktop') }}</span></label>
          <label class="settings-toggle"><input v-model="form.high_contrast" type="checkbox" @change="applyPreview"><span>{{ ui('เพิ่มความคมชัดของเส้นและข้อความ', 'Increase the contrast of borders and text') }}</span></label>
          <label class="settings-toggle"><input v-model="form.reduce_motion" type="checkbox" @change="applyPreview"><span>{{ ui('ลดภาพเคลื่อนไหว', 'Reduce motion') }}</span></label>
        </div>
      </section>

      <section class="content-card settings-section">
        <div class="section-heading"><div><p class="eyebrow">NOTIFICATIONS</p><h2>{{ ui('การแจ้งเตือน', 'Notifications') }}</h2></div></div>
        <label class="settings-toggle"><input v-model="form.in_app_notifications" type="checkbox"><span>{{ ui('รับการแจ้งเตือนภายในแอปที่ไอคอนกระดิ่ง', 'Receive in-app notifications in the notification bell.') }}</span></label>
        <label class="settings-toggle"><input v-model="form.email_notifications" type="checkbox"><span>{{ ui('รับอีเมลแจ้งเตือนงานและ Workflow ที่เกี่ยวข้องกับบัญชีนี้', 'Receive email notifications for assigned work and related workflows.') }}</span></label>
        <p class="muted">{{ ui('เลือกประเภทงานที่ต้องการรับ โดยใช้กับทั้งการแจ้งเตือนในแอปและอีเมลที่เปิดใช้งาน', 'Choose workflow categories for every enabled notification channel.') }}</p>
        <div class="settings-notification-grid">
          <label class="settings-toggle"><input v-model="form.notification_categories.billing" type="checkbox"><span>{{ ui('ใบเสนอราคาและงานเปิดบิล', 'Quotations and billing') }}</span></label>
          <label class="settings-toggle"><input v-model="form.notification_categories.credit" type="checkbox"><span>{{ ui('คำขอวงเงิน', 'Credit requests') }}</span></label>
          <label class="settings-toggle"><input v-model="form.notification_categories.booking" type="checkbox"><span>{{ ui('คำขอและผลจองเครื่อง FibroScan', 'FibroScan booking requests and decisions') }}</span></label>
          <label class="settings-toggle"><input v-model="form.notification_categories.tasks" type="checkbox"><span>{{ ui('มอบหมายงานและอัปเดตงาน', 'Task assignments and updates') }}</span></label>
          <label class="settings-toggle"><input v-model="form.notification_categories.call_sale" type="checkbox"><span>{{ ui('การนำเข้า Call Sale และความเห็น SM', 'Call Sale imports and SM comments') }}</span></label>
        </div>
      </section>

      <section class="content-card settings-section">
        <div class="section-heading"><div><p class="eyebrow">SECURITY</p><h2>{{ ui('เปลี่ยนรหัสผ่าน', 'Change password') }}</h2></div><small>{{ ui('อย่างน้อย 8 ตัว และมีทั้งตัวอักษรกับตัวเลข', 'Use at least 8 characters with letters and numbers.') }}</small></div>
        <div class="settings-grid password-grid">
          <label><span>{{ ui('รหัสผ่านปัจจุบัน', 'Current password') }}</span><input v-model="passwordForm.current_password" type="password" autocomplete="current-password" maxlength="255" @keydown.enter.prevent="changePassword"></label>
          <label><span>{{ ui('รหัสผ่านใหม่', 'New password') }}</span><input v-model="passwordForm.password" type="password" autocomplete="new-password" minlength="8" @keydown.enter.prevent="changePassword"></label>
          <label><span>{{ ui('ยืนยันรหัสผ่านใหม่', 'Confirm new password') }}</span><input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" @keydown.enter.prevent="changePassword"></label>
          <div class="password-action"><button class="secondary-button" type="button" :disabled="changingPassword || !passwordForm.current_password || !passwordForm.password || !passwordForm.password_confirmation" @click="changePassword">{{ changingPassword ? ui('กำลังเปลี่ยน…', 'Changing…') : ui('เปลี่ยนรหัสผ่าน', 'Change password') }}</button></div>
        </div>
      </section>
      <p class="muted">{{ ui('หากตั้งค่าเสร็จแล้วต้องกดบันทึกทุกครั้งเพื่อเป็นการบันทึกแล้วใช้ครั้งต่อไป', 'Once you have finished setting up, you must click save each time to save the changes.') }}</p>
      <div class="settings-actions"><button class="primary-button" type="button" :disabled="saving" @click="save">{{ saving ? ui('กำลังบันทึก…', 'Saving…') : ui('บันทึกการตั้งค่า', 'Save settings') }}</button></div>
    </div>
  </AppShell>
</template>

<style scoped>
.authorization-identity-section{border-top:3px solid var(--primary-600)}
.identity-notice{margin-bottom:16px;padding:11px 13px;border:1px solid var(--primary-200);border-radius:var(--radius-md);background:var(--primary-50);color:var(--gray-700)}
.settings-signature-preview{display:block;max-width:280px;max-height:110px;object-fit:contain;margin:12px 0;padding:8px;border:1px solid var(--gray-200);border-radius:var(--radius-sm);background:var(--gray-0)}
.settings-layout{display:grid;gap:var(--space-4);max-width:1050px}.settings-section{padding:var(--space-5)}.settings-grid,.settings-notification-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--space-4)}.settings-grid label:not(.settings-toggle){display:grid;gap:7px}.settings-grid .profile-wide{grid-column:1/-1}.settings-grid label>span,.account-managed span,.regional-preview span{color:var(--gray-700);font-weight:600}.settings-grid select,.settings-grid input,.settings-grid textarea{width:100%}.settings-grid textarea{resize:vertical}.settings-grid small{color:var(--gray-500);line-height:1.45}.settings-toggle{display:flex;align-items:center;gap:10px;min-height:42px;color:var(--gray-700);font-weight:500}.settings-toggle input{width:18px;height:18px;accent-color:var(--primary-600)}.settings-notification-grid.is-disabled{opacity:.55}.settings-actions,.profile-actions{display:flex;justify-content:flex-end}.settings-actions{position:sticky;bottom:var(--space-4);z-index:5;padding:var(--space-3);border:1px solid var(--gray-200);border-radius:var(--radius-md);background:rgba(255,255,255,.94);box-shadow:var(--shadow-md);backdrop-filter:blur(8px)}.settings-actions button,.profile-actions button{min-width:180px}.account-managed{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.account-managed>div,.regional-preview{display:grid;gap:4px;padding:12px 14px;border:1px solid var(--gray-200);border-radius:var(--radius-md);background:var(--gray-50)}.account-managed span,.regional-preview span{font-size:var(--text-caption)}.account-managed strong,.regional-preview strong{overflow-wrap:anywhere;color:var(--gray-900)}.profile-actions{margin-top:16px}.password-grid{align-items:end}.password-action{display:flex;justify-content:flex-end}.password-action button{min-height:42px}@media(max-width:720px){.settings-grid,.settings-notification-grid,.account-managed{grid-template-columns:1fr}.settings-grid .profile-wide{grid-column:auto}}
</style>
