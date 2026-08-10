export type StatusTone = 'success' | 'ready' | 'info' | 'warning' | 'revision' | 'danger' | 'neutral'

function containsAny(status: string, keywords: string[]) {
  return keywords.some((keyword) => status.includes(keyword))
}

export function statusTone(value: string | null | undefined): StatusTone {
  const status = String(value || '').trim().toLocaleLowerCase('th-TH')

  if (!status || status === '-') return 'neutral'

  // Negative states must be checked first because "ไม่อนุมัติ" contains "อนุมัติ".
  if (containsAny(status, ['ไม่อนุมัติ', 'ยกเลิก', 'ล้มเหลว', 'ไม่สำเร็จ', 'ไม่พร้อม', 'failed', 'error', 'canceled', 'cancelled'])) {
    return 'danger'
  }

  if (containsAny(status, ['รอแก้ไข', 'ต้องแก้ไข', 'ส่งกลับแก้ไข'])) return 'revision'

  if (containsAny(status, [
    'ดำเนินการเสร็จสิ้น',
    'เสร็จสิ้น',
    'สำเร็จ',
    'อนุมัติแล้ว',
    'อนุมัติวงเงินแล้ว',
    'เปิดวงเงินแล้ว',
    'เปิดใช้งาน',
    'พร้อมใช้งาน',
    'success',
    'completed',
  ])) return 'success'

  if (containsAny(status, ['เตรียมส่งออก', 'พร้อมส่งออก', 'พร้อมจัดส่ง'])) return 'ready'
  if (containsAny(status, ['รอแอดมินตรวจสอบ', 'กำลังทำ', 'กำลังดำเนินการ', 'processing', 'in progress'])) return 'info'
  if (containsAny(status, ['รออนุมัติ', 'รอหัวหน้า', 'รออนุมัติวงเงิน', 'รอดำเนินการ', 'pending'])) return 'warning'

  return 'neutral'
}
