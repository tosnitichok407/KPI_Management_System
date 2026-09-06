<?php

session_start();
require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || !in_array((int) ($_SESSION["role_id"] ?? 0), [1, 2], true)) {
    header("Location: ../login.php");
    exit;
}

$employeeId = (int) ($_POST["employee_id"] ?? 0);
$periodId = (int) ($_POST["period_id"] ?? 0);
$score = (float) ($_POST["evaluation_score"] ?? -1);
$feedback = trim($_POST["feedback"] ?? "");

if ($employeeId <= 0 || $periodId <= 0 || $score < 0 || $score > 100 || $feedback === "") {
    header("Location: index.php?period_id={$periodId}&saved=0");
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manager_feedback (feedback_id INT AUTO_INCREMENT PRIMARY KEY, manager_id INT NOT NULL, employee_id INT NOT NULL, period_id INT NOT NULL, evaluation_score DECIMAL(5,2) NOT NULL, feedback TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_review (manager_id, employee_id, period_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->prepare("INSERT INTO manager_feedback (manager_id, employee_id, period_id, evaluation_score, feedback) VALUES (:manager_id, :employee_id, :period_id, :evaluation_score, :feedback) ON DUPLICATE KEY UPDATE evaluation_score = VALUES(evaluation_score), feedback = VALUES(feedback)");
    $stmt->execute([":manager_id" => (int) $_SESSION["user_id"], ":employee_id" => $employeeId, ":period_id" => $periodId, ":evaluation_score" => $score, ":feedback" => $feedback]);
    header("Location: index.php?period_id={$periodId}&saved=1");
} catch (PDOException $exception) {
    header("Location: index.php?period_id={$periodId}&saved=0");
}
exit;
