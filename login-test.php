<?php

require_once "config/database.php";

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$result = null;
$username = trim($_POST["username"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $result = [
            "success" => false,
            "message" => "กรุณากรอก Username และ Password"
        ];
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.user_id,
                    u.username,
                    u.password_hash,
                    u.status AS user_status,
                    e.employee_code,
                    e.first_name,
                    e.last_name,
                    e.status AS employee_status,
                    r.role_name
                FROM users u
                INNER JOIN employees e
                    ON e.employee_id = u.employee_id
                INNER JOIN roles r
                    ON r.role_id = u.role_id
                WHERE u.username = :username
                LIMIT 1
            ");
            $stmt->execute([":username" => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user["password_hash"])) {
                $result = [
                    "success" => false,
                    "message" => "Username หรือ Password ไม่ถูกต้อง"
                ];
            } elseif ($user["user_status"] !== "Active" || $user["employee_status"] !== "Active") {
                $result = [
                    "success" => false,
                    "message" => "บัญชีผู้ใช้หรือพนักงานไม่ได้เปิดใช้งาน"
                ];
            } else {
                $result = [
                    "success" => true,
                    "message" => "ตรวจสอบ Login สำเร็จ",
                    "user" => $user
                ];
            }
        } catch (PDOException $exception) {
            $result = [
                "success" => false,
                "message" => "เชื่อมต่อฐานข้อมูลหรือ Query ไม่สำเร็จ: " . $exception->getMessage()
            ];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Test | KPI System</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            background: #195ee8;
            color: #1f2937;
            font-family: "Kanit", sans-serif;
        }

        .test-card {
            width: min(440px, 100%);
            padding: 28px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
        }

        h1 {
            margin: 0 0 5px;
            color: #1e3a8a;
            font-size: 25px;
        }

        .description {
            margin: 0 0 22px;
            color: #6b7280;
            font-size: 13px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-size: 14px;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font: inherit;
        }

        input:focus {
            outline: 2px solid #bfdbfe;
            border-color: #1e3a8a;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 11px;
            border: 0;
            border-radius: 7px;
            background: #1e3a8a;
            color: #fff;
            font: inherit;
            cursor: pointer;
        }

        button:hover {
            background: #244397;
        }

        .result {
            margin: 18px 0 0;
            padding: 13px;
            border-radius: 7px;
            font-size: 13px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .user-data {
            margin: 10px 0 0;
            padding-top: 10px;
            border-top: 1px solid rgba(0, 0, 0, .1);
            line-height: 1.8;
        }

        .warning {
            margin-top: 20px;
            color: #92400e;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <main class="test-card">
        <h1>ทดสอบ Login</h1>
        <p class="description">หน้านี้ใช้ตรวจสอบ Username, Password และสถานะบัญชีเท่านั้น</p>

        <form method="post">
            <label for="username">Username</label>
            <input id="username" name="username" value="<?= escape($username) ?>" autocomplete="username" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">ตรวจสอบ Login</button>
        </form>

        <?php if ($result): ?>
            <div class="result <?= $result["success"] ? "success" : "error" ?>">
                <strong><?= escape($result["message"]) ?></strong>
                <?php if ($result["success"]): ?>
                    <div class="user-data">
                        Username: <?= escape($result["user"]["username"]) ?><br>
                        Employee: <?= escape($result["user"]["employee_code"]) ?> - <?= escape($result["user"]["first_name"] . " " . $result["user"]["last_name"]) ?><br>
                        Role: <?= escape($result["user"]["role_name"]) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="warning">ลบไฟล์นี้ก่อนนำระบบขึ้นใช้งานจริง เพราะเป็นหน้า debug สำหรับทดสอบเท่านั้น</p>
    </main>
</body>

</html>