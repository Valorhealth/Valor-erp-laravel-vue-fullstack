# Deploy Valor ERP ขึ้น Render Free แบบ Step by Step

ไฟล์นี้ใช้กับโครงสร้าง `Laravel API + Vue + Python Budget Worker` ใน repository นี้ โดยเก็บฐานข้อมูลใน Supabase PostgreSQL, เก็บไฟล์ Budget ใน Supabase Storage และส่งอีเมลผ่าน Gmail API (HTTPS)

## 1. เตรียม Supabase Storage

1. เข้า Supabase Dashboard ของโปรเจกต์ ERP
2. ไปที่ `Storage` แล้วสร้าง Bucket ชื่อ `erp-budget-analysis`
3. ตั้ง Bucket เป็น `Private`
4. ไปที่ `Project Settings > Storage > S3 Access Keys`
5. เปิด S3 access และสร้าง Access Key ใหม่สำหรับ Server
6. เก็บค่า Endpoint, Region, Access Key ID และ Secret Access Key ไว้ใน Password Manager
7. ห้ามใส่ Secret Access Key ลง GitHub หรือไฟล์ `.env.example`

ค่าที่จะนำไปใส่ Render:

- `SUPABASE_STORAGE_ENDPOINT` = `https://PROJECT_REF.storage.supabase.co/storage/v1/s3`
- `SUPABASE_STORAGE_REGION` = Region ที่ Supabase แสดง
- `SUPABASE_STORAGE_ACCESS_KEY_ID` = Access Key ID
- `SUPABASE_STORAGE_SECRET_ACCESS_KEY` = Secret Access Key
- `SUPABASE_STORAGE_BUCKET` = `erp-budget-analysis`

## 2. เตรียม Gmail API สำหรับบัญชีผู้ส่ง

1. เข้า Google Cloud Console และสร้าง/เลือก Project ของ Valor ERP
2. ไปที่ `APIs & Services > Library`
3. ค้นหา `Gmail API` แล้วกด Enable
4. ไปที่ `Google Auth Platform` และตั้งค่า OAuth consent screen
5. เพิ่ม `sukirin.valorhealth@gmail.com` เป็น Test user ในช่วงทดสอบ
6. ไปที่ `Clients` และสร้าง OAuth Client ชนิด `Web application`
7. เพิ่ม Authorized redirect URI เป็น `https://developers.google.com/oauthplayground`
8. เปิด Google OAuth 2.0 Playground
9. กดรูปเฟือง เปิด `Use your own OAuth credentials` แล้วใส่ Client ID และ Client Secret
10. เลือก Scope `https://www.googleapis.com/auth/gmail.send`
11. กด Authorize APIs และล็อกอินด้วย `sukirin.valorhealth@gmail.com`
12. กด Exchange authorization code for tokens
13. เก็บ Refresh Token ที่ได้ไว้ใน Password Manager

ค่าที่จะนำไปใส่ Render:

- `WORKFLOW_EMAIL_TRANSPORT` = `gmail_api`
- `GMAIL_CLIENT_ID` = OAuth Client ID
- `GMAIL_CLIENT_SECRET` = OAuth Client Secret
- `GMAIL_REFRESH_TOKEN` = Refresh Token
- `GMAIL_SENDER` = `sukirin.valorhealth@gmail.com`

หมายเหตุ: OAuth app ที่อยู่สถานะ Testing อาจทำให้ Refresh Token หมดอายุใน 7 วัน ควรเปลี่ยน Publishing status เป็น Production ก่อนใช้งานจริง และจำกัด Scope ไว้เฉพาะ `gmail.send`

## 3. เตรียม Laravel APP_KEY

เปิด Terminal ที่โฟลเดอร์ `backend` แล้วรัน:

```powershell
php artisan key:generate --show
```

คัดลอกค่าที่ขึ้นต้นด้วย `base64:` เก็บไว้สำหรับตัวแปร `APP_KEY` ใน Render ห้ามเผยแพร่ค่านี้

## 4. สร้าง Render Web Service จาก Blueprint

1. Push โค้ดล่าสุดขึ้น GitHub repository `Valor-erp-laravel-vue-fullstack`
2. เข้า Render Dashboard
3. กด `New + > Blueprint`
4. เชื่อม GitHub และเลือก repository นี้
5. Render จะอ่านไฟล์ `render.yaml` และสร้าง Web Service ชื่อ `valor-erp-laravel-vue`
6. กรอก Secret Environment Variables ที่ Render ถามจากขั้นก่อนหน้า
7. ใส่ `DB_URL` เป็น Supabase PostgreSQL connection string สำหรับ Server
8. ใส่ `APP_KEY` จากขั้นที่ 3
9. ใส่ `GEMINI_API_KEY` จาก Google AI Studio เพื่อให้ฟังก์ชันสแกนเอกสารทำงานออนไลน์
10. ใส่ URL ของเว็บให้ `APP_URL` และ `FRONTEND_URL` เช่น `https://valor-erp-laravel-vue.onrender.com`
11. ใส่เฉพาะ hostname ให้ `SANCTUM_STATEFUL_DOMAINS` เช่น `valor-erp-laravel-vue.onrender.com`
12. กด Apply/Deploy และรอ Docker build เสร็จ

## 5. ตรวจหลัง Deploy

1. เปิด `https://ชื่อเว็บ.onrender.com/up` ต้องได้ข้อความสถานะผ่าน
2. เปิด `https://ชื่อเว็บ.onrender.com/api/v1/health` ต้องเห็น `database: connected`
3. ล็อกอิน แล้วปิด/เปิด Browser ใหม่เพื่อทดสอบ Session
4. เปิดบิลทดสอบ แล้วตรวจว่าผู้ใช้ Role Approve ได้รับอีเมล
5. อนุมัติบิล แล้วตรวจว่าผู้ใช้ Role Admin ได้รับอีเมล
6. อัปโหลด `.xlsx` ที่หน้า Budget Analysis โดยปิด Ollama แล้วตรวจ Progress จนถึง 100%
7. ตรวจใน Supabase Storage ว่ามีไฟล์ใต้ `budget/USER_ID/ANALYSIS_ID/`
8. กดดาวน์โหลดผล Excel และเปิดตรวจข้อมูล

## 6. ข้อจำกัด Render Free ที่ต้องรู้

- เมื่อไม่มีคนใช้ Render จะพัก Container แต่ PostgreSQL และ Supabase Storage ไม่ถูกลบ
- งาน Budget ที่ถูกขัดจังหวะจะกลับเข้า Queue เมื่อ Container ตื่น
- Ollama ไม่ได้ติดตั้งใน Container; ระบบออนไลน์จะใช้ TF-IDF Vector Search ที่ประหยัด RAM และยังส่งออก Excel รูปแบบเดิม
- เครื่อง Local ยังคงใช้ Sentence Transformer เดิมได้ด้วย `REDBOOK_EMBEDDING_BACKEND=transformer`
- หากไฟล์ Excel ใหญ่มากจน RAM 512 MB ไม่พอ ต้องย้าย Budget Worker ไปบริการที่มี RAM มากขึ้น โดยไม่ต้องเปลี่ยนฐานข้อมูลหรือไฟล์ใน Supabase
