<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

//DB設定
require("src/setting/DB_connect.php");
$pdo = DBconnect();