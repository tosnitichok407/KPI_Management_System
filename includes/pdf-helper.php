<?php

/*
|--------------------------------------------------------------------------
| PDF Helper (Dompdf + ฟอนต์ภาษาไทย)
|--------------------------------------------------------------------------
|
| Dompdf v3 อ่านไฟล์ได้เฉพาะภายใน chroot จึงต้องใช้ฟอนต์ที่อยู่ในโปรเจกต์ (assets/fonts)
| ใช้ TH Sarabun New เพราะแสดงวรรณยุกต์/สระบน-ล่างใน Dompdf ได้ถูกต้อง
| (Kanit ใน Dompdf วรรณยุกต์หาย เช่น ป่า → ปา)
|
*/

use Dompdf\Dompdf;
use Dompdf\Options;

function createThaiPdf(): Dompdf
{
    $projectRoot = realpath(__DIR__ . "/..");

    // โฟลเดอร์ทำงานของ Dompdf (cache ฟอนต์ + ไฟล์ชั่วคราว) ต้องเขียนได้โดยผู้ใช้ที่รัน PHP
    $workDir = thaiPdfWorkDir();

    $options = new Options();
    $options->setChroot([$projectRoot]);
    $options->setTempDir($workDir);
    $options->setFontDir($workDir);
    $options->setFontCache($workDir);
    $options->setIsRemoteEnabled(false);
    $options->setDefaultFont("Sarabun");

    $pdf = new Dompdf($options);

    // ลงทะเบียนฟอนต์ไว้ก่อน เพื่อให้วัดความกว้างข้อความได้ตั้งแต่ก่อน render (thaiPdfWrap)
    $fontDir = realpath(__DIR__ . "/../assets/fonts");
    $metrics = $pdf->getFontMetrics();
    $metrics->registerFont(["family" => "Sarabun", "style" => "normal", "weight" => "normal"], $fontDir . "/THSarabunNew.ttf");
    $metrics->registerFont(["family" => "Sarabun", "style" => "normal", "weight" => "bold"], $fontDir . "/THSarabunNew-Bold.ttf");

    return $pdf;
}


/*
|--------------------------------------------------------------------------
| ตัดบรรทัดภาษาไทยสำหรับ PDF
|--------------------------------------------------------------------------
|
| Dompdf ตัดบรรทัดได้เฉพาะที่ช่องว่าง แต่ภาษาไทยไม่เว้นวรรคระหว่างคำ
| ข้อความยาวจึงล้นออกนอกช่องตาราง
|
| ฟังก์ชันนี้วัดความกว้างด้วย FontMetrics ของ Dompdf (ฟอนต์/ขนาดเดียวกับที่ใช้จริง)
| แล้วแทรก <br> ตรงจุดที่ตัดได้อย่างปลอดภัย:
| - ตัดที่ช่องว่างก่อนเสมอ
| - คำที่ยาวเกินช่อง ตัดระหว่างตัวอักษรไทย แต่ไม่ตัดก่อนสระ/วรรณยุกต์ และไม่ตัดหลัง เ แ โ ใ ไ
|
| คืนค่าเป็น HTML ที่ escape แล้ว
|
*/

function thaiPdfWrap(Dompdf $pdf, string $text, float $maxWidthPt, float $fontSizePx, bool $bold = false): string
{
    $text = trim(preg_replace('/\s+/u', " ", $text) ?? "");

    if ($text === "") {
        return "";
    }

    $metrics = $pdf->getFontMetrics();
    $font = $metrics->getFont("Sarabun", $bold ? "bold" : "normal");
    $size = $fontSizePx * 0.75; // px → pt (96 dpi)

    $measure = static fn(string $value): float => $metrics->getTextWidth($value, $font, $size);

    $lines = [];
    $line = "";

    foreach (explode(" ", $text) as $word) {

        $candidate = $line === "" ? $word : $line . " " . $word;

        if ($measure($candidate) <= $maxWidthPt) {
            $line = $candidate;
            continue;
        }

        if ($line !== "") {
            $lines[] = $line;
            $line = "";
        }

        if ($measure($word) <= $maxWidthPt) {
            $line = $word;
            continue;
        }

        // คำเดียวยาวเกินช่อง → ตัดตามกลุ่มตัวอักษร
        foreach (thaiPdfClusters($word) as $cluster) {

            if ($line !== "" && $measure($line . $cluster) > $maxWidthPt) {
                $lines[] = $line;
                $line = "";
            }

            $line .= $cluster;
        }
    }

    if ($line !== "") {
        $lines[] = $line;
    }

    return implode("<br>", array_map(
        static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, "UTF-8"),
        $lines
    ));
}


/* แยกคำเป็นกลุ่มตัวอักษรที่ตัดบรรทัดคั่นได้ (สระ/วรรณยุกต์ติดกับพยัญชนะเสมอ) */
function thaiPdfClusters(string $word): array
{
    $chars = mb_str_split($word, 1, "UTF-8");
    $clusters = [];
    $current = "";

    foreach ($chars as $index => $char) {

        if ($current !== "" && thaiPdfCanBreakBetween($chars[$index - 1], $char)) {
            $clusters[] = $current;
            $current = "";
        }

        $current .= $char;
    }

    if ($current !== "") {
        $clusters[] = $current;
    }

    return $clusters;
}


function thaiPdfCanBreakBetween(string $previous, string $next): bool
{
    $prev = mb_ord($previous, "UTF-8");
    $nextCode = mb_ord($next, "UTF-8");

    // สระหลัง/บน/ล่าง, ไม้ยมก/ไปยาล, วรรณยุกต์ ต้องติดกับตัวหน้า
    if (
        ($nextCode >= 0x0E30 && $nextCode <= 0x0E3A)
        || $nextCode === 0x0E2F
        || $nextCode === 0x0E45
        || ($nextCode >= 0x0E47 && $nextCode <= 0x0E4E)
    ) {
        return false;
    }

    // สระหน้า เ แ โ ใ ไ ต้องติดกับพยัญชนะตัวถัดไป
    if ($prev >= 0x0E40 && $prev <= 0x0E44) {
        return false;
    }

    // ไม่ตัดกลางคำภาษาอังกฤษ/ตัวเลข/สัญลักษณ์
    $isThai = static fn(int $code): bool => $code >= 0x0E00 && $code <= 0x0E7F;

    if (!$isThai($prev) && !$isThai($nextCode)) {
        return false;
    }

    return true;
}


/*
| โฟลเดอร์ทำงานของ Dompdf
|
| Apache (ผู้ใช้ daemon) กับ PHP CLI ใช้คนละผู้ใช้ และ sys_get_temp_dir() ของ Apache
| อาจชี้ไปโฟลเดอร์ที่ daemon เขียนไม่ได้ → Dompdf สร้างไฟล์ชั่วคราวไม่ได้ (Path cannot be empty)
| จึงเลือกโฟลเดอร์แรกที่เขียนได้จริง และแยกโฟลเดอร์ย่อยตามผู้ใช้ที่รัน PHP
*/
function thaiPdfWorkDir(): string
{
    $user = function_exists("posix_geteuid") ? (string) posix_geteuid() : "php";

    $bases = array_filter([
        ini_get("upload_tmp_dir") ?: null,
        sys_get_temp_dir(),
        __DIR__ . "/../storage"
    ]);

    foreach ($bases as $base) {

        $dir = rtrim($base, "/\\") . DIRECTORY_SEPARATOR . "kpi-dompdf-" . $user;

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    throw new RuntimeException("ไม่พบโฟลเดอร์ชั่วคราวที่เขียนได้สำหรับสร้าง PDF");
}


/* แสดงข้อความแทนหน้าขาว เมื่อสร้าง PDF ไม่สำเร็จ (รายละเอียดบันทึกลง error log) */
function thaiPdfExceptionHandler(Throwable $exception): void
{
    error_log(
        "PDF export failed: " . $exception->getMessage()
        . " @ " . $exception->getFile() . ":" . $exception->getLine()
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: text/html; charset=UTF-8");
    }

    echo "<!doctype html><meta charset='UTF-8'>"
        . "<p style='font-family:sans-serif;padding:24px'>ไม่สามารถสร้างไฟล์ PDF ได้ กรุณาลองใหม่อีกครั้ง หรือติดต่อผู้ดูแลระบบ</p>";
}


/* @font-face สำหรับใส่ใน <style> ของ HTML ที่จะแปลงเป็น PDF */
function thaiPdfFontCss(): string
{
    $fontDir = realpath(__DIR__ . "/../assets/fonts");

    return "
        @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: normal; src: url('{$fontDir}/THSarabunNew.ttf') format('truetype'); }
        @font-face { font-family: 'Sarabun'; font-style: normal; font-weight: bold; src: url('{$fontDir}/THSarabunNew-Bold.ttf') format('truetype'); }
    ";
}


/* เลขหน้า "หน้า x / y" มุมขวาล่าง (เรียกหลัง render) */
function thaiPdfPageNumbers(Dompdf $pdf, string $leftText = ""): void
{
    $canvas = $pdf->getCanvas();
    $font = $pdf->getFontMetrics()->getFont("Sarabun");
    $color = [0.4, 0.44, 0.5];

    $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 26, "หน้า {PAGE_NUM} / {PAGE_COUNT}", $font, 11, $color);

    if ($leftText !== "") {
        $canvas->page_text(30, $canvas->get_height() - 26, $leftText, $font, 11, $color);
    }
}
