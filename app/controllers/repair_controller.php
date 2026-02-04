<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Repair.php';

class RepairController {
    public function createRepair() {
        $database = new Database();
        $db = $database->connect();

        $repair = new Repair($db);

        $repair->customer_name = $_POST['customer_name'];
        $repair->phone = $_POST['phone'];
        $repair->device_type = $_POST['device_type'];
        $repair->issue_description = $_POST['issue'];

        if ($repair->create()) {
            echo "success";
        } else {
            echo "error";
        }
    }
}

$controller = new RepairController();
$controller->createRepair();
