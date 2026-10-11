# ممیزی Docker و CI/CD — ۲۰۲۶/۱۰/۱۱

تغییرات مستقیم در پروژه موجود انجام شدند؛ سورس جایگزین ساخته نشد. تنظیمات خصوصی اصلی، registry و لاگ موجود با hash پایه مقایسه شدند. تنها تغییر runtime برنامه، heartbeat ناظر است. پنل و account/QR/2FA و قفل worker حفظ شده‌اند. تنظیمات Docker adapter مستقل است و فایل config اصلی را نمی‌نویسد.

## آزمون محلی

- lint تمام PHPها و syntax تمام shellهای جدید و قدیمی.
- 35 بررسی unit، 160 به‌روزرسانی هم‌زمان، 6 بررسی migration، 27 بررسی HTTP و 7 بررسی تنظیمات Docker.
- قراردادهای source-only context/release، pinهای action و image پایه، SSH، ترتیب backup و serialization.
- 222 بررسی قرارداد موفق، 44 مسیر ignored و 84 مسیر واجد سورس/fixture در هر دو حالت جستجو موفق؛ fixture انتشار 76 فایل داشت که یکی نمونه env مصنوعی آزمون است.
- تست ignore در دو حالت Git-style و `.ignore`، archive با canary خصوصی و checksum فایل‌ها.
- YAML فایل Compose و workflow parse شد؛ این بررسی جایگزین اجرای Docker نیست.
- نصب تازه 56 dependency از lockfile و check-platform روی Windows آزمون شد. ابزار 7z متفرقه سیستم ابتدا لینک مستندات dependency را رد کرد؛ با PATH محدود به ابزار Git/Windows آزمون تکرار شد. این تغییر فقط محیط فرمان آزمایش بود و تنظیمات سیستم را عوض نکرد.
- Composer audit با lockfile فعلی هیچ advisory یا پکیج abandoned گزارش نکرد. hash هر سه فایل خصوصی config.php/accounts.json/MadelineProto.log با baseline یکسان است.
- lifecycle لینوکس در Windows skip شد. Docker daemon/Compose اجرایی در این محیط وجود ندارد؛ تست host deployment mock فقط داخل CI image لینوکس اجرا می‌شود.

## پذیرش لازم خارج این محیط

اولین Actions باید ساخت و composer audit و Trivy و Gitleaks و lifecycle و تست mock rollback و smoke Compose را موفق اجرا کند. smoke شامل mount پایدار، restart و recreation، PID مشترک، health و رد دسترسی فایل‌های خصوصی است. context واقعی با canary در پوشه‌های مجاز نیز روی Docker بررسی می‌شود. هیچ حساب واقعی در smoke فعال نیست.

SSH و DNS و ACME، اعتبار private GHCR، reboot VPS، ورود واقعی QR/2FA و تداوم نشست تلگرام هنوز روی VPS اجرا نشده‌اند. secrets سرور و GitHub در این کار ایجاد نشده‌اند و هیچ commit/push/deploy خارجی انجام نشده است. اجرای چک‌لیست PRODUCTION_DEPLOY_FA شرط پذیرش نهایی تولید است.

## نکات عملیاتی

deploy برنامه تنظیمات میزبان را دست نمی‌زند؛ نسخه سالم قبلی و backup تنظیمات حفظ می‌شوند. ارتقای ابزار میزبان بازبینی جدا می‌خواهد. backup شامل secret است و فقط برای root خواندنی است؛ نگهداری خارج سرور و retention باید توسط مدیر تنظیم شود. rollback داده را برنمی‌گرداند؛ restore داده عملیات جداست. حساب‌های بسیار زیاد که یک گردش supervisor بیش از 90 ثانیه طول بکشد نیاز به ارزیابی timeout سلامت و منابع دارند. به‌روزرسانی dependency/base image پس از PR و CI انجام شود.

بسته انتشار جدید فایل‌های عمومی Docker و workflow را نیز دارد. گزارش IGNORE_AUDIT_FA مربوط به مرحله پیش از اضافه شدن Docker است؛ قواعد جدید data/secrets/state/settings.env به ignore اضافه شدند. `.gitattributes` پایان خط LF را برای checkout حفظ می‌کند.
