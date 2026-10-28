<?php
require 'auth.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify();

    $nom = trim($_POST['nom'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT id, nom, mot_de_passe
         FROM administrateurs
         WHERE nom = ?
         LIMIT 1"
    );

    $stmt->execute([$nom]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    error_log("Connexion : nom administrateur introuvable");
} elseif (!password_verify($mot_de_passe, $admin['mot_de_passe'])) {
    error_log("Connexion : mot de passe non reconnu");
}

    if ($admin && 
    password_verify(
        $mot_de_passe,
        $admin['mot_de_passe']
    )) {
        session_regenerate_id(true);

        $_SESSION['admin_id'] = $admin['id'];

        header('Location: admin.php');
        exit;
    }

    $erreur = "Nom ou mot de passe incorrect";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion - Célestine Fashion</title>
</head>
<body style="font-family:Arial;max-width:420px;margin:70px auto;">

<h1>Administration Célestine Fashion</h1>

<p style="color:red;">
    <?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?>
</p>

<form method="POST">

    <input type="hidden" name="csrf"
           value="<?= htmlspecialchars(token(), ENT_QUOTES, 'UTF-8') ?>">

    <p>
        <input type="text" name="nom"
               placeholder="Entrez votre nom" required>
    </p>

    <p>
        <input type="password" name="mot_de_passe"
               placeholder="Entrez votre mot de passe" required>
    </p>

    <button type="submit">Se connecter</button>

</form>

<p><a href="index.php">Retour à la boutique</a></p>

</body>
</html>
