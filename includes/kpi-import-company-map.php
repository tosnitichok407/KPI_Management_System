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
|     แหล่งข้อมูล (เปลี่ยน 2026-10-29) = ชีต "สรุปทั้งปี69" เซลล์ R5 (มกราคม) … R16 (ธันวาคม) ตาม KPI_COMPANY_RENEWAL_CELLS ในตัวอ่าน
|     Actual = ค่าในเซลล์ × 100 ตัดทศนิยมลงเหลือ 2 ตำแหน่ง (ไม่คำนวณใหม่ ไม่หักขายเพิ่ม) · Target ใน Assignment ต้องเป็น 100 (%)
|     คะแนนใช้เกณฑ์ของ KPI ในระบบ (kpiCalculateScore) ไม่ใช้เกรดจาก Excel
|   และ "จำนวนรายการปรับเพิ่มมูลค่าสัญญา" (kpi_id 8 · ยืนยัน 2026-10-18): value_rule "contract_uplift_s_over_o_floor_percent"
|     แหล่งข้อมูล = ชีตของเดือนที่เลือก: ผลต่าง = R − O รายแถวที่ระบบคำนวณเอง (2026-10-29) · คอลัมน์ S / S<n+1> ใช้ตรวจเทียบ
|     Actual = FLOOR(S<n+1> × 100) จำนวนเต็ม · Target ใน Assignment ต้องเป็น 20 (%) · เกณฑ์คะแนน 1-5 ต้องครบ
|     หมายเหตุ: ข้อมูล KPI 8 ของเดือนใดไม่ผ่าน → ไฟล์ของเดือนนั้นนำเข้าไม่ได้ทั้งไฟล์ (รวม KPI 14) ตามกฎไม่บันทึกบางส่วน
|   KPI 11 (เจ้าของระบบกำหนด 2026-10-29): value_rule "visit_plan_count_over_customers_percent" — นับจากชีต "แผนการตรวจเยี่ยม"
|     ลูกค้าเดือนสัญญาที่ราคา ≥ 15,000 · ครั้งที่เข้าเยี่ยม (วันที่ที่ผ่านมาแล้ว) ÷ จำนวนลูกค้า × 100 · ดู KPI_COMPANY_VISIT_RULE ในตัวอ่าน
|   KPI 12 (เปลี่ยน 2026-10-29): ไม่อ่านค่าจาก Excel แล้ว ("channel" = "system_form_kpi12" · ดู KPI_COMPANY_CHANNELS ในตัวอ่าน)
|     กรอกผ่านแบบฟอร์ม KPI12 ในระบบ + หลักฐาน · Manager อนุมัติ (includes/kpi-contract-return.php)
|   เดิม KPI 11 / 12 ใช้ "kpi_am_cell_ratio_times_100" (ช่อง "ที่ทำได้" K16-K17 × 100) · KPI 12 ยังคง confirmed = true เพื่อไม่ให้รับผ่านช่องทางอื่น
|   KPI 10 (ยืนยัน 2026-10-26): value_rule "upsell_s_positive_count_over_b_percent" — จำนวนลูกค้าที่ S (ผลต่างราคา) > 0
|     ÷ จำนวนลูกค้าเดิมที่ต่อสัญญา (B<แถวรวม> = COUNTA) ของชีตเดือน × 100 · ไม่ใช้ K15 และไม่ใช้คอลัมน์ Q (ดู kpiCompanyEvaluateUpsell)
|   Competency 15-19 (ยืนยัน 2026-10-19): value_rule "competency_integer" — คะแนน 1-5 จากช่อง "ที่ทำได้"
|     2026-10-29: ปิดการนำเข้าจาก Excel ("channel" = "system_form_competency") · พนักงานกรอกในหน้าผลการปฏิบัติงาน
|     ครั้งเดียวแล้วบันทึกทุกเดือนที่เปิดอยู่ (kpiCompetencyFormSave ใน includes/kpi-import-helper.php) · Manager อนุมัติรายเดือนตามเดิม
|   KPI 9 มูลค่าไม่ต่อสัญญา (เจ้าของระบบกำหนด 2026-10-19): value_rule "non_renewal_count_t_over_c_percent"
|     แหล่งข้อมูล = สรุปทั้งปี69!U23 (= ช่อง K14 ของแบบฟอร์มฉบับปรับปรุง) · ตาราง "Portfolio(จำนวนราย)" แถวของแต่ละเดือน
|     Actual = T (= G/C จำนวนรายที่ต่อสัญญา ÷ จำนวนรายตาม Budget) × 100 ตัดทศนิยมลง 2 ตำแหน่ง · Target = 100 (%)
|     คะแนนตามกฎ KPI 9 ของระบบ (≥100 / 96 / 90 / 85%) ซึ่งตรงกับสูตรเกรด L14
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
        /* ยืนยันแล้ว: Actual = สรุปทั้งปี69!R5..R16 ของเดือน × 100 (ดู KPI_COMPANY_RENEWAL_RULE / KPI_COMPANY_RENEWAL_CELLS ในตัวอ่าน) */
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
        /* ยืนยันแล้ว: Actual = FLOOR(Σ(R − O) × 100 ÷ ΣO) จากชีตของเดือน · ระบบคำนวณผลต่างเอง (ดู KPI_COMPANY_UPLIFT_RULE ในตัวอ่าน) */
        "section" => "performance",
        "label" => "จำนวนรายการปรับเพิ่มมูลค่าสัญญา",
        "expected_weight" => 5,
        "kpi_id" => 8,
        "value_rule" => "contract_uplift_s_over_o_floor_percent",
        "confirmed_kpi_name" => "จำนวนรายการปรับเพิ่มมูลค่าสัญญา",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-18",
        "confirmed" => true
    ],
    [
        /* ยืนยันแล้ว: Actual = สรุปทั้งปี!U ของเดือน (มกราคม = U23) × 100 · Target 100 (ดู KPI_COMPANY_NON_RENEWAL_RULE ในตัวอ่าน) */
        "section" => "performance",
        "label" => "มูลค่าไม่ต่อสัญญา",
        "expected_weight" => 20,
        "kpi_id" => 9,
        "value_rule" => "non_renewal_count_t_over_c_percent",
        "confirmed_kpi_name" => "มูลค่าไม่ต่อสัญญา",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ],
    [
        "section" => "performance",
        "label" => "ขายเพิ่มผลิตภัณฑ์และบริการในลูกค้าเดิม",
        "expected_weight" => 10,
        "kpi_id" => 10,
        /* 2026-10-26: เจ้าของระบบยืนยันสูตรใหม่ (S > 0 ÷ ลูกค้าต่อสัญญาในคอลัมน์ B ของชีตเดือน) แทนช่อง K15 × 100 */
        "value_rule" => "upsell_s_positive_count_over_b_percent",
        "confirmed_kpi_name" => "ขายเพิ่มผลิตภัณฑ์และบริการในลูกค้าเดิม",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-26",
        "confirmed" => true
    ],
    [
        "section" => "performance",
        "label" => "เข้าเยี่ยมลูกค้าตามKPIที่บริษัทกำหนด",
        "expected_weight" => 5,
        "kpi_id" => 11,
        /* 2026-10-29: นับจากชีต "แผนการตรวจเยี่ยม" (ครั้งที่เข้าเยี่ยม ÷ ลูกค้าราคา ≥ 15,000 × 100) แทน KPI AM!K16 · ดู KPI_COMPANY_VISIT_RULE */
        "value_rule" => "visit_plan_count_over_customers_percent",
        "confirmed_kpi_name" => "เข้าเยี่ยมลูกค้าตาม KPI ที่บริษัทกำหนด",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-29",
        "confirmed" => true
    ],
    [
        "section" => "performance",
        "label" => "อัตราคงค้างสัญญาตัวจริงนำกลับ",
        "expected_weight" => 10,
        "kpi_id" => 12,
        /* 2026-10-29: บันทึกผ่านแบบฟอร์ม KPI12 ในระบบ (employee/kpi-contract-returns.php) แทนการอ่าน KPI AM!K17 */
        "channel" => "system_form_kpi12",
        "value_rule" => null,
        "confirmed_kpi_name" => "อัตราค้างสัญญาตัวจริงนำกลับ",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ],
    [
        "section" => "competency",
        "label" => "ความรู้เชิงชีวของแมลงและการใช้สารเคมีอย่างถูกต้อง",
        "expected_weight" => 4,
        "kpi_id" => 15,
        /* 2026-10-29: พนักงานกรอกในหน้าผลการปฏิบัติงาน (ครั้งเดียวทุกเดือนที่เปิดอยู่) · ไม่นำเข้าจาก Excel */
        "channel" => "system_form_competency",
        "value_rule" => "competency_integer",
        "confirmed_kpi_name" => "ความรู้เชิงชีวะของแมลงและการใช้สารเคมีอย่างถูกต้อง",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ],
    [
        "section" => "competency",
        "label" => "ความรู้ด้านสินค้าและผลิตภัณฑ์",
        "expected_weight" => 4,
        "kpi_id" => 16,
        /* 2026-10-29: พนักงานกรอกในหน้าผลการปฏิบัติงาน (ครั้งเดียวทุกเดือนที่เปิดอยู่) · ไม่นำเข้าจาก Excel */
        "channel" => "system_form_competency",
        "value_rule" => "competency_integer",
        "confirmed_kpi_name" => "ความรู้ด้านสินค้าและผลิตภัณฑ์",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ],
    [
        "section" => "competency",
        "label" => "ทักษะด้านการสื่อสาร",
        "expected_weight" => 4,
        "kpi_id" => 17,
        /* 2026-10-29: พนักงานกรอกในหน้าผลการปฏิบัติงาน (ครั้งเดียวทุกเดือนที่เปิดอยู่) · ไม่นำเข้าจาก Excel */
        "channel" => "system_form_competency",
        "value_rule" => "competency_integer",
        "confirmed_kpi_name" => "ทักษะด้านการสื่อสาร",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ],
    [
        "section" => "competency",
        "label" => "บริหารจัดการด้านข้อมูลและคอมพิวเตอร์",
        "expected_weight" => 4,
        "kpi_id" => 18,
        /* 2026-10-29: พนักงานกรอกในหน้าผลการปฏิบัติงาน (ครั้งเดียวทุกเดือนที่เปิดอยู่) · ไม่นำเข้าจาก Excel */
        "channel" => "system_form_competency",
        "value_rule" => "competency_integer",
        "confirmed_kpi_name" => "บริหารจัดการด้านข้อมูลและคอมพิวเตอร์",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ],
    [
        "section" => "competency",
        "label" => "บุคลิกภาพที่ดี",
        "expected_weight" => 4,
        "kpi_id" => 19,
        /* 2026-10-29: พนักงานกรอกในหน้าผลการปฏิบัติงาน (ครั้งเดียวทุกเดือนที่เปิดอยู่) · ไม่นำเข้าจาก Excel */
        "channel" => "system_form_competency",
        "value_rule" => "competency_integer",
        "confirmed_kpi_name" => "บุคลิกภาพที่ดี",
        "confirmed_by" => "เจ้าของระบบ",
        "confirmed_at" => "2026-10-19",
        "confirmed" => true
    ]
];
