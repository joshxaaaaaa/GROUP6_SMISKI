<?php
require_once __DIR__ . '/../config/db.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    if (!empty($email)) {
        $message = "If an account exists with $email, a password reset link has been generated. (Demo Mode: Contact admin to reset).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - E-Commerce Store</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #22c55e;          /* Vibrant Green */
            --primary-hover: #16a34a;    /* Darker Green */
            --secondary: #14532d;
            --dark: #052e16;
            --light: #14532d;            
            --gray-100: rgba(255, 255, 255, 0.1);
            --gray-200: rgba(187, 247, 208, 0.3);
            --gray-600: #dcfce7;
            --gray-800: #ffffff;
            --success: #22c55e;
            --danger: #f87171;
            --warning: #fbbf24;
            --radius: 12px;
            --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
        
        body { 
            background: linear-gradient(135deg, #14532d 0%, #166534 50%, #052e16 100%) !important;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            padding: 1.5rem; 
            color: var(--gray-800);
        }

        .card { 
            background: rgba(20, 83, 45, 0.95); 
            backdrop-filter: blur(12px); 
            border-radius: var(--radius); 
            padding: 2.5rem; 
            width: 100%; 
            max-width: 430px; 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4); 
            border: 1px solid rgba(187, 247, 208, 0.3);
        }

        .card-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        h2 { 
            font-size: 1.75rem; 
            color: #ffffff; 
            margin-bottom: 0.3rem; 
            font-weight: 700;
        }

        p { 
            color: #dcfce7; 
            font-size: 0.9rem; 
            margin-bottom: 1.5rem; 
            text-align: center;
        }

        .form-group { 
            margin-bottom: 1.25rem; 
        }

        label { 
            display: block; 
            font-size: 0.85rem; 
            font-weight: 600; 
            color: #dcfce7; 
            margin-bottom: 0.4rem; 
        }

        .input-control { 
            width: 100%; 
            padding: 0.75rem 1rem; 
            border: 1px solid rgba(187, 247, 208, 0.3); 
            border-radius: 10px; 
            font-size: 0.95rem; 
            outline: none;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
            transition: border-color 0.2s;
        }

        .input-control::placeholder {
            color: rgba(220, 252, 231, 0.5);
        }

        .input-control:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.1);
        }

        .btn { 
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%; 
            padding: 0.85rem; 
            background: var(--primary); 
            color: white; 
            border: 1px solid #16a34a; 
            border-radius: var(--radius); 
            font-size: 1rem; 
            font-weight: 600; 
            cursor: pointer; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: all 0.2s;
        }

        .btn:hover { 
            background: var(--primary-hover); 
            transform: translateY(-1px);
        }

        .alert { 
            background: rgba(20, 83, 45, 0.9); 
            color: #86efac; 
            border: 1px solid rgba(134, 239, 172, 0.4); 
            padding: 0.75rem 1rem; 
            border-radius: 8px; 
            margin-bottom: 1.25rem; 
            font-size: 0.85rem; 
        }

        .back-link { 
            text-align: center; 
            margin-top: 1.5rem; 
        }

        .back-link a { 
            color: #dcfce7; 
            text-decoration: none; 
            font-weight: 500; 
            font-size: 0.85rem; 
            transition: color 0.2s;
        }

        .back-link a:hover {
            color: #ffffff;
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <a href="index.php" title="Back to Home Store" style="display: inline-block; text-decoration: none; margin-bottom: 0.75rem;">
            <img src="../assets/ecommerce-logo.png" alt="E-Commerce Logo" style="width: 64px; height: 64px; object-fit: contain; transition: transform 0.2s;" onmouseenter="this.style.transform='scale(1.08)'" onmouseleave="this.style.transform='none'">
        </a>
        <h2>Forgot Password</h2>
        <p>Enter your email to receive password reset instructions</p>
    </div>

    <?php if ($message): ?>
        <div class="alert"><i class="fa-solid fa-info-circle"></i> <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" class="input-control" required placeholder="name@example.com">
        </div>
        <button type="submit" class="btn"><i class="fa-solid fa-paper-plane"></i> Reset Password</button>
    </form>

    <div class="back-link">
        <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Log In</a>
    </div>
</div>
</body>
</html>