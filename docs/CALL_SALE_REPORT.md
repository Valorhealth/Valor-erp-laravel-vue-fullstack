# Call Sale Report — ขอบเขตข้อมูลและสูตรรายงาน

## หลักการออกแบบ

ระบบเก็บข้อมูลแบบ **หนึ่งกิจกรรมต่อหนึ่งแถว** และผูกเจ้าของจากบัญชีที่ล็อกอิน ไม่ให้ Sale เลือกชื่อผู้กรอกเอง หน้า Sale แสดงตารางครั้งละครึ่งเดือนตามแม่แบบ `Report_Call plan template - BEER.xlsx` และบันทึกทุกวันในช่วงเดียวกันพร้อมกัน ส่วน Approve, Admin และ Super Admin เป็นผู้ดู Dashboard, Raw Data และไฟล์ส่งออก

แนวทางนี้สอดคล้องกับระบบ CRM มาตรฐานที่เก็บโทรศัพท์ อีเมล นัดหมาย และงานติดตามเป็น activity ซึ่งเชื่อมกับลูกค้า พร้อมเวลา ผู้สร้าง สถานะ และประวัติการติดต่อ:

- Microsoft Dynamics 365 — [Track and manage activities](https://learn.microsoft.com/en-us/dynamics365/sales/manage-activities)
- Microsoft Dynamics 365 — [Connect with customers through your work list](https://learn.microsoft.com/en-us/dynamics365/sales/connect-with-customers)
- Microsoft Dynamics 365 — [Gain insights with dashboards](https://learn.microsoft.com/en-us/dynamics365/sales/dashboards)
- Microsoft Dynamics 365 — [View overall insights of sales and sellers](https://learn.microsoft.com/en-us/dynamics365/sales/dynamics365-sales-insights-app-home-page)

## ข้อมูลที่ Sale กรอก

| กลุ่ม | ข้อมูล |
|---|---|
| วันและสถานที่ | Call No., Date ที่ล็อกตามวันที่เลือก และ Place of Work |
| ลูกค้า | Customer Name, Specialty, Department และ Group |
| กิจกรรม | Product-1, Call Objective, Call Detail (Obj) |
| ผลลัพธ์ | Call Outcome และ Call Detail (Out) |
| การติดตาม | F/U Date, Note, SM Comment และ Status |

Call No. เรียงใหม่ตั้งแต่ 1 แยกตาม Sale และวันที่ ระบบใช้ PostgreSQL Advisory Lock และ Unique Index ป้องกันเลขซ้ำ ตารางเปล่าที่ไม่มีข้อมูลสำคัญจะไม่ถูกสร้างเป็น Raw Data

## สูตร KPI

- **Total Call** = จำนวนแถวที่มี `SPECIALTY` และ `CALL OUTCOME` ไม่ใช่ `No Call` ตามตัวกรอง
- **ลูกค้าที่ติดต่อ** = จำนวนชื่อลูกค้าไม่ซ้ำ โดยตัดช่องว่างและไม่แยกตัวพิมพ์เล็ก/ใหญ่
- **Outcome Success / Failure / No Call** = จำนวนกิจกรรมแต่ละผลลัพธ์ตามตัวกรอง
- **Success Rate** = Outcome `Success` ที่มี Status `ทำงาน` ÷ Total Call × 100
- **Working Day** = จำนวนวันที่ไม่ซ้ำที่มีกิจกรรม Status `ทำงาน` โดยนับได้แม้แถวนั้นไม่มี Specialty
- **Call / Working Day** = Total Call ÷ Working Day
- **ติดตามครบกำหนด** = มี F/U Date น้อยกว่าหรือเท่ากับวันนี้และ Status `ทำงาน`

Success Rate คำนวณระดับกิจกรรม ไม่ใช่จำนวน Opportunity ที่ไม่ซ้ำ หากภายหลังต้องการ Win Rate ต่อโครงการ ควรเพิ่ม `opportunity_id` แยกต่างหาก

## สิทธิ์และไฟล์ส่งออก

- Sale: เห็นตารางครั้งละครึ่งเดือนและประวัติของตน เพิ่มแถว บันทึก แก้ไขรายการเดี่ยว หรือลบประวัติที่เลือกหลายรายการได้ แต่เปิด Report/Export ของทีมไม่ได้
- Approve และ Admin: ดู กรอง ส่งออก และใช้ Checklist รายเดือนเพื่อตรวจว่า Sale คนใดส่งข้อมูลแล้ว สามารถแก้ไข Call รายการเดี่ยว หรือลบข้อมูล Call ทั้งเดือนของ Sale ที่เลือกได้
- ไฟล์ Excel ต้นฉบับที่ Sale นำเข้าจะถูกเก็บแบบ private และดาวน์โหลดได้เฉพาะผู้มีสิทธิ์ Call Sale Report เพื่อใช้ตรวจสอบกิจกรรมอื่นที่ไม่มี Specialty; การลบ Call ทั้งเดือนไม่ลบไฟล์หลักฐานนี้
- Approve และ Admin: จัดการ Dropdown ส่วนกลางทั้ง 7 หมวด ได้แก่ Specialty, Department, Group, Product-1, Call Objective, Call Outcome และ Status โดยเพิ่ม เปลี่ยนชื่อ จัดลำดับ หรือปิดใช้งานได้
- Super Admin: สิทธิ์เดียวกับ Approve สำหรับกรณีฉุกเฉิน
- Excel: Sheet ภาพรวม + Raw Data พร้อม Auto Filter
- Dashboard ของ Approve: Sales Performance, Outcome/Objective, Product & Specialty, Product × Customer และ Success/Failure Breakdown ซึ่งถอดโครงสร้างจาก `Report BKK Call Sale Avanos.pbix`
- รูปแบบกราฟ: Bubble/Scatter สำหรับผลงาน Sale, Donut สำหรับ Outcome และ Specialty, Line + Area สำหรับแนวโน้ม, Treemap สำหรับสินค้า และ 100% Stacked สำหรับ Objective × Outcome
- Dashboard PDF: ส่งออก KPI, Donut, Bubble, Trend, Product Treemap, Specialty, Objective × Outcome และตารางสรุปตามตัวกรองเดียวกับหน้าเว็บ พร้อมฟอนต์ภาษาไทย

## ไฟล์หลัก

- `database/schema/sales_call_reports.sql` — ตาราง Call, index และทะเบียนไฟล์ Excel ต้นฉบับ (`sales_call_import_batches`)
- `database/schema/sales_call_lookup_options.sql` — ตารางและค่าเริ่มต้น Dropdown ส่วนกลาง (รันหลัง `sales_call_reports.sql`)
- `backend/app/Http/Controllers/Api/SalesCallController.php` — API และ validation
- `backend/app/Services/SalesCalls/SalesCallReportService.php` — สิทธิ์ ตัวกรอง KPI และ export
- `backend/app/Services/SalesCalls/SalesCallDashboardPdf.php` — สร้าง Dashboard PDF พร้อมกราฟและตารางหลายหน้า
- `frontend/src/views/SalesCallReportView.vue` — ตารางครึ่งเดือนของ Sale และหน้า Report ของ Approve

# การนำเข้า Call Sale จาก Excel

Sale สามารถนำเข้าไฟล์ `.xlsx` หรือ `.xls` จากหน้า **บันทึก Call Sale** โดยระบบรองรับทั้งไฟล์ที่แยกหนึ่งชีตต่อวันและไฟล์ตารางรวมหลายวัน ระบบค้นหาแถวหัวคอลัมน์จากชื่อคอลัมน์ ไม่ยึดตำแหน่งแถวตายตัว และรองรับชื่อ `CALL OBECTIVE` ที่สะกดตามไฟล์เดิม ระบบถือว่าแถวที่มีค่า `SPECIALTY` เป็นหนึ่ง Call; หาก Date ว่างจะใช้วันที่ล่าสุดจากแถวก่อนหน้า หรือวันที่จากชื่อชีต

ขั้นตอนนำเข้ามีสองช่วง:

1. เลือกไฟล์เพื่อดู Preview จำนวนรายการ ช่วงวันที่ คอลัมน์ที่ตรวจพบ คอลัมน์ที่ขาด ช่องสำคัญที่ว่าง แถวที่ไม่นำเข้า ค่าที่ระบบปรับให้ตรงกับ Dropdown และคำเตือน โดยทุกแถวที่มี Specialty ต้องมี PLACE OF WORK, CUSTOMER NAME, SPECIALTY, DEPARTMENT, GROUP, PRODUCT-1, CALL OBJECTIVE และ CALL OUTCOME ครบ ระบบจะแสดงวันที่ ชีต และเลขแถวที่ผิด พร้อมปิดปุ่มยืนยันจนกว่าจะแก้ไฟล์ครบ
2. กดยืนยันเพื่อนำเข้าทุกวันที่พบในไฟล์พร้อมกันทั้งเดือน โดยแทนที่ข้อมูลเดิมของ Sale คนนั้น **เฉพาะวันที่ที่อยู่ในไฟล์** วันอื่นจะไม่ถูกแก้ไข การนำเข้าไฟล์เดิมซ้ำจึงไม่สร้างรายการซ้ำ การแบ่งครึ่งเดือนใช้เฉพาะการแสดงผลและแก้ไขบนหน้าเว็บ ไม่ได้จำกัดขอบเขตการ Import

สำหรับไฟล์แบบชีตรายวันที่ไม่มีคอลัมน์ Date ระบบอ่านวันที่จากชื่อชีต `DDMMYY` หากชีตส่วนใหญ่เป็นเดือนเดียวกันแต่มีบางชื่อคลาดเคลื่อน ระบบจะใช้เดือนและปีหลักของสมุดงาน พร้อมแสดงคำเตือนในหน้า Preview

Status ไม่ใช่เงื่อนไขบังคับในการ Import หากไม่ระบุ ระบบอนุมานจาก Place of Work ตามกติกาเดิม โดย Call หรือกิจกรรมปกติถือเป็น `ทำงาน` แถวที่มีข้อมูลกิจกรรมแต่ไม่มี Specialty จะถูกเก็บเพื่อคำนวณ Working Day แต่ไม่เข้า KPI ของ Call ส่วนแถวที่มี Specialty และ Outcome เป็น `No Call` จะเก็บเป็นหลักฐานการติดต่อแต่ไม่รวมใน Total Call
