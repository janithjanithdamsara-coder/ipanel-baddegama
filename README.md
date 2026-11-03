# i-Panel Baddegama Portal 🏢✨

> **Ceiling Panels, Materials Inventory, Direct Counter Billing, Quotation Estimator & Client Project Management System**  
> *Developed for i-Panel Baddegama Branch, Sri Lanka.*

---

## 🌟 Overview

**i-Panel Baddegama Portal** is a web-based operational system built for ceiling and wall panel installers, retail outlets, and distribution centers. It allows seamless management of materials inventory, fast counter checkout with automatic stock deduction, client project estimation, custom quotation printing, and detailed business revenue analytics.

---

## 🚀 Key Modules & Features

### 1. 📦 Materials & Inventory Management
- Real-time catalog tracking for:
  - **Ceiling Panels** (LITE, HEAVY B, HEAVY Flat, custom lengths & widths)
  - **Finishing Profiles** (Perimeter trims, joiners, transition profiles)
  - **Accessories** (H, L, U corners, TOP corner joints)
- Dynamic search & category filtering.
- In-stock count tracking with inline quick-adjust for administrators.

### 2. 🧾 Direct Billing & Fast Counter Sales
- Walk-in customer cash & card checkout.
- Itemized billing with auto unit price calculation.
- **Automatic Inventory Deduction:** Stock levels are automatically reduced upon billing.
- Instant retail invoice generation with printable receipt view (`print_invoice.php`).

### 3. 🏗️ Client Project Tracker & Quotation Builder
- Manage installation site work orders from `Draft` to `Completed`.
- Multi-item quotation builder with transport costs, installation labor, discounts, and tax rates.
- Professional printable estimates and PDF export (`print_quotation.php`).

### 4. 📊 Analytics, Reports & Data Export
- Comprehensive sales revenue, payment method breakdowns, and daily summaries.
- Material valuation and stock warning alerts (low stock & out-of-stock).
- One-click CSV exports for accounting.

### 5. 🛡️ Enterprise Security Hardening
Audited and hardened against OWASP Top 10 vulnerabilities:
- **CSRF Defense:** Cryptographic CSRF tokens on all state-changing actions.
- **Brute-Force & Rate Limiting:** Account lockout after 5 consecutive failed login attempts within 5 minutes.
- **Session Protection:** `HttpOnly`, `SameSite=Lax`, and HTTPS `Secure` cookie parameters, with 30-minute idle session timeout.
- **Session Fixation Prevention:** Session ID regeneration upon authentication.
- **Database File Protection:** Root `.htaccess` blocking direct public HTTP downloads of `.sqlite` and backup files.
- **Audit Logs:** Full logging of logins, inventory updates, deletions, and administrative resets.
- **HTTP Security Headers:** `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, and `Referrer-Policy`.

---

## 💻 Tech Stack

- **Backend:** Pure PHP 8.x (zero bulky external dependencies)
- **Database:** SQLite 3 (self-contained, zero-configuration)
- **Frontend:** Vanilla HTML5, modern CSS3 (Custom Responsive Dark/Light Accents), Vanilla JavaScript
- **Web Server:** Apache / LiteSpeed (XAMPP compatible)

---

## ⚙️ Installation & Setup (Localhost)

1. **Clone the repository:**
   ```bash
   git clone https://github.com/janithjanithdamsara-coder/ipanel-baddegama.git
   ```

2. **Move to web server directory:**
   Place the project folder into your web root (e.g., `C:\xampp\htdocs\ipanel-baddegama`).

3. **Start Apache in XAMPP:**
   Ensure the Apache module is running in your XAMPP Control Panel.

4. **Launch the Portal:**
   Open your browser and navigate to:
   ```
   http://localhost/ipanel-baddegama/
   ```

5. **First-Time Database Initialization:**
   The SQLite database schema, default materials catalog, and initial administrator accounts are **automatically created** on the first run.

6. **Default Logins:**
   - **Administrator:** `admin` / `admin123`
   - **Billing Desk:** `staff` / `staff123`

---

## 📄 License
This project is proprietary software for internal business operations at **i-Panel Baddegama Branch**. All rights reserved.
