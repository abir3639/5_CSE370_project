# Campus Ride-Sharing & Lost and Found System

**Course:** CSE370 — Database Systems

**Project Type:** Full-Stack Web Application (University Term Project)

---

## 📌 Project Overview

This project is a centralized **Campus Ride-Sharing Platform** designed specifically for university students and faculty members. It facilitates cost-effective, secure, and reliable carpooling across campus commute routes while incorporating a dedicated **Lost & Found** management module to handle misplaced belongings during trips.

The system emphasizes relational database design principles, normalized schemas, role-based interaction, real-time trip coordination, and automated data operations.

---

## ✨ Key Features

* **User Authentication & Profiles:** Secure registration, login/logout session handling, and user profile management.


* **Ride Matching & Publishing:**
* Drivers can offer rides by specifying start/end points, departure times, available seats, and fares.


* Interactive map and coordinate selection using built-in geocoding tools (`location_picker.js`, `api_geocode.php`).


* Passengers can browse, filter, and request seats on active rides.




* **Trip Coordination & Messaging:** Direct communication channel between riders and drivers (`chat.php`) and automated trip alerts (`notifications.php`, `api_actions.php`).


* **Ratings & Reviews:** Post-trip feedback system for passengers and drivers to build platform trust (`rate.php`).


* **Lost & Found Module:** Dedicated interface for reporting, tracking, and claiming lost items from shared rides (`lost_found.php`).


* **Analytics & Admin Dashboard:** Summary metrics and usage statistics (`stats.php`).


* **Testing & Maintenance Utilities:** Built-in test suite (`test_suite.php`) and test-user reset scripts (`reset_users.php`).



---

## 🛠️ Tech Stack

* **Backend:** PHP (Native / PDO / MySQLi)


* **Frontend:** HTML5, CSS3 (`style.css`), Vanilla JavaScript (`location_picker.js`)


* **Database:** MySQL / MariaDB


* **Containerization & Deployment:** Docker & Docker Compose



---

## 📂 Project Structure

```text
5_CSE370_project/
├── docker-compose.yml       # Docker configuration for web server & database
├── .gitignore               # Ignored files and directories
└── html/                    # Application source files
    ├── index.php            # Homepage / ride search & listing
    ├── login.php            # User authentication
    ├── register.php         # Account creation
    ├── logout.php           # Session termination
    ├── profile.php          # User profile management
    ├── offer_ride.php       # Driver ride creation interface
    ├── ride_details.php     # Detailed ride view & booking
    ├── my_rides.php         # User ride history & active bookings
    ├── chat.php             # Rider-driver direct messaging
    ├── notifications.php    # Status updates and request alerts
    ├── rate.php             # Review and rating submission
    ├── lost_found.php       # Lost & Found reporting module
    ├── stats.php            # Platform analytics dashboard
    ├── db.php               # Database connection configuration
    ├── schema.sql           # Database schema & table definitions
    ├── setup_db.php         # Database migration/initialization script
    ├── reset_users.php      # Utility to reset test users
    ├── test_suite.php       # Functional verification test runner
    ├── api_actions.php      # Backend AJAX action handlers
    ├── api_geocode.php      # Geolocation lookup API
    ├── helpers.php          # Reusable helper functions
    ├── location_picker.js   # Client-side map location picker script
    └── style.css            # Global styling

```

---

## 🚀 Getting Started & Setup Guide

### Option 1: Running with Docker (Recommended)

1. **Clone the repository:**
```bash
git clone https://github.com/abir3639/5_CSE370_project.git
cd 5_CSE370_project

```


2. **Start the containers:**
```bash
docker compose up -d

```


*(Or `docker-compose up -d` depending on your Docker version)*

3. **Initialize the Database:**
* Open your browser and navigate to: `http://localhost:8080/setup_db.php` (or your configured port).


* This executes the database initialization script and loads the schema from `schema.sql`.




4. **Access the application:**
* Navigate to `http://localhost:8080` (or the port defined in your `docker-compose.yml`).





---

### Option 2: Running with Local Server (XAMPP / WAMP / LAMP)

1. **Move files to web root:**
* Place the contents of the `html/` directory into your web server root (e.g., `htdocs/5_CSE370_project` for XAMPP).




2. **Database Setup:**
* Start MySQL in your XAMPP/WAMP control panel.
* Open `phpMyAdmin` and create a database (or configure your credentials in `html/db.php`).


* Import the `html/schema.sql` file or navigate to `http://localhost/5_CSE370_project/setup_db.php` in your browser.




3. **Run the Application:**
* Open `http://localhost/5_CSE370_project` in your browser.





---

## 🧪 Testing & Verification

* To run test routines for application logic and API responses, run `test_suite.php` directly from your browser:


```text
http://localhost:<PORT>/test_suite.php

```


* To reset demo/test accounts during development, use `reset_users.php`.``markdown
# Campus Ride-Sharing & Lost and Found System
**Course:** CSE370 — Database Systems  
**Project Type:** Full-Stack Web Application (University Term Project)

---

## 📌 Project Overview

This project is a centralized **Campus Ride-Sharing Platform** designed specifically for university students and faculty members. It facilitates cost-effective, secure, and reliable carpooling across campus commute routes while incorporating a dedicated **Lost & Found** management module to handle misplaced belongings during trips.

The system emphasizes relational database design principles, normalized schemas, role-based interaction, real-time trip coordination, and automated data operations.

---

## ✨ Key Features

- **User Authentication & Profiles:** Secure registration, login/logout session handling, and user profile management[cite: 1].
- **Ride Matching & Publishing:**
  - Drivers can offer rides by specifying start/end points, departure times, available seats, and fares[cite: 1].
  - Interactive map and coordinate selection using built-in geocoding tools (`location_picker.js`, `api_geocode.php`)[cite: 1].
  - Passengers can browse, filter, and request seats on active rides[cite: 1].
- **Trip Coordination & Messaging:** Direct communication channel between riders and drivers (`chat.php`) and automated trip alerts (`notifications.php`, `api_actions.php`)[cite: 1].
- **Ratings & Reviews:** Post-trip feedback system for passengers and drivers to build platform trust (`rate.php`)[cite: 1].
- **Lost & Found Module:** Dedicated interface for reporting, tracking, and claiming lost items from shared rides (`lost_found.php`)[cite: 1].
- **Analytics & Admin Dashboard:** Summary metrics and usage statistics (`stats.php`)[cite: 1].
- **Testing & Maintenance Utilities:** Built-in test suite (`test_suite.php`) and test-user reset scripts (`reset_users.php`)[cite: 1].

---

## 🛠️ Tech Stack

- **Backend:** PHP (Native / PDO / MySQLi)[cite: 1]
- **Frontend:** HTML5, CSS3 (`style.css`), Vanilla JavaScript (`location_picker.js`)[cite: 1]
- **Database:** MySQL / MariaDB[cite: 1]
- **Containerization & Deployment:** Docker & Docker Compose[cite: 1]

---

## 📂 Project Structure

```text
5_CSE370_project/
├── docker-compose.yml       # Docker configuration for web server & database[cite: 1]
├── .gitignore               # Ignored files and directories[cite: 1]
└── html/                    # Application source files[cite: 1]
    ├── index.php            # Homepage / ride search & listing[cite: 1]
    ├── login.php            # User authentication[cite: 1]
    ├── register.php         # Account creation[cite: 1]
    ├── logout.php           # Session termination[cite: 1]
    ├── profile.php          # User profile management[cite: 1]
    ├── offer_ride.php       # Driver ride creation interface[cite: 1]
    ├── ride_details.php     # Detailed ride view & booking[cite: 1]
    ├── my_rides.php         # User ride history & active bookings[cite: 1]
    ├── chat.php             # Rider-driver direct messaging[cite: 1]
    ├── notifications.php    # Status updates and request alerts[cite: 1]
    ├── rate.php             # Review and rating submission[cite: 1]
    ├── lost_found.php       # Lost & Found reporting module[cite: 1]
    ├── stats.php            # Platform analytics dashboard[cite: 1]
    ├── db.php               # Database connection configuration[cite: 1]
    ├── schema.sql           # Database schema & table definitions[cite: 1]
    ├── setup_db.php         # Database migration/initialization script[cite: 1]
    ├── reset_users.php      # Utility to reset test users[cite: 1]
    ├── test_suite.php       # Functional verification test runner[cite: 1]
    ├── api_actions.php      # Backend AJAX action handlers[cite: 1]
    ├── api_geocode.php      # Geolocation lookup API[cite: 1]
    ├── helpers.php          # Reusable helper functions[cite: 1]
    ├── location_picker.js   # Client-side map location picker script[cite: 1]
    └── style.css            # Global styling[cite: 1]

```

---

## 🚀 Getting Started & Setup Guide

### Option 1: Running with Docker (Recommended)

1. **Clone the repository:**
```bash
git clone [https://github.com/abir3639/5_CSE370_project.git](https://github.com/abir3639/5_CSE370_project.git)
cd 5_CSE370_project

```


2. **Start the containers:**
```bash
docker compose up -d

```


*(Or `docker-compose up -d` depending on your Docker version)*

3. **Initialize the Database:**
* Open your browser and navigate to: `http://localhost:8080/setup_db.php` (or your configured port).


* This executes the database initialization script and loads the schema from `schema.sql`.




4. **Access the application:**
* Navigate to `http://localhost:8080` (or the port defined in your `docker-compose.yml`).





---

### Option 2: Running with Local Server (XAMPP / WAMP / LAMP)

1. **Move files to web root:**
* Place the contents of the `html/` directory into your web server root (e.g., `htdocs/5_CSE370_project` for XAMPP).




2. **Database Setup:**
* Start MySQL in your XAMPP/WAMP control panel.
* Open `phpMyAdmin` and create a database (or configure your credentials in `html/db.php`).


* Import the `html/schema.sql` file or navigate to `http://localhost/5_CSE370_project/setup_db.php` in your browser.




3. **Run the Application:**
* Open `http://localhost/5_CSE370_project` in your browser.





---

## 🧪 Testing & Verification

* To run test routines for application logic and API responses, run `test_suite.php` directly from your browser:
```text
http://localhost:<PORT>/test_suite.php

```



* To reset demo/test accounts during development, use `reset_users.php`.



```

```
