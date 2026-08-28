# Valor ERP — Laravel API + Vue

ระบบ ERP สำหรับ Sale, Approver, Admin และ Super Admin เชื่อม PostgreSQL บน Supabase

## โครงสร้างหลัก

```text
backend/                 Laravel API และ business logic
  app/                   Controllers, Models, Services, Middleware
  resources/             ฟอนต์ โลโก้ และข้อมูลจังหวัดที่ Backend ใช้
  scripts/               OCR และ Budget Analysis Python adapters
  tests/                 Backend automated tests
frontend/                Vue 3 + TypeScript SPA
  src/views/             หน้าเว็บแยกตามเมนู
  src/components/        UI ที่ใช้ร่วมกัน
  src/api/               API client
database/schema/         SQL ที่ใช้ปรับ schema Supabase
docs/                    คู่มือโครงสร้างและปรับแต่งหน้าเว็บ
scripts/                 คำสั่งเปิดและหยุดระบบ Local
```

รายละเอียดว่าแก้ฟีเจอร์แต่ละส่วนที่ไฟล์ใด ดูที่ [`docs/file-guide.md`](docs/file-guide.md)

รายการหน้าที่แบบหนึ่งบรรทัดต่อทุกไฟล์จริง รวม dependency และไฟล์ generated ดูที่ [`docs/FILE_SCOPE_ALL_FILES.md`](docs/FILE_SCOPE_ALL_FILES.md) และสร้างใหม่ได้ด้วย `php scripts/generate-file-scope.php`

## เปิดระบบ Local

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
```

- Web: http://127.0.0.1:5173
- API health: http://127.0.0.1:8000/api/v1/health

หยุดระบบ:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\stop-local.ps1
```

## ทดสอบ

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\verify-project.ps1
```

ก่อน deploy รุ่นที่มี Password Reset, PO Versioning, Revision, การยกเลิกเอกสาร, PNG ลายเซ็นแบบ Version และหลักฐานลายมือชื่ออิเล็กทรอนิกส์ ให้รัน [`database/schema/production_readiness_workflow.sql`](database/schema/production_readiness_workflow.sql) ใน Supabase SQL Editor หนึ่งครั้งก่อน แล้วจึง push/deploy โค้ด ดูหลักการใช้งานที่ [`docs/ETDA_ELECTRONIC_SIGNATURE_WORKFLOW.md`](docs/ETDA_ELECTRONIC_SIGNATURE_WORKFLOW.md)

หากติดตั้ง Python ใหม่สำหรับเครื่อง Local ให้ใช้ `pip install -r backend/requirements-budget-local-ai.txt` ส่วน Docker/Render ใช้ชุดประหยัด RAM จาก `backend/requirements-budget.txt`

ค่าฐานข้อมูลและ secret อยู่ใน `backend/.env` และ `backend/.secrets/` ซึ่งถูก Git ignore ห้ามนำ secret ไปใส่ใน Frontend
