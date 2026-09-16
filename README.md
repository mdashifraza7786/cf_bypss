# Reverse Proxy - User Guide

A lightweight PHP-based reverse proxy designed to forward incoming web requests to a designated target domain, bypass bot/Cloudflare checks using modern browser emulation headers, manage session cookies, and dynamically rewrite responses so users stay within the proxy host.

---

## 1. Project Structure

```text
├── .htaccess                   # Web server routing rules (redirects all requests to proxy script)
├── cookie.txt                  # Stores session/authentication cookies in Netscape format
├── <your_script_name>.php      # Main reverse proxy logic (contains target domain & cookie placeholders)
└── README.md                   # Documentation and setup instructions
```

### File Details:
- **`.htaccess`**: Ensures all incoming paths/routes are directed through the proxy script and protects sensitive files like `cookie.txt` from direct public download.
- **`cookie.txt`**: Plain text file storing valid Netscape-formatted cookies (e.g., exported from browser after login).
- **`<your_script_name>.php`**: Intercepts requests, forwards them using cURL with realistic browser fingerprinting, applies cookies, and rewrites URLs in responses.

---

## 2. Overview

This script acts as an intermediary between a client browser and a target web service:
- **Request Interception:** Captures incoming HTTP requests (GET, POST, PUT, etc.) along with parameters and headers.
- **Forwarding:** Dispatches requests to the target domain using cURL with Chrome-like TLS ciphers and HTTP/2.
- **Cookie Injection:** Injects authenticated session cookies from a local cookie file.
- **Response Rewriting:** Dynamically updates hyperlinks, assets, redirection (`Location`) headers, and content-security-policies to route through the proxy host.

---

## 3. Configuration

Open your PHP proxy file and configure the placeholders defined at the top:

```php
// Target Domain & Scheme
$targetDomain = 'TARGET_DOMAIN_PLACEHOLDER'; // e.g., 'www.example.com'
$targetScheme = 'https';                      // 'https' or 'http'

// Cookie Domain (used for filtering cookies from the cookie file)
$cookieDomain = 'COOKIE_DOMAIN_PLACEHOLDER'; // e.g., 'example.com' (without www)
```

### Example
If proxying to `https://www.mysite.com`:
```php
$targetDomain = 'www.mysite.com';
$targetScheme = 'https';
$cookieDomain = 'mysite.com';
```

---

## 4. Cookie Setup

The script reads cookies from a Netscape-formatted `cookie.txt` file located in the same directory.

### How to export cookies:
1. Install a browser extension such as **"Get cookies.txt LOCALLY"** (available for Chrome/Firefox).
2. Navigate to your target website and log in.
3. Export the cookies in **Netscape HTTP Cookie format**.
4. Save or copy the exported content into a file named `cookie.txt` in the script's directory.

### Netscape Format Structure:
```text
# Domain	Include subdomains	Path	Secure	Expiration	Name	Value
.example.com	TRUE	/	TRUE	1893456000	cf_clearance	sample_token_here
.example.com	TRUE	/	TRUE	1893456000	session_id	sample_session_here
```

> **Note:** The script will automatically filter out expired cookies and only include cookies matching `$cookieDomain` or `$targetDomain`.

---

## 5. How to Run

### Local Testing (PHP Built-in Server)
You can start a local development server using:
```bash
php -S localhost:8000 <your_script_name>.php
```
Then visit `http://localhost:8000` in your web browser.

### Web Server (Apache / Nginx)
Direct all incoming traffic to the proxy script:

- **Apache (`.htaccess`):**
  Ensure the placeholder in `.htaccess` points to your script file name:
  ```apache
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^(.*)$ <your_script_name>.php [QSA,L]
  ```

- **Nginx:**
  ```nginx
  location / {
      try_files $uri $uri/ /<your_script_name>.php?$query_string;
  }
  ```

---

## 6. Key Features

- **Dynamic URL Rewriting:** Automatically replaces target domain links inside HTML, CSS, JavaScript, and HTTP headers with the proxy host.
- **Browser Fingerprinting:** Matches Chrome 131 HTTP headers, `sec-ch-ua` attributes, TLS v1.2/v1.3, and standard cipher suites to prevent bot detection.
- **Cloudflare Token Refresh:** Checks for `__cf_bm` cookie expiration and attempts a headless refresh when expired.
- **Full HTTP Method Support:** Forwards raw request bodies for `POST`, `PUT`, and `PATCH` requests.

---

## 7. Common Issues & Solutions

| Issue | Cause | Solution |
| :--- | :--- | :--- |
| **502 Proxy Error** | Invalid target URL or network issue | Verify `$targetDomain` and verify the PHP cURL extension is installed. |
| **Logged Out / Session Lost** | Expired or missing cookies | Export fresh cookies to `cookie.txt` and ensure `$cookieDomain` matches. |
| **Broken Styles or Redirects** | Hardcoded external assets | Check if the target site loads assets from secondary CDNs not covered by the domain placeholder. |
