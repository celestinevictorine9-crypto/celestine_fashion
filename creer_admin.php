<?php
require 'connexion.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nom = trim($_POST['nom'] ?? '');
    $mot_de_passe = $_POST['mot_de_passe'] ?? '';

    if ($nom === '' || strlen($mot_de_passe) < 12) {
        $message = "Entrez un nom et un mot de passe de 12 caractères minimum.";
    } else {

        $stmt = $pdo->prepare(
            "SELECT id FROM administrateurs WHERE nom = ?"
        );
        $stmt->execute([$nom]);

        if ($stmt->fetch()) {
            $message = "Ce nom d'utilisateur existe déjà.";
        } else {

            $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);

            $email = 'admin-' . bin2hex(random_bytes(8)) . '@example.com';

            $stmt = $pdo->prepare(
                "INSERT INTO administrateurs
                (nom, email, mot_de_passe)
                VALUES (?, ?, ?)"
            );

            $stmt->execute([$nom, $email, $hash]);

            $message = "Compte administrateur créé avec succès !";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Créer administrateur - Célestine Fashion</title>
</head>
<body>

<h2>Créer mon compte administrateur</h2>

<p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>

<form method="POST">

    <input type="text" name="nom"
           placeholder="Nom d'utilisateur" required>

    <br><br>

    <input type="password" name="mot_de_passe"
           placeholder="Mot de passe"
           minlength="12" required>

    <br><br>

    <button type="submit">Créer mon compte</button>

</form>

</body>
</html>
