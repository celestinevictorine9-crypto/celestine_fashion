<?php
session_start();

$host = "localhost";
$dbname = "celestine_fashion";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("Erreur de connexion à MySQL. Vérifiez XAMPP et la base de données.");
}

/* ==================================
   FONCTIONS
================================== */

function h($valeur) {
    return htmlspecialchars(
        (string)$valeur,
        ENT_QUOTES,
        'UTF-8'
    );
}

function photoProduit($image) {
    if (filter_var($image, FILTER_VALIDATE_URL)) {
        return $image;
    }
    return "images/" . rawurlencode($image);
}

if (!isset($_SESSION['panier'])) {
    $_SESSION['panier'] = [];
}

$erreur = "";
if (empty($_SESSION['csrf_public'])) { $_SESSION['csrf_public'] = bin2hex(random_bytes(32)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf_public'], $_POST['csrf_public'] ?? '')) { http_response_code(403); exit('Formulaire invalide'); }
$succes = "";

/* ==================================
   AJOUTER AU PANIER
================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajouter'])
) {
    $id = trim((string)($_POST['produit_id'] ?? ''));

    if (preg_match('/^(?:([pm])_)?([1-9][0-9]*)$/', $id, $matches)) {

        $prefixe = $matches[1] ?? '';
        $table = ($prefixe === 'm') ? 'marchandises' : 'produits';
        $idProduit = (int)$matches[2];

        if ($table === 'produits') {
            $stmt = $pdo->prepare(
                "SELECT id, stock FROM produits WHERE id = ?"
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, 10 AS stock FROM marchandises WHERE id = ?"
            );
        }

        $stmt->execute([$idProduit]);
        $produit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($produit) {
            $quantite = (int)($_SESSION['panier'][$id] ?? 0);

            if ($quantite < (int)$produit['stock']) {
                $_SESSION['panier'][$id] = $quantite + 1;
            } else {
                $erreur = "Stock insuffisant.";
            }
        } else {
            $erreur = "Produit ou marchandise introuvable.";
        }
    } else {
        $erreur = "Identifiant invalide.";
    }

    header("Location: index.php#panier");
    exit;
}



/* ==================================
   SUPPRIMER DU PANIER
================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['supprimer'])) {

    $id = (int)($_POST['produit_id'] ?? 0);
    unset($_SESSION['panier'][$id]);

    header("Location: index.php#panier");
    exit;
}

/* ==================================
   ENREGISTRER UNE COMMANDE
================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['commander'])) {

    $nom = trim($_POST['nom'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');

    if ($nom === '' || $telephone === ''
        || $adresse === '') {

        $erreur = "Veuillez remplir tous les champs.";

    } elseif (empty($_SESSION['panier'])) {

        $erreur = "Votre panier est vide.";

    } else {

        try {
            $pdo->beginTransaction();

            $articlesCommande = [];
            $totalCommande = 0;

            foreach ($_SESSION['panier'] as $id => $qte) {

                $stmt = $pdo->prepare(
                    "SELECT * FROM produits
                     WHERE id = ? FOR UPDATE"
                );
                $stmt->execute([(int)$id]);
                $produit = $stmt->fetch();

                if (!$produit || $qte <= 0
                    || $qte > $produit['stock']) {

                    throw new Exception(
                        "Stock insuffisant pour un produit."
                    );
                }

                $produit['quantite'] = (int)$qte;
                $articlesCommande[] = $produit;

                $totalCommande +=
                    $produit['prix'] * $qte;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO commandes
                (nom_client, telephone, adresse, total)
                VALUES (?, ?, ?, ?)"
            );

            $stmt->execute([
                $nom,
                $telephone,
                $adresse,
                $totalCommande
            ]);

            $commandeId = $pdo->lastInsertId();

            foreach ($articlesCommande as $produit) {

                $stmt = $pdo->prepare(
                    "INSERT INTO details_commandes
                    (commande_id, produit_id, quantite, prix)
                    VALUES (?, ?, ?, ?)"
                );

                $stmt->execute([
                    $commandeId,
                    $produit['id'],
                    $produit['quantite'],
                    $produit['prix']
                ]);

                $stmt = $pdo->prepare(
                    "UPDATE produits
                     SET stock = stock - ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $produit['quantite'],
                    $produit['id']
                ]);
            }

            $pdo->commit();

            $_SESSION['panier'] = [];

            $succes = "Commande enregistrée ! Numéro : "
                . $commandeId;

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $erreur = $e->getMessage();
        }
    }
}

/* ==================================
   RÉCUPÉRER LES PRODUITS
================================== */

$produits = $pdo->query("
SELECT
 CONCAT('p_' , id) AS id,
    id, nom, prix, description,
            image, categorie, stock
     FROM produits
    UNION ALL
    SELECT
    CONCAT('m_' , id) AS id,
    id, nom, prix, description,
   photo AS image,'Tous' AS categorie,
   10 AS stock
   FROM marchandises"

)->fetchAll(PDO::FETCH_ASSOC);


/* ==================================
   CALCULER LE PANIER
================================== */

$articles = [];
$total = 0;

foreach ($_SESSION['panier'] as $id => $quantite) {

    $produit = null;

    foreach ($produits as $p) {
        if ((string)$p['id'] === (string)$id) {
            $produit = $p;
            break;
        }
    }

    if ($produit) {

        $produit['quantite'] = (int)$quantite;

        $produit['sous_total'] =
            $produit['prix'] * $quantite;

        $articles[] = $produit;
        $total += $produit['sous_total'];
    }
}

$nombrePanier = array_sum($_SESSION['panier']);
?>

<!DOCTYPE html>
<html lang="fr">

<head>
<meta charset="UTF-8">
<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Celestine_fashion - Vente de vêtements homme et femme</title>


</head>

<body>

<!-- PROMOTION -->

<div class="promo">
    Bienvenue chez Celestine_fashion
    — Découvrez nos nouvelles collections !
</div>

<!-- MENU -->
<link rel="stylesheet" href="css/style.css">
<header>

    <h2>CELESTINE FASHION</h2>

    <nav>
        <a href="#accueil">Accueil</a>
        <a href="#boutique">Boutique</a>

        <a href="#boutique"
           onclick="choisirCategorie('Homme')">
            Hommes
        </a>

        <a href="#boutique"
           onclick="choisirCategorie('Femme')">
            Femmes
        </a>

        <a href="#panier">
            Panier (<?= $nombrePanier ?>)
        </a>

        <a href="#contact">Contact</a>
<a href="login.php">Administration</a>
    </nav>

</header>

<!-- ACCUEIL -->

<div class="hero" id="accueil">

    <div class="hero-contenu">

        <h1>
            Vente de vêtements homme et femme.
        </h1>

        <p>
            
            Découvrez nos collections modernes,
            élégantes et accessibles.
            La mode qui révèle
            votre élégance
        </p>

        <a href="#boutique" class="btn">
            Acheter maintenant
        </a>

    </div>

</div>

<!-- BOUTIQUE -->

<section id="boutique">

    <h2 class="titre">Nos collections</h2>

    <p class="sous-titre">
        Les dernières tendances pour hommes et femmes
    </p>

    <div class="recherche">

        <input type="search"
            id="recherche"
            placeholder="Rechercher un vêtement..."
            oninput="afficherProduits()">

    </div>

    <div class="filtres">

        <button class="actif"
            data-filtre="Tous"
            onclick="choisirCategorie('Tous')">
            Tous
        </button>

        <button data-filtre="Homme"
            onclick="choisirCategorie('Homme')">
            Hommes
        </button>

        <button data-filtre="Femme"
            onclick="choisirCategorie('Femme')">
            Femmes
        </button>

    </div>

    <div class="grille">

    <?php foreach ($produits as $p): ?>

        <div class="produit"
            data-categorie="<?= h($p['categorie']) ?>"
            data-nom="<?= h($p['nom']) ?>">

            <img
                src="<?= h(photoProduit($p['image'])) ?>"
                alt="<?= h($p['nom']) ?>"
                loading="lazy">

            <div class="infos">

                <div class="categorie">
                    Collection <?= h($p['categorie']) ?>
                </div>

                <h3><?= h($p['nom']) ?></h3>

                <p class="description">
                    <?= h($p['description']) ?>
                </p>

                <div class="prix">
                    <?= number_format($p['prix'], 2) ?> $
                </div>

                <div class="stock">
                    Stock : <?= (int)$p['stock'] ?>
                </div>

                <form method="POST"><input type="hidden" name="csrf_public" value="<?= h($_SESSION['csrf_public']) ?>"><input type="hidden" name="csrf_public" value="<?= h($_SESSION['csrf_public']) ?>">

                    <input type="hidden"
                        name="produit_id"
                        value="<?= htmlspecialchars((string) $p['id'], ENT_QUOTES, 'UTF-8') ?>">

                    <button class="btn"
                        type="submit"
                        name="ajouter"
                        <?= $p['stock'] <= 0
                            ? 'disabled' : '' ?>>

                        <?= $p['stock'] > 0
                            ? 'Ajouter au panier'
                            : 'Rupture de stock' ?>

                    </button>

                </form>

            </div>
        </div>

    <?php endforeach; ?>

    </div>

</section>

<!-- PANIER -->

<section class="zone-panier" id="panier">

    <h2 class="titre">Mon panier</h2>

    <div class="conteneur-panier">

        <?php if ($erreur): ?>

            <div class="message">
                <?= h($erreur) ?>
            </div>

        <?php endif; ?>

        <?php if ($succes): ?>

            <div class="message">
                <?= h($succes) ?>
            </div>

        <?php endif; ?>

        <div class="carte-panier">

        <?php if (empty($articles)): ?>

            <p>Votre panier est vide.</p>

        <?php else: ?>

            <?php foreach ($articles as $p): ?>

                <div class="article">

                    <div>

                        <h3><?= h($p['nom']) ?></h3>

                        <p>
                            Quantité :
                            <?= (int)$p['quantite'] ?>
                        </p>

                        <p class="prix">
                            <?= number_format(
                                $p['sous_total'], 2
                            ) ?> $
                        </p>

                    </div>

                    <form method="POST"><input type="hidden" name="csrf_public" value="<?= h($_SESSION['csrf_public']) ?>"><input type="hidden" name="csrf_public" value="<?= h($_SESSION['csrf_public']) ?>">

                        <input type="hidden"
                            name="produit_id"
                            value="<?= (int)$p['id'] ?>">

                        <button class="supprimer"
                            type="submit"
                            name="supprimer">
                            Supprimer
                        </button>

                    </form>

                </div>

            <?php endforeach; ?>

            <div class="total">
                Total :
                <?= number_format($total, 2) ?> $
            </div>

        <?php endif; ?>

        </div>

        <!-- FORMULAIRE DE COMMANDE -->

        <?php if (!empty($articles)): ?>

        <div class="carte-panier formulaire">

            <h2>Finaliser la commande</h2>

            <form method="POST"><input type="hidden" name="csrf_public" value="<?= h($_SESSION['csrf_public']) ?>"><input type="hidden" name="csrf_public" value="<?= h($_SESSION['csrf_public']) ?>">

                <label>Nom complet</label>

                <input type="text"
                    name="nom"
                    maxlength="100"
                    required>

                <label>Numéro de téléphone</label>

                <input type="tel"
                    name="telephone"
                    maxlength="30"
                    required>

                <label>Adresse de livraison</label>

                <textarea
                    name="adresse"
                    rows="4"
                    required></textarea>

                <button class="btn"
                    type="submit"
                    name="commander">
                    Confirmer la commande
                </button>

            </form>

        </div>

        <?php endif; ?>

    </div>

</section>

<!-- AVANTAGES -->

<section class="avantages">

    <div class="avantage">
        <h3>Mode tendance</h3>
        <p>Des vêtements modernes et élégants</p>
    </div>

    <div class="avantage">
        <h3>Prix accessibles</h3>
        <p>Des articles pour tous les budgets</p>
    </div>

    <div class="avantage">
        <h3>Commande facile</h3>
        <p>Commandez directement sur notre site</p>
    </div>

</section>

<!-- CONTACT -->

<section class="contact" id="contact">

    <h2 class="titre">Contactez-nous</h2>

    <p>
        Celestine_fashion,
        votre boutique de mode en ligne.
    </p>

    <p>Vente de vêtements homme et femme</p>

</section>

<!-- PIED DE PAGE -->

<footer>

    <h3>CELESTINE_FASHION</h3>

    <p>Vente de vêtements homme et femme</p>

    <p>
        &copy; <?= date('Y') ?>
        Celestine_fashion.
        Tous droits réservés.
    </p>

</footer>

<!-- JAVASCRIPT -->

<script>

let categorieActive = "Tous";

function choisirCategorie(categorie) {

    categorieActive = categorie;

    document.querySelectorAll(
        ".filtres button"
    ).forEach(bouton => {

        bouton.classList.toggle(
            "actif",
            bouton.dataset.filtre === categorie
        );

    });

    afficherProduits();
}

function afficherProduits() {

    const recherche = document.getElementById(
        "recherche"
    ).value.toLowerCase();

    document.querySelectorAll(
        ".produit"
    ).forEach(produit => {

        const nom = produit.dataset.nom.toLowerCase();
        const categorie = produit.dataset.categorie;

        const visible =
            (categorieActive === "Tous" ||
             categorie === categorieActive)
            && nom.includes(recherche);

        produit.style.display =
            visible ? "block" : "none";

    });
}

</script>

</body>
</html>

