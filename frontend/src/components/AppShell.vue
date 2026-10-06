<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { auth } from '../stores/auth'

defineProps<{ title: string; subtitle?: string }>()

const router = useRouter()
const desktopMenuQuery = window.matchMedia('(min-width: 761px)')
const menuOpen = ref(desktopMenuQuery.matches)
const links = computed(() => auth.user.value?.menu ?? [])
const roleGroupLabels = {
  super_admin: 'ผู้ดูแลระบบสูงสุด',
  sale: 'ฝ่ายขาย',
  approve: 'ผู้อนุมัติ',
  admin: 'ผู้ดูแลระบบ',
  default: 'ผู้ใช้งานทั่วไป',
}
const roleGroupLabel = computed(() => roleGroupLabels[auth.user.value?.role_group ?? 'default'])

function syncMenuWithViewport(event: MediaQueryListEvent) {
  menuOpen.value = event.matches
}

function closeMenuOnMobile() {
  if (!desktopMenuQuery.matches) menuOpen.value = false
}

function addViewportListener() {
  if (typeof desktopMenuQuery.addEventListener === 'function') desktopMenuQuery.addEventListener('change', syncMenuWithViewport)
  else desktopMenuQuery.addListener(syncMenuWithViewport)
}

function removeViewportListener() {
  if (typeof desktopMenuQuery.removeEventListener === 'function') desktopMenuQuery.removeEventListener('change', syncMenuWithViewport)
  else desktopMenuQuery.removeListener(syncMenuWithViewport)
}

onMounted(addViewportListener)
onBeforeUnmount(removeViewportListener)

async function signOut() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="app-layout" :class="{ 'app-layout--sidebar-open': menuOpen }">
    <aside class="sidebar" :class="{ 'sidebar--open': menuOpen }">
      <div class="menu-scroll-area">
        <div class="sidebar-brand">
          <span class="sidebar-brand__logo">
            <img src="/valorhealth-removebg.png" alt="Valor Health">
          </span>
          <!-- <span class="center"><strong>VALOR HEALTH Sale Co</strong></span> -->
        </div>

        <nav class="nav" aria-label="เมนูหลัก">
          <RouterLink class="nav__notice" to="/notice-board" @click="closeMenuOnMobile">
            <span aria-hidden="true">📢</span>บอร์ดประกาศ
          </RouterLink>
          <p class="nav__heading">เมนู</p>
          <RouterLink v-for="link in links" :key="link.key" :to="link.path" @click="closeMenuOnMobile">
            <span aria-hidden="true">{{ link.icon }}</span>{{ link.label }}
          </RouterLink>
        </nav>
      </div>

      <div class="profile account-fixed-bottom">
        <div class="profile__avatar">{{ auth.user.value?.username?.slice(0, 1).toUpperCase() }}</div>
        <div class="profile__text">
          <strong>{{ auth.user.value?.first_name || auth.user.value?.username }}</strong>
          <small>{{ roleGroupLabel }} · {{ auth.user.value?.department || '-' }}</small>
          <small class="profile__raw-role">{{ auth.user.value?.role || 'ไม่ระบุ Role' }}</small>
        </div>
        <button class="icon-button logout-button" type="button" aria-label="ออกจากระบบ" @click="signOut">
          <span aria-hidden="true">↪</span>
          <span class="logout-button__label">ออกจากระบบ</span>
        </button>
      </div>
    </aside>

    <main class="main-content">
      <header class="page-header">
        <button
          class="menu-button"
          type="button"
          :aria-label="menuOpen ? 'ปิดเมนู' : 'เปิดเมนู'"
          :aria-expanded="menuOpen"
          @click="menuOpen = !menuOpen"
        >
          <span class="menu-toggle-icon" aria-hidden="true">
            <span class="menu-toggle-icon__panel"></span>
            <span class="menu-toggle-icon__chevron"></span>
          </span>
        </button>
        <div>
          <h1>{{ title }}</h1>
          <p v-if="subtitle">{{ subtitle }}</p>
        </div>
        <div class="environment-badge"><span></span> Online </div>
      </header>
      <slot />

    </main>
  </div>
</template>
