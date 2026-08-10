<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import type { Paginated } from '../types/api'
import { apiError } from '../utils/format'

interface ManagedUser { id: number; username: string; first_name: string; last_name: string; email: string; department: string | null; role: string }
const roles = ['Sale (กรอกใบขอเปิดบิล)', 'Approve (หัวหน้าอนุมัติ)', 'Admin (สั่งพิมพ์ใบเปิดบิล)', 'Super Admin (ผู้ดูแลระบบสูงสุด)']
const users = ref<ManagedUser[]>([]); const userPage = ref<Paginated<ManagedUser> | null>(null); const search = ref(''); const editingId = ref<number | null>(null); const error = ref(''); const message = ref(''); const saving = ref(false)
const form = reactive({ username: '', password: '', password_confirmation: '', first_name: '', last_name: '', email: '', department: '', role: roles[0] })

async function load(page = 1) { try { const response = await api.get<Paginated<ManagedUser>>('/api/v1/users', { params: { page, search: search.value, per_page: 25 } }); userPage.value = response.data; users.value = response.data.data } catch (cause) { error.value = apiError(cause) } }
function clear() { editingId.value = null; Object.assign(form, { username: '', password: '', password_confirmation: '', first_name: '', last_name: '', email: '', department: '', role: roles[0] }) }
function edit(user: ManagedUser) { editingId.value = user.id; Object.assign(form, { ...user, department: user.department || '', password: '', password_confirmation: '' }); window.scrollTo({ top: 0, behavior: 'smooth' }) }
async function save() { saving.value = true; error.value = ''; message.value = ''; try { if (editingId.value) await api.put(`/api/v1/users/${editingId.value}`, form); else await api.post('/api/v1/users', form); message.value = editingId.value ? 'แก้ไขผู้ใช้แล้ว' : 'สร้างผู้ใช้แล้ว'; clear(); await load() } catch (cause) { error.value = apiError(cause) } finally { saving.value = false } }
async function remove(user: ManagedUser) { if (!confirm(`ลบบัญชี ${user.username} หรือไม่?`)) return; try { await api.delete(`/api/v1/users/${user.id}`); message.value = 'ลบบัญชีแล้ว'; await load() } catch (cause) { error.value = apiError(cause) } }
onMounted(load)
</script>

<template><AppShell title="จัดการผู้ใช้งาน" subtitle="สร้าง แก้ไข ลบบัญชี และกำหนด Role ซึ่งควบคุมเมนู Sidebar และ API จริง">
  <p v-if="error" class="form-error section-gap-sm">{{ error }}</p><p v-if="message" class="success-message section-gap-sm">{{ message }}</p>
  <form class="content-card form-grid" @submit.prevent="save"><div class="span-2 section-heading"><div><p class="eyebrow eyebrow--red">User account</p><h2>{{ editingId ? 'แก้ไขบัญชี' : 'สร้างบัญชีใหม่' }}</h2></div><button v-if="editingId" type="button" class="secondary-button" @click="clear">ยกเลิกแก้ไข</button></div>
    <label><span>Username *</span><input v-model.trim="form.username" minlength="3" required></label><label><span>Role *</span><select v-model="form.role" required><option v-for="role in roles" :key="role">{{ role }}</option></select></label>
    <label><span>ชื่อ *</span><input v-model.trim="form.first_name" required></label><label><span>นามสกุล *</span><input v-model.trim="form.last_name" required></label><label><span>Email *</span><input v-model.trim="form.email" type="email" required></label><label><span>แผนก</span><input v-model.trim="form.department"></label>
    <label><span>{{ editingId ? 'รหัสผ่านใหม่ (เว้นว่างได้)' : 'รหัสผ่าน *' }}</span><input v-model="form.password" type="password" :required="!editingId" minlength="4" autocomplete="new-password"></label><label><span>ยืนยันรหัสผ่าน</span><input v-model="form.password_confirmation" type="password" :required="Boolean(form.password)" autocomplete="new-password"></label>
    <button class="primary-button span-2" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : 'บันทึกผู้ใช้งาน' }}</button>
  </form>
  <section class="content-card section-gap"><form class="search-row" @submit.prevent="load(1)"><label><span>ค้นหา Username หรือชื่อ</span><input v-model="search"></label><button class="secondary-button">ค้นหา</button></form><div class="table-wrap section-gap-sm"><table><thead><tr><th>Username</th><th>ชื่อ-นามสกุล</th><th>Email</th><th>Role</th><th>แผนก</th><th>จัดการ</th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td class="strong">{{ user.username }}</td><td>{{ user.first_name }} {{ user.last_name }}</td><td>{{ user.email }}</td><td><StatusPill :status="user.role" /></td><td>{{ user.department || '-' }}</td><td class="actions"><button class="secondary-button" @click="edit(user)">แก้ไข</button><button class="danger-link" @click="remove(user)">ลบ</button></td></tr></tbody></table></div><div v-if="userPage" class="pagination"><span>ทั้งหมด {{ userPage.total }} รายการ</span><div><button class="secondary-button" :disabled="!userPage.prev_page_url" @click="load(userPage.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ userPage.current_page }} / {{ userPage.last_page }}</span><button class="secondary-button" :disabled="!userPage.next_page_url" @click="load(userPage.current_page + 1)">ถัดไป</button></div></div></section>
</AppShell></template>
