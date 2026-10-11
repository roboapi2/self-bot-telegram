# ممیزی Docker و CI/CD — ۲۰۲۶/۱۰/۱۱

## اصلاح شکست Nginx در run 38138518842

وضعیت این اصلاح: **تا قبولی GitHub Actions تأیید نهایی نشده است.**

[job validate](https://github.com/roboapi2/self-bot-telegram/actions/runs/38138518842/job/114467308748) از طریق log اصلی GitHub بررسی شد. روی Ubuntu 24.04 ساخت image، آزمون PHP/lifecycle، composer audit، آزمون mock استقرار و Trivy موفق بودند. runtime، supervisor و web سالم شدند؛ در ساعت 12:17:45 UTC فقط Nginx unhealthy گزارش شد. log آن run خروجی داخلی Nginx یا `nginx -t` نداشت؛ بنابراین پیام دقیق `mkdir` و errno از آن run قابل تأیید نیست.

ایراد مشخص پیکربندی: فقط `client_body_temp_path` و `fastcgi_temp_path` به `/tmp` منتقل شده بودند. مسیرهای proxy/uWSGI/SCGI به default پکیج Debian واگذار شده بودند؛ این مسیرها نیز هنگام initialization بررسی/ایجاد می‌شوند، حتی بدون route مربوط به آن ماژول. برای UID10001 و root filesystem فقط‌خواندنی، مسیر پیش‌فرض `/var/lib/nginx/proxy` و مسیرهای مشابه قابل ایجاد/نوشتن نیستند. این **علت محتمل شکست runtime** است؛ تأیید پیام واقعی startup نیازمند اجرای Linux Docker و diagnostics جدید در Actions است. دستورهای temp path در [مستندات رسمی Nginx](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_temp_path) تعریف شده‌اند.

اصلاحات:

- هر پنج temp path صریحاً زیر tmpfs موجود `/tmp` قرار دارند؛ مجوز777 روی root filesystem، اجرای root یا volume اضافی ایجاد نشده است.
- entrypoint قبل از اجرای Nginx، `nginx -t` را اجرا می‌کند. smoke ابتدا وب را سالم بالا می‌آورد تا DNS upstream `web` موجود باشد؛ سپس `nginx -t` را با همان Compose UID/read-only/tmpfs در container یک‌بارمصرف و داخل container فعال اجرا می‌کند.
- healthcheck فعال باقی مانده و curl timeout سه‌ثانیه‌ای دارد، کمتر از timeout پنج‌ثانیه‌ای Docker.
- smoke سلامت HTTP، UID10001/read-only، قابلیت نوشتن هر پنج temp directory و فقط‌خواندنی بودن config را بررسی می‌کند؛ privacy، persistence و restart/recreation gateها حفظ شده‌اند.
- در شکست، قبل از حذف containerهای آزمایشی، `compose ps --all`، 150 خط logs هر سرویس، inspect محدود به State/health و ویژگی‌های امنیتی، و اجرای مستقل `nginx -t` چاپ می‌شود. inspect کامل و Config.Env چاپ نمی‌شود. شکست diagnostics، exit code خطای اصلی را مخفی نمی‌کند.
- Dockerfile بررسی شد و تغییر لازم نداشت: config اصلاح‌شده را COPY می‌کند و USER10001 حفظ شده است. persistent bindها و cap_drop/no-new-privileges دست‌نخورده‌اند.

آزمون محلی این اصلاح روی Windows:

- lint PHP و shell: موفق؛ 35 unit، 160 locked update، 6 migration، 7 config Docker و 27 HTTP موفق. lifecycle Linux در محیط محلی skip شد؛ اجرای موفق قبلی آن در run GitHub مشاهده شد.
- 251 قرارداد offline شامل YAML و محدودیت‌های Compose: موفق؛ بررسی ignore با 44 مسیر خصوصی و 85 مسیر واجد سورس/fixture در هر دو حالت موفق، بسته fixture77 فایل داشت.
- 8 بررسی failure diagnostics با Docker/sudo ساختگی: موفق؛ ps/logs/inspect/nginx-test عمداً شکست می‌خورند، خروجی تشخیصی چاپ و cleanup اجرا می‌شود، exit code اصلی23 حفظ می‌شود. این تست Docker واقعی نیست.
- ساخت Docker اصلاح‌شده، `nginx -t` واقعی، Compose smoke/privacy/health و reproduction خطای قدیمی محلی اجرا نشدند: workspace Docker ندارد و Linux engine دستگاه آزمایشی نیز قابل اتصال نبود. طبق درخواست مالک، Ubuntu24.04 در GitHub Actions مرجع آزمون است. هیچ VPS تولیدی خوانده/تغییر داده نشده و deploy انجام نشده است.

پس از commit/push مالک، job validate باید تمام گیت‌ها را اجرا کند. برای اجرای مستقل روی Linux غیرتولیدی:

```bash
docker build --target runtime -t ubcc-candidate .
bash scripts/docker/ci-smoke.sh ubcc-candidate
```

اگر هنوز fail شد، خروجی diagnostics همین step باید بررسی شود؛ healthcheck یا security gate را حذف نکنید. اصلاح فقط پس از سبز شدن Actions پذیرفته است. گزارش بخش‌های بعد مربوط به تحویل قبلی است؛ نتایج آن جایگزین آزمون image اصلاح‌شده نیست.

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
