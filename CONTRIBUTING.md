# راهنمای مشارکت

ممنون که می‌خواهید افزونهٔ هوش مصنوعی وردپرس نِت اَرز را بهتر کنید. این‌ها را خوشحال می‌پذیریم:

- رفع اشکال، با شرح دقیق این‌که اشکال چطور دیده می‌شود
- سازگاری با نسخهٔ تازهٔ وردپرس، ووکامرس یا یک پوستهٔ پرکاربرد
- بهبود ترجمهٔ فارسی یا انگلیسی، یا زبان تازه
- امکانی که به کار پشتیبانی یا تولید محتوای یک سایت وردپرسی می‌آید

پیش از تغییر بزرگ، اول یک [Issue](https://github.com/netarz/netarz-ai-wordpress/issues/new/choose) باز کنید تا دربارهٔ راه‌حل هم‌نظر شویم.

## قاعده‌ها

1. کلید واقعی (`sk-ntz-v1-…`) در کد، README یا تاریخچهٔ گیت نگذارید. اگر اشتباهی کلیدی را کامیت کردید، پیش از هر کاری آن را از
   پنل نِت اَرز باطل کنید؛ پاک کردن کامیت کافی نیست.
2. افزونه باید روی **PHP 7.4** اجرا شود: بدون `match`، ویژگی‌های سازنده (promoted properties)، `readonly`، enum و نوع‌های اجتماع.
   بررسی: `php7.4 -l` روی هر فایلی که عوض کرده‌اید.
3. هر خروجی با `esc_html`، `esc_attr`، `esc_url` یا `wp_kses` ساخته شود. هر مسیر REST و هر فرم مدیریت، بررسی دسترسی
   (capability) و nonce دارد. در جاوااسکریپت متن کاربر یا مدل را با `textContent` بگذارید، هرگز با `innerHTML`.
4. متنی که کاربر یا مدل می‌فرستد را با `Netarz_AI_Util::clean_text()` پاک کنید، نه با `sanitize_textarea_field`: آن تابع
   کدهای `%XX` را می‌بُرد و نشانی‌های فارسی را خراب می‌کند. متن فارسی را با `trim()` بایتی هم کوتاه نکنید.
5. هر متنی که کاربر می‌بیند با `__()` و دامنهٔ `netarz-ai` نوشته شود و در `languages/netarz-ai.pot` بیاید.
   متن فارسی «شما» است، بدون شکلک (emoji)، و نام برند همیشه «نِت اَرز».
6. داده‌ای که به مدل می‌رسد را آگاهانه گسترش دهید: رمز، اطلاعات پرداخت و یادداشت‌های داخلی سفارش هرگز به مدل نمی‌رسند.
7. افزونه فقط با `https://netarz.ir/api/ai/v1` حرف می‌زند. برای توسعهٔ محلی، ثابت `NETARZ_AI_API_BASE` را در
   `wp-config.php` به یک سرور آزمایشی ببرید تا اعتبار واقعی خرج نشود.
8. کد و توضیح‌های داخل کد انگلیسی باشد.
9. اگر رفتار افزونه عوض شده، نسخه را در `netarz-ai.php` و `readme.txt` بالا ببرید و `CHANGELOG.md` را به‌روز کنید.

## مجوز

با فرستادن Pull Request می‌پذیرید که کد شما با مجوز همین مخزن (GPL-2.0-or-later) منتشر شود.

---

## Contributing (English)

Thanks for improving NetArz AI for WordPress. Open an issue before a large change. Never commit a real key (`sk-ntz-v1-…`);
if you did, revoke it in the NetArz panel first.

- Keep **PHP 7.4** compatibility (no `match`, promoted properties, `readonly`, enums or union types) and lint with `php7.4 -l`.
- Escape every output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`); every REST route and admin form checks a capability and a
  nonce. In JavaScript use `textContent`, never `innerHTML` with user or model text.
- Clean user and model text with `Netarz_AI_Util::clean_text()`, not `sanitize_textarea_field` (it strips `%XX` and breaks
  Persian URLs). Never byte-`trim()` Persian text.
- Every user-facing string goes through `__()` with the `netarz-ai` text domain and into `languages/netarz-ai.pot`.
- Do not widen what reaches the model: passwords, payment data and private order notes never do.
- For local development point `NETARZ_AI_API_BASE` in `wp-config.php` at a mock server so no real credit is spent.
- Bump the version in `netarz-ai.php` and `readme.txt` and update `CHANGELOG.md` when behaviour changes.

By contributing you agree your work is released under GPL-2.0-or-later.
