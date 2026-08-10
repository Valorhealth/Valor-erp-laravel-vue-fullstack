import axios from 'axios'

const apiOrigin = import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000'

export const api = axios.create({
  baseURL: apiOrigin,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  withXSRFToken: true,
})

function responseFilename(contentDisposition?: string): string | null {
  if (!contentDisposition) return null
  const utf8Name = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  if (utf8Name) {
    try { return decodeURIComponent(utf8Name.replace(/^"|"$/g, '')) } catch { /* ใช้ชื่อสำรอง */ }
  }
  return contentDisposition.match(/filename="?([^";]+)"?/i)?.[1] ?? null
}

export async function downloadFile(url: string, filename?: string, method: 'get' | 'post' = 'get', data?: unknown): Promise<void> {
  try {
    const response = await api.request<Blob>({ url, method, data, responseType: 'blob' })
    const objectUrl = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = objectUrl
    link.download = responseFilename(response.headers['content-disposition']) || filename || 'download'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(objectUrl)
  } catch (cause) {
    let message = 'ระบบไม่สามารถสร้างไฟล์ได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ'
    if (axios.isAxiosError(cause) && cause.response?.data instanceof Blob) {
      try {
        const payload = JSON.parse(await cause.response.data.text()) as { message?: string }
        message = payload.message || message
      } catch { /* response ไม่ใช่ JSON */ }
    }
    window.alert(`ดาวน์โหลดเอกสารไม่สำเร็จ\n${message}`)
  }
}
