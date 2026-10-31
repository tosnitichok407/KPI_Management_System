<?php

/*
|--------------------------------------------------------------------------
| Icon Helper (Lucide · inline SVG)
|--------------------------------------------------------------------------
|
| ไอคอนทั้งระบบใช้ชุด Lucide (https://lucide.dev · ISC License) แบบเส้น (outline) ชุดเดียว
| ไฟล์ SVG อยู่ใน assets/icons/ ฝังลงหน้าเว็บโดยตรง จึงไม่ต้องโหลดจาก CDN และไม่ใช้ JavaScript
|
|   <?= icon("users") ?>                     ไอคอนหน้าข้อความ
|   <?= icon("menu", "icon-only") ?>         ไอคอนอย่างเดียว (ไม่มีระยะห่างด้านขวา)
|
| สีและขนาดกำหนดใน assets/css/icons.css (ค่าเริ่มต้นสีน้ำเงิน #244397 ขนาดตามตัวอักษร)
| ไอคอนเป็นส่วนตกแต่ง (aria-hidden) ปุ่มที่มีแต่ไอคอนต้องมี aria-label ของตัวเอง
|
*/

function icon(string $name, string $class = ""): string
{
    static $cache = [];

    /* รับเฉพาะชื่อไฟล์ตัวพิมพ์เล็ก ตัวเลข และขีด (กัน path traversal) */
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return "";
    }

    if (!array_key_exists($name, $cache)) {

        $path = __DIR__ . "/../assets/icons/" . $name . ".svg";
        $body = "";

        if (is_file($path)) {

            $svg = (string) file_get_contents($path);

            /* เก็บเฉพาะรูปทรงภายใน <svg> ... </svg> */
            if (preg_match('/<svg\b[^>]*>(.*)<\/svg>/s', $svg, $matches)) {
                $body = trim(preg_replace('/\s+/', " ", $matches[1]));
            }
        }

        $cache[$name] = $body;
    }

    if ($cache[$name] === "") {
        return "";
    }

    $classes = trim("icon icon-" . $name . " " . $class);

    return '<svg class="' . htmlspecialchars($classes, ENT_QUOTES, "UTF-8") . '"'
        . ' xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"'
        . ' fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">'
        . $cache[$name]
        . '</svg>';
}
