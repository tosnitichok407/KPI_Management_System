# KPI Management System

ระบบจัดการและประเมินผล KPI ของพนักงาน พัฒนาด้วย PHP และ MySQL สำหรับใช้งานบน XAMPP โดยมีส่วนจัดการข้อมูลสำหรับผู้ดูแลระบบ และระบบเข้าสู่ระบบสำหรับผู้ใช้งาน

## ความสามารถหลัก

- เข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่าน
- ตรวจสอบสถานะบัญชีผู้ใช้และสถานะพนักงานก่อนเข้าสู่ระบบ
- จัดการข้อมูลพนักงาน
- จัดการบัญชีผู้ใช้และเปิด/ปิดการใช้งานบัญชี
- จัดการหมวดหมู่ KPI และรายการ KPI
- จัดการรอบการประเมิน
- บันทึกประวัติการเข้าสู่ระบบสำเร็จหรือล้มเหลว
- แยกสิทธิ์การเข้าถึงส่วนผู้ดูแลระบบด้วย `role_id`

## ความต้องการของระบบ

- macOS, Windows หรือ Linux
- XAMPP ที่มี Apache, PHP และ MySQL/MariaDB
- PHP ที่เปิดใช้งาน PDO และ `pdo_mysql`
- เว็บเบราว์เซอร์รุ่นปัจจุบัน

## การติดตั้งบน XAMPP

1. วางโฟลเดอร์โปรเจกต์ไว้ในโฟลเดอร์ `htdocs` ของ XAMPP เช่น
	`/Applications/XAMPP/xamppfiles/htdocs/KPI-System`
2. เปิด XAMPP และ Start **Apache** กับ **MySQL**
3. สร้างฐานข้อมูลชื่อ `kpi_management_system` ผ่าน phpMyAdmin หรือ MySQL CLI
4. นำเข้าโครงสร้างตารางและข้อมูลเริ่มต้นของระบบ หากมีไฟล์ database dump จากผู้พัฒนา
5. ตรวจสอบค่าการเชื่อมต่อใน [config/database.php](config/database.php)
6. เปิดระบบที่ `http://localhost/KPI-System/login.php`

ค่าเริ่มต้นในไฟล์เชื่อมต่อฐานข้อมูลคือ:

| ค่า | ค่าเริ่มต้น |
| --- | --- |
| Host | `localhost` |
| Database | `kpi_management_system` |
| Username | `root` |
| Password | ค่าว่าง |

ควรเปลี่ยน username/password ของฐานข้อมูลให้เหมาะสมกับสภาพแวดล้อมจริง และไม่ควรใช้บัญชี `root` ใน production

> ใน repository นี้ยังไม่มีไฟล์ schema หรือ database seed รวมมาให้ จึงต้องเตรียมตารางที่ระบบเรียกใช้ก่อน โดยอย่างน้อยประกอบด้วย `users`, `employees`, `roles`, `departments`, `positions` และ `login_logs`

## การใช้งาน

1. เปิดหน้า `login.php`
2. กรอก username และ password ของบัญชีที่มีอยู่ในตาราง `users`
3. บัญชีที่มี `role_id = 1` จะถูกส่งไปยังหน้า Admin Dashboard
4. ผู้ใช้งานบทบาทอื่นจะถูกส่งไปยัง `dashboard.php`
5. ออกจากระบบผ่าน [logout.php](logout.php)

## โครงสร้างโฟลเดอร์

```text
KPI-System/
├── admin/                 # หน้าและการจัดการสำหรับผู้ดูแลระบบ
│   ├── employees/         # จัดการพนักงาน
│   ├── evaluation/        # จัดการรอบการประเมิน
│   ├── kpi/               # จัดการ KPI
│   ├── kpi-categories/    # จัดการหมวดหมู่ KPI
│   └── user-accounts/     # จัดการบัญชีผู้ใช้
├── assets/                # CSS, JavaScript และรูปภาพ
├── config/                # การตั้งค่าระบบ เช่น ฐานข้อมูล
├── employee/              # ส่วนการใช้งานของพนักงาน
├── manager/               # ส่วนการใช้งานของผู้จัดการ
├── includes/              # ไฟล์ร่วม เช่น header, footer และ auth
├── login.php              # หน้าเข้าสู่ระบบ
├── login_process.php      # การประมวลผลการเข้าสู่ระบบ
├── dashboard.php          # Dashboard สำหรับผู้ใช้งาน
└── logout.php             # การออกจากระบบ
```

## ความปลอดภัยและการตั้งค่าเพิ่มเติม

- ใช้ prepared statements ของ PDO สำหรับคำสั่ง SQL
- ตั้งค่า `display_errors=Off` และบันทึก error ลง log เมื่อใช้งานจริง
- ใช้ HTTPS และตั้งค่า session cookie ให้ปลอดภัยใน production
- ตรวจสอบสิทธิ์ผู้ใช้ทุกครั้งก่อนเปิดหน้าใน `admin/`
- สำรองฐานข้อมูลเป็นประจำ

## สถานะการพัฒนา

โครงสร้างระบบและหน้า Admin บางส่วนมีอยู่แล้ว แต่หน้าใน `employee/` และ `manager/` รวมถึงฟังก์ชันบางรายการอาจยังอยู่ระหว่างพัฒนา ควรตรวจสอบลิงก์และ schema ของฐานข้อมูลให้ตรงกับ deployment ก่อนใช้งานจริง

## ผู้พัฒนา

KPI Management System สำหรับการจัดการผลการปฏิบัติงานขององค์กร
