# Input Validation, Security and Error Handling

Project SMISKI e-commerce system. This document describes the validation rules, security measures and error handling built into the store, and the results of testing them.

## 1. How it is organised

| Part | File | Purpose |
|---|---|---|
| Central library | `config/validation.php` | Standard messages, `Validator` class, CSRF protection, flash messages, JSON responses, upload checks, login/message rate limits, error handler. Loaded automatically by `config/db.php`, so every page can use it. |
| Database + session | `config/db.php` | Secure session cookie (HttpOnly, SameSite=Lax), safe DB-connection error, **automatic CSRF check on every POST request**. |
| Browser validation | `assets/js/form-validation.js` | Runs on every POST form: field messages, submit blocking, phone/number restrictions, character counters, loading state, double-click protection. |
| Feedback styles | `assets/css/form-feedback.css` | `.field-error`, `.is-invalid`, `.form-alert-*`, loading spinner, in the green glass theme. |
| Upload protection | `assets/uploads/.htaccess` | Stops Apache from executing scripts placed in upload folders. |

Browser checks only improve usability. **Every rule is enforced again in PHP.**

## 2. Validation rules

| Field type | Rule | Message |
|---|---|---|
| Any required field | Not empty or whitespace-only | "This field is required." (text areas: "This field cannot be empty.") |
| Person name (register, checkout, contact, admin edit, cardholder) | 2–100 characters; letters, spaces, `.` `'` `-` | "Please enter a valid name using letters, spaces, periods, apostrophes or hyphens only." |
| Email | Valid format, at most 100 characters, stored lower-case, unique per account | "Please enter a valid email address." / "This email address is already registered." |
| Phone (all phone fields) | Digits only, 7–15 digits; letters and symbols blocked while typing | "Please enter a valid phone number using numbers only." |
| New password | 8–72 characters, at least one letter and one number | "Password must be at least 8 characters long." / "Password must contain at least one letter and one number." |
| Confirm password | Must match | "Passwords do not match. Please try again." |
| Address | 5–500 characters | Field-specific length message |
| Support subject / message | Subject 3–200, message 10–2,000 characters | "Your message exceeds the maximum allowed character limit." |
| Admin reply | 2–2,000 characters | Same as above |
| Product name | 2–200 characters, unique within its category | "A product with this name already exists in this category." |
| Product price | Greater than 0, at most ₱1,000,000, up to 2 decimals | "Price must be greater than zero." / "Price cannot be negative." |
| Stock quantity | Whole number 0–100,000 | "Please enter a whole number…" / "Stock quantity cannot be negative." |
| Description | Optional, at most 2,000 characters | Limit message |
| Category, status, payment method, order status | Must be one of the allowed values | "Please select a valid option." |
| Cart quantity | Positive whole number, never above available stock | "Insufficient stock. Only {n} item(s) available." |
| Card payment | Luhn-valid 13–19 digits, MM/YY not expired, CVV 3–4 digits | Field-specific messages |
| Images (GCash receipt, product photo) | Type detected from file **content** (not name), fully decoded with GD, at most 5 MB | "Invalid file format. Please upload an accepted file type." / "File size exceeds the maximum allowed limit." |
| IDs, search, filters, order numbers | Positive integers / allowed values / length limits | Invalid filters are reset with a notice |
| Login | Email + password required; same message for every failure | "Invalid email or password." |

## 3. Security measures

- **SQL injection:** every query uses prepared statements. Search terms escape `%` and `_`.
- **XSS:** input is stored as typed and escaped on output with `htmlspecialchars`. JSON inside `onclick` attributes uses `jsonAttr()`. Before this change, input was HTML-encoded on save, so names like *O'Brien* displayed as `O&#039;Brien`.
- **CSRF:** a per-session token on every POST form, checked automatically in `db.php`. Cart links and AJAX calls carry the token in the URL. Failure gives "Your session has expired… Please refresh the page and try again." (HTTP 403).
- **Duplicate submissions:** a one-time form token on checkout, Add Product, support messages and Contact Us, plus Post/Redirect/Get on every admin action. The submit button is disabled with a spinner while the request is processing.
- **Authentication and authorisation:**
  - Every admin page and action requires an admin session; AJAX gets 401 JSON.
  - Customers can only see their own orders, and guests must log in to track orders.
  - Support messages are always filed under the logged-in account's email.
- **Login protection:**
  - 5 failed attempts per IP within 15 minutes blocks further logins (HTTP 429). Attempts are recorded in `activity_logs` as `LOGIN_FAILED`.
  - The session ID is regenerated after login.
  - Legacy plain-text admin passwords are converted to bcrypt on first login.
  - The admin password is no longer pre-filled in the login page.
- **Message rate limit:** at most 5 support messages per account every 10 minutes.
- **Uploads:**
  - Files are checked by MIME type from content and fully decoded.
  - Files are saved under random names with the extension taken from the detected type.
  - Scripts can't run in `assets/uploads`.
  - The upload is saved only after all other checks pass, and is deleted if the order fails.
- **Business rules recalculated on the server:**
  - Prices, subtotal, the loyalty discount (including eligibility), tax, shipping, the total and stock levels are all calculated by the server.
  - Order ownership is checked on the server.
  - Values the browser sends for these are ignored.
- **Errors:**
  - `display_errors` is off and technical details go to the PHP error log (prefix `[SMISKI]`).
  - Users only ever see friendly messages: no SQL, paths or stack traces.

## 4. Error responses

| Situation | HTML pages | AJAX / JSON |
|---|---|---|
| Validation failure | Page re-shown with field messages (HTTP 422), valid values kept, passwords cleared | `{"success": false, "message", "errors": {field: msg}}`, 422 |
| Malformed request | Redirect with message (400) | 400 |
| Not logged in | Redirect to login | 401 |
| Not allowed / bad CSRF token | Redirect with message | 403 |
| Missing record | Redirect with message | 404 |
| Conflict (stock, duplicate, already paid) | Message | 409 |
| Too many attempts | Message | 429 |
| Unexpected error | Generic "Something went wrong. Please try again later." page (500), details logged | 500 |

## 5. Database integrity

- **Checkout:** the order, its items and the stock deductions run in one transaction. Stock is checked again inside it with `UPDATE … WHERE stock_quantity >= qty`, and the transaction is rolled back if any item is short.
- **Order status changes:** they lock the order row (`SELECT … FOR UPDATE`). Delivered and Cancelled are final. Cancelling or rejecting a payment restores stock exactly once.
- **Deleting a customer who has orders:** the account is deactivated instead, so order history and inventory stay consistent. This matches the existing product behaviour.
- **Deleting a delivered order:** the stock isn't restored.
- **Removed:** a checkout branch that silently created a user with the password `customer123` when the session account was missing.

## 6. Test results

Tests were run against a copy of the site in a SQLite test database: HTTP-level tests, real-browser tests in Chrome (desktop 1280px and mobile 390px), and regression tests.

**HTTP tests: 99 / 99 passed**

| Area | Scenarios | Result |
|---|---|---|
| Registration | Empty / whitespace-only required fields rejected<br>Invalid email format<br>Phone with letters<br>Password too short<br>Password without number (complexity)<br>Password confirmation mismatch<br>Name over 100 characters<br>Name with symbols rejected<br>Valid input preserved on error (not password)<br>Unexpected input type (array) rejected<br>Valid registration succeeds + redirects<br>Text stored as typed (no HTML-encoding: O'Brien)<br>Duplicate email (case-insensitive) rejected | 13/13 |
| Security | Missing/invalid CSRF token rejected (403 -> redirect)<br>Password stored as bcrypt hash<br>SQL injection in login rejected<br>Session ID regenerated after login<br>Login throttled after 5 failures (429)<br>Throttle blocks even correct password during lock window<br>XSS in search escaped<br>Upload: PHP file renamed .png rejected (MIME check)<br>Tampered price/total/discount fields ignored (server recalculated)<br>Valid image saved under safe random name (.png, not .php)<br>Customer cannot view another customer's order<br>Customer cannot view another customer's order by numeric id<br>Guest order lookup requires login<br>Email forced to account email (cannot impersonate)<br>Stored XSS payload escaped on display<br>Message rate limit (5 per 10 minutes) -> 429<br>Admin password no longer pre-filled in login page<br>Legacy admin password re-hashed on login<br>Product JSON in onclick escaped (no attribute break-out)<br>AJAX details without admin session -> 401 JSON<br>Customer cannot run admin actions | 21/21 |
| Login | Empty login fields<br>Wrong password -> "Invalid email or password."<br>Valid login succeeds | 3/3 |
| Search | Invalid filters reset with notice; injection treated as text<br>Normal search still works | 2/2 |
| Product | Malformed product id -> redirect + message<br>Missing product -> "could not be found" message | 2/2 |
| Cart | AJAX add without CSRF token -> 403 JSON<br>AJAX add valid -> 200 JSON<br>Negative / invalid id -> 400<br>Unknown action -> 400<br>Non-existent product -> 404<br>Out-of-stock product -> 409<br>Quantity exceeding stock -> "Insufficient stock. Only 2 item(s) available."<br>Cart warns when stock dropped below quantity | 8/8 |
| Checkout | Checkout redirects back when cart exceeds stock<br>Empty name/address + bad phone -> field errors (422)<br>Payment method not in allowed list<br>Card: bad Luhn number, bad expiry, short CVV<br>Card: expired card rejected<br>GCash without receipt rejected<br>Upload over 5 MB rejected<br>Valid COD order placed | 8/8 |
| Integrity | No order created by any invalid checkout<br>Stock deducted exactly once<br>Re-submitting same checkout -> no duplicate order<br>Duplicate submission (reused token) -> no 2nd ticket<br>No product created by invalid submissions<br>Refresh/resubmit of Add Product -> no duplicate<br>Customer with orders deactivated, not deleted (orders kept) | 7/7 |
| Orders | Owner can track own order<br>Malformed order number -> validation message | 2/2 |
| Messages | Empty message -> "This field cannot be empty."<br>Message over 2000 chars -> limit message<br>Valid message saved (PRG redirect)<br>Duplicate shows "This request has already been submitted." | 4/4 |
| Contact form | Errors shown beside fields after redirect + modal reopens | 1/1 |
| Admin | Admin login (legacy plain-text password) works | 1/1 |
| Admin inventory | Negative price<br>Zero price<br>Price with 3 decimals<br>Decimal stock<br>Negative stock<br>Huge stock<br>Unknown category<br>Empty name<br>Duplicate name in category<br>Description over 2000<br>Fake image rejected<br>Valid product added (PRG redirect)<br>Edit missing product -> 404 message<br>Edit with invalid status rejected | 14/14 |
| Admin customers | Duplicate email + bad phone -> field errors (no DB crash)<br>Valid edit saved | 2/2 |
| Admin orders | Delivered -> Pending blocked<br>Unknown status rejected<br>Approving a COD payment rejected<br>AJAX details returns fragment only (bug fix)<br>AJAX details missing order -> 404 JSON<br>AJAX details malformed id -> 400 JSON | 6/6 |
| Admin messages | Empty reply -> "This field cannot be empty."<br>Reply to missing message -> not-found message<br>Valid reply saved | 3/3 |
| Admin audit | Unknown action filters ignored, long search shortened | 1/1 |
| Errors | No SQL / stack traces / file paths in any response | 1/1 |

**Browser tests: 39 / 39 passed.** Run on both desktop and mobile:
- Field messages appear beside fields and clear when corrected.
- Phone letters are blocked and the character counter works.
- AJAX Add to Cart works, and over-stock shows an error toast.
- Card fields are required only when Card is chosen.
- The submit button locks and shows a spinner, and repeat clicks are ignored.
- The logout confirmation works.
- Admin forms block invalid values, server errors re-open the dialog with the typed values, and the order Details dialog works.
- No JavaScript errors.

**Regression tests: 12 / 12 passed:**
- Saved cart restored after logout and login
- Loyalty discount applied and Remove Discount honoured
- Tampered discount flag rejected
- Cancel restores stock once, and cancelled orders stay final
- Refresh doesn't repeat an action
- Every customer and admin page loads
- Admin pages redirect guests to login

**Known limitation (unchanged):** the existing navigation bar doesn't wrap on narrow phone screens, so pages scroll sideways on mobile. Validation itself works on mobile.

## 7. Not present in the system

The request mentioned customer profile editing, password changes, product reviews and discount management screens. These features don't exist in the current system, so there were no forms to validate. If they're added later, use the same `Validator` methods (for example, `newPassword()` + `confirmation()` for password changes and `message()` for reviews) and the same front-end attributes.
