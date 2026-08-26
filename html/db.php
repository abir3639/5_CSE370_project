<?php

date_default_timezone_set('Asia/Dhaka');

define('DB_HOST', 'db');
define('DB_USER', 'root');
define('DB_PASS', 'rootpassword');
define('DB_NAME', 'rideshare_db');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    $pdo->exec("SET time_zone = '+06:00'");

    // Migration Check: If old schema is detected, drop old tables.
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'User'")->fetch();
        if ($tableCheck) {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM `User` LIKE 'ProfileImage'")->fetch();
            if (!$columnCheck) {
                // Old schema! Drop all tables to trigger clean recreation.
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $oldTables = ['Rating', 'Payment', 'Ride', 'Vehicle', 'PoolMembership', 'PoolRide', 'FavoriteLocation', 'Booking', 'Driver', 'Passenger', 'User_Phone', 'User', 'Admin', 'RideRequest', 'RideParticipant', 'Notification'];
                foreach ($oldTables as $ot) {
                    $pdo->exec("DROP TABLE IF EXISTS `$ot`");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        }
    } catch (PDOException $e) {
        // Database or table doesn't exist, ignore
    }

    $tables = [
        "Admin" => "CREATE TABLE IF NOT EXISTS `Admin` (
            `AdminID` INT AUTO_INCREMENT PRIMARY KEY,
            `Email` VARCHAR(100) NOT NULL UNIQUE,
            `Role` VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB",

        "User" => "CREATE TABLE IF NOT EXISTS `User` (
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

        "User_Phone" => "CREATE TABLE IF NOT EXISTS `User_Phone` (
            `UserID` INT NOT NULL,
            `Phone` VARCHAR(20) NOT NULL,
            PRIMARY KEY (`UserID`, `Phone`),
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Passenger" => "CREATE TABLE IF NOT EXISTS `Passenger` (
            `UserID` INT PRIMARY KEY,
            `PassRating` DECIMAL(3,2) DEFAULT 5.00 CHECK (`PassRating` BETWEEN 0.00 AND 5.00),
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Driver" => "CREATE TABLE IF NOT EXISTS `Driver` (
            `UserID` INT PRIMARY KEY,
            `LicenseNo` VARCHAR(50) NOT NULL UNIQUE,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Vehicle" => "CREATE TABLE IF NOT EXISTS `Vehicle` (
            `RegNo` VARCHAR(50) PRIMARY KEY,
            `Model` VARCHAR(100) NOT NULL,
            `UserID` INT NOT NULL,
            FOREIGN KEY (`UserID`) REFERENCES `Driver`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "FavoriteLocation" => "CREATE TABLE IF NOT EXISTS `FavoriteLocation` (
            `LocID` INT AUTO_INCREMENT PRIMARY KEY,
            `Address` VARCHAR(255) NOT NULL,
            `UserID` INT NOT NULL,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Ride" => "CREATE TABLE IF NOT EXISTS `Ride` (
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
            `IsWomenOnly` TINYINT(1) DEFAULT 0,
            `Status` ENUM('Draft', 'Open', 'Full', 'Pending', 'Confirmed', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Open',
            `Distance` DECIMAL(6,2) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`DriverID`) REFERENCES `Driver`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "RideRequest" => "CREATE TABLE IF NOT EXISTS `RideRequest` (
            `RequestID` INT AUTO_INCREMENT PRIMARY KEY,
            `RideID` INT NOT NULL,
            `PassengerID` INT NOT NULL,
            `Status` ENUM('Pending', 'Accepted', 'Rejected') NOT NULL DEFAULT 'Pending',
            `RequestedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `RespondedAt` TIMESTAMP NULL,
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`PassengerID`) REFERENCES `Passenger`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "RideParticipant" => "CREATE TABLE IF NOT EXISTS `RideParticipant` (
            `RideID` INT NOT NULL,
            `UserID` INT NOT NULL,
            `Role` ENUM('Driver', 'Passenger') NOT NULL DEFAULT 'Passenger',
            `ArrivalStatus` ENUM('Pending', 'Reached', 'Not Reached') NOT NULL DEFAULT 'Pending',
            `JoinedAt` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`RideID`, `UserID`),
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "Rating" => "CREATE TABLE IF NOT EXISTS `Rating` (
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

        "Notification" => "CREATE TABLE IF NOT EXISTS `Notification` (
            `NotificationID` INT AUTO_INCREMENT PRIMARY KEY,
            `UserID` INT NOT NULL,
            `Type` VARCHAR(50) NOT NULL,
            `Title` VARCHAR(100) NOT NULL,
            `Message` TEXT NOT NULL,
            `IsRead` TINYINT(1) DEFAULT 0,
            `Link` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "RideMessage" => "CREATE TABLE IF NOT EXISTS `RideMessage` (
            `MessageID` INT AUTO_INCREMENT PRIMARY KEY,
            `RideID` INT NOT NULL,
            `SenderID` INT NOT NULL,
            `Message` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`SenderID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "LostItem" => "CREATE TABLE IF NOT EXISTS `LostItem` (
            `ItemID` INT AUTO_INCREMENT PRIMARY KEY,
            `ReportType` ENUM('Lost', 'Found') NOT NULL,
            `ItemName` VARCHAR(150) NOT NULL,
            `Category` ENUM('Electronics', 'Student ID & Cards', 'Bags & Wallets', 'Keys', 'Clothing & Accessories', 'Books & Documents', 'Other') NOT NULL DEFAULT 'Other',
            `Description` TEXT NOT NULL,
            `RideID` INT NULL,
            `LocationDetails` VARCHAR(255) NOT NULL,
            `DateLostFound` DATE NOT NULL,
            `ContactPhone` VARCHAR(50) NULL,
            `PosterID` INT NOT NULL,
            `Status` ENUM('Open', 'Claimed', 'Resolved') NOT NULL DEFAULT 'Open',
            `ResolvedBy` INT NULL,
            `ResolutionNotes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`RideID`) REFERENCES `Ride`(`RideID`) ON DELETE SET NULL ON UPDATE CASCADE,
            FOREIGN KEY (`PosterID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`ResolvedBy`) REFERENCES `User`(`UserID`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB",

        "LostItemComment" => "CREATE TABLE IF NOT EXISTS `LostItemComment` (
            `CommentID` INT AUTO_INCREMENT PRIMARY KEY,
            `ItemID` INT NOT NULL,
            `UserID` INT NOT NULL,
            `Message` TEXT NOT NULL,
            `IsClaim` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`ItemID`) REFERENCES `LostItem`(`ItemID`) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (`UserID`) REFERENCES `User`(`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB"
    ];

    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
    }

    // Auto-migration for existing tables
    try {
        $pdo->exec("ALTER TABLE `Ride` ADD COLUMN `IsWomenOnly` TINYINT(1) DEFAULT 0 AFTER `Notes`");
    } catch (PDOException $e) {
        // Column already exists
    }

    try {
        $pdo->exec("ALTER TABLE `Notification` ADD COLUMN `Link` VARCHAR(255) NULL AFTER `IsRead`");
    } catch (PDOException $e) {
        // Column already exists
    }
    
    $pdo->exec("INSERT IGNORE INTO `Admin` (`AdminID`, `Email`, `Role`) VALUES (1, 'admin@rideshare.com', 'SuperAdmin')");

    $adminUser = $pdo->query("SELECT * FROM `User` WHERE `Email` = 'admin@rideshare.com'")->fetch();
    $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
    if (!$adminUser) {
        $pdo->prepare("INSERT INTO `User` (`Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `AdminID`, `UniversityVerified`) VALUES ('Admin User', 'admin@rideshare.com', ?, 'Other', 30, 'Admin', 1, 0)")
            ->execute([$defaultPassword]);
    } else {
        $pdo->prepare("UPDATE `User` SET `Password` = ?, `UserType` = 'Admin', `Name` = 'Admin User' WHERE `Email` = 'admin@rideshare.com'")
            ->execute([$defaultPassword]);
    }

    $standardUserCount = $pdo->query("SELECT COUNT(*) FROM `User` WHERE `UserType` != 'Admin'")->fetchColumn();
    if ($standardUserCount == 0) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO `User` (`UserID`, `Name`, `Email`, `Password`, `Gender`, `Age`, `UserType`, `AdminID`, `UniversityVerified`, `RatingAverage`, `RatingCount`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([1, 'Alice Johnson', 'alice@example.com', $defaultPassword, 'Female', 24, 'Passenger', 1, 0, 4.85, 1]);
        $stmt->execute([2, 'Bob Smith', 'bob@example.com', $defaultPassword, 'Male', 32, 'Driver', 1, 0, 5.00, 1]);
        $stmt->execute([3, 'Carol Williams', 'carol@example.com', $defaultPassword, 'Female', 29, 'Passenger', 1, 0, 4.90, 1]);
        $stmt->execute([4, 'David Miller', 'david@example.com', $defaultPassword, 'Male', 40, 'Driver', 1, 0, 5.00, 0]);
        $stmt->execute([6, 'Rahim Ahmed', 'rahim@g.bracu.ac.bd', $defaultPassword, 'Male', 21, 'Passenger', 1, 1, 4.80, 1]);
        $stmt->execute([7, 'Karim Islam', 'karim@g.bracu.ac.bd', $defaultPassword, 'Male', 23, 'Driver', 1, 1, 4.50, 1]);
        $stmt->execute([8, 'Tanvir Hasan', 'tanvir.hasan@g.bracu.ac.bd', $defaultPassword, 'Male', 22, 'Driver', 1, 1, 5.00, 2]);

        $phoneStmt = $pdo->prepare("INSERT IGNORE INTO `User_Phone` (`UserID`, `Phone`) VALUES (?, ?)");
        $phoneStmt->execute([1, '+1-555-0101']);
        $phoneStmt->execute([1, '+1-555-0102']);
        $phoneStmt->execute([2, '+1-555-0201']);
        $phoneStmt->execute([3, '+1-555-0301']);
        $phoneStmt->execute([4, '+1-555-0401']);
        $phoneStmt->execute([6, '+880-1711-223344']);
        $phoneStmt->execute([7, '+880-1811-556677']);
        $phoneStmt->execute([8, '+880-1911-889900']);

        $pdo->prepare("INSERT IGNORE INTO `Passenger` (`UserID`, `PassRating`) VALUES (1, 4.85), (3, 4.90), (6, 4.80)")->execute();
        $pdo->prepare("INSERT IGNORE INTO `Driver` (`UserID`, `LicenseNo`) VALUES (2, 'DL-NY-98765432'), (4, 'DL-CA-12345678'), (7, 'DL-BD-55667788'), (8, 'DL-BD-99001122')")->execute();

        $pdo->prepare("INSERT IGNORE INTO `Vehicle` (`RegNo`, `Model`, `UserID`) VALUES ('ABC-1234', 'Toyota Camry 2022', 2), ('XYZ-5678', 'Honda Accord 2021', 4), ('DHA-9988', 'Toyota Axio 2018', 7), ('DHA-5544', 'Honda Grace Hybrid', 8)")->execute();

        $pdo->prepare("INSERT IGNORE INTO `FavoriteLocation` (`LocID`, `Address`, `UserID`) VALUES (1, '123 Main St, Downtown', 1), (2, '456 University Ave, Campus', 1), (3, '789 Airport Blvd, Terminal 2', 3)")->execute();

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $rideStmt = $pdo->prepare("INSERT IGNORE INTO `Ride` (`RideID`, `DriverID`, `StartLocation`, `Destination`, `StartLatitude`, `StartLongitude`, `DestinationLatitude`, `DestinationLongitude`, `RideDate`, `DepartureTime`, `AvailableSeats`, `TotalSeats`, `VehicleInfo`, `SharedCost`, `Notes`, `Status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $rideStmt->execute([1, 2, 'Mirpur 10', 'BRAC University', 23.8069, 90.3687, 23.7781, 90.4265, $tomorrow, '08:30:00', 3, 4, 'Toyota Camry 2022 (ABC-1234)', 120.00, 'Please be on time!', 'Open']);
        $rideStmt->execute([2, 7, 'Uttara', 'BRAC University', 23.8759, 90.3795, 23.7781, 90.4265, $tomorrow, '08:00:00', 4, 4, 'Toyota Axio 2018 (DHA-9988)', 150.00, 'AC is on. No smoking.', 'Open']);
        $rideStmt->execute([3, 2, 'BRAC University', 'Mirpur 10', 23.7781, 90.4265, 23.8069, 90.3687, $yesterday, '17:30:00', 0, 4, 'Toyota Camry 2022 (ABC-1234)', 120.00, 'Going back home.', 'Completed']);

        $partStmt = $pdo->prepare("INSERT IGNORE INTO `RideParticipant` (`RideID`, `UserID`, `Role`, `ArrivalStatus`) VALUES (?, ?, ?, ?)");
        $partStmt->execute([3, 2, 'Driver', 'Reached']);
        $partStmt->execute([3, 1, 'Passenger', 'Reached']);

        $rateStmt = $pdo->prepare("INSERT IGNORE INTO `Rating` (`RatingID`, `RideID`, `ReviewerID`, `RecipientID`, `Rating`, `Review`) VALUES (?, ?, ?, ?, ?, ?)");
        $rateStmt->execute([1, 3, 1, 2, 5, 'Great driver, very friendly!']);
        $rateStmt->execute([2, 3, 2, 1, 5, 'Polite and on time passenger.']);

        $reqStmt = $pdo->prepare("INSERT IGNORE INTO `RideRequest` (`RequestID`, `RideID`, `PassengerID`, `Status`, `RequestedAt`) VALUES (?, ?, ?, ?, ?)");
        $reqStmt->execute([1, 1, 6, 'Pending', date('Y-m-d H:i:s')]);

        $notifStmt = $pdo->prepare("INSERT IGNORE INTO `Notification` (`NotificationID`, `UserID`, `Type`, `Title`, `Message`, `IsRead`) VALUES (?, ?, ?, ?, ?, ?)");
        $notifStmt->execute([1, 2, 'request', 'New Join Request', 'Rahim Ahmed has requested to join your ride from Mirpur 10 to BRAC University.', 0]);
    }

} catch (PDOException $e) {
    die("Database Connection / Initialization Error: " . htmlspecialchars($e->getMessage()));
}
?>
