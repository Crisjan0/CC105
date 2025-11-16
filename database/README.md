# Database Setup

This directory contains the SQL script to create and initialize the school enrollment database.

## Quick Setup

### Option 1: Using MySQL Command Line
```bash
mysql -u root -p < school_enrollment.sql
```

### Option 2: Using phpMyAdmin
1. Open phpMyAdmin in your browser
2. Go to the "Import" tab
3. Select the `school_enrollment.sql` file
4. Click "Go" to execute the script

## Database Information

- **Database Name**: `school_enrollment`
- **Character Set**: utf8mb4
- **Collation**: utf8mb4_general_ci

## Tables Created

1. **users** - Stores user account information (students and admins)
2. **courses** - Stores available courses
3. **enrollments** - Tracks student course enrollments
4. **payments** - Records payment transactions

## Default Users

The database includes two default users for testing:

**Admin Account**
- Username: `Admin`
- Password: (hashed - use the application to login)
- Email: admin@example.com

**Student Account**
- Username: `Student`
- Password: (hashed - use the application to login)
- Email: student@example.com

## Configuration

Make sure to update the database connection settings in `/includes/db_connect.php`:
- Host: localhost (default)
- Database: school_enrollment
- Username: root (default)
- Password: (empty by default, set as needed)
