<?php
if (session_status() === PHP_SESSION_NONE){
session_start();
}

require __DIR__.'/connexion.php';
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function token(){return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));}
function verify(){if(!hash_equals(token(),$_POST['csrf']??'')){http_response_code(403);exit('Formulaire invalide');}}
function adminOnly(){if(empty($_SESSION['admin_id'])){header('Location: login.php');exit;}}
