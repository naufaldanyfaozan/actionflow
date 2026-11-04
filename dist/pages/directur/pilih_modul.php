<?php
require_once __DIR__ . '/../config/auth_helper.php';
require_once __DIR__ . '/../config/branding_helper.php';
mulaiSessionAman();
wajibLogin();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Pilih Modul</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="../../../dist/css/adminlte.css" />

    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
        }

        .modul-wrapper {
            max-width: 720px;
            width: 100%;
            padding: 20px;
        }

        .modul-title {
            text-align: center;
            margin-bottom: 24px;
        }

        .modul-title img {
            height: 50px;
            margin-bottom: 10px;
        }

        .modul-cards {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .modul-card {
            flex: 1 1 260px;
            max-width: 320px;
            background: #fff;
            border-radius: 14px;
            padding: 30px 20px;
            text-align: center;
            text-decoration: none;
            color: #1e293b;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .08);
            transition: .15s;
            border: 2px solid transparent;
        }

        .modul-card:hover {
            transform: translateY(-4px);
            border-color: #0d6efd;
            color: #1e293b;
        }

        .modul-card i {
            font-size: 42px;
            color: #0d6efd;
            margin-bottom: 12px;
            display: block;
        }

        .modul-card h5 {
            font-weight: 600;
            margin-bottom: 6px;
        }

        .modul-card p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }

        .modul-logout {
            text-align: center;
            margin-top: 24px;
        }
    </style>
</head>

<body>
    <div class="modul-wrapper">
        <div class="modul-title">
            <img src="<?= htmlspecialchars(brandLogo()) ?>" alt="Logo">
            <h4>Pilih Modul</h4>
            <p class="text-muted">Halo, <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>. Silakan pilih modul yang ingin dibuka.</p>
        </div>
        <div class="modul-cards">
            <a href="index.php" class="modul-card">
                <i class="bi bi-gear-fill"></i>
                <h5>FPM IMP &amp; Asakai</h5>
                <p>Form &amp; monitoring FPM Down Time, FPM Internal, dan Asakai.</p>
            </a>
            <a href="index_qa.php" class="modul-card">
                <i class="bi bi-patch-check-fill"></i>
                <h5>FPM QA</h5>
                <p>Claim Machining &amp; LOG TPDP khusus QA.</p>
            </a>
        </div>
        <div class="modul-logout">
            <?php if (isset($_SESSION['pf_username'])): ?>
                <a href="/productionflow/dashboard.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Ganti Modul</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</body>

</html>
