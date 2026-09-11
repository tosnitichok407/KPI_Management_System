<?php

function monthlyPeriodMonths(): array
{
    return [
        1 => "มกราคม", 2 => "กุมภาพันธ์", 3 => "มีนาคม", 4 => "เมษายน",
        5 => "พฤษภาคม", 6 => "มิถุนายน", 7 => "กรกฎาคม", 8 => "สิงหาคม",
        9 => "กันยายน", 10 => "ตุลาคม", 11 => "พฤศจิกายน", 12 => "ธันวาคม"
    ];
}

function monthlyPeriodDetails(int $year, int $month): array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException("Invalid evaluation month");
    }
    $startDate = sprintf("%04d-%02d-01", $year, $month);
    $months = monthlyPeriodMonths();
    return [
        "year" => $year,
        "month" => $month,
        "quarter" => "Q" . (int) ceil($month / 3),
        "period_name" => "ประจำเดือน" . $months[$month],
        "start_date" => $startDate,
        "end_date" => date("Y-m-t", strtotime($startDate))
    ];
}
