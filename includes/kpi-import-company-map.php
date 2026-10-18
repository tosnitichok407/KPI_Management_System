<?php

/*
|--------------------------------------------------------------------------
| Mapping แบบฟอร์ม KPI AM ของบริษัท → KPI ในระบบ
|--------------------------------------------------------------------------
|
| ใช้กับ includes/kpi-import-company-reader.php เท่านั้น
|
| แต่ละแถวของแบบฟอร์มจับคู่ด้วย "ชื่อหัวข้อ" (ตัดเลขข้อ / ช่องว่างซ้ำ แล้ว) + ส่วน (performance / competency)
| และตรวจน้ำหนักในแบบฟอร์มกับ expected_weight เพื่อจับกรณีแบบฟอร์มเปลี่ยน
|
| ทุกรายการยังไม่ได้รับการยืนยันจากเจ้าของระบบ (confirmed = false, kpi_id = null)
| → ตัวอ่านจะแสดงสถานะ NEEDS_CONFIRMATION และไม่ส่งข้อมูลเข้าสู่การนำเข้า
|
| ขั้นตอนยืนยัน (Competency):
|   1. เจ้าของระบบ/Admin เปิดหน้า "จัดการ KPI" ของระบบจริง จดรหัส KPI และชื่อ KPI ตามที่แสดงทุกตัวอักษร
|      แล้วเปิดหน้าแก้ไขของ KPI นั้นเพื่อยืนยันว่าประเภทเป็น Competency
|   2. ส่งแบบฟอร์มยืนยัน (หัวข้อในแบบฟอร์มบริษัท → kpi_id + ชื่อ KPI ในระบบ + ผู้ยืนยัน + วันที่)
|   3. ผู้พัฒนากรอกค่าด้านล่างตามแบบฟอร์มยืนยันเท่านั้น:
|        kpi_id              รหัส KPI ในระบบ
|        confirmed_kpi_name  ชื่อ KPI ในระบบทุกตัวอักษร (ระบบตรวจซ้ำตอนอ่านไฟล์ ไม่ตรง → ไม่นำเข้า)
|        confirmed_by        ผู้ยืนยัน (บทบาท/ชื่อ)
|        confirmed_at        วันที่ยืนยัน (YYYY-MM-DD)
|        value_rule          "competency_integer" (คะแนน 1-5 ตามที่กรอก)
|        confirmed           true
|   4. ขณะอ่านไฟล์ ระบบตรวจทุกครั้งว่า KPI มีอยู่จริง เป็นประเภท Competency ชื่อตรงกับที่ยืนยัน
|      และพนักงานมี Assignment ที่ครอบคลุมเดือนนั้น ก่อนจะให้สถานะ READY
| Performance ยังไม่มีกฎแปลงค่าที่ยืนยัน (สัดส่วน / ร้อยละ / ค่าดิบ ขึ้นกับหน่วยและ Target) จึงยังนำเข้าไม่ได้
|   ยกเว้น "บรรลุเป้าหมายการต่อสัญญา" (ยืนยัน 2026-10-17): value_rule "renewal_total_r_over_budget_percent"
|     แหล่งข้อมูล = ชีต "สรุปทั้งปี.." แถวของแต่ละเดือน: Q = G/C (Total R / Budget, ไม่รวม New)
|     Actual = (Total R / Budget) × 100 ตัดทศนิยมลงเหลือ 2 ตำแหน่ง · Target ใน Assignment ต้องเป็น 100 (%)
|     คะแนนใช้เกณฑ์ของ KPI ในระบบ (kpiCalculateScore) ไม่ใช้เกรดจาก Excel
| KPI 9 (มูลค่าไม่ต่อสัญญา) ถูกแยกออกจากการนำเข้าเสมอในตัวอ่าน
| ห้ามกรอกค่าโดยการเดา และห้ามใช้ kpi_id จาก Seed / ฐานข้อมูลทดสอบ
|
| เปิดนำเข้า KPI ทีละตัว: ก่อนตั้ง confirmed = true ต้องผ่านครบ
|   1) ชื่อและ kpi_id  2) ประเภทและหน่วย  3) Target และรอบประเมิน  4) ชีต/เซลล์ต้นทาง
|   5) ตรวจสูตร Excel ทุกชั้น  6) ความสัมพันธ์ข้อมูลต้นทาง→ผลลัพธ์  7) ข้อมูลที่รวม/ตัดออก
|   8) วิธีแปลง Actual/Target  9) กฎคะแนนของระบบ  10) เงื่อนไขข้อมูลผิดปกติ
|   11) ทดสอบกับไฟล์บริษัทจริง  12) Preview ก่อนบันทึก
| ยังไม่เข้าใจข้อมูลหรือสูตรข้อใด → คง confirmed = false (ระบบไม่บันทึก KPI นั้น)
| KPI ที่ confirmed = true บันทึกได้จากไฟล์บริษัทเท่านั้น (Template ของระบบปฏิเสธ · kpiImportCompanyOnlyKpiIds)
|
*/

return [
    [
        /* ยืนยันแล้ว: Actual = Total R / Budget จากชีต "สรุปทั้งปี" (ดู KPI_COMPANY_RENEWAL_RULE ในตัวอ่าน) */
        "section" => "performance",
        "label" => "บรรลุเป้าหมายการต่อสัญญา",
        "expected_weight" => 30,
        "kpi_id" => 14,
        "value_rule" => "renewal_total_r_over_budget_percent",
        "confirmed_kpi_name" => "บรรลุเป้าหมายการต่อสัญญา",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-17",
        "confirmed" => true
    ],
    [
        "section" => "performance",
        "label" => "จำนวนรายการปรับเพิ่มมูลค่าสัญญา",
        "expected_weight" => 5,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "performance",
        "label" => "มูลค่าไม่ต่อสัญญา",
        "expected_weight" => 20,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "performance",
        "label" => "ขายเพิ่มผลิตภัณฑ์และบริการในลูกค้าเดิม",
        "expected_weight" => 10,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "performance",
        "label" => "เข้าเยี่ยมลูกค้าตามKPIที่บริษัทกำหนด",
        "expected_weight" => 5,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "performance",
        "label" => "อัตราคงค้างสัญญาตัวจริงนำกลับ",
        "expected_weight" => 10,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "competency",
        "label" => "ความรู้เชิงชีวของแมลงและการใช้สารเคมีอย่างถูกต้อง",
        "expected_weight" => 4,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "competency",
        "label" => "ความรู้ด้านสินค้าและผลิตภัณฑ์",
        "expected_weight" => 4,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "competency",
        "label" => "ทักษะด้านการสื่อสาร",
        "expected_weight" => 4,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "competency",
        "label" => "บริหารจัดการด้านข้อมูลและคอมพิวเตอร์",
        "expected_weight" => 4,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ],
    [
        "section" => "competency",
        "label" => "บุคลิกภาพที่ดี",
        "expected_weight" => 4,
        "kpi_id" => null,
        "value_rule" => null,
        "confirmed_kpi_name" => null,
        "confirmed_by" => null,
        "confirmed_at" => null,
        "confirmed" => false
    ]
];
