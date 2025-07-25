<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../../config/database.php';
require_once '../admin/includes/functions.php';

// Check if user is logged in and is employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../../login/employee.php");
    exit();
}

// Handle profile update and password change from topbar modals
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Profile update
    if (isset($_POST['update_profile'])) {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $upload_dir = __DIR__ . '/../../uploads/profile_photos';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $error_message = "First name, last name, and email are required fields.";
        } else {
            try {
                $pdo->beginTransaction();
                // Update employees table
                $stmt = $pdo->prepare("
                    UPDATE employees 
                    SET first_name = ?, last_name = ?, phone = ?, address = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$first_name, $last_name, $phone, $address, $_SESSION['user_id']]);
                // Update users table
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET email = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$email, $_SESSION['user_id']]);
                // Handle profile image upload
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                    $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png'];
                    if (in_array($file_extension, $allowed_extensions)) {
                        $new_filename = 'employee_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
                        $upload_path = $upload_dir . '/' . $new_filename;
                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                            // Update profile image in database
                            $stmt = $pdo->prepare("UPDATE employees SET profile_image = ? WHERE user_id = ?");
                            $stmt->execute([$new_filename, $_SESSION['user_id']]);
                        } else {
                            throw new Exception("Error uploading profile image");
                        }
                    } else {
                        throw new Exception("Invalid file type. Only JPG, JPEG, and PNG files are allowed.");
                    }
                }
                $pdo->commit();
                $success_message = "Profile updated successfully!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "Error updating profile: " . $e->getMessage();
            }
        }
    }
    // Password change
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match.";
        } elseif (strlen($new_password) < 6) {
            $error_message = "New password must be at least 6 characters long.";
        } else {
            try {
                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                if ($user && password_verify($current_password, $user['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Current password is incorrect.";
                }
            } catch (PDOException $e) {
                $error_message = "Error changing password: " . $e->getMessage();
            }
        }
    }
}

// Initialize variables
$success = $error = '';
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Get the employee's emp_id from the session user_id
$stmt = $pdo->prepare('SELECT emp_id, first_name, last_name FROM employees WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$emp = $stmt->fetch();
$emp_id = $emp ? $emp['emp_id'] : 0;
$employee_name = $emp ? trim($emp['first_name'] . ' ' . $emp['last_name']) : $_SESSION['username'];

// Get company settings for header
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings_array = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$company_name = $settings_array['company_name'] ?? 'Naallo';
$company_address = $settings_array['company_address'] ?? 'Kismayo, Somalia';
$company_email = $settings_array['company_email'] ?? 'info@naallo.com';
$company_phone = $settings_array['company_phone'] ?? '+252 615 123 456';
$logo_path = '../../assets/images/LOGO.jpg';

// Get the latest payroll record for the employee
try {
    $query = "
        SELECT p.*, 
               pp.start_date, pp.end_date,
               ap.attendance_percentage, ap.bonus_percentage, ap.bonus_amount,
               ap.days_present, ap.days_late, ap.days_absent, ap.days_half_day,
               ap.total_working_days
        FROM payroll p 
        JOIN payroll_periods pp ON p.period_id = pp.period_id
        LEFT JOIN attendance_performance ap ON p.employee_id = ap.emp_id 
            AND MONTH(pp.start_date) = ap.month 
            AND YEAR(pp.start_date) = ap.year
        WHERE p.employee_id = ? 
        AND DATE_FORMAT(pp.start_date, '%Y-%m') = ?
        ORDER BY p.created_at DESC 
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$emp_id, $current_month]);
    $payroll = $stmt->fetch();

    // Get all payroll records for the employee
    $stmt = $pdo->prepare("
        SELECT p.*, 
               pp.start_date, pp.end_date,
               ap.attendance_percentage, ap.bonus_percentage, ap.bonus_amount
        FROM payroll p 
        JOIN payroll_periods pp ON p.period_id = pp.period_id
        LEFT JOIN attendance_performance ap ON p.employee_id = ap.emp_id 
            AND MONTH(pp.start_date) = ap.month 
            AND YEAR(pp.start_date) = ap.year
        WHERE p.employee_id = ? 
        ORDER BY pp.start_date DESC, p.created_at DESC
    ");
    $stmt->execute([$emp_id]);
    $payroll_history = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Error fetching payroll data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payslip</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #4e73df;
            --success-color: #1cc88a;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --info-color: #36b9cc;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }

        body {
            font-family: 'Nunito', sans-serif;
            background-color: #f8f9fc;
        }

        .page-header {
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 1.5rem 1.5rem 1.5rem 1.5rem;
            margin-bottom: 2rem;
            margin-top: 5rem;
            color: #222;
        }

        .page-header h1 {
            color: #333;
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .main-content {
            background-color: #f8f9fc;
            min-height: 100vh;
            padding: 1.5rem;
        }

        .card.card-stat {
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            transition: all 0.3s ease;
            border: none;
            position: relative;
            overflow: hidden;
            color: white;
            min-height: 100%;
            background: linear-gradient(45deg, #4e73df, #224abe);
        }

        .card.card-stat.total {
            background: linear-gradient(45deg, #4e73df, #6f8de3);
        }

        .card.card-stat.paid {
            background: linear-gradient(45deg, #1cc88a, #4cd4a3);
        }

        .card.card-stat.pending {
            background: linear-gradient(45deg, #f6c23e, #f8d06b);
        }

        .card.card-stat.draft {
            background: linear-gradient(45deg, #36b9cc, #5ccfe6);
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.75rem;
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .icon-circle {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-circle i {
            font-size: 1.25rem;
            color: currentColor;
        }

        .card h6 {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .card h2 {
            font-size: 1.75rem;
            font-weight: 700;
        }

        .stat-value {
            font-size: 1.875rem;
            font-weight: 700;
            color: white;
            margin: 0.5rem 0;
        }

        .stat-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .fancy-payslip {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 0.25rem 2rem 0 rgba(58, 59, 69, 0.10);
            overflow: hidden;
            margin: 1.5rem;
            border: 1.5px solid #e3e6f0;
        }

        .fancy-payslip-header {
            background: linear-gradient(90deg, #4e73df 0%, #36b9cc 100%);
            color: #fff;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
            text-align: center;
            position: relative;
        }

        .fancy-payslip-header .icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            color: #fff;
        }

        .fancy-payslip-header h4 {
            font-weight: 700;
            margin-bottom: 0.25rem;
            letter-spacing: 0.05em;
        }

        .fancy-payslip-header p {
            color: rgba(255,255,255,0.85);
            margin-bottom: 0;
        }

        .fancy-payslip-details {
            padding: 2rem 2rem 1rem 2rem;
            background: #f8f9fc;
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            border-bottom: 1.5px solid #e3e6f0;
        }

        .fancy-payslip-details .detail-col {
            flex: 1 1 220px;
        }

        .fancy-payslip-details label {
            font-weight: 600;
            color: #5a5c69;
            margin-bottom: 0.25rem;
        }

        .fancy-payslip-details .value {
            color: #222;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .fancy-payslip-summary {
            background: linear-gradient(90deg, #f8fafc 0%, #e3e6f0 100%);
            padding: 2rem;
            border-radius: 0 0 1.25rem 1.25rem;
        }

        .fancy-payslip-summary h5 {
            color: #4e73df;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .fancy-payslip-summary .row {
            margin-bottom: 0.75rem;
        }

        .fancy-payslip-summary label {
            font-weight: 600;
            color: #5a5c69;
        }

        .fancy-payslip-summary .value {
            text-align: right;
            font-weight: 700;
            color: #222;
        }

        .fancy-payslip-summary .total {
            border-top: 2px solid #e3e6f0;
            padding-top: 1rem;
            margin-top: 1rem;
        }

        .fancy-payslip-summary .total .value {
            color: #4e73df;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .attendance-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            border-radius: 0.5rem;
            flex: 1;
            margin: 0 0.5rem;
        }

        .stat-item:first-child {
            margin-left: 0;
        }

        .stat-item:last-child {
            margin-right: 0;
        }

        .stat-item.present {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success-color);
        }

        .stat-item.late {
            background-color: rgba(246, 194, 62, 0.1);
            color: var(--warning-color);
        }

        .stat-item.absent {
            background-color: rgba(231, 74, 59, 0.1);
            color: var(--danger-color);
        }

        .stat-item.half-day {
            background-color: rgba(54, 185, 204, 0.1);
            color: var(--info-color);
        }

        .stat-item .number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-item .label {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-radius: 0.35rem;
            transition: all 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, #224abe 100%);
            border: none;
        }

        .form-control, .form-select {
            border-radius: 0.35rem;
            border: 1px solid #d1d3e2;
            padding: 0.75rem 1rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }

        .alert {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        .payroll-history {
            margin-top: 2rem;
        }

        .payroll-history .card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 1rem;
        }

        .payroll-history .card-header {
            background-color: white;
            border-bottom: 1px solid #e3e6f0;
            padding: 1rem 1.5rem;
        }

        .payroll-history .card-body {
            padding: 1.5rem;
        }

        .payroll-history .status-badge {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 0.35rem;
        }

        .badge.bg-success, .badge.bg-info, .badge.bg-warning {
            font-size: 1rem;
            font-weight: 600;
            border-radius: 1.5em;
            padding: 0.5em 1.2em;
            display: inline-flex;
            align-items: center;
            gap: 0.5em;
        }

        .badge.bg-success i, .badge.bg-info i, .badge.bg-warning i {
            font-size: 1.1em;
        }

        @media print {
            .page-header, .card, .btn, .payroll-history {
                display: none !important;
            }
            
            .payslip {
                box-shadow: none;
                padding: 0;
                margin: 0;
            }
            
            body {
                background-color: white;
            }
        }

        .payslip-header-print {
            display: none;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 2px solid #e3e6f0;
            margin-bottom: 1.5rem;
        }
        .payslip-header-print .logo img {
            max-height: 50px;
        }
        .payslip-header-print .company-details {
            text-align: right;
        }
        .payslip-header-print .company-details h3 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 700;
        }
        .payslip-header-print .company-details p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--dark-color);
        }

        body {
            font-family: 'Nunito', sans-serif;
            background-color: #f8f9fc;
        }
        .main-content > .container-fluid > .row, .main-content > .container-fluid > .card {
            display: none !important;
        }

        .payslip-wrapper {
            display: block !important;
        }

        #main-content > .container-fluid {
            padding: 0 !important;
        }

        .fancy-payslip {
            box-shadow: none !important;
            margin: 0 !important;
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
        }

        .payslip-header-print {
            display: flex !important;
        }
        
        /* Force colors to print */
        .fancy-payslip-header, .fancy-payslip-summary, .badge {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .badge.bg-success { background-color: #1cc88a !important; color: white !important; }
        .badge.bg-info { background-color: #36b9cc !important; color: white !important; }
        .badge.bg-warning { background-color: #f6c23e !important; color: white !important; }
        
        .fancy-payslip-header {
            background: linear-gradient(90deg, #4e73df 0%, #36b9cc 100%) !important;
        }
        .fancy-payslip-summary {
            background: linear-gradient(90deg, #f8fafc 0%, #e3e6f0 100%) !important;
        }

        @media print {
            body * {
                visibility: hidden !important;
            }
            .print-area, .print-area * {
                visibility: visible !important;
            }
            .print-area {
                display: block !important;
                position: absolute;
                left: 0; top: 0; width: 100%;
            }
            .onscreen-payslip {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content" id="main-content">
        <!-- Topbar -->
        <?php include 'includes/topbar.php'; ?>

        <!-- Page Header -->
        <div class="container-fluid">
            <div class="page-header mb-4">
                <div class="container-fluid">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>My Payslip</h1>
                        <!-- <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Payslip</button> -->
                    </div>
                </div>
            </div>

        <!-- Page Content -->
        <div class="container-fluid">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($payroll): ?>
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card card-stat total h-100">
                        <div class="stat-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stat-value">1</div>
                        <div class="stat-label">Total Payrolls</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-stat paid h-100">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value">$<?php echo number_format($payroll['net_salary'], 2); ?></div>
                        <div class="stat-label">Paid</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-stat pending h-100">
                        <div class="stat-icon">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <div class="stat-value">0</div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card card-stat draft h-100">
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-value">0</div>
                        <div class="stat-label">Draft</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <div class="card mb-4">
                <style>
                    .icon-circle {
                        width: 3rem;
                        height: 3rem;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .icon-circle i {
                        font-size: 1.25rem;
                        color: currentColor;
                    }
                    .card h6 {
                        font-size: 0.75rem;
                        font-weight: 700;
                        letter-spacing: 0.05em;
                    }
                    .card h2 {
                        font-size: 1.75rem;
                        font-weight: 700;
                    }
                </style>
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold">Select Pay Period</h6>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Month</label>
                            <input type="month" class="form-control" name="month" value="<?php echo $current_month; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- On-screen Payslip (normal view) -->
            <div class="onscreen-payslip">
                <?php if ($payroll): ?>
                    <div class="payslip-wrapper">
                        <div class="fancy-payslip p-4">
                            <div class="fancy-payslip-header">
                                <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
                                <h4>PAYSLIP</h4>
                                <p>For the period of <?php echo date('F Y', strtotime($payroll['start_date'])); ?></p>
                            </div>
                            <div class="fancy-payslip-details">
                                <div class="detail-col">
                                    <label>Employee Name</label>
                                    <div class="value"><?php echo htmlspecialchars($employee_name); ?></div>
                                    <label>Pay Period</label>
                                    <div class="value">
                                        <?php echo date('M d, Y', strtotime($payroll['start_date'])); ?> - 
                                        <?php echo date('M d, Y', strtotime($payroll['end_date'])); ?>
                                    </div>
                                </div>
                                <div class="detail-col">
                                    <label>Status</label>
                                    <div class="value">
                                        <span class="badge bg-<?php 
                                            echo $payroll['status'] === 'paid' ? 'success' : 
                                                ($payroll['status'] === 'approved' ? 'info' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($payroll['status']); ?>
                                        </span>
                                    </div>
                                    <label>Payslip ID</label>
                                    <div class="value">#<?php echo str_pad($payroll['payroll_id'], 6, '0', STR_PAD_LEFT); ?></div>
                                </div>
                            </div>
                            <div class="fancy-payslip-summary">
                                <h5>Salary Summary</h5>
                                <div class="row">
                                    <div class="col-6">
                                        <label>Basic Salary</label>
                                    </div>
                                    <div class="col-6">
                                        <div class="value">$<?php echo number_format($payroll['basic_salary'], 2); ?></div>
                                    </div>
                                </div>
                                <?php if ($payroll['bonus_amount'] > 0): ?>
                                    <div class="row">
                                        <div class="col-6">
                                            <label>Attendance Bonus</label>
                                        </div>
                                        <div class="col-6">
                                            <div class="value">$<?php echo number_format($payroll['bonus_amount'], 2); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="row total">
                                    <div class="col-6">
                                        <label>Net Salary</label>
                                    </div>
                                    <div class="col-6">
                                        <div class="value">$<?php echo number_format($payroll['net_salary'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-center">
                                <button class="btn btn-primary" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print Payslip
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info" style="border-radius: 0.75rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);">
                        <i class="fas fa-info-circle me-2"></i>
                        No payroll records found for the selected period.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Print-Only Payslip (manager style) -->
            <?php if ($payroll): ?>
            <div class="print-area" style="display:none">
                <div class="payslip-wrapper">
                    <div class="payslip-header-print">
                        <div class="logo">
                            <?php if (file_exists($logo_path)): ?>
                                <img src="<?php echo $logo_path; ?>" alt="Company Logo">
                            <?php endif; ?>
                        </div>
                        <div class="company-details">
                            <h3><?php echo htmlspecialchars($company_name); ?></h3>
                            <p><?php echo htmlspecialchars($company_address); ?></p>
                            <p><?php echo htmlspecialchars($company_email); ?> | <?php echo htmlspecialchars($company_phone); ?></p>
                        </div>
                    </div>
                    <div class="fancy-payslip">
                        <div class="fancy-payslip-header">
                            <div class="icon"><i class="fas fa-file-invoice-dollar"></i></div>
                            <h4>PAYSLIP</h4>
                            <p>For the period of <?php echo date('F Y', strtotime($payroll['start_date'])); ?></p>
                        </div>
                        <div class="fancy-payslip-details">
                            <div class="detail-col">
                                <label>Employee Name:</label>
                                <div class="value"><?php echo htmlspecialchars($employee_name); ?></div>
                            </div>
                            <div class="detail-col">
                                <label>Status:</label>
                                <div class="value">
                                    <span class="badge bg-<?php 
                                        echo $payroll['status'] === 'paid' ? 'success' : 
                                            ($payroll['status'] === 'approved' ? 'info' : 'warning'); 
                                    ?>">
                                        <?php if ($payroll['status'] === 'paid'): ?><i class="fas fa-check-circle me-1"></i><?php endif; ?>
                                        <?php if ($payroll['status'] === 'approved'): ?><i class="fas fa-info-circle me-1"></i><?php endif; ?>
                                        <?php if ($payroll['status'] === 'draft'): ?><i class="fas fa-hourglass-half me-1"></i><?php endif; ?>
                                        <?php if ($payroll['status'] === 'cancelled'): ?><i class="fas fa-ban me-1"></i><?php endif; ?>
                                        <?php echo ucfirst($payroll['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="detail-col">
                                <label>Pay Period:</label>
                                <div class="value">
                                    <?php echo date('M d, Y', strtotime($payroll['start_date'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($payroll['end_date'])); ?>
                                </div>
                            </div>
                            <div class="detail-col">
                                <label>Payslip ID:</label>
                                <div class="value">#<?php echo str_pad($payroll['payroll_id'], 6, '0', STR_PAD_LEFT); ?></div>
                            </div>
                        </div>
                        <div class="fancy-payslip-summary">
                            <h5>Salary Summary</h5>
                            <div class="row">
                                <div class="col-6">
                                    <label>Basic Salary:</label>
                                </div>
                                <div class="col-6">
                                    <div class="value">$<?php echo number_format($payroll['basic_salary'], 2); ?></div>
                                </div>
                            </div>
                            <?php if ($payroll['bonus_amount'] > 0): ?>
                                <div class="row">
                                    <div class="col-6">
                                        <label>Attendance Bonus:</label>
                                    </div>
                                    <div class="col-6">
                                        <div class="value">$<?php echo number_format($payroll['bonus_amount'], 2); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="row total">
                                <div class="col-6">
                                    <label>Net Salary:</label>
                                </div>
                                <div class="col-6">
                                    <div class="value">$<?php echo number_format($payroll['net_salary'], 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
            <script>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: <?php echo json_encode($success_message); ?>,
                confirmButtonColor: '#4e73df'
            });
            </script>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
            <script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?php echo json_encode($error_message); ?>,
                confirmButtonColor: '#e74a3b'
            });
            </script>
            <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 