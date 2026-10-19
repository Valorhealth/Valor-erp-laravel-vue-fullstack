<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '../api/client'
import AppShell from '../components/AppShell.vue'
import StatusPill from '../components/StatusPill.vue'
import { auth } from '../stores/auth'
import type { Paginated, Salesman } from '../types/api'
import { apiError } from '../utils/format'
import { preferredRowsPerPage } from '../utils/preferences'

type PermissionMode = 'inherit' | 'grant' | 'deny'
interface PermissionItem { key: string; label: string; path: string; icon: string; default_groups: string[]; role_variants: Record<string, { label: string; path: string; icon: string }> }
interface ManagedUser { id: number; username: string; first_name: string; last_name: string; email: string; phone: string | null; department: string | null; role: string; role_group: string; salesman_id: number | null; salesman: Salesman | null; quotation_signing_authorized: boolean; has_signature: boolean; permission_overrides: Record<string, boolean> }
interface UserForm { username: string; password: string; password_confirmation: string; first_name: string; last_name: string; email: string; phone: string; department: string; role: string; salesman_id: number | null; quotation_signing_authorized: boolean; permission_overrides: Record<string, boolean> }
const roles = ['Sale (กรอกใบขอเปิดบิล)', 'Approve (หัวหน้าอนุมัติ)', 'Admin (สั่งพิมพ์ใบเปิดบิล)', 'Super Admin (ผู้ดูแลระบบสูงสุด)']
const users = ref<ManagedUser[]>([]); const userPage = ref<Paginated<ManagedUser> | null>(null); const search = ref(''); const editingId = ref<number | null>(null); const error = ref(''); const message = ref(''); const saving = ref(false)
const salesmen = ref<Salesman[]>([])
const form = reactive<UserForm>({ username: '', password: '', password_confirmation: '', first_name: '', last_name: '', email: '', phone: '', department: '', role: roles[0], salesman_id: null, quotation_signing_authorized: false, permission_overrides: {} })
const signatureFile = ref<File | null>(null); const signaturePreview = ref(''); const signatureAvailable = ref(false); const uploadingSignature = ref(false)
const permissionCatalog = ref<PermissionItem[]>([])
const canManagePermissions = computed(() => auth.user.value?.role_group === 'super_admin')
const availableRoles = computed(() => canManagePermissions.value ? roles : roles.filter(role => !role.startsWith('Super Admin')))

async function load(page = 1) { try { const response = await api.get<Paginated<ManagedUser>>('/api/v1/users', { params: { page, search: search.value, per_page: preferredRowsPerPage(25) } }); userPage.value = response.data; users.value = response.data.data } catch (cause) { error.value = apiError(cause) } }
function revokeSignaturePreview() { if (signaturePreview.value) URL.revokeObjectURL(signaturePreview.value); signaturePreview.value = '' }
function clear() { editingId.value = null; signatureFile.value = null; signatureAvailable.value = false; revokeSignaturePreview(); Object.assign(form, { username: '', password: '', password_confirmation: '', first_name: '', last_name: '', email: '', phone: '', department: '', role: roles[0], salesman_id: null, quotation_signing_authorized: false, permission_overrides: {} }) }
async function loadSignaturePreview(user: ManagedUser) { revokeSignaturePreview(); signatureAvailable.value = Boolean(user.has_signature); if (!user.has_signature) return; const response = await api.get(`/api/v1/users/${user.id}/signature`, { responseType: 'blob' }); signaturePreview.value = URL.createObjectURL(response.data) }
async function edit(user: ManagedUser) { editingId.value = user.id; signatureFile.value = null; Object.assign(form, { ...user, phone: user.phone || '', department: user.department || '', password: '', password_confirmation: '', permission_overrides: { ...(user.permission_overrides || {}) } }); try { await loadSignaturePreview(user) } catch (cause) { error.value = apiError(cause, 'โหลดตัวอย่างลายเซ็นไม่สำเร็จ') }; window.scrollTo({ top: 0, behavior: 'smooth' }) }
function selectSignature(event: Event) { signatureFile.value = (event.target as HTMLInputElement).files?.[0] || null }
async function uploadSignature() { if (!editingId.value || !signatureFile.value) return; uploadingSignature.value = true; error.value = ''; try { const data = new FormData(); data.append('file', signatureFile.value); await api.post(`/api/v1/users/${editingId.value}/signature`, data); signatureAvailable.value = true; signatureFile.value = null; const user = users.value.find((item) => item.id === editingId.value); if (user) { user.has_signature = true; await loadSignaturePreview(user) }; message.value = 'บันทึก Version ลายเซ็น PNG แล้ว สามารถเปิดสิทธิ์ลงนามและกดบันทึกผู้ใช้งานได้' } catch (cause) { error.value = apiError(cause) } finally { uploadingSignature.value = false } }
async function save() { saving.value = true; error.value = ''; message.value = ''; try { const payload: Partial<UserForm> = { ...form, permission_overrides: { ...form.permission_overrides } }; if (!canManagePermissions.value) delete payload.permission_overrides; if (editingId.value) await api.put(`/api/v1/users/${editingId.value}`, payload); else await api.post('/api/v1/users', payload); message.value = editingId.value ? 'แก้ไขผู้ใช้และสิทธิ์เรียบร้อยแล้ว' : 'สร้างผู้ใช้แล้ว'; clear(); await load() } catch (cause) { error.value = apiError(cause) } finally { saving.value = false } }
async function remove(user: ManagedUser) { if (!confirm(`ลบบัญชี ${user.username} หรือไม่?`)) return; try { await api.delete(`/api/v1/users/${user.id}`); message.value = 'ลบบัญชีแล้ว'; await load() } catch (cause) { error.value = apiError(cause) } }
async function loadPermissionCatalog() { if (!canManagePermissions.value) return; try { const response = await api.get<{ data: PermissionItem[] }>('/api/v1/users/permission-catalog'); permissionCatalog.value = response.data.data } catch (cause) { error.value = apiError(cause, 'โหลดรายการสิทธิ์ไม่สำเร็จ') } }
async function loadSalesmen() { try { const response = await api.get<{ data: Salesman[] }>('/api/v1/users/salesmen'); salesmen.value = response.data.data } catch (cause) { error.value = apiError(cause, 'โหลดรหัสพนักงานขายไม่สำเร็จ') } }
function selectedRoleGroup(): string { const role = form.role.toLocaleLowerCase(); if (role.includes('super admin')) return 'super_admin'; if (role.includes('approve')) return 'approve'; if (role.includes('admin')) return 'admin'; if (role.includes('sale')) return 'sale'; return 'default' }
function permissionMode(key: string): PermissionMode { if (!Object.prototype.hasOwnProperty.call(form.permission_overrides, key)) return 'inherit'; return form.permission_overrides[key] ? 'grant' : 'deny' }
function setPermission(key: string, mode: PermissionMode) { if (mode === 'inherit') delete form.permission_overrides[key]; else form.permission_overrides[key] = mode === 'grant' }
function basePermission(item: PermissionItem): boolean { return selectedRoleGroup() === 'super_admin' || item.default_groups.includes(selectedRoleGroup()) }
function permissionVariant(item: PermissionItem) { return item.role_variants[selectedRoleGroup()] || item }
function canManageAccount(user: ManagedUser): boolean { return canManagePermissions.value || user.role_group !== 'super_admin' }
onMounted(async () => { await Promise.all([load(), loadPermissionCatalog(), loadSalesmen()]) })
onBeforeUnmount(revokeSignaturePreview)
</script>

<template><AppShell title="จัดการผู้ใช้งาน" subtitle="สร้าง แก้ไข ลบบัญชี และกำหนด Role ซึ่งควบคุมเมนู Sidebar และ API จริง">
  <p v-if="error" class="form-error section-gap-sm">{{ error }}</p><p v-if="message" class="success-message section-gap-sm">{{ message }}</p>
  <form class="content-card form-grid" @submit.prevent="save"><div class="span-2 section-heading"><div><p class="eyebrow eyebrow--red">User account</p><h2>{{ editingId ? 'แก้ไขบัญชี' : 'สร้างบัญชีใหม่' }}</h2></div><button v-if="editingId" type="button" class="secondary-button" @click="clear">ยกเลิกแก้ไข</button></div>
    <label><span>Username *</span><input v-model.trim="form.username" minlength="3" required></label><label><span>Role *</span><select v-model="form.role" required><option v-for="role in availableRoles" :key="role">{{ role }}</option></select></label>
    <label><span>ชื่อ *</span><input v-model.trim="form.first_name" required></label><label><span>นามสกุล *</span><input v-model.trim="form.last_name" required></label><label><span>Email *</span><input v-model.trim="form.email" type="email" required></label><label><span>เบอร์โทรศัพท์</span><input v-model.trim="form.phone" type="tel" maxlength="30"></label><label><span>แผนก</span><input v-model.trim="form.department"></label><label><span>รหัสพนักงานขาย Bplus</span><select v-model="form.salesman_id"><option :value="null">— ไม่กำหนด —</option><option v-for="salesman in salesmen" :key="salesman.id" :value="salesman.id">{{ salesman.salesman_code }} · {{ salesman.salesman_name }}</option></select></label>
    <section class="span-2 signature-management"><div><strong>ลายเซ็น PNG ประจำบัญชี</strong><p v-if="!editingId" class="muted">กรุณาสร้างบัญชีก่อน แล้วกดแก้ไขบัญชีนั้นเพื่อแนบลายเซ็น</p><template v-else><input type="file" accept="image/png,.png" @change="selectSignature"><button type="button" class="secondary-button" :disabled="!signatureFile || uploadingSignature" @click="uploadSignature">{{ uploadingSignature ? 'กำลังบันทึก…' : signatureAvailable ? 'เพิ่ม Version ลายเซ็นใหม่' : 'แนบลายเซ็น PNG' }}</button></template></div><img v-if="signaturePreview" :src="signaturePreview" class="signature-preview" alt="ตัวอย่างลายเซ็นประจำบัญชี"></section>
    <label class="span-2 approval-consent"><input v-model="form.quotation_signing_authorized" type="checkbox" :disabled="!signatureAvailable"><span>บริษัทได้ตรวจสอบอำนาจ/หนังสือมอบอำนาจ และอนุญาตให้บัญชีนี้ลงนามใบเสนอราคา</span></label>
    <details v-if="canManagePermissions" class="span-2 permission-management">
      <summary><span>กำหนดสิทธิ์เมนูรายบัญชี</span><small>เฉพาะ Super Admin · ค่าเริ่มต้นอ้างอิงตาม Role</small></summary>
      <div class="permission-help"><strong>วิธีใช้</strong><span>“ตาม Role” ใช้สิทธิ์มาตรฐาน, “อนุญาตเพิ่ม” เพิ่มเมนูเฉพาะบัญชี และ “ปิดเฉพาะบัญชี” ถอนเมนูเฉพาะบัญชี</span></div>
      <div class="permission-grid">
        <article v-for="item in permissionCatalog" :key="item.key" class="permission-item">
          <div class="permission-name"><span class="permission-icon">{{ permissionVariant(item).icon }}</span><div><strong>{{ permissionVariant(item).label }}</strong><small>{{ permissionVariant(item).path }}</small></div></div>
          <div class="permission-control">
            <span class="base-state" :class="basePermission(item) ? 'allowed' : 'blocked'">Role: {{ basePermission(item) ? 'อนุญาต' : 'ไม่อนุญาต' }}</span>
            <select :value="permissionMode(item.key)" :disabled="selectedRoleGroup() === 'super_admin'" @change="setPermission(item.key, ($event.target as HTMLSelectElement).value as PermissionMode)">
              <option value="inherit">ตาม Role</option>
              <option value="grant">อนุญาตเพิ่ม</option>
              <option value="deny">ปิดเฉพาะบัญชี</option>
            </select>
          </div>
        </article>
      </div>
      <p v-if="selectedRoleGroup() === 'super_admin'" class="permission-note">บัญชี Super Admin มีสิทธิ์เต็มเสมอ เพื่อป้องกันระบบไม่มีผู้ดูแล จึงไม่ใช้ค่าปิดรายบัญชี</p>
    </details>
    <label><span>{{ editingId ? 'รหัสผ่านใหม่ (เว้นว่างได้)' : 'รหัสผ่าน *' }}</span><input v-model="form.password" type="password" :required="!editingId" minlength="8" pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="อย่างน้อย 8 ตัว และมีทั้งตัวอักษรกับตัวเลข" autocomplete="new-password"></label><label><span>ยืนยันรหัสผ่าน</span><input v-model="form.password_confirmation" type="password" :required="Boolean(form.password)" minlength="8" autocomplete="new-password"></label>
    <button class="primary-button span-2" :disabled="saving">{{ saving ? 'กำลังบันทึก…' : 'บันทึกผู้ใช้งาน' }}</button>
  </form>
  <section class="content-card section-gap"><form class="search-row" @submit.prevent="load(1)"><label><span>ค้นหา Username หรือชื่อ</span><input v-model="search"></label><button class="secondary-button">ค้นหา</button></form><div class="table-wrap section-gap-sm"><table><thead><tr><th>Username</th><th>ชื่อ-นามสกุล</th><th>รหัสพนักงานขาย</th><th>Email</th><th>Role</th><th>PNG ลายเซ็น</th><th>สิทธิ์ลงนามใบเสนอราคา</th><th>แผนก</th><th>จัดการ</th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td class="strong">{{ user.username }}</td><td>{{ user.first_name }} {{ user.last_name }}</td><td>{{ user.salesman?.salesman_code || '-' }}</td><td>{{ user.email }}</td><td><StatusPill :status="user.role" /></td><td>{{ user.has_signature ? 'มีแล้ว' : 'ยังไม่มี' }}</td><td>{{ user.quotation_signing_authorized ? 'ได้รับมอบอำนาจ' : 'ยังไม่ได้กำหนด' }}</td><td>{{ user.department || '-' }}</td><td class="actions"><template v-if="canManageAccount(user)"><button type="button" class="secondary-button" @click="edit(user)">แก้ไข</button><button type="button" class="danger-link" @click="remove(user)">ลบ</button></template><small v-else class="muted">เฉพาะ Super Admin</small></td></tr></tbody></table></div><div v-if="userPage" class="pagination"><span>ทั้งหมด {{ userPage.total }} รายการ</span><div><button class="secondary-button" :disabled="!userPage.prev_page_url" @click="load(userPage.current_page - 1)">ก่อนหน้า</button><span>หน้า {{ userPage.current_page }} / {{ userPage.last_page }}</span><button class="secondary-button" :disabled="!userPage.next_page_url" @click="load(userPage.current_page + 1)">ถัดไป</button></div></div></section>
</AppShell></template>

<style scoped>
.permission-management{border:1px solid #dfe3e8;border-radius:12px;background:#fafbfc;overflow:hidden}.permission-management summary{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;color:#263142;font-weight:800}.permission-management summary small{color:#667085;font-weight:500}.permission-help{display:flex;gap:8px;padding:11px 16px;background:#fff5f6;color:#6f2630;font-size:.8rem}.permission-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;padding:14px}.permission-item{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:11px;border:1px solid #e3e6eb;border-radius:10px;background:#fff}.permission-name{display:flex;align-items:center;gap:9px;min-width:0}.permission-icon{display:grid;place-items:center;width:32px;height:32px;flex-shrink:0;border-radius:9px;background:#f2f4f7}.permission-name div{display:grid;min-width:0}.permission-name strong{font-size:.82rem}.permission-name small{overflow:hidden;color:#7a8493;font-size:.67rem;text-overflow:ellipsis;white-space:nowrap}.permission-control{display:grid;justify-items:end;gap:5px;flex-shrink:0}.permission-control select{min-width:132px;padding:6px 8px;border:1px solid #d5d9e0;border-radius:8px;background:#fff}.base-state{font-size:.66rem;font-weight:700}.base-state.allowed{color:#15803d}.base-state.blocked{color:#64748b}.permission-note{margin:0 14px 14px;padding:10px;border-radius:8px;background:#fff8e6;color:#8a5a00;font-size:.78rem}@media(max-width:800px){.permission-grid{grid-template-columns:1fr}.permission-management summary,.permission-item{align-items:flex-start}.permission-management summary{display:grid}.permission-item{flex-wrap:wrap}.permission-control{width:100%;justify-items:stretch}.permission-control select{width:100%}}
</style>
