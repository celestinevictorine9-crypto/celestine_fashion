
<?php
require_once __DIR__ . '/auth.php';
adminOnly();

$message = '';
$erreur = '';
$modifier = null;
$elements = [];
$commandes = [];

$types = [
    'produits' => [
        'titre' => 'Produits',
        'table' => 'produits',
        'photo_col' => 'image',
        'categorie_stock' => true
    ],
    'marchandises' => [
        'titre' => 'Marchandises',
        'table' => 'marchandises',
        'photo_col' => 'photo',
        'categorie_stock' => false
    ]
];

function hcrud($valeur) {
    return htmlspecialchars((string)$valeur, ENT_QUOTES, 'UTF-8');
}

function photoUrl($nom) {
    return $nom !== '' ? 'images/' . rawurlencode(basename($nom)) : '';
}

$type = $_POST['type'] ?? $_GET['type'] ?? 'produits';
if (!isset($types[$type])) {
    $type = 'produits';
}

$config = $types[$type];

if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_admin'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Formulaire invalide. Recharge la page.');
        }

        $type = $_POST['type'] ?? 'produits';
        if (!isset($types[$type])) {
            throw new RuntimeException('Type invalide.');
        }

        $config = $types[$type];
        $table = $config['table'];
        $photoCol = $config['photo_col'];
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);

        // SUPPRIMER
        if ($action === 'supprimer') {
            if ($id < 1) {
                throw new RuntimeException('Identifiant invalide.');
            }

            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
            $stmt->execute([$id]);

            header('Location: admin.php?type=' . urlencode($type) . '&message=' . urlencode('Élément supprimé avec succès.'));
            exit;
        }

        // AJOUTER OU MODIFIER
        if ($action === 'ajouter' || $action === 'modifier') {
            $nom = trim($_POST['nom'] ?? '');
            $prix = filter_var($_POST['prix'] ?? '', FILTER_VALIDATE_FLOAT);
            $description = trim($_POST['description'] ?? '');
            $categorie = trim($_POST['categorie'] ?? 'Tous');
            $stock = filter_var($_POST['stock'] ?? '0', FILTER_VALIDATE_INT);

            if ($nom === '' || $prix === false || $prix < 0) {
                throw new RuntimeException('Le nom et un prix valide sont obligatoires.');
            }

            if ($config['categorie_stock'] && ($stock === false || $stock < 0)) {
                throw new RuntimeException('Le stock doit être un nombre entier positif ou nul.');
            }

            $photo = '';

            if ($action === 'modifier') {
                if ($id < 1) {
                    throw new RuntimeException('Identifiant invalide.');
                }

                $stmt = $pdo->prepare("SELECT `$photoCol` AS photo FROM `$table` WHERE id = ?");
                $stmt->execute([$id]);
                $ancien = $stmt->fetch();

                if (!$ancien) {
                    throw new RuntimeException('Élément introuvable.');
                }

                $photo = (string)($ancien['photo'] ?? '');
            }

            // Enregistrer une nouvelle photo si elle est fournie
            if (isset($_FILES['photo']) &&
                $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {

                if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Erreur lors du chargement de la photo.');
                }

                if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
                    throw new RuntimeException('La photo ne doit pas dépasser 5 Mo.');
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['photo']['tmp_name']);

                $extensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($extensions[$mime])) {
                    throw new RuntimeException('Formats autorisés : JPG, PNG ou WEBP.');
                }

                $dossier = __DIR__ . '/images';

                if (!is_dir($dossier)) {
                    mkdir($dossier, 0755, true);
                }

                $nouveauNom = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];

                if (!move_uploaded_file(
                    $_FILES['photo']['tmp_name'],
                    $dossier . '/' . $nouveauNom
                )) {
                    throw new RuntimeException('Impossible d’enregistrer la photo.');
                }

                $photo = $nouveauNom;
            }

            if ($config['categorie_stock']) {
                if ($action === 'ajouter') {
                    $sql = "INSERT INTO produits
                        (nom, prix, description, image, categorie, stock)
                        VALUES (?, ?, ?, ?, ?, ?)";

                    $params = [
                        $nom, $prix, $description, $photo,
                        $categorie !== '' ? $categorie : 'Tous',
                        $stock
                    ];
                } else {
                    $sql = "UPDATE produits
                        SET nom=?, prix=?, description=?, image=?, categorie=?, stock=?
                        WHERE id=?";

                    $params = [
                        $nom, $prix, $description, $photo,
                        $categorie !== '' ? $categorie : 'Tous',
                        $stock, $id
                    ];
                }
            } else {
                if ($action === 'ajouter') {
                    $sql = "INSERT INTO marchandises
                        (nom, prix, description, photo)
                        VALUES (?, ?, ?, ?)";

                    $params = [$nom, $prix, $description, $photo];
                } else {
                    $sql = "UPDATE marchandises
                        SET nom=?, prix=?, description=?, photo=?
                        WHERE id=?";

                    $params = [$nom, $prix, $description, $photo, $id];
                }
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $texte = $action === 'ajouter'
                ? 'Élément ajouté avec succès.'
                : 'Modifications enregistrées.';

            header('Location: admin.php?type=' . urlencode($type) . '&message=' . urlencode($texte));
            exit;
        }

        throw new RuntimeException('Action inconnue.');
    }

    if (isset($_GET['message'])) {
        $message = (string)$_GET['message'];
    }

    // Charger l'élément à modifier
    if (isset($_GET['modifier'])) {
        $id = (int)$_GET['modifier'];

        $stmt = $pdo->prepare("SELECT * FROM `{$config['table']}` WHERE id = ?");
        $stmt->execute([$id]);
        $modifier = $stmt->fetch();

        if (!$modifier) {
            $erreur = 'Élément à modifier introuvable.';
        }
    }

    // Afficher la liste
    $stmt = $pdo->query("SELECT * FROM `{$config['table']}` ORDER BY id DESC");
    $elements = $stmt->fetchAll();

    // Les commandes sont facultatives
    try {
        $commandes = $pdo->query("SELECT * FROM commandes ORDER BY id DESC")->fetchAll();
    } catch (Throwable $e) {
        $commandes = [];
    }

} catch (Throwable $e) {
    $erreur = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administration - Célestine Fashion</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f5f5f8;
    color: #252238;
}

header {
    background: #241b38;
    color: white;
    padding: 22px;
    text-align: center;
}

nav {
    background: #e91e63;
    padding: 14px;
    text-align: center;
}

nav a {
    color: white;
    margin: 8px 12px;
    text-decoration: none;
    font-weight: bold;
    display: inline-block;
}

main {
    max-width: 1200px;
    margin: auto;
    padding: 20px;
}

section {
    background: white;
    padding: 22px;
    margin-bottom: 24px;
    border-radius: 10px;
    box-shadow: 0 2px 8px #0001;
}

h2 {
    color: #d81b60;
}

input, textarea, select {
    width: 100%;
    padding: 11px;
    margin: 7px 0 14px;
    border: 1px solid #ccc;
    border-radius: 6px;
}

button, .btn {
    background: #e91e63;
    color: white;
    padding: 10px 14px;
    border: 0;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.danger {
    background: #c62828;
}

.tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin: 15px 0;
}

.tabs a {
    padding: 10px 14px;
    background: #eee;
    border-radius: 6px;
    text-decoration: none;
    color: #241b38;
}

.tabs a.active {
    background: #241b38;
    color: white;
}

.scroll {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    min-width: 650px;
}

th, td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: left;
}

th {
    background: #241b38;
    color: white;
}

.photo {
    width: 75px;
    height: 75px;
    object-fit: cover;
}

.success {
    color: #16743b;
}

.error {
    color: #b71c1c;
}

.actions {
    display: flex;
    gap: 7px;
    align-items: center;
    flex-wrap: wrap;
}

.actions form {
    margin: 0;
}

@media (max-width: 600px) {
    main {
        padding: 10px;
    }

    section {
        padding: 14px;
    }
}
</style>
</head>

<body>

<header>
    <h1>Célestine Fashion</h1>
    <p>Tableau de bord administrateur</p>
</header>

<nav>
    <a href="#gestion">Gestion du catalogue</a>
    <a href="#commandes">Commandes</a>
    <a href="index.php">Voir la boutique</a>
    <a href="logout.php">Déconnexion</a>
</nav>

<main>

<?php if ($message): ?>
    <p class="success"><?= hcrud($message) ?></p>
<?php endif; ?>

<?php if ($erreur): ?>
    <p class="error"><?= hcrud($erreur) ?></p>
<?php endif; ?>

<section id="gestion">
    <h2>Gestion des produits et marchandises</h2>

    <div class="tabs">
        <a class="<?= $type === 'produits' ? 'active' : '' ?>"
           href="admin.php?type=produits">Produits</a>

        <a class="<?= $type === 'marchandises' ? 'active' : '' ?>"
           href="admin.php?type=marchandises">Marchandises</a>
    </div>

    <h3><?= $modifier ? 'Modifier' : 'Ajouter' ?> : <?= hcrud($config['titre']) ?></h3>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= hcrud($csrf) ?>">
        <input type="hidden" name="type" value="<?= hcrud($type) ?>">
        <input type="hidden" name="action"
               value="<?= $modifier ? 'modifier' : 'ajouter' ?>">
        <input type="hidden" name="id"
               value="<?= (int)($modifier['id'] ?? 0) ?>">

        <label>Nom</label>
        <input type="text" name="nom" required
               value="<?= hcrud($modifier['nom'] ?? '') ?>">

        <label>Prix</label>
        <input type="number" name="prix" min="0" step="0.01" required
               value="<?= hcrud($modifier['prix'] ?? '') ?>">

        <label>Description</label>
        <textarea name="description" rows="3"><?= hcrud($modifier['description'] ?? '') ?></textarea>

        <?php if ($config['categorie_stock']): ?>
            <label>Catégorie</label>
            <select name="categorie">
                <?php
                $categories = ['Tous', 'Homme', 'Femme', 'Enfant'];
                $categorieActuelle = $modifier['categorie'] ?? 'Tous';
                foreach ($categories as $cat):
                ?>
                    <option value="<?= hcrud($cat) ?>"
                        <?= $categorieActuelle === $cat ? 'selected' : '' ?>>
                        <?= hcrud($cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Stock</label>
            <input type="number" name="stock" min="0" step="1" required
                   value="<?= hcrud($modifier['stock'] ?? 0) ?>">
        <?php endif; ?>

        <label>Photo (JPG, PNG ou WEBP — 5 Mo maximum)</label>
        <input type="file" name="photo"
               accept="image/jpeg,image/png,image/webp">

        <?php
        $anciennePhoto = $modifier[$config['photo_col']] ?? '';
        if ($anciennePhoto):
        ?>
            <p>Photo actuelle :</p>
            <img class="photo" src="<?= hcrud(photoUrl($anciennePhoto)) ?>"
                 alt="Photo actuelle">
        <?php endif; ?>

        <button type="submit">
            <?= $modifier ? 'Enregistrer les modifications' : 'Ajouter' ?>
        </button>

        <?php if ($modifier): ?>
            <a class="btn" href="admin.php?type=<?= hcrud($type) ?>">Annuler</a>
        <?php endif; ?>
    </form>
</section>

<section>
    <h2>Liste des <?= strtolower($config['titre']) ?></h2>

    <?php if (empty($elements)): ?>
        <p>Aucun élément enregistré pour le moment.</p>
    <?php else: ?>
        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Nom</th>
                        <th>Prix</th>
                        <?php if ($config['categorie_stock']): ?>
                            <th>Catégorie</th>
                            <th>Stock</th>
                        <?php endif; ?>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($elements as $el): ?>
                    <tr>
                        <td>
                            <?php if (!empty($el[$config['photo_col']])): ?>
                                <img class="photo"
                                     src="<?= hcrud(photoUrl($el[$config['photo_col']])) ?>"
                                     alt="Photo du produit">
                            <?php else: ?>
                                Aucune photo
                            <?php endif; ?>
                        </td>

                        <td><?= hcrud($el['nom'] ?? '') ?></td>
                        <td><?= hcrud($el['prix'] ?? '') ?></td>

                        <?php if ($config['categorie_stock']): ?>
                            <td><?= hcrud($el['categorie'] ?? '') ?></td>
                            <td><?= hcrud($el['stock'] ?? 0) ?></td>
                        <?php endif; ?>

                        <td><?= hcrud($el['description'] ?? '') ?></td>

                        <td>
                            <div class="actions">
                                <a class="btn"
                                   href="admin.php?type=<?= hcrud($type) ?>&modifier=<?= (int)$el['id'] ?>">
                                    Modifier
                                </a>

                                <form method="POST"
                                      onsubmit="return confirm('Confirmer la suppression ?')">
                                    <input type="hidden" name="csrf"
                                           value="<?= hcrud($csrf) ?>">
                                    <input type="hidden" name="type"
                                           value="<?= hcrud($type) ?>">
                                    <input type="hidden" name="action"
                                           value="supprimer">
                                    <input type="hidden" name="id"
                                           value="<?= (int)$el['id'] ?>">

                                    <button class="danger" type="submit">
                                        Supprimer
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section id="commandes">
    <h2>Commandes des clients</h2>

    <?php if (empty($commandes)): ?>
        <p>Aucune commande enregistrée ou table commandes indisponible.</p>
    <?php else: ?>
        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <?php foreach (array_keys($commandes[0]) as $col): ?>
                            <th><?= hcrud($col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commandes as $commande): ?>
                        <tr>
                            <?php foreach ($commande as $val): ?>
                                <td><?= hcrud($val) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</main>
</body>
</html>
