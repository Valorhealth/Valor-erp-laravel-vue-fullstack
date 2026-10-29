# Valor ERP — Laravel API + Vue

> ตรวจทานล่าสุด: 29 ตุลาคม 2569

ระบบ ERP สำหรับ Sale, Approver, Admin และ Super Admin เชื่อม PostgreSQL และ Storage บน Supabase ครอบคลุมใบเสนอราคา/เปิดบิล, การอนุมัติ, การยืนยันจากลูกค้า, ศูนย์เอกสาร, Export Bplus/PO, Call Sale Report, เครดิตลูกค้า, ตารางงาน และการจองเครื่อง FibroScan

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

เอกสารปัจจุบันที่ควรอ่านก่อนดูแลระบบ:

- [`docs/SYSTEM_OVERVIEW_AND_WORKFLOW.md`](docs/SYSTEM_OVERVIEW_AND_WORKFLOW.md) — โมดูล สิทธิ์ และ workflow ปัจจุบัน
- [`docs/BACKUP_AND_RESTORE.md`](docs/BACKUP_AND_RESTORE.md) — นโยบายสำรองข้อมูลวันละ 2 รอบและการทดสอบกู้คืน
- [`docs/RENDER_DEPLOY_STEP_BY_STEP.md`](docs/RENDER_DEPLOY_STEP_BY_STEP.md) — Deploy และตรวจระบบบน Render
- [`docs/CALL_SALE_REPORT.md`](docs/CALL_SALE_REPORT.md) — โครงสร้างข้อมูล สูตร และการ Import Call Sale
- [`docs/ETDA_ELECTRONIC_SIGNATURE_WORKFLOW.md`](docs/ETDA_ELECTRONIC_SIGNATURE_WORKFLOW.md) — หลักฐานการอนุมัติและลายมือชื่ออิเล็กทรอนิกส์

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

ก่อน deploy รุ่นที่ผูกเอกสารและตารางงานกับ Account แบบถาวร พร้อมยุบ Timeline ไว้ใน `sales_tasks` ให้รัน [`database/schema/workflow_identity_cleanup.sql`](database/schema/workflow_identity_cleanup.sql) ใน Supabase SQL Editor หนึ่งครั้ง ไฟล์นี้จะรักษาประวัติเดิมไว้ใน JSONB, ผูกบิล/ใบเสนอราคา/ผู้รับมอบหมายด้วย `user_id`, ลบตาราง Timeline เก่าหลังย้ายข้อมูลสำเร็จ และรวมสถานะเดิมเป็น `รอแอดมินส่งออก`

ก่อน deploy รุ่นตารางจองเครื่อง FibroScan ให้รัน [`database/schema/fibroscan_booking.sql`](database/schema/fibroscan_booking.sql) หนึ่งครั้ง ไฟล์นี้สร้างทะเบียนเครื่อง ตารางจอง และ PostgreSQL Exclusion Constraint สำหรับป้องกันการจองเครื่องเดียวกันในช่วงเวลาซ้อนกัน

ก่อน deploy รุ่น Quotation Document Editor ให้รัน migration ทั้งหมดด้วย `php artisan migrate --force` ตามลำดับ ระบบปัจจุบันรองรับ Draft Autosave, PDF Revision/Snapshot/SHA-256, Template โรงพยาบาลและมาตรฐาน, ตำแหน่งโลโก้/หัวเรื่อง, รูปสินค้า, ตารางและส่วนสรุปราคาที่ซ่อนได้ รวมถึง Draft หนังสือมอบอำนาจสำหรับผู้มีสิทธิ์จัดเตรียมเอกสาร

ตารางใหม่ทุกตารางต้องกำหนดสิทธิ์ Supabase Data API ตามมาตรฐานใน [`database/schema/supabase_new_table_template.sql`](database/schema/supabase_new_table_template.sql) และตรวจด้วย [`database/schema/supabase_data_api_audit.sql`](database/schema/supabase_data_api_audit.sql)

Local หรือ Supabase Session Pooler พอร์ต `5432` ให้ใช้ `DB_PERSISTENT=false` ตามเดิม ส่วน Render ที่เชื่อมผ่าน Transaction Pooler พอร์ต `6543` ตั้ง `DB_PERSISTENT=true` เพื่อให้ PHP-FPM นำ client connection กลับมาใช้ซ้ำ โดย `pm.max_children=5` จำกัดจำนวน connection ค้างไว้ไม่เกิน 5 รายการ หากเปลี่ยน `DB_URL` กลับไปใช้พอร์ต `5432` ต้องเปลี่ยนค่านี้กลับเป็น `false`

หากติดตั้ง Python ใหม่สำหรับเครื่อง Local ให้ใช้ `pip install -r backend/requirements-budget-local-ai.txt` ส่วน Docker/Render ใช้ชุดประหยัด RAM จาก `backend/requirements-budget.txt`

ค่าฐานข้อมูลและ secret อยู่ใน `backend/.env` และ `backend/.secrets/` ซึ่งถูก Git ignore ห้ามนำ secret ไปใส่ใน Frontend

## การสำรองข้อมูล

Production ต้องสำรองฐานข้อมูลและไฟล์ Storage วันละ 2 รอบ เก็บอย่างน้อย 2 ตำแหน่งที่แยกจากระบบจริง ได้แก่พื้นที่ของบริษัทและ Google Cloud Storage หรือพื้นที่ Cloud ที่บริษัทอนุมัติ ทุกชุดต้องตรวจขนาดไฟล์/Checksum และทดสอบ Restore อย่างน้อยเดือนละครั้ง รายละเอียดอยู่ใน [`docs/BACKUP_AND_RESTORE.md`](docs/BACKUP_AND_RESTORE.md)
