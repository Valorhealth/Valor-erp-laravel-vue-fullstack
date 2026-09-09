# Call Sale Report — ขอบเขตข้อมูลและสูตรรายงาน

## หลักการออกแบบ

ระบบเก็บข้อมูลแบบ **หนึ่งกิจกรรมต่อหนึ่งแถว** และผูกเจ้าของจากบัญชีที่ล็อกอิน ไม่ให้ Sale เลือกชื่อผู้กรอกเอง หน้า Sale เป็นตารางรายวันตามแม่แบบ `Report_Call plan template - BEER.xlsx` และบันทึกทุกแถวของวันนั้นพร้อมกัน ส่วน Approve, Admin และ Super Admin เป็นผู้ดู Dashboard, Raw Data และไฟล์ส่งออก

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

- **Total Call** = จำนวน activity ตามตัวกรอง (หนึ่งแถวเท่ากับหนึ่ง Call)
- **ลูกค้าที่ติดต่อ** = จำนวนชื่อลูกค้าไม่ซ้ำ โดยตัดช่องว่างและไม่แยกตัวพิมพ์เล็ก/ใหญ่
- **Outcome Success / Failure / No Call** = จำนวนกิจกรรมแต่ละผลลัพธ์ตามตัวกรอง
- **Success Rate** = Outcome `Success` ที่มี Status `ทำงาน` ÷ Total Call × 100
- **Working Day** = จำนวนวันที่ไม่ซ้ำที่มี Status `ทำงาน`
- **Call / Working Day** = Total Call ÷ Working Day
- **ติดตามครบกำหนด** = มี F/U Date น้อยกว่าหรือเท่ากับวันนี้และ Status `ทำงาน`

Success Rate คำนวณระดับกิจกรรม ไม่ใช่จำนวน Opportunity ที่ไม่ซ้ำ หากภายหลังต้องการ Win Rate ต่อโครงการ ควรเพิ่ม `opportunity_id` แยกต่างหาก

## สิทธิ์และไฟล์ส่งออก

- Sale: เห็นเฉพาะตารางรายวันและตารางประวัติของตน เพิ่มแถว เปลี่ยนวัน บันทึก และเลือกช่วงวันที่ของประวัติได้ แต่เปิด Report/Export ของทีมไม่ได้
- Approve และ Admin: ดู กรอง และส่งออกข้อมูลทั้งทีม แต่แก้ไขหรือลบข้อมูล Call ของ Sale ไม่ได้
- Approve และ Admin: จัดการ Dropdown ส่วนกลางทั้ง 7 หมวด ได้แก่ Specialty, Department, Group, Product-1, Call Objective, Call Outcome และ Status โดยเพิ่ม เปลี่ยนชื่อ จัดลำดับ หรือปิดใช้งานได้
- Super Admin: สิทธิ์เดียวกับ Approve สำหรับกรณีฉุกเฉิน
- Excel: Sheet ภาพรวม + Raw Data พร้อม Auto Filter
- Dashboard ของ Approve: Sales Performance, Outcome/Objective, Product & Specialty, Product × Customer และ Success/Failure Breakdown ซึ่งถอดโครงสร้างจาก `Report BKK Call Sale Avanos.pbix`
- รูปแบบกราฟ: Bubble/Scatter สำหรับผลงาน Sale, Donut สำหรับ Outcome และ Specialty, Line + Area สำหรับแนวโน้ม, Treemap สำหรับสินค้า และ 100% Stacked สำหรับ Objective × Outcome
- Dashboard PDF: ส่งออก KPI, Donut, Bubble, Trend, Product Treemap, Specialty, Objective × Outcome และตารางสรุปตามตัวกรองเดียวกับหน้าเว็บ พร้อมฟอนต์ภาษาไทย

## ไฟล์หลัก

- `database/schema/sales_call_reports.sql` — ตารางและ index
- `database/schema/sales_call_lookup_options.sql` — ตารางและค่าเริ่มต้น Dropdown ส่วนกลาง (รันหลัง `sales_call_reports.sql`)
- `backend/app/Http/Controllers/Api/SalesCallController.php` — API และ validation
- `backend/app/Services/SalesCalls/SalesCallReportService.php` — สิทธิ์ ตัวกรอง KPI และ export
- `backend/app/Services/SalesCalls/SalesCallDashboardPdf.php` — สร้าง Dashboard PDF พร้อมกราฟและตารางหลายหน้า
- `frontend/src/views/SalesCallReportView.vue` — ตารางรายวันของ Sale และหน้า Report ของ Approve
