# Screenshot Index

**วันที่ตรวจ:** 2026-10-20  
**แหล่งข้อมูล:** `screenshot-manifest.csv` และไฟล์ใน `screenshots/`  
**ขนาดภาพ:** 1440 × 1000 px

## สรุปชุดหลักฐาน

| รายการ | จำนวน / ผล |
| --- | ---: |
| ไฟล์ PNG ที่เปิดตรวจแล้ว | 38 |
| เนื้อหาภาพไม่ซ้ำกัน (SHA-256) | 37 |
| ผลล่าสุด Success | 37 |
| ผลล่าสุด Blocked | 1 |
| ภาพเก่าที่ Superseded | 1 |
| Export แบบ binary ที่ไม่ต้องมี Screenshot | 1 (XLSX) |
| สถานะที่ยังไม่ทดสอบใน Manifest | 0 |

ทุกไฟล์ที่ระบุใน Manifest มีอยู่และถอดรหัส PNG ได้ ภาพซ้ำ `import-form.png` / `import-history.png` เป็นผลจากหน้า Import ที่รวมประวัติไว้ด้วยกัน และฐานทดสอบมีประวัติเพียงรายการเดียว จึงได้ภาพเหมือนกันโดยตั้งใจ; ดูคำอธิบายในแถว employee-006.

## ดัชนีภาพ

### ทั่วไป (ก่อนเข้าสู่ระบบ)

#### Authentication / เข้าสู่ระบบ

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| shared-001 | [login.png](../shared/login.png) | ทั่วไป (ก่อนเข้าสู่ระบบ) | Authentication / เข้าสู่ระบบ | หน้าเข้าสู่ระบบจริง | SUCCESS | HTTP 200; ภาพนี้ไม่ส่งข้อมูลล็อกอิน. |

### Admin

#### Dashboard

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-001 | [admin-dashboard.png](../admin/admin-dashboard.png) | Admin | Dashboard | ภาพรวม Dashboard ของ Admin | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### จัดการพนักงาน

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-002 | [employee-list.png](../admin/employee-list.png) | Admin | จัดการพนักงาน | รายการพนักงาน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-003 | [employee-create.png](../admin/employee-create.png) | Admin | จัดการพนักงาน | แบบฟอร์มเพิ่มพนักงาน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-004 | [employee-edit.png](../admin/employee-edit.png) | Admin | จัดการพนักงาน | แบบฟอร์มแก้ไขพนักงานสังเคราะห์ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### บัญชีผู้ใช้

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-005 | [account-list.png](../admin/account-list.png) | Admin | บัญชีผู้ใช้ | รายการบัญชีผู้ใช้ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-006 | [account-create.png](../admin/account-create.png) | Admin | บัญชีผู้ใช้ | แบบฟอร์มเพิ่มบัญชี | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-007 | [account-edit.png](../admin/account-edit.png) | Admin | บัญชีผู้ใช้ | แบบฟอร์มแก้ไขบัญชีทดสอบ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### หมวดหมู่ KPI

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-008 | [category-list.png](../admin/category-list.png) | Admin | หมวดหมู่ KPI | รายการหมวดหมู่ KPI | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-009 | [category-create.png](../admin/category-create.png) | Admin | หมวดหมู่ KPI | แบบฟอร์มเพิ่มหมวดหมู่ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-010 | [category-edit.png](../admin/category-edit.png) | Admin | หมวดหมู่ KPI | แบบฟอร์มแก้ไขหมวดหมู่สังเคราะห์ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### จัดการ KPI

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-011 | [kpi-list.png](../admin/kpi-list.png) | Admin | จัดการ KPI | รายการ KPI | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-012 | [kpi-create.png](../admin/kpi-create.png) | Admin | จัดการ KPI | แบบฟอร์มเพิ่ม KPI | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-013 | [kpi-edit.png](../admin/kpi-edit.png) | Admin | จัดการ KPI | แบบฟอร์มแก้ไข KPI สังเคราะห์ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### รอบประเมิน

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-014 | [evaluation-period-list.png](../admin/evaluation-period-list.png) | Admin | รอบประเมิน | รายการรอบประเมินและสถานะ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-015 | [evaluation-period-create.png](../admin/evaluation-period-create.png) | Admin | รอบประเมิน | แบบฟอร์มสร้างรอบประเมิน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-016 | [evaluation-period-edit.png](../admin/evaluation-period-edit.png) | Admin | รอบประเมิน | แบบฟอร์มแก้ไขรอบประเมิน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### มอบหมาย KPI

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-017 | [assignment-list.png](../admin/assignment-list.png) | Admin | มอบหมาย KPI | รายการมอบหมาย KPI | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-018 | [assignment-edit.png](../admin/assignment-edit.png) | Admin | มอบหมาย KPI | แบบฟอร์มแก้ไขการมอบหมาย | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### สรุปผล KPI

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| admin-019 | [summary.png](../admin/summary.png) | Admin | สรุปผล KPI | Dashboard สรุปผลการประเมิน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| admin-020 | [summary-pdf.png](../admin/summary-pdf.png) | Admin | สรุปผล KPI | PDF สรุปผล Admin ที่เปิดแสดงใน Viewer | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

### Manager

#### Dashboard

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| manager-001 | [manager-dashboard.png](../manager/manager-dashboard.png) | Manager | Dashboard | ภาพรวม Dashboard ของ Manager | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### แผนก

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| manager-002 | [department-comparison.png](../manager/department-comparison.png) | Manager | แผนก | เปรียบเทียบผลรายแผนก | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| manager-003 | [department-month-detail.png](../manager/department-month-detail.png) | Manager | แผนก | รายละเอียดผลรายเดือนของแผนก | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### จัดการพนักงาน

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| manager-004 | [employee-ranking.png](../manager/employee-ranking.png) | Manager | จัดการพนักงาน | ตารางจัดอันดับพนักงาน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| manager-005 | [employee-detail.png](../manager/employee-detail.png) | Manager | จัดการพนักงาน | รายละเอียดพนักงานสังเคราะห์ในมุมมอง Manager | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### Feedback

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| manager-006 | [feedback-dialog.png](../manager/feedback-dialog.png) | Manager | Feedback | หน้าต่าง Feedback; ไม่ส่งแบบฟอร์ม | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

### Employee

#### Dashboard

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-001 | [employee-dashboard.png](../employee/employee-dashboard.png) | Employee | Dashboard | ภาพรวม KPI ของ Employee สังเคราะห์ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### ผลการปฏิบัติงาน

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-002 | [performance-month.png](../employee/performance-month.png) | Employee | ผลการปฏิบัติงาน | ผลการปฏิบัติงานรายเดือน | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| employee-003 | [evidence-expanded.png](../employee/evidence-expanded.png) | Employee | ผลการปฏิบัติงาน | รายการหลักฐานสังเคราะห์ที่เปิดแสดง | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### รายละเอียด KPI

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-007 | [kpi-detail.png](../employee/kpi-detail.png) | Employee | รายละเอียด KPI | รายละเอียด KPI และผลประเมินของ Employee สังเคราะห์ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### นำเข้าและประวัติ

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-004 | [import-form.png](../employee/import-form.png) | Employee | นำเข้าและประวัติ | หน้า Import และสถานะ KPI ที่พร้อมนำเข้า | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |
| employee-005 | [import-preview-validation.png](../employee/import-preview-validation.png) | Employee | นำเข้าและประวัติ | ผลตรวจไฟล์รอบก่อนที่ติดปัญหารหัสพนักงาน | SUPERSEDED (ภาพเดิม) | ภาพเดิมเก็บไว้เพื่อเปรียบเทียบ; สถานะภาพเดิมถูก supersede โดย employee-012. |
| employee-006 | [import-history.png](../employee/import-history.png) | Employee | นำเข้าและประวัติ | แผงประวัติ Import ซึ่งอยู่ในหน้าเดียวกับฟอร์ม | SUCCESS | ภาพเหมือน import-form.png ทุกพิกเซล เพราะหน้า Import รวมประวัติไว้ในหน้าเดียวและฐานทดสอบมีประวัติ 1 รายการ. |
| employee-012 | [import-preview-mapping-blocked.png](../employee/import-preview-mapping-blocked.png) | Employee | นำเข้าและประวัติ | ผลตรวจ Import ด้วยสำเนาที่ใช้รหัส Employee ทดสอบตรงกัน | BLOCKED | Blocked: KPI 14/9 ไม่ผ่านกฎหัวตาราง, KPI 8 ไม่มีในฐานทดสอบ; READY 0, REJECTED 3. ไม่มีการยืนยันนำเข้า. |

#### Feedback

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-008 | [feedback-list.png](../employee/feedback-list.png) | Employee | Feedback | รายการ Feedback ที่ Employee ได้รับ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### ข้อมูลส่วนตัว

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-009 | [profile.png](../employee/profile.png) | Employee | ข้อมูลส่วนตัว | ข้อมูล Profile ของ Employee สังเคราะห์ | SUCCESS | จับภาพจาก Session ของ Role ทดสอบ; ไม่ส่งฟอร์มที่เปลี่ยนข้อมูล. |

#### Export

| ลำดับ | ไฟล์ | Role | Module | จุดประสงค์ | ผล QA | หมายเหตุ |
| ---: | --- | --- | --- | --- | --- | --- |
| employee-010 | ไม่มีภาพ (ดาวน์โหลดไฟล์) | Employee | Export | ผล XLSX Export (ไฟล์ดาวน์โหลด ไม่ใช่หน้าจอ) | SUCCESS | HTTP 200, MIME เป็น XLSX และผ่าน ZIP integrity check; ไม่มีภาพเพราะเป็นไฟล์ดาวน์โหลด. |
| employee-011 | [performance-pdf-page-1.png](../employee/performance-pdf-page-1.png) | Employee | Export | PDF Export ที่ตรวจแล้วว่ามี 3 หน้า | SUCCESS | HTTP 200; PDFKit ตรวจได้ 3 หน้า. ภาพเป็นหน้า 1/3 และข้อมูลตรงกับฐานทดสอบ. |

## ผลตรวจสิทธิ์ (ไม่มีภาพ Error แยก)

| บัญชีทดสอบ | Employee Performance / KPI detail | Employee PDF/XLSX โดยตรง |
| --- | --- | --- |
| Admin | Redirect ไปหน้า Admin (302) | HTTP 403 |
| Manager | Redirect ไปหน้า Manager (302) | HTTP 403 |
| Employee | HTTP 200; เห็นข้อมูลตาม Employee ID ใน Session | HTTP 200 |

Manager ยังเปิดรายละเอียดพนักงานผ่านหน้ารายชื่อพนักงานของ Manager ได้ตามสิทธิ์เดิม (HTTP 200). การเปลี่ยน `employee_id` ใน URL ก่อนเพิ่ม Role Check ไม่เปลี่ยนข้อมูลในหน้า Performance; การทดสอบนี้ครอบคลุมเฉพาะบัญชีสังเคราะห์และเส้นทางที่ระบุ จึงไม่ใช่ข้อสรุปว่าระบบปลอดภัยครบทุกกรณี.

ไม่มีหน้า Approval แยกในเส้นทาง Admin/Manager ที่ตรวจพบ; สถานะผลประเมินแสดงใน Admin Summary และ Manager Employee detail. หลัง Role Check หน้า Employee จะ redirect ตาม Role และ Export ตอบ HTTP 403 จึงไม่มีหน้า Access Denied แยกให้จับภาพ.

## สถานะที่ยัง Blocked

Import Preview ยังคง Blocked ตาม employee-012: KPI 14 และ KPI 9 ไม่ผ่านกฎหัวตารางที่ยืนยันไว้, KPI 8 ไม่มีใน schema ทดสอบ, มีรายการพร้อมนำเข้า 0 รายการ และไม่มีการยืนยันนำเข้า. ภาพเก่า employee-005 คงไว้ในชุดเพื่อเทียบ แต่สถานะของภาพนั้นเป็น Superseded.

## ขอบเขตการตรวจรับ

ภาพหน้าจอเป็นหลักฐานการแสดงผล ไม่ได้ยืนยันการส่งแบบฟอร์ม CRUD, การอนุมัติ, การส่ง Feedback, การแนบ/ลบไฟล์ หรือการยืนยันนำเข้า. หน้า Import ยัง Blocked; XLSX/PDF ตรวจจาก response และไฟล์จริง. รายละเอียดฉบับเต็มอยู่ใน [screenshot-report.md](screenshot-report.md), รายการสถานะต้นทางอยู่ใน [screenshot-manifest.csv](screenshot-manifest.csv).
