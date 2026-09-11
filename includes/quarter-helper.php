<?php

function getQuarterByMonth(int $month): ?string
{
    if ($month < 1 || $month > 12) {
        return null;
    }

    return "Q" . (int) ceil($month / 3);
}

function getQuarterFromDate(?string $date): ?string
{
    if (!$date) {
        return null;
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return null;
    }

    $month = (int) date("n", $timestamp);

    return getQuarterByMonth($month);
}

function getQuarterLabel(?string $quarter): string
{
    return $quarter ?: "-";
}

function getQuarterMonths(string $quarter): array
{
    $ranges = [
        "Q1" => [1, 3],
        "Q2" => [4, 6],
        "Q3" => [7, 9],
        "Q4" => [10, 12]
    ];

    return $ranges[$quarter] ?? [];
}
