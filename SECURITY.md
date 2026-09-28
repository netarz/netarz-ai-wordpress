# سیاست امنیتی

## گزارش آسیب‌پذیری

اگر در افزونهٔ هوش مصنوعی وردپرس نِت اَرز یا در وب‌سرویس‌های نِت اَرز (`netarz.ir/api/...`) مشکل امنیتی پیدا کردید، لطفاً آن را در Issue،
Pull Request یا Discussion عمومی ننویسید. به **`dev@netarz.ir`** ایمیل بفرستید و در عنوان بنویسید `Security: netarz-ai-wordpress`.

در گزارش این‌ها به ما کمک می‌کند:

- شرح کوتاه مشکل و این‌که چه کسی از آن آسیب می‌بیند
- مراحل بازتولید، یا یک نمونهٔ کوچک که مشکل را نشان می‌دهد
- فایل، نسخه یا endpoint مربوط
- اگر راه‌حلی به ذهنتان رسیده، آن را هم

در گزارش کلید واقعی، رمز یا اطلاعات شخصی کسی را نفرستید. اگر برای نشان دادن مشکل لازم است، از یک کلید تازه در حساب خودتان استفاده کنید
و بعد آن را باطل کنید.

دریافت گزارش را با ایمیل تأیید می‌کنیم، دربارهٔ اصلاح خبرتان می‌کنیم و تا اصلاح منتشر نشده جزئیات را عمومی نمی‌کنیم.
از شما هم همین را می‌خواهیم. اگر بخواهید، نامتان را در یادداشت اصلاح می‌آوریم.

## کلید شما لو رفته؟

این مورد آسیب‌پذیری نیست ولی فوری است: از پنل نِت اَرز کلید را باطل کنید و کلید تازه بسازید. پاک کردن کامیت یا فایل
کافی نیست، چون کلید در تاریخچهٔ گیت و در کش‌های عمومی می‌ماند.

## نسخه‌های پشتیبانی‌شده

آخرین نسخهٔ منتشرشده (Release) و شاخهٔ `main`.

## خارج از این سیاست

- حملهٔ از کار انداختن سرویس (DoS) و آزمایش بار روی سرورهای نِت اَرز
- مهندسی اجتماعی، فیشینگ یا حمله به کارمندان و مشتریان
- گزارش خودکار اسکنرها بدون نشان دادن اثر واقعی

---

## Security policy (English)

Please do not report security issues in public issues, pull requests or discussions. Email **`dev@netarz.ir`** with the
subject `Security: netarz-ai-wordpress`, including a description, reproduction steps, the affected file or endpoint and, if you have one,
a suggested fix. Do not send real keys or personal data. We acknowledge every report, keep you informed and do not disclose
details before a fix is released; please do the same. Supported: the latest release and `main`.
Out of scope: denial of service and load testing, social engineering, and unverified automated scanner output.
If your own key leaked, revoke it in the NetArz panel right away; deleting the commit is not enough.
