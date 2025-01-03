# Comprehensive Documentation: Migrating `advert-zendesk-batch.php` to Laravel

This document outlines the migration process of the `advert-zendesk-batch.php` script to a Laravel-based application. The migration focuses on leveraging Laravel’s features like Eloquent ORM, dependency injection, and task scheduling to modernize and optimize the functionality.

---

## **1. Overview**
The `advert-zendesk-batch.php` script contains various functions to handle Zendesk tickets, inventory tool updates, and advertising ticket metrics. Migrating this to Laravel involves:

1. Transforming procedural code into structured, reusable services and controllers.
2. Replacing raw SQL queries with Eloquent ORM.
3. Utilizing Laravel’s task scheduling for periodic execution.
4. Implementing error handling and logging using Laravel’s built-in features.
5. Enhancing code readability and maintainability.

---

## **2. Migration Process**

### **2.1 Setting Up Laravel Environment**
Ensure your Laravel environment is properly configured:

1. **Install Laravel**:
   ```bash
   composer create-project laravel/laravel lucky-21-partners
   ```

2. **Set Up Database**:
   - Configure `.env` for database connection:
     ```env
     DB_CONNECTION=mysql
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_DATABASE=your_database
     DB_USERNAME=your_username
     DB_PASSWORD=your_password
     ```

3. **Install Required Packages**:
   - Example: For Zendesk API integration:
     ```bash
     composer require huddledigital/zendesk-laravel
     composer require sentry/sentry-laravel
     ```

### **2.2 Database Migration**

Create migration files for tables referenced in the script (`zendesk_tickets`, `zendesk_ticket_items`, `zendesk_ticket_tags`, `ab_inventory_tool`).

Example: Migration for `zendesk_tickets`
```php
php artisan make:migration create_zendesk_tickets_table
```

Define the schema:
```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateZendeskTicketsTable extends Migration
{
    public function up()
    {
        Schema::create('zendesk_tickets', function (Blueprint $table) {
            $table->id('ticketid');
            $table->string('storeid')->nullable();
            $table->string('subject');
            $table->text('ticket_json');
            $table->enum('status', ['open', 'solved', 'closed']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('zendesk_tickets');
    }
}
```

Run the migrations:
```bash
php artisan migrate
```

### **2.3 Model Creation**

Create Eloquent models for each table.

---

### **2.4 Service Classes**

Create service classes to encapsulate Zendesk-related logic.

Example: `TicketAutomationService.php`

### **2.5 Refactor Functions**

1. **Eloquent for Queries**:
   Replace raw SQL queries with Eloquent for better readability and maintainability.

---

### **2.6 Task Scheduling**

Use Laravel’s `Task Scheduler` to automate script execution.

1. **Create Artisan Command**:
   ```bash
   php artisan make:command AdvertZendeskBatch
   ```
---
