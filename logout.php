<?php
require 'auth.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
verify();$_SESSION=[];session_destroy();header('Location: login.php');exit;
