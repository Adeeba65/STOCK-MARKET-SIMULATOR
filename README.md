# STOCK-MARKET-SIMULATOR


A realistic, multi-user stock trading simulator built as a semester project for **Advanced Programming** course.

## 📌 Tech Stack
- **Frontend:** C# WinForms (.NET Framework 4.8)
- **Backend:** PHP 8 REST API
- **Database:** MySQL (10 tables + 2 views)
- **Server:** XAMPP

## ✨ Features

### 👤 Guest
- Browse live market
- View leaderboard
- Read market news

### 👨‍💼 Registered User
- Virtual trading with $100,000 cash
- Portfolio tracking with P/L
- Watchlist and price alerts
- PDF export

### 🔧 Admin
- Manage stocks (CRUD)
- Manage users
- Publish market news

## 📊 Database Schema
- 10 normalized tables with foreign keys
- Analytical views for leaderboard & portfolio valuation
- Parameterized PDO queries for security

## 🛠️ Setup Instructions

1. Start XAMPP (Apache + MySQL)
2. Import `database/schema.sql` to phpMyAdmin
3. Copy `api/` folder to `C:\xampp\htdocs\stockapi`
4. Open `StockMarketSimulator.sln` in Visual Studio
5. Press F5 to run

## 🔐 Login Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@stocksim.local | admin123 |
| User | demo@stocksim.local | user1234 |


## 📅 Course
Advanced Programming (C# .NET)
