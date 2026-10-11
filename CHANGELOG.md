# تاریخچه تغییرات

## ۲۰۲۶/۱۰/۱۱ — رفع deadlock و بهینه‌سازی CI

- آزمون‌های یک‌بارمصرف Nginx با `--entrypoint nginx` از اجرای foreground برنامه جدا شدند.
- timeout مستقل برای عملیات Docker/Compose، timestamp، diagnostics محدود و cleanup orphanهای CI اضافه شد؛ validate15 دقیقه و smoke300 ثانیه با مهلت cleanup دارد.
- cache لایه‌های build و scanner/pip، artifact دارای checksum/image ID و انتقال جداگانه بسته کوچک اضافه شد؛ تکرار scan/smoke فقط پس از تطبیق هویت image حذف می‌شود.
- regression برای entrypoint، timeout/kill، diagnostics معلق و cleanup اضافه شد؛ تمام گیت‌های امنیت، persistence و rollback حفظ شدند. تأیید Docker منتظر Actions است.

## ۲۰۲۶/۱۰/۱۱ — اصلاح startup و diagnostics Nginx

- مسیرهای proxy/uWSGI/SCGI نیز به tmpfs `/tmp` منتقل شدند تا Nginx با UID10001 و filesystem فقط‌خواندنی به defaultهای `/var/lib/nginx` وابسته نباشد.
- `nginx -t` قبل از startup و در Compose smoke، بررسی temp directories/UID/read-only و timeout محدود curl اضافه شدند.
- شکست smoke پیش از cleanup وضعیت، logs، health inspect محدود و آزمون مستقل Nginx را چاپ می‌کند؛ env خصوصی چاپ و خطای اصلی با diagnostics پوشانده نمی‌شود.
- آزمون failure diagnostics با Docker ساختگی و گیت CI آن اضافه شد. اعتبارسنجی واقعی این اصلاح منتظر قبولی Actions است؛ VPS تولیدی تغییر نکرد.

## ۲۰۲۶/۱۰/۱۱ — Docker و CI/CD

- Docker چندمرحله‌ای، PHP-FPM و Nginx غیر root، Caddy HTTPS و bindهای خصوصی پایدار اضافه شدند.
- فضای PID جدا و مشترک میان وب/supervisor، heartbeat سلامت و shutdown ترتیبی با حفظ worker lockها اضافه شد.
- GitHub Actions برای PR، main، نسخه و dispatch با اسکن امنیتی، آزمون Linux و Compose، GHCR و انتشار بسته پاک اضافه شد.
- استقرار SSH با host key ثابت، digest immutable، قفل و sequence، backup قبل از migration و rollback image بدون بازگردانی داده اضافه شد.
- ابزار setup/control/restore و startup پس از reboot، مستندات فارسی و آزمون قرارداد/تنظیمات/شبیه‌سازی خرابی اضافه شدند.
- Docker context و release هر دو allowlist صریح دارند؛ نشست/config خصوصی دست‌نخورده باقی ماندند. گزارش Docker حدود آزمون محلی و پذیرش Linux/VPS را مشخص می‌کند.

## ۲۰۲۶/۱۰/۱۱ — ممیزی ignore و انتشار

- قواعد `.gitignore` با مسیرهای واقعی config، حساب، نشست، QR/2FA، لاگ، پشتیبان، release و خروجی توسعه تطبیق داده شد.
- `.ignore` با قواعد همسان برای ripgrep اضافه شد تا جستجوی پیش‌فرض در workspace بدون Git نیز داده خصوصی را کنار بگذارد؛ همسانی در تست کنترل می‌شود.
- config و accounts.json فقط در ریشه کنار گذاشته می‌شوند؛ نمونه‌های تنظیمات و fixtureهای پاک‌سازی‌شده باقی می‌مانند.
- تنظیم محلی Claude و داده تولیدشده graph/audit کنار گذاشته شدند؛ مستندات معماری graph باقی ماندند.
- بسته‌سازی از فهرست پوشه‌ها به فهرست صریح فایل‌های مجاز تغییر کرد؛ `.gitignore` و همین تاریخچه نیز در release هستند.
- آزمون رفتار ignore با موتور ripgrep و آزمون بسته‌سازی با داده ساختگی اضافه شد. Git در workspace مقداردهی نشد.
- راهبرد حفظ و بررسی سورس سفارشی حساب، محدودیت ignore و گزارش ممیزی در `docs/IGNORE_AUDIT_FA.md` مستند شد.
- نسخه تمیز تازه با نام `ubcc-20261011-ignore-audit` تولید و محتوا و SHA-256 آن بررسی شد؛ نسخه قبلی بازنویسی نشد.

## مرحله دوم استقرار

جزئیات اصلاحات نصب، مهاجرت حساب‌ها، کنترل سرویس، پشتیبان/بازیابی و نتایج آزمون‌ها در `docs/FINAL_AUDIT_FA.md` است. اعتبارسنجی واقعی Ubuntu و Telegram همچنان انجام نشده است.
