<script setup lang="ts">
import axios from 'axios'
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { api } from '../api/client'

const route = useRoute()
const email = computed(() => typeof route.query.email === 'string' ? route.query.email : '')
const token = computed(() => typeof route.query.token === 'string' ? route.query.token : '')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const completed = ref(false)
const errorMessage = ref('')

async function submit() {
  errorMessage.value = ''
  if (!email.value || !token.value) { errorMessage.value = 'ลิงก์ไม่ครบถ้วน กรุณาขอลิงก์ใหม่'; return }
  if (password.value !== passwordConfirmation.value) { errorMessage.value = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน'; return }
  submitting.value = true
  try {
    await api.get('/sanctum/csrf-cookie')
    await api.post('/api/v1/auth/reset-password', {
      email: email.value,
      token: token.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    completed.value = true
  } catch (error) {
    errorMessage.value = axios.isAxiosError(error)
      ? (error.response?.data?.errors?.token?.[0] || error.response?.data?.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ')
      : 'เชื่อมต่อระบบไม่สำเร็จ'
  } finally { submitting.value = false }
}
</script>

<template>
  <main class="login-page">
    <section class="login-intro">
      <div class="login-brand"><span class="login-brand__logo"><img src="/valorhealth-removebg.png" alt="Valor Health" class="logo-image"></span></div>
      <div><p class="eyebrow">VALOR ERP</p><h1>ตั้งรหัสผ่าน<br>อย่างปลอดภัย</h1><p class="login-intro__copy">ลิงก์นี้ใช้ได้ครั้งเดียวและมีอายุ 30 นาที</p></div>
    </section>
    <section class="login-panel">
      <div v-if="completed" class="login-card">
        <div><p class="eyebrow eyebrow--red">สำเร็จ</p><h2>ตั้งรหัสผ่านใหม่แล้ว</h2><p class="muted">เซสชันเดิมของบัญชีจะถูกยกเลิก กรุณาเข้าสู่ระบบอีกครั้ง</p></div>
        <RouterLink class="primary-button" to="/login">ไปหน้าเข้าสู่ระบบ</RouterLink>
      </div>
      <form v-else class="login-card" @submit.prevent="submit">
        <div><p class="eyebrow eyebrow--red">PASSWORD RESET</p><h2>กำหนดรหัสผ่านใหม่</h2><p class="muted">อย่างน้อย 8 ตัว และต้องมีทั้งตัวอักษรกับตัวเลข</p></div>
        <label><span>อีเมล</span><input :value="email" type="email" disabled></label>
        <label><span>รหัสผ่านใหม่</span><input v-model="password" type="password" autocomplete="new-password" minlength="8" required></label>
        <label><span>ยืนยันรหัสผ่านใหม่</span><input v-model="passwordConfirmation" type="password" autocomplete="new-password" minlength="8" required></label>
        <p v-if="errorMessage" class="form-error" role="alert">{{ errorMessage }}</p>
        <button class="primary-button" type="submit" :disabled="submitting">{{ submitting ? 'กำลังบันทึก…' : 'บันทึกรหัสผ่านใหม่' }}</button>
        <RouterLink class="login-link" to="/login">← กลับไปหน้าเข้าสู่ระบบ</RouterLink>
      </form>
    </section>
  </main>
</template>
