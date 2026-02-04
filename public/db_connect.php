<?php
$host = "localhost";
$user = "root"; // replace with your username
$pass = "";     // replace with your password
$dbname = "repairs_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
