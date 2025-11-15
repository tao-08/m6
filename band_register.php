<?php
session_start();
//DB設定
require("src/setting/DB_connect.php");
$pdo = DBconnect();

header("location:/member_upload");