<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { auth } from '../stores/auth'

defineProps<{ title: string; subtitle?: string }>()

const router = useRouter()
const menuOpen = ref(false)
const links = computed(() => auth.user.value?.menu ?? [])
const roleGroupLabels = {
  super_admin: 'ผู้ดูแลระบบสูงสุด',
  sale: 'ฝ่ายขาย',
  approve: 'ผู้อนุมัติ',
  admin: 'ผู้ดูแลระบบ',
  default: 'ผู้ใช้งานทั่วไป',
}
const roleGroupLabel = computed(() => roleGroupLabels[auth.user.value?.role_group ?? 'default'])

async function signOut() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="app-layout">
    <aside class="sidebar" :class="{ 'sidebar--open': menuOpen }">
      <div class="sidebar-brand">
        <span class="sidebar-brand__logo" >
          <img src="/valorhealth.png" alt="Valor Health">
        </span>
      </div>
        <span><strong>VALOR HEALTH</strong><small>  ERP Workspace</small></span>

      <nav class="nav" aria-label="เมนูหลัก">
        <RouterLink class="nav__notice" to="/notice-board" @click="menuOpen = false">
          <span aria-hidden="true">⌑</span>บอร์ดประกาศ
        </RouterLink>
        <p class="nav__heading">เมนู</p>
        <RouterLink v-for="link in links" :key="link.key" :to="link.path" @click="menuOpen = false">
          <span aria-hidden="true">{{ link.icon }}</span>{{ link.label }}
        </RouterLink>
      </nav>

      <div class="profile">
        <div class="profile__avatar">{{ auth.user.value?.username?.slice(0, 1).toUpperCase() }}</div>
        <div class="profile__text">
          <strong>{{ auth.user.value?.first_name || auth.user.value?.username }}</strong>
          <small>{{ roleGroupLabel }} · {{ auth.user.value?.department || '-' }}</small>
          <small class="profile__raw-role">{{ auth.user.value?.role || 'ไม่ระบุ Role' }}</small>
        </div>
        <button class="icon-button" type="button" aria-label="ออกจากระบบ" @click="signOut">↪</button>
      </div>
    </aside>

    <main class="main-content">
      <header class="page-header">
        <button class="menu-button" type="button" aria-label="เปิดเมนู" @click="menuOpen = !menuOpen">☰</button>
        <div>
          <h1>{{ title }}</h1>
          <p v-if="subtitle">{{ subtitle }}</p>
        </div>
        <div class="environment-badge"><span></span> Local prototype</div>
      </header>
      <slot />

    </main>
  </div>
</template>