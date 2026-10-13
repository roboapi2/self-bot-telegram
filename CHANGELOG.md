# تاریخچه تغییرات

## ۲۰۲۶/۱۰/۱۳ — سازگاری MadelineProto با filesystem فقط‌خواندنی

- scratch اختصاصی0700 در tmpfs و تنظیم صریح cwd/script_cwd برای QR/bot و spawned IPC اضافه شد؛ داده session جابه‌جا نمی‌شود.
- patch محدود و hash-checked برای MadelineProto8.7.0، constructor/restoration logger را روی Closure redacting نگه می‌دارد؛ نسخه dependency ثابت است.
- regression runtime با non-root/read-only/network none، legacy fopen failure، prime fallback، API preflight و SVG مصنوعی QR اضافه شد. تأیید Telegram واقعی منتظر Actions و آزمون کاربر است.

## ۲۰۲۶/۱۰/۱۳ — اصلاح callback logger ورود QR

- callback logger به Closure تبدیل شد تا با Revolt queue سازگار باشد؛ نسخه dependency و داده session تغییر نکردند.
- error-only در callback enforce و redaction مقادیر quoted، authorization و session/token تقویت شد؛ objectها و exceptionها محتوای خصوصی چاپ نمی‌کنند.
- regression با vendor واقعی، TypeError قبلی، queue، serialization/restoration/merge settings و privacy بدون اتصال Telegram به test suite اضافه شد.

## ۲۰۲۶/۱۰/۱۱ — gateway patched و opt-in استقرار

- Caddy پایدار2.11.7 با Go1.26.9 و x/net0.60.0 از source و dependencyهای pin‌شده بازسازی می‌شود؛ base imageها و آرشیو source با digest/checksum ثابت‌اند.
- runtime و gateway هر دو scan، smoke، checksum و image-ID verification دارند؛ انتشار، state و rollback هر دو digest را حفظ می‌کنند. health/privacy و persistence گواهی gateway به smoke اضافه شد.
- job deploy فقط با repository variable `PRODUCTION_DEPLOY_ENABLED=true` فعال می‌شود؛ CI، GHCR و Releases مستقل باقی می‌مانند. validate15 دقیقه و cache/timeoutهای قبلی حفظ شدند.
- تأیید امنیت image نهایی و استقرار منتظر Actions جدید است؛ VPS و داده خصوصی تغییر نکردند.

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
