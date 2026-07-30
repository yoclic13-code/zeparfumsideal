<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        $_SESSION['is_admin'] = true;
        redirect('perfumes.php');
    } else {
        $error = 'Mot de passe incorrect.';
    }
}

if (isAdminLoggedIn()) {
    redirect('perfumes.php');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administration — <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="../public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
  <div class="login-box">
    <h1>Administration</h1>
    <form method="POST" class="admin-form">
      <label for="password">Mot de passe</label>
      <input type="password" id="password" name="password" required autofocus>
      <button type="submit" class="btn-primary" style="margin-top:1.4rem;width:100%;">Se connecter</button>
      <?php if ($error): ?><p class="flash-error"><?= e($error) ?></p><?php endif; ?>
    </form>
  </div>
</body>
</html>
