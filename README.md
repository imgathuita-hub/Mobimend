# Mobimend



Mobimend is a comprehensive phone care platform designed to manage repair bookings, sales of parts and accessories, and wholesale distribution. It integrates a customer-facing portal with a powerful admin backend, creating a seamless operating system for a phone repair business.

The system features a dual-backend architecture: a primary PHP/MySQL application handles the core website and business logic, while a dedicated Node.js/MongoDB service provides robust authentication and advanced inventory management with transactional integrity.

## Key Features

*   **Repair Management:** Customers can book repairs by selecting their device and issue. Admins can manage the entire lifecycle of a booking from a dedicated dashboard.
*   **Inventory Control:** Live inventory tracking for both retail accessories and wholesale parts. The system supports stock adjustments, movement auditing, and automated low-stock alerts.
*   **E-Commerce:**
    *   **Retail Shop:** A public-facing accessories store with a shopping cart and checkout flow that deducts from live inventory.
    *   **Wholesale Desk:** A specialized portal for B2B customers to order parts in bulk, with support for Minimum Order Quantity (MOQ) rules.
*   **Admin Operations Hub:** A central dashboard (`admin_dashboard.php`) provides a single view for managing new bookings, part requirements, low-stock items, payment verifications, and wholesale applications.
*   **Secure Authentication:** A role-based access control system for customers, technicians, and administrators.
*   **Payment & Order Tracking:** Functionality to create payment records (M-Pesa, Bank Transfer, Cash) and track order statuses from creation to completion.

## Project Structure

The repository is organized into several key directories:

*   **`php_backend/`**: The primary application built with PHP and MySQL. It contains all public-facing pages (`repair.php`, `accessories.php`, `wholesale.php`), admin dashboards, and the core business logic. This is the main entry point for the application.
*   **`backend/`**: A supporting microservice built with Node.js, Express, and MongoDB. It handles user authentication and provides a transactional inventory management API, ensuring data integrity during complex stock operations like checkouts.
*   **`public/`**: Contains static HTML/CSS prototypes and assets. These serve as a design reference for the dynamic PHP pages.
*   **`docs/`**: Project documentation, including the data model and an overview of the project structure.

## Tech Stack

*   **Primary Application:** PHP 8.2+, MySQL / MariaDB
*   **Inventory & Auth Service:** Node.js, Express.js, MongoDB, Mongoose
*   **Frontend:** HTML, CSS, JavaScript

## Setup and Installation

To run the full application, both the PHP and Node.js backends must be set up.

### PHP Backend (`php_backend/`)

1.  **Configure Environment:**
    *   Copy `php_backend/.env.example` to `php_backend/.env`.
    *   Update the `.env` file with your database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`) and application URL.

2.  **Database Setup:**
    *   Create a new MySQL database.
    *   Import the database schema from `php_backend/database/schema.sql`.

3.  **Run Admin Seeder:**
    *   Navigate to `php_backend/public/setup_admin.php` in your browser.
    *   Use the form to create the first administrative user. You will need the `ADMIN_SETUP_TOKEN` from your `.env` file.

4.  **Web Server:**
    *   Configure a web server like Apache or Nginx to serve the `php_backend/public/` directory.

### Node.js Backend (`backend/`)

This service runs the inventory and authentication APIs.

1.  **Navigate to the directory:**
    ```bash
    cd backend
    ```

2.  **Install Dependencies:**
    ```bash
    npm install
    ```

3.  **Configure Environment:**
    *   Copy `backend/.env.example` to `backend/.env`.
    *   Update the `.env` file with your MongoDB connection string (`MONGO_URI`) and JWT secret.

4.  **Seed Admin User:**
    *   This script seeds the first admin user into the MongoDB database, which is used by the Node.js authentication service. Ensure your `.env` file is configured with the desired `ADMIN_EMAIL` and `ADMIN_PASSWORD`.
    ```bash
    npm run seed:admin
    ```

5.  **Start the Server:**
    *   The server will run on the port specified in your `.env` file (defaults to 4000).
    ```bash
    npm run dev
