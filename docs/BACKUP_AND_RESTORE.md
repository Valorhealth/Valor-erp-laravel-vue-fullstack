# นโยบาย Backup และ Restore

> ตรวจทานล่าสุด: 29 ตุลาคม 2569

เอกสารนี้กำหนดภาพรวมการสำรองข้อมูล Valor ERP สำหรับการใช้งานจริง เป้าหมายคือสามารถกู้ฐานข้อมูล เอกสาร และไฟล์แนบกลับมาเป็นชุดข้อมูลที่สอดคล้องกันได้เมื่อระบบหรือผู้ให้บริการมีปัญหา

## ขอบเขตที่ต้องสำรอง

1. PostgreSQL/Supabase: Roles, Schema และ Data
2. Supabase Storage: เอกสารแนบ PO ใบเสนอราคา ไฟล์ Call Sale ต้นฉบับ และผลวิเคราะห์งบที่ต้องเก็บ
3. Source code และ migration: เก็บใน private Git repository พร้อม Release tag
4. Configuration inventory: รายการชื่อ Environment Variables, Owner และวิธีขอ/หมุน Secret โดยไม่คัดลอก Secret ลงเอกสารหรือ Git

`vendor`, `node_modules`, `dist`, cache และ log ชั่วคราวไม่ใช่ข้อมูลหลักของ Backup เพราะสร้างใหม่ได้จาก lockfile และขั้นตอน Deploy

## ตารางเวลา

- รอบที่ 1: 12:00 น. เวลา Asia/Bangkok
- รอบที่ 2: 00:00 น. เวลา Asia/Bangkok
- งาน Backup ต้องไม่ลบชุดล่าสุดที่ตรวจสอบผ่านจนกว่าชุดใหม่จะสำเร็จครบทั้งสองปลายทาง

## ตำแหน่งจัดเก็บ

| สำเนา | ตำแหน่ง | ข้อกำหนด |
|---|---|---|
| บริษัท | NAS หรือเครื่อง Backup ที่แยกจากเครื่องพัฒนาและ Production | จำกัดสิทธิ์ เข้ารหัส และไม่ Sync ลบทันทีตามต้นทาง |
| Cloud | Google Cloud Storage Bucket ของบริษัท หรือ Cloud ที่อนุมัติ | Private, Service Account สิทธิ์ต่ำสุด, Versioning/Retention และ Lifecycle |

ไม่ควรนับไฟล์ที่เก็บอยู่ในเครื่อง Production หรือโฟลเดอร์เดียวกับโปรเจกต์เป็นสำเนาแยก

## ขั้นตอนของแต่ละรอบ

1. สร้าง Dump ของ Roles, Schema และ Data ด้วย Supabase CLI/เครื่องมือ PostgreSQL เวอร์ชันที่รองรับ
2. สำรองไฟล์ใน Storage โดยรักษา path และ metadata ที่จำเป็น
3. ตรวจ exit code, ขนาดไฟล์ต้องมากกว่า 0 และจำนวนไฟล์ต้องไม่ลดลงผิดปกติ
4. สร้าง manifest ระบุเวลา ขนาด SHA-256 และสถานะของแต่ละส่วน
5. เข้ารหัสชุด Backup ก่อนส่งออกจากเครื่องที่สร้าง
6. ส่งสำเนาไปพื้นที่บริษัทและ Google Cloud Storage
7. ตรวจสอบว่า Upload สำเร็จทั้งสองที่ แล้วจึงบันทึกผลรอบนั้น
8. แจ้ง Admin ทันทีหากขั้นตอนใดล้มเหลวหรือขนาดผิดปกติ

## Retention เริ่มต้น

- รายวัน: 30 วัน
- รายสัปดาห์: 12 สัปดาห์
- สิ้นเดือน: 12 เดือน

บริษัทสามารถเพิ่มระยะเวลาได้ตามนโยบายบัญชี กฎหมาย และพื้นที่จัดเก็บ แต่การลบต้องใช้ Lifecycle/Retention ที่ตรวจสอบได้ ไม่ใช้การลบด้วยมือแบบไม่มีบันทึก

## การทดสอบ Restore

อย่างน้อยเดือนละครั้งให้กู้ลง Test Project ที่แยกจาก Production แล้วตรวจ:

- ตารางและจำนวนข้อมูลหลัก
- Login และ Role/Ability
- Workflow ใบเสนอราคา อนุมัติ ยืนยัน และ Export
- ไฟล์ PO เอกสารแนบ PDF และ Call Sale import
- Customer Address, เครดิต, สินค้า, หน่วย และรหัสพนักงานขาย
- การจอง FibroScan และทะเบียนเครื่อง

ห้ามทดสอบ Restore ทับ Production โดยตรง ผู้ทดสอบต้องบันทึกวันที่ ชุด Backup ผลการตรวจ ปัญหาที่พบ และผู้อนุมัติปิดการทดสอบ

## ผู้รับผิดชอบ

- System Owner: อนุมัตินโยบาย Retention และการกู้ Production
- Admin/Operator: ตรวจผล Backup รายวันและแก้เหตุล้มเหลว
- Technical Maintainer: ดูแล Script, สิทธิ์ Cloud, Encryption และ Restore drill
- Data Owner: ยืนยันความครบถ้วนของข้อมูลธุรกิจหลัง Restore
