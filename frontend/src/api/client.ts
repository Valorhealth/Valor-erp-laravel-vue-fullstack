import axios from 'axios'
import { networkActivity } from '../stores/networkActivity'

declare module 'axios' {
  export interface AxiosRequestConfig { skipGlobalLoading?: boolean }
  export interface InternalAxiosRequestConfig { skipGlobalLoading?: boolean }
}

const apiOrigin = import.meta.env.VITE_API_URL
  ?? (import.meta.env.PROD ? window.location.origin : 'http://127.0.0.1:8000')

export const api = axios.create({
  baseURL: apiOrigin,
  // Never leave a screen waiting forever when the API or database connection stalls.
  // Long-running operations such as PO scanning explicitly override this value.
  timeout: 30_000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
  withXSRFToken: true,
})

api.interceptors.request.use((config) => {
  if (!config.skipGlobalLoading) networkActivity.begin()
  return config
}, error => Promise.reject(error))
api.interceptors.response.use((response) => {
  if (!response.config.skipGlobalLoading) networkActivity.end()
  return response
}, (error) => {
  if (!error.config?.skipGlobalLoading) networkActivity.end()
  return Promise.reject(error)
})

function responseFilename(contentDisposition?: string): string | null {
  if (!contentDisposition) return null
  const utf8Name = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  if (utf8Name) {
    try { return decodeURIComponent(utf8Name.replace(/^"|"$/g, '')) } catch { /* ใช้ชื่อสำรอง */ }
  }
  return contentDisposition.match(/filename="?([^";]+)"?/i)?.[1] ?? null
}

export async function downloadFile(url: string, filename?: string, method: 'get' | 'post' = 'get', data?: unknown, throwOnError = false): Promise<void> {
  try {
    const response = await api.request<Blob>({ url, method, data, responseType: 'blob' })
    const objectUrl = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = objectUrl
    link.download = responseFilename(response.headers['content-disposition']) || filename || 'download'
    link.rel = 'noopener'
    link.style.display = 'none'
    document.body.appendChild(link)
    link.click()
    link.remove()
    // Safari may cancel a download when the Blob URL is revoked in the same task.
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1_000)
  } catch (cause) {
    let message = 'ระบบไม่สามารถสร้างไฟล์ได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ'
    if (axios.isAxiosError(cause) && cause.response?.data instanceof Blob) {
      try {
        const payload = JSON.parse(await cause.response.data.text()) as { message?: string }
        message = payload.message || message
      } catch { /* response ไม่ใช่ JSON */ }
    }
    if (throwOnError) throw new Error(message)
    window.alert(`ดาวน์โหลดเอกสารไม่สำเร็จ\n${message}`)
  }
}
