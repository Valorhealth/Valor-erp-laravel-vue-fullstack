<script setup lang="ts">
import axios from 'axios'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import { auth } from '../stores/auth'
import { i18n } from '../stores/i18n'
import UiIcon from '../components/UiIcon.vue'

const router = useRouter()
const route = useRoute()
const username = ref('')
const password = ref('')
const errorMessage = ref('')
const submitting = ref(false)
const resetMode = ref(false)
const email = ref('')
const successMessage = ref('')
const showPassword = ref(false)
const english = computed(() => i18n.language.value === 'en')
const ui = (thai: string, en: string) => english.value ? en : thai

async function submit() {
  errorMessage.value = ''
  submitting.value = true

  try {
    await auth.login(username.value.trim(), password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : auth.defaultPath()
    await router.replace(redirect)
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      errorMessage.value = error.response.data?.message || 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'
    } else {
      errorMessage.value = 'เชื่อมต่อระบบไม่ได้ กรุณาตรวจสอบว่า Laravel API กำลังทำงาน'
    }
  } finally {
    submitting.value = false
  }
}

async function requestResetLink() {
  errorMessage.value = ''; successMessage.value = ''
  submitting.value = true
  try {
    await api.get('/sanctum/csrf-cookie')
    const response = await api.post('/api/v1/auth/forgot-password', { email: email.value.trim() })
    successMessage.value = response.data?.message || 'หากมีบัญชีนี้ ระบบจะส่งลิงก์ให้ทางอีเมล'
  } catch (error) {
    errorMessage.value = axios.isAxiosError(error) ? (error.response?.data?.message || 'ส่งคำขอไม่สำเร็จ') : 'ส่งคำขอไม่สำเร็จ'
  } finally { submitting.value = false }
}
</script>

<template>
  <main class="login-page">
    <section class="login-intro">
      <div class="login-brand">
        <span class="login-brand__logo">
          <img src="/valorhealth-removebg.png" alt="Valor Health" class="logo-image" />
        </span>
        <!-- <span><strong>VALOR</strong><small>ERP Workspace</small></span> -->
      </div>
      <div>
        <p class="eyebrow">VALOR HEALTH WORKSPACE</p>
        <h1>{{ ui('ระบบงานขายที่ชัดเจนในทุกขั้นตอน', 'Sales operations, clearly connected.') }}</h1>
        <p class="login-intro__copy">{{ ui('จัดการใบเสนอราคา การอนุมัติ เอกสาร และข้อมูลการขายในพื้นที่ทำงานเดียว', 'Manage quotations, approvals, documents and sales data in one secure workspace.') }}</p>
      </div>
      <div class="architecture-note"><span></span> Vue UI → Laravel API → Supabase PostgreSQL</div>
    </section>

    <section class="login-panel">
      <div class="login-language" aria-label="Language"><button type="button" :class="{ active: !english }" @click="i18n.setLanguage('th')">ไทย</button><button type="button" :class="{ active: english }" @click="i18n.setLanguage('en')">EN</button></div>
      <form class="login-card" @submit.prevent="resetMode ? requestResetLink() : submit()">
        <div>
          <p class="eyebrow eyebrow--red">{{ ui('ยินดีต้อนรับ', 'WELCOME') }}</p>
          <h2>{{ resetMode ? ui('ขอลิงก์ตั้งรหัสผ่านใหม่', 'Request a password reset link') : ui('เข้าสู่ระบบ', 'Sign in') }}</h2>
          <p class="muted">{{ resetMode ? ui('ระบบจะส่งลิงก์ใช้ครั้งเดียวไปยังอีเมลของบัญชี', 'A one-time reset link will be sent to your account email.') : ui('เข้าสู่ระบบด้วยบัญชีบริษัท ValorHealth ของคุณ', 'Sign in with your ValorHealth company account.') }}</p>
        </div>

        <label v-if="!resetMode">
          <span>Username</span>
          <input v-model="username" name="username" autocomplete="username" required autofocus :placeholder="ui('กรอกชื่อผู้ใช้', 'Enter username')">
        </label>

        <label v-if="resetMode">
          <span>{{ ui('อีเมลที่ใช้สมัคร', 'Account email') }}</span>
          <input v-model="email" name="email" type="email" autocomplete="email" required placeholder="name@company.com">
        </label>

        <label v-if="!resetMode">
          <span>Password</span>
          <span class="password-field"><input v-model="password" name="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required :placeholder="ui('กรอกรหัสผ่าน', 'Enter password')"><button type="button" :aria-label="showPassword ? ui('ซ่อนรหัสผ่าน', 'Hide password') : ui('แสดงรหัสผ่าน', 'Show password')" @click="showPassword = !showPassword"><UiIcon name="eye" :size="18" /></button></span>
        </label>

        <p v-if="errorMessage" class="form-error" role="alert">{{ errorMessage }}</p>
        <p v-if="successMessage" class="success-message">{{ successMessage }}</p>
        <button class="primary-button" type="submit" :disabled="submitting">
          {{ submitting ? ui('กำลังดำเนินการ…', 'Processing…') : (resetMode ? ui('ส่งลิงก์ไปที่อีเมล', 'Send reset link') : ui('เข้าสู่ระบบ', 'Sign in')) }}
        </button>
        <button class="login-link" type="button" @click="resetMode = !resetMode; errorMessage = ''; successMessage = ''">{{ resetMode ? ui('← กลับไปหน้าล็อกอิน', '← Back to sign in') : ui('ลืมรหัสผ่าน?', 'Forgot password?') }}</button>
      </form>
    </section>
  </main>
</template>

<style scoped>
.login-panel{position:relative}.login-language{position:absolute;top:22px;right:22px;display:flex;padding:3px;border:1px solid var(--gray-200);border-radius:var(--radius-pill);background:var(--gray-0)}.login-language button{border:0;border-radius:var(--radius-pill);background:transparent;padding:5px 10px;color:var(--gray-600);font-weight:700;cursor:pointer}.login-language button.active{background:var(--primary-600);color:var(--gray-0)}.password-field{position:relative;display:block}.password-field input{width:100%;padding-right:44px}.password-field button{position:absolute;top:50%;right:8px;display:grid;width:32px;height:32px;padding:0;transform:translateY(-50%);place-items:center;border:0;border-radius:var(--radius-sm);background:transparent;color:var(--gray-500);cursor:pointer}.password-field button:hover{background:var(--gray-100);color:var(--gray-800)}
</style>
