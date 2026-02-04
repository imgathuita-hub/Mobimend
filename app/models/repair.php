<?php
class Repair {
    private $conn;
    private $table = "repairs";

    public $customer_name;
    public $phone;
    public $device_type;
    public $issue_description;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $query = "INSERT INTO " . $this->table . "
                  (customer_name, phone, device_type, issue_description)
                  VALUES (:customer_name, :phone, :device_type, :issue_description)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':customer_name', $this->customer_name);
        $stmt->bindParam(':phone', $this->phone);
        $stmt->bindParam(':device_type', $this->device_type);
        $stmt->bindParam(':issue_description', $this->issue_description);

        return $stmt->execute();
    }
}
?>
