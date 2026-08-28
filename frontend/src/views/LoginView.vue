<script setup lang="ts">
import axios from 'axios'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api/client'
import { auth } from '../stores/auth'

const router = useRouter()
const route = useRoute()
const username = ref('')
const password = ref('')
const errorMessage = ref('')
const submitting = ref(false)
const resetMode = ref(false)
const email = ref('')
const successMessage = ref('')

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
        <p class="eyebrow">ระบบ ERP ฉบับ PHP&SQL</p>
        <h1>ValorHeath<br>Faster Worker</h1>
        <p class="login-intro__copy">Prototype นี้ใช้ Laravel API + Vue เชื่อมต่อกับฐาน PostgreSQL บน Supabase </p>
      </div>
      <div class="architecture-note"><span></span> Vue UI → Laravel API → Supabase PostgreSQL</div>
    </section>

    <section class="login-panel">
      <form class="login-card" @submit.prevent="resetMode ? requestResetLink() : submit()">
        <div>
          <p class="eyebrow eyebrow--red">ยินดีต้อนรับ</p>
          <h2>{{ resetMode ? 'ขอลิงก์ตั้งรหัสผ่านใหม่' : 'เข้าสู่ระบบ' }}</h2>
          <p class="muted">{{ resetMode ? 'ระบบจะส่งลิงก์ใช้ครั้งเดียวไปยังอีเมลของบัญชี' : 'เข้าสู่ระบบด้วยบัญชีบริษัท ValorHealth ของคุณ' }}</p>
        </div>

        <label v-if="!resetMode">
          <span>Username</span>
          <input v-model="username" name="username" autocomplete="username" required autofocus placeholder="กรอกชื่อผู้ใช้">
        </label>

        <label v-if="resetMode">
          <span>อีเมลที่ใช้สมัคร</span>
          <input v-model="email" name="email" type="email" autocomplete="email" required placeholder="name@company.com">
        </label>

        <label v-if="!resetMode">
          <span>Password</span>
          <input v-model="password" name="password" type="password" autocomplete="current-password" required placeholder="กรอกรหัสผ่าน">
        </label>

        <p v-if="errorMessage" class="form-error" role="alert">{{ errorMessage }}</p>
        <p v-if="successMessage" class="success-message">{{ successMessage }}</p>
        <button class="primary-button" type="submit" :disabled="submitting">
          {{ submitting ? 'กำลังดำเนินการ…' : (resetMode ? 'ส่งลิงก์ไปที่อีเมล' : 'เข้าสู่ระบบ') }}
        </button>
        <button class="login-link" type="button" @click="resetMode = !resetMode; errorMessage = ''; successMessage = ''">{{ resetMode ? '← กลับไปหน้าล็อกอิน' : 'ลืมรหัสผ่าน?' }}</button>
      </form>
    </section>
  </main>
</template>
