# คู่มือแก้หน้าตาเว็บและเอกสาร PDF

คู่มือนี้รวมตำแหน่งที่แก้บ่อย เพื่อให้ปรับหน้าตาได้โดยไม่ต้องไล่อ่านทั้งโปรเจกต์

## เปลี่ยนสีและรูปแบบทั้งเว็บไซต์

ไฟล์หลักคือ `frontend/src/style.css`

ส่วน `:root` บนสุดของไฟล์เป็นชุดสีกลาง:

- `--brand` สีแดงหลักของปุ่ม เมนู และ focus
- `--brand-dark` สีแดงเข้มเมื่อ hover
- `--brand-soft` สีพื้นหลังแดงอ่อน
- `--line-strong` สีเส้นขอบช่องกรอก
- `--field-bg` สีพื้นช่องกรอกปกติ
- `--field-readonly` สีช่องที่อ่านอย่างเดียว
- `--section-bg` สีพื้นของกลุ่มข้อมูล
- `--section-border` สีเส้นรอบกลุ่มข้อมูล
- `--sidebar` สี sidebar

ค้นหา selector ต่อไปนี้เมื่อต้องการแก้เฉพาะส่วน:

| Selector | สิ่งที่ควบคุม |
|---|---|
| `input, select, textarea` | ช่องกรอกทั้งหมด |
| `input:focus, select:focus, textarea:focus` | สีเมื่อกำลังกรอก |
| `.content-card` | กล่องเนื้อหาหลัก |
| `.form-section-title` | แถบหัวข้อแบ่งช่วงข้อมูล |
| `.form-section-block` | กล่องครอบสินค้า/คลัง |
| `.primary-button` | ปุ่มหลัก |
| `.secondary-button` | ปุ่มรอง |
| `.danger-button` | ปุ่มลบ/ยกเลิก |
| `.status-pill` | ป้ายสถานะ |
| `.sidebar`, `.nav a.router-link-active` | Sidebar และเมนูที่เลือก |

หัวโลโก้ของแต่ละพื้นที่แยก CSS ออกจากกัน:

| Selector | พื้นที่ |
|---|---|
| `.login-brand`, `.login-brand__logo` | หน้า Login เท่านั้น (ใช้ `valorhealth.png`) |
| `.sidebar-brand`, `.sidebar-brand__logo` | Sidebar หลังเข้าสู่ระบบเท่านั้น |
| `.portal-brand`, `.portal-brand__mark` | Customer Document Portal เท่านั้น |

การแก้ขนาดโลโก้ Sidebar จึงไม่กระทบหน้า Login หรือหน้าลูกค้า

## แก้ข้อความหรือลำดับข้อมูลของแต่ละหน้า

ไฟล์หน้าเว็บอยู่ใน `frontend/src/views`:

- `SaleOrderView.vue` หน้าเปิดบิล/ใบเสนอราคา
- `EditBillView.vue` หน้าแก้ไขบิล
- `DocumentCenterView.vue` หน้าดาวน์โหลด PDF
- `SystemAdminView.vue` หน้า Admin สำหรับ Export Bplus/PDF, ปิดงาน และแก้ข้อมูลต้นทาง
- `ApprovalsView.vue` หน้า Approve
- `DashboardView.vue` หน้า Dashboard
- `PublicTaxView.vue` หน้า Customer Portal

คลาสที่ใช้แบ่งฟอร์มให้อ่านง่าย:

```html
<div class="span-2 form-section-title">
  <h2>1. ชื่อช่วงข้อมูล</h2>
</div>

<div class="span-2 form-section-block">
  <!-- ช่องกรอกในกลุ่มเดียวกัน -->
</div>
```

## แก้หน้าตา PDF

ไฟล์ `backend/app/Services/Documents/DocumentService.php` รับผิดชอบ PDF และ Excel:

- เมธอด `billPdf()` เตรียมข้อมูลใบกำกับภาษี
- เมธอด `quotationPdf()` เตรียมข้อมูลใบเสนอราคา
- เมธอด `renderPdf()` มี HTML/CSS ของเอกสาร
- เมธอด `quotationDownloadName()` และ `billDownloadName()` ตั้งชื่อไฟล์เป็น `ประเภทเอกสาร_ชื่อลูกค้า_วันที่.pdf`
- เมธอด `documentNumber()` สร้างเลขเอกสาร
- เมธอด `bplusWorkbook()` สร้าง Excel Bplus

ไฟล์ประกอบ PDF:

- `backend/resources/assets/fonts/THSarabunNew.ttf` ฟอนต์ภาษาไทยสำหรับ PDF
- `backend/resources/assets/images/valorhealth.png` โลโก้สำหรับเอกสาร Backend
- `frontend/public/valorhealth.png` โลโก้สำหรับหน้าเว็บ
- `backend/storage/app/private/dompdf` font cache/temp ที่สร้างอัตโนมัติ

ปุ่ม PDF ในหน้าเว็บเรียกผ่าน:

- `frontend/src/api/client.ts` ฟังก์ชัน `downloadFile()`
- `frontend/src/views/DocumentCenterView.vue`
- `frontend/src/views/SaleOrderView.vue`
- `frontend/src/views/PublicTaxView.vue`

API ที่ส่ง PDF อยู่ใน:

- `backend/routes/api.php`
- `QuotationController::pdf()`
- `BillingWorkflowController::pdf()`
- `PublicTaxController::pdf()`

## ลำดับตรวจหลังแก้เอง

1. รัน `pnpm run build` ใน `frontend` หลังแก้ Vue/CSS
2. รัน `php artisan test` ใน `backend` หลังแก้ PHP
3. เปิด localhost แล้วทดลองด้วย Role ที่มีสิทธิ์หน้านั้น
4. ถ้า PDF มีปัญหา ดู `backend/storage/logs/laravel.log`

อย่าแก้ไฟล์ใน `frontend/node_modules`, `frontend/dist` หรือ `backend/vendor` เพราะถูกสร้างใหม่และการแก้จะหายเมื่ออัปเดต dependencies
