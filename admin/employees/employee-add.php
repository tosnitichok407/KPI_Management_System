<?php

require_once __DIR__ . "/../../includes/security.php";

require_once __DIR__ . "/../../config/database.php";

/** @var PDO $pdo ตัวเชื่อมต่อฐานข้อมูลจาก config/database.php */


/*
|--------------------------------------------------------------------------
| Admin Access
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login.php");
    exit;
}

if ((int) ($_SESSION["role_id"] ?? 0) !== 1) {
    redirectToRoleHome("../../");
}


$error = "";


/*
|--------------------------------------------------------------------------
| Get Departments
|--------------------------------------------------------------------------
*/

$departments = $pdo
    ->query("
        SELECT department_id, department_name
        FROM departments
        ORDER BY department_name
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Get Positions
|--------------------------------------------------------------------------
*/

$positions = $pdo
    ->query("
        SELECT position_id, position_name
        FROM positions
        ORDER BY position_name
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Add Employee
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $employee_code = trim($_POST["employee_code"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $gender = $_POST["gender"] ?? null;
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $department_id = (int) ($_POST["department_id"] ?? 0);
    $position_id = (int) ($_POST["position_id"] ?? 0);
    $hire_date = trim($_POST["hire_date"] ?? "");

    if (!csrfVerify()) {

        $error = "Session expired. Please try again.";

    } elseif (
        $employee_code === "" ||
        $first_name === "" ||
        $last_name === ""
    ) {

        $error = "Please fill in all required fields.";

    } elseif (
        mb_strlen($employee_code) > 20 ||
        mb_strlen($first_name) > 100 ||
        mb_strlen($last_name) > 100 ||
        mb_strlen($phone) > 20 ||
        mb_strlen($email) > 150
    ) {

        $error = "Input is too long.";

    } elseif (!in_array($gender, ["", null, "Male", "Female", "Other"], true)) {

        $error = "Invalid gender.";

    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Invalid email address.";

    } elseif ($hire_date !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date)) {

        $error = "Invalid hire date.";

    } else {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO employees
                (
                    employee_code,
                    first_name,
                    last_name,
                    gender,
                    phone,
                    email,
                    department_id,
                    position_id,
                    hire_date,
                    status
                )
                VALUES
                (
                    :employee_code,
                    :first_name,
                    :last_name,
                    :gender,
                    :phone,
                    :email,
                    :department_id,
                    :position_id,
                    :hire_date,
                    'Active'
                )
            ");

            $stmt->execute([
                ":employee_code" => $employee_code,
                ":first_name" => $first_name,
                ":last_name" => $last_name,
                ":gender" => $gender ?: null,
                ":phone" => $phone ?: null,
                ":email" => $email ?: null,
                ":department_id" => $department_id ?: null,
                ":position_id" => $position_id ?: null,
                ":hire_date" => $hire_date ?: null
            ]);


            header("Location: ../index.php?page=employees");
            exit;

        } catch (PDOException $e) {

            if ($e->getCode() === "23000") {
                $error = "Employee ID already exists.";
            } else {
                $error = "Unable to add employee.";
            }

        }
    }
}

?>

<!DOCTYPE html>

<html lang="th">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../../assets/css/employee.css"
    >

    <title>Add Employee</title>

</head>


<body>

<div class="page-container">

    <header class="page-header">

        <div>

            <h1>
                เพิ่มพนักงานใหม่
            </h1>

            <p>
                เพิ่มข้อมูลพนักงานใหม่ในระบบ
            </p>

        </div>

    </header>


    <?php if ($error !== ""): ?>

        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <section class="filter-card">

                <form method="POST">

            <?= csrfField() ?>

            <div class="form-group">

                <label>
                     รหัสพนักงาน *
                </label>

                <input
                    type="text"
                    name="employee_code"
                    placeholder="EMP001"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    ชื่อ *
                </label>

                <input
                    type="text"
                    name="first_name"
                    placeholder="กรอกชื่อ"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    นามสกุล *
                </label>

                <input
                    type="text"
                    name="last_name"
                    placeholder="กรอกนามสกุล"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    เพศ *
                </label>

                <select name="gender">

                    <option value="">
                        เลือกเพศ
                    </option>

                    <option value="Male">
                        ชาย
                    </option>

                    <option value="Female">
                        หญิง
                    </option>

                    <option value="Other">
                        อื่น ๆ
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label>
                    เบอร์โทรศัพท์
                </label>

                <input
                    type="text"
                    name="phone"
                >

            </div>


            <div class="form-group">

                <label>
                    อีเมล
                </label>

                <input
                    type="email"
                    name="email"
                >

            </div>


            <div class="form-group">

                <label>
                    แผนก
                </label>

                <select name="department_id">

                    <option value="">
                        เลือกแผนก
                    </option>

                    <?php foreach ($departments as $department): ?>

                        <option
                            value="<?= $department["department_id"] ?>"
                        >
                            <?= htmlspecialchars(
                                $department["department_name"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label>
                    ตำแหน่ง
                </label>

                <select name="position_id">

                    <option value="">
                        เลือกตำแหน่ง
                    </option>

                    <?php foreach ($positions as $position): ?>

                        <option
                            value="<?= $position["position_id"] ?>"
                        >
                            <?= htmlspecialchars(
                                $position["position_name"]
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label>
                    วันที่จ้างงาน
                </label>

                <input
                    type="date"
                    name="hire_date"
                >

            </div>


                        <div class="form-buttons compact">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    บันทึกข้อมูลพนักงาน
                </button>

                <a
                    href="../index.php?page=employees"
                    class="btn btn-secondary"
                >
                    ยกเลิก
                </a>

            </div>


        </form>

    </section>

</div>

<script src="../../assets/js/admin.js"></script>

</body>

</html>
