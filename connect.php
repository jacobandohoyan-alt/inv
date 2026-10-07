<?php
$servername = "localhost"; //server address
$username = "root";
$password = "";
$dbname = "abuzodb";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection Failed!". $conn->connect_error);
}

// echo "Connected Successfully";
?>