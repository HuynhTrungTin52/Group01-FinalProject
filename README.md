 GROUP 01 \- FINAL PROJECT: LOPAY E-WALLET

1. SYSTEM REQUIREMENTS  
* Local server environment: XAMPP (or WAMP/MAMP) installed on the machine.  
* Required modules: PHP and MySQL.  
* Network requirement: An active Internet connection is strictly required for the PHPMailer library to function properly (sending registration passwords, OTP codes, and notifications).  
2. SOURCE CODE INSTALLATION  
* Step 1: Extract the submitted project archive from [HuynhTrungTin52/Group01-FinalProject](https://github.com/HuynhTrungTin52/Group01-FinalProject).  
* Step 2: Copy the entire extracted folder named "GROUP01-FINALPROJECT-MAIN".  
* Step 3: Paste the folder into the "htdocs" directory of your XAMPP installation (Default path usually is C:\\xampp\\htdocs).  
* Step 4: Open the XAMPP Control Panel and click "Start" for both Apache and MySQL modules.  
3. DATABASE CONFIGURATION  
* Step 1: Open a web browser and navigate to http://localhost/phpmyadmin/  
* Step 2: Create a new database named "ewallet\_db" (Recommended Collation: utf8mb4\_general\_ci).  
* Step 3: Select the newly created "ewallet\_db" database from the left sidebar.  
* Step 4: Click on the "Import" tab located at the top navigation bar.  
* Step 5: In the "File to import" section, click "Choose File" and select the "ewallet\_db.sql" file located in the root directory of the project folder.  
* Step 6: Scroll down to the bottom and click the "Import" or "Go" button to execute the script. Note: The database connection configuration in the "db.php" file is set to the XAMPP default settings (username: "root", password: "").  
4. RUNNING THE PROJECT  
* Open your web browser and access the following URL:  [paynest.fun](http://paynest.fun)  
* The application will load and redirect you to the main interface.  
5. TEST CREDENTIALS FOR EVALUATION To facilitate the grading process, please use the following predefined credentials and test data:

Admin Account:

* Email/Username: admin@gmail.com  
* Password: Admin@123456

Verified User Account:

* Email/Username: hongbaonhi6@gmail.com  
* Password: Hongbaonhi6@

Credit Card Data for Deposit Testing:

* Valid card (Unlimited top-up): Card number: 111111 | Expiration: 10/10/2022 | CVV: 411  
* Insufficient funds (Generates "out of money" message): Card number: 222222 | Expiration: 11/11/2022 | CVV: 443 Card number: 333333 | Expiration: 12/12/2022 | CVV: 577  
* Unsupported card: Any 6-digit number not listed above (e.g., 999999).

End of document.

