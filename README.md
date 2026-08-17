# VixMed Ticketing & CRM System 🏥🎫

An internal Ticketing and Customer Relationship Management (CRM) system developed in **PHP** to handle administrative requests, employee time-tracking, and internal operations for VixMed. 

This platform allows seamless internal communication, issue tracking, and workforce management with an integrated SQL database.

## 🚀 Key Features

- **Ticketing System**: Employees can open support tickets and track their statuses in real-time.
- **CRM Integration**: Manages employee profiles, departments, and user access levels.
- **Time-Tracking (Ponto)**: Integrated punch-clock system to track employee working hours efficiently.
- **Stock Management**: Built-in inventory and stock management modules (`gerenciar_estoque.php`).

## 💻 Tech Stack

- **Backend**: PHP 8+
- **Frontend**: HTML5, CSS3, JavaScript
- **Database**: MySQL (using standard PDO/MySQLi connections)
- **Deployment**: Configured to run on standard Apache/Nginx web servers (Linux environment).

## 📂 Project Structure Highlights

- `index.php`: The main entry point and dashboard.
- `novo_chamado.php` & `ver_chamado.php`: Core ticketing functionalities.
- `folhadeponto.php`: The time-tracking module for workforce management.
- `gerenciar_estoque.php` & `gerenciar_usuarios.php`: Administrative CRM functions.
- `crm/`: Subdirectory for extended Customer Relationship features.

## ⚙️ Installation & Setup

1. Clone this repository into your local web server (e.g., `htdocs` for XAMPP or `/var/www/html/` for Apache).
2. Create a MySQL database and import the `vixmed_db_export.sql` file.
3. Update database credentials in `conexao.php`.
4. Run the application locally via `http://localhost/chamadosvixmed`.

---
*This repository contains proprietary logic tailored for VixMed operations, demonstrating backend architecture and database integration.*
