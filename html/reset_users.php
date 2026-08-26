<?php
require_once 'db.php';

echo "<h2>Resetting and Re-seeding Database</h2>";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    $tablesToDrop = [
        'Notification', 'Rating', 'RideParticipant', 'RideRequest', 
        'Ride', 'FavoriteLocation', 'Vehicle', 'Driver', 
        'Passenger', 'User_Phone', 'User', 'Admin'
    ];
    
    foreach ($tablesToDrop as $tbl) {
        $pdo->exec("DROP TABLE IF EXISTS `$tbl`");
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Re-include db.php to recreate and seed tables
    // Since we already included it, we can trigger the same initialization logic by instantiating/calling it,
    // or since require_once won't run again, let's execute the creation & seeding logic directly.
    
} catch (Exception $e) {
    echo "<p style='color:red;'>Error dropping tables: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Since require_once 'db.php' was already executed at the top, let's define the recreation logic explicitly in case
// db.php was already loaded. Actually, to be safe, we can just run the table creation and seed scripts here or
// run a separate PDO connection that does it.
// Even better: let's write reset_users.php to connect, drop all tables, and then run the exact same table definitions and inserts.
// Let's write the complete code:
?>
<?php
define('DB_HOST_RESET', 'localhost');
define('DB_USER_RESET', 'root');
define('DB_PASS_RESET', '');
define('DB_NAME_RESET', 'rideshare_db');

try {
    $pdoReset = new PDO("mysql:host=" . DB_HOST_RESET . ";charset=utf8mb4", DB_USER_RESET, DB_PASS_RESET, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $pdoReset->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME_RESET . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdoReset->exec("USE `" . DB_NAME_RESET . "`");
    
    $pdoReset->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tablesToDrop = ['Notification', 'Rating', 'RideParticipant', 'RideRequest', 'Ride', 'FavoriteLocation', 'Vehicle', 'Driver', 'Passenger', 'User_Phone', 'User', 'Admin'];
    foreach ($tablesToDrop as $tbl) {
        $pdoReset->exec("DROP TABLE IF EXISTS `$tbl`");
    }
    $pdoReset->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Now include db.php but since we dropped, we can just execute the file or connect again.
    // To ensure the tables are created by db.php, we can simply redirect to setup_db.php or call a function,
    // or just run db.php's code by reading and executing it, or simply writing the CREATE TABLE statements here.
    // Let's write the CREATE TABLE statements to make this script completely self-contained and robust!
    
    $tables = [
        "Admin" => "CREATE TABLE `Admin` (
            `AdminID` INT AUTO_INCREMENT PRIMARY KEY,
            `Email` VARCHAR(100) NOT NULL UNIQUE,
            `Role` VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB",

        "User" => "CREATE TABLE `User` (
            `UserID` INT AUTO_INCREMENT PRIMARY KEY,
            `Name` VARCHAR(100) NOT NULL,
            `Email` VARCHAR(100) NOT NULL UNIQUE,
            `Password` VARCHAR(255) NOT NULL,
            `Gender` ENUM('Male', 'Female', 'Other') NOT NULL,
            `Age` INT CHECK (`Age` >= 18),
            `UserType` ENUM('Passenger', 'Driver', 'Admin') NOT NULL DEFAULT 'Passenger',
            `AdminID` INT NULL,
            `ProfileImage` VARCHAR(255) NULL,
            `UniversityVerified` TINYINT(1) DEFAULT 0,
            `RatingAverage` DECIMAL(3,2) DEFAULT 5.00,
            `RatingCount` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`AdminID`) REFERENCES `Admin`(`AdminID`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "User_Phone" => "CREATE TABLE `User_Phone` (
            `UserID` INT NOT NULL,
            `Phone` VARCHAR(20) NOT NULL,
            PRIMARY KEY (`UserID`, `Phone`),
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Passenger" => "CREATE TABLE `Passenger` (
            `UserID` INT PRIMARY KEY,
            `PassRating` DECIMAL(3,2) DEFAULT 5.00 CHECK (`PassRating` BETWEEN 0.00 AND 5.00),
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Driver" => "CREATE TABLE `Driver` (
            `UserID` INT PRIMARY KEY,
            `LicenseNo` VARCHAR(50) NOT NULL UNIQUE,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Vehicle" => "CREATE TABLE `Vehicle` (
            `RegNo` VARCHAR(50) PRIMARY KEY,
            `Model` VARCHAR(100) NOT NULL,
            `UserID` INT NOT NULL,
            FOREIGN KEY (`UserID`) REFERENCES `Driver`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "FavoriteLocation" => "CREATE TABLE `FavoriteLocation` (
            `LocID` INT AUTO_INCREMENT PRIMARY KEY,
            `Address` VARCHAR(255) NOT NULL,
            `UserID` INT NOT NULL,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Ride" => "CREATE TABLE `Ride` (
            `RideID` INT AUTO_INCREMENT PRIMARY KEY,
            `DriverID` INT NOT NULL,
            `StartLocation` VARCHAR(255) NOT NULL,
            `Destination` VARCHAR(255) NOT NULL,
            `StartLatitude` DECIMAL(10, 8) NULL,
            `StartLongitude` DECIMAL(11, 8) NULL,
            `DestinationLatitude` DECIMAL(10, 8) NULL,
            `DestinationLongitude` DECIMAL(11, 8) NULL,
            `RideDate` DATE NOT NULL,
            `DepartureTime` TIME NOT NULL,
            `AvailableSeats` INT NOT NULL,
            `TotalSeats` INT NOT NULL,
            `VehicleInfo` VARCHAR(255) NULL,
            `SharedCost` DECIMAL(10,2) DEFAULT 0.00,
            `Notes` TEXT NULL,
            `Status` ENUM('Draft', 'Open', 'Full', 'Pending', 'Confirmed', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Open',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`DriverID`) REFERENCES `Driver`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "RideRequest" => "CREATE TABLE `RideRequest` (
            `RequestID` INT AUTO_INCREMENT PRIMARY KEY,
            `RideID` INT NOT NULL,
            `PassengerID` INT NOT NULL,
            `Status` ENUM('Pending', 'Accepted', 'Rejected') NOT NULL DEFAULT 'Pending',
            `RequestedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `RespondedAt` TIMESTAMP NULL,
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`PassengerID`) REFERENCES `Passenger`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "RideParticipant" => "CREATE TABLE `RideParticipant` (
            `RideID` INT NOT NULL,
            `UserID` INT NOT NULL,
            `Role` ENUM('Driver', 'Passenger') NOT NULL DEFAULT 'Passenger',
            `ArrivalStatus` ENUM('Pending', 'Reached', 'Not Reached') NOT NULL DEFAULT 'Pending',
            `JoinedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`RideID`, `UserID`),
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Rating" => "CREATE TABLE `Rating` (
            `RatingID` INT AUTO_INCREMENT PRIMARY KEY,
            `RideID` INT NOT NULL,
            `ReviewerID` INT NOT NULL,
            `RecipientID` INT NOT NULL,
            `Rating` INT NOT NULL CHECK (`Rating` BETWEEN 1 AND 5),
            `Review` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`ReviewerID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`RecipientID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Notification" => "CREATE TABLE `Notification` (
            `NotificationID` INT AUTO_INCREMENT PRIMARY KEY,
            `UserID` INT NOT NULL,
            `Type` VARCHAR(50) NOT NULL,
            `Title` VARCHAR(100) NOT NULL,
            `Message` TEXT NOT NULL,
            `IsRead` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    ];

    foreach ($tables as $name => $sql) {
        $pdoReset->exec($sql);
    }
    
    $pdoReset->exec("INSERT INTO `Admin` (`AdminID`, `Email`, `Role`) VALUES (1, 'admin@rideshare.com', 'SuperAdmin')");

    $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
    $pdoReset->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `AdminID`, `UniversityVerified`) VALUES ('Admin User', 'admin@rideshare.com', ?, 'Other', 30, 'Admin', 1, 0)")
        ->execute([$defaultPassword]);

    $stmt = $pdoReset->prepare("INSERT INTO `User` (`UserID`, `Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `AdminID`, `UniversityVerified`, `RatingAverage`, `RatingCount`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([1, 'Alice Johnson', 'alice@example.com', $defaultPassword, 'Female', 24, 'Passenger', 1, 0, 4.85, 1]);
    $stmt->execute([2, 'Bob Smith', 'bob@example.com', $defaultPassword, 'Male', 32, 'Driver', 1, 0, 5.00, 1]);
    $stmt->execute([3, 'Carol Williams', 'carol@example.com', $defaultPassword, 'Female', 29, 'Passenger', 1, 0, 4.90, 1]);
    $stmt->execute([4, 'David Miller', 'david@example.com', $defaultPassword, 'Male', 40, 'Driver', 1, 0, 5.00, 0]);
    $stmt->execute([6, 'Rahim Ahmed', 'rahim@g.bracu.ac.bd', $defaultPassword, 'Male', 21, 'Passenger', 1, 1, 4.80, 1]);
    $stmt->execute([7, 'Karim Islam', 'karim@g.bracu.ac.bd', $defaultPassword, 'Male', 23, 'Driver', 1, 1, 4.50, 1]);

    $phoneStmt = $pdoReset->prepare("INSERT INTO `User_Phone` (`UserID`, `Phone`) VALUES (?, ?)");
    $phoneStmt->execute([1, '+1-555-0101']);
    $phoneStmt->execute([1, '+1-555-0102']);
    $phoneStmt->execute([2, '+1-555-0201']);
    $phoneStmt->execute([3, '+1-555-0301']);
    $phoneStmt->execute([4, '+1-555-0401']);
    $phoneStmt->execute([6, '+880-1711-223344']);
    $phoneStmt->execute([7, '+880-1811-556677']);

    $pdoReset->prepare("INSERT INTO `Passenger` (`UserID`, `PassRating`) VALUES (1, 4.85), (3, 4.90), (6, 4.80)")->execute();
    $pdoReset->prepare("INSERT INTO `Driver` (`UserID`, `LicenseNo`) VALUES (2, 'DL-NY-98765432'), (4, 'DL-CA-12345678'), (7, 'DL-BD-55667788')")->execute();

    $pdoReset->prepare("INSERT INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES ('ABC-1234', 'Toyota Camry 2022', 2), ('XYZ-5678', 'Honda Accord 2021', 4), ('DHA-9988', 'Toyota Axio 2018', 7)")->execute();

    $pdoReset->prepare("INSERT INTO `FavoriteLocation` (`LocID`, `Address`, `UserID`) VALUES (1, '123 Main St, Downtown', 1), (2, '456 University Ave, Campus', 1), (3, '789 Airport Blvd, Terminal 2', 3)")->execute();

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $rideStmt = $pdoReset->prepare("INSERT INTO `Ride` (`RideID`, `DriverID`, `StartLocation`, `Destination`, `StartLatitude`, `StartLongitude`, `DestinationLatitude`, `DestinationLongitude`, `RideDate`, `DepartureTime`, `AvailableSeats`, `TotalSeats`, `VehicleInfo`, `SharedCost`, `Notes`, `Status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $rideStmt->execute([1, 2, 'Mirpur 10', 'BRAC University', 23.8069, 90.3687, 23.7781, 90.4265, $tomorrow, '08:30:00', 3, 4, 'Toyota Camry 2022 (ABC-1234)', 120.00, 'Please be on time!', 'Open']);
    $rideStmt->execute([2, 7, 'Uttara', 'BRAC University', 23.8759, 90.3795, 23.7781, 90.4265, $tomorrow, '08:00:00', 4, 4, 'Toyota Axio 2018 (DHA-9988)', 150.00, 'AC is on. No smoking.', 'Open']);
    $rideStmt->execute([3, 2, 'BRAC University', 'Mirpur 10', 23.7781, 90.4265, 23.8069, 90.3687, $yesterday, '17:30:00', 0, 4, 'Toyota Camry 2022 (ABC-1234)', 120.00, 'Going back home.', 'Completed']);

    $partStmt = $pdoReset->prepare("INSERT INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`) VALUES (?, ?, ?, ?)");
    $partStmt->execute([3, 2, 'Driver', 'Reached']);
    $partStmt->execute([3, 1, 'Passenger', 'Reached']);

    $rateStmt = $pdoReset->prepare("INSERT INTO `Rating` (`RatingID`, `RideID`, `ReviewerID`, `RecipientID`, `Rating`, `Review`) VALUES (?, ?, ?, ?, ?, ?)");
    $rateStmt->execute([1, 3, 1, 2, 5, 'Great driver, very friendly!']);
    $rateStmt->execute([2, 3, 2, 1, 5, 'Polite and on time passenger.']);

    $reqStmt = $pdoReset->prepare("INSERT INTO `RideRequest` (`RequestID`, `RideID`, `PassengerID`, `Status`, `RequestedAt`) VALUES (?, ?, ?, ?, ?)");
    $reqStmt->execute([1, 1, 6, 'Pending', date('Y-m-d H:i:s')]);

    $notifStmt = $pdoReset->prepare("INSERT INTO `Notification` (`NotificationID`, `UserID`, `Type`, `Title`, `Message`, `IsRead`) VALUES (?, ?, ?, ?, ?, ?)");
    $notifStmt->execute([1, 2, 'request', 'New Join Request', 'Rahim Ahmed has requested to join your ride from Mirpur 10 to BRAC University.', 0]);

    echo "<p style='color:green; font-weight:bold;'>Database successfully re-seeded with BRAC University Rideshare tables and records!</p>";

    $test = $pdoReset->query("SELECT * FROM `User`")->fetchAll();
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>UserID</th><th>Name</th><th>Email</th><th>Password Verify Test</th></tr>";
    foreach ($test as $u) {
        $verify = password_verify('password123', $u['Password']) ? '<span style="color:green">VALID MATCH</span>' : '<span style="color:red">FAILED</span>';
        echo "<tr><td>{$u['UserID']}</td><td>{$u['Name']}</td><td>{$u['Email']}</td><td>{$verify}</td></tr>";
    }
    echo "</table>";
    echo "<br><a href='login.php' style='display:inline-block; padding:10px 20px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px;'>Go to Login Page</a>";

} catch (Exception $e) {
    echo "<p style='color:red;'>Error resetting database: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
