# atsreadycv
A lightweight AI-powered CV tailoring portal built with vanilla PHP, MySQL, Tailwind CSS, and the OpenAI API. Generates ATS-friendly resumes, supports DOCX export, and includes IP-based rate limiting.


# CV Tailor Portal v4

A lightweight Vanilla PHP, MySQL and TailwindCSS portal for tailoring a CV against a job description through the OpenAI Chat Completions API.

## Folder structure

```text
/
├── index.php                  Public UI
├── process.php                AJAX generation endpoint
├── download.php               DOCX download endpoint
├── assets/
│   ├── css/app.css            Existing page and print styles
│   └── js/app.js              Existing UI behavior plus rate-limit countdown
├── app/
│   ├── bootstrap.php          Autoloader and configuration loader
│   ├── Config/                App, database and OpenAI configuration
│   ├── Database/              PDO connection factory
│   ├── Repositories/          MySQL transaction access
│   ├── Services/              OpenAI, IP rate limiting and DOCX generation
│   └── Support/               HTTP, session/security and text helpers
└── database/
    ├── schema.sql             Fresh installation
    └── migration_v4.sql       Upgrade from the previous version
```

The public filenames remain `index.php`, `process.php` and `download.php`, so the working browser flow and URLs are unchanged.

## Upgrade an existing installation

1. Back up the current files and MySQL database.
2. Import `database/migration_v4.sql` into the same database. This adds only the rate-limit table.
3. Enter the existing database credentials in `app/Config/database.php`.
4. Enter the existing API key in `app/Config/openai.php`.
5. Upload the complete folder structure into the current portal directory and overwrite the three public PHP files.
6. Keep the generated value in `app/Config/app.php` under `rate_limit.hash_secret`, or replace it with another long random value. Do not change it repeatedly because existing hashed-IP rows would no longer match.
7. Test one CV generation and one DOCX download.

Do not import `schema.sql` when upgrading unless the original transaction table is missing. `migration_v4.sql` is the intended upgrade script.

## IP rate limiting

- A maximum of **5 valid generation submissions per IP address** is allowed in a one-hour window.
- The attempt is reserved after CSRF and input validation, immediately before the OpenAI request.
- The IP itself is not stored. MySQL stores an HMAC-SHA256 hash using `rate_limit.hash_secret`.
- When the fifth attempt is used, the Generate button is disabled and shows a live countdown.
- Refreshing the page preserves the blocked state because the limit is stored in MySQL.
- After the window resets, five attempts become available automatically.
- Users sharing one public IP, such as an office, Wi-Fi network or carrier NAT, also share the five-attempt allowance.

### Cloudflare

The secure default uses `REMOTE_ADDR`. When the domain is definitely behind Cloudflare, set:

```php
'trust_cloudflare_ip_header' => true,
```

in `app/Config/app.php`. Do not enable it when requests can reach the origin directly.

## Hosting requirements

- PHP 8.1 or newer
- PDO MySQL
- cURL
- MySQL/MariaDB with InnoDB
- Apache/LiteSpeed `.htaccess` support is recommended to block direct access to `app` and `database`

No Composer packages or PHP ZIP extension are required. The existing native DOCX generator remains unchanged internally.
