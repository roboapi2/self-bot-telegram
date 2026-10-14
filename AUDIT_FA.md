# گزارش ممیزی و تغییرات پروژه

## اصلاح authentication fixture Compose — ۱۴ اکتبر ۲۰۲۶

run38587385941/commit aa378e4 بررسی مستقیم شد: Linux lifecycle، rollback mock، package، Gitleaks، runtime/gateway scan و gateway validation موفق؛ Compose در ابتدای authenticated account creation شکست خورد و publish/rolling اجرا نشدند. PHP stream client قدیمی redirect ورود را با Cookie قدیمی دنبال می‌کرد؛ session_regenerate_id(true) آن session را باطل کرده بود. بازتولید محلی با login واقعی، نبود dashboard CSRF و401 برای session قبلی را ثابت کرد.

client مشترک تست tests/http_client.php automatic redirect را خاموش،302 و Set-Cookie تازه و مقصد داخلی را بررسی، سپس GET تازه برای dashboard/CSRF انجام می‌دهد؛ status/JSON/fieldها قبل از استفاده بررسی و خطاها بدون body/cookie/token چاپ می‌شوند. همان client در تست HTTP محلی account را با API واقعی ایجاد/حذف و invalid CSRF و logout را بررسی می‌کند:32 HTTP checks موفق. production auth/session/CSRF و Docker restrictionها تغییر نکردند. نتیجه Linux نسخه قبلی اکنون PASS CI است؛ Compose کامل patch جدید، publish/rolling و Telegram هنوز UNVERIFIED هستند.

## وضعیت بازبینی تکمیلی — ۱۴ اکتبر ۲۰۲۶

در ادامه همان ممیزی، defect طبقه‌بندی مرگ worker هنگام polling launcher و race در transaction انتقال bot به QR اصلاح شدند. patch فعلی هنوز staged و بدون commit/push است. QR اکنون Stop و ثبت قصد QR را زیر یک lifecycle.lock انجام می‌دهد؛ آزمون Linux با QR worker مصنوعی، توقف bot، نبود enabled، cleanup و حفظ feature state را بررسی می‌کند. diagnostic timeout Compose اکنون خلاصه whitelist وضعیت و category را چاپ می‌کند. [گزارش کامل](docs/LIFECYCLE_AUDIT_FA.md) شامل معماری، filesystem، patch dependency، checklist یکپارچه و rollback است.

آخرین اجرای محلی:55 unit،160 concurrency،6 migration،7 Docker configuration،34 logger،31 bot contract،29 HTTP و13 frontend؛ 153 CI regression،298 قرارداد deployment،18 publish mock و13 diagnostics mock؛ release fixture95 فایل با checksum/allowlist/privacy موفق. Linux/Compose/Trivy/Gitleaks/rolling این patch هنوز UNVERIFIED هستند؛ Windows بدون Docker و WSL نمی‌تواند نتیجه Linux بدهد. نسخه قبلی f783854 در run38476529900 شکست داشته است؛ تایید gateهای بعدی یا عملکرد واقعی Telegram ادعا نمی‌شود. هیچ داده production، credential، session، ports/TLS و dependency version تغییر نکرده است.

## ممیزی end-to-end چرخه حساب — ۱۴ اکتبر ۲۰۲۶

[گزارش کامل معماری، اشکالات تأییدشده، قرارداد filesystem، ماتریس آزمون، استقرار/rollback و checklist یکپارچه پذیرش](docs/LIFECYCLE_AUDIT_FA.md) مبنای patch فعلی است. سورس در همان repository موجود اصلاح شد؛ VPS، session/config و credential واقعی تغییر نکرده‌اند و push/deploy انجام نشده است.

include ناامن UserBot، export ناقص environment IPC، running کاذب از PID، retry نامحدود و احتمال deadlock Stop زیر data.lock تأیید و اصلاح شدند. readiness بعد از validation واقعی handler، heartbeat/generation/birth identity، failure ساختاری و retry پایدار سه‌مرحله‌ای، atomic Restart، توقف handlerهای restored حساب خاموش و تشخیص امن افزوده شد. media classification و کپی streaming آرشیو نیز اصلاح شدند. dependency/lockfile، امنیت Docker، پورت/TLS و volumeها ثابت‌اند. علت دقیق queued در VPS هنوز بدون خروجی تشخیص امن معلوم نیست؛ وجود این defects سورس را نباید با اثبات علت production یکی دانست.

شاهد جدید production: enabled/logged_in با PID0 و bot/QR غیرزنده، ناظر recent healthy و PHP/shell_exec قابل استفاده. این خروجی مشکل startup/supervision را محدود می‌کند، اما نه readonly و نه lock فعال را ثابت می‌کند. append لاگ پیش از shell و exceptionهای bootstrap پیش از PID اکنون failure مشخص دارند؛ heartbeat ناظر پس از scan ثبت می‌شود. regression واقعی PHP برای pre-PID failure محلی موفق است؛ فایل‌های0400 و stale PID نیز به gate واقعی Compose افزوده شدند. علت اختصاصی VPS هنوز UNVERIFIED است.

محلی:50 unit،160 locked update،6 migration،7 Docker config،34 logger،31 bot contract،29 HTTP،13 frontend،152 CI regression،18 publish mock و13 diagnostics mock موفق. Composer audit advisory ندارد؛ نصب مستقل56 dependency و suite از allowlist عمومی موفق. آزمون‌های Linux و Docker جدید هنوز در Windows بدون engine قابل اجرا نیستند؛ GitHub Ubuntu gateهای واقعی process/Compose/Trivy/Gitleaks/rolling را حفظ می‌کند. Telegram واقعی و پذیرش production هنوز NOT TESTABLE هستند. برنامه هنوز fully working یا production-fixed اعلام نمی‌شود.

## اصلاح filesystem ورود QR — ۱۳ اکتبر ۲۰۲۶

تشخیص گزارش‌شده fopen(/opt/app/MadelineProto.log) با سورس MadelineProto8.7.0 تأیید شد: Magic::start مسیر script_cwd را از filename بیرونی PHP می‌گیرد، نه صرفاً cwd. Settings/Logger هنگام restoration به logger پیش‌فرض برمی‌گردد و در IPC، getType به دلیل MADELINE_WORKER همیشه FILE_LOGGER است. اگر extra قبلی absolute باشد، تغییر script_cwd آن را اصلاح نمی‌کند. بنابراین callback settings سمت QR client به‌تنهایی تمام loggerهای spawned IPC و restored session را پوشش نمی‌دهد. worker واقعی از /opt/app شروع می‌شود و PrimeModule fallbackهای .prime.py/.alt_prime.py را در getcwd() کپی می‌کند؛ این ریسک در مسیر واقعی QR نیز وجود دارد. حضور failure در diagnostic همان startup/session-stage را پوشش می‌دهد، اما به‌تنهایی اثبات شکست یا موفقیت کل ورود نیست.

initialize_madeline_runtime پیش از Settings در make_api و bot، directory تصادفی0700 زیر sys_get_temp_dir می‌سازد؛ در Docker این مسیر /tmp tmpfs موجود است. cwd و Magic::$script_cwd/::$cwd هر دو صریحاً تنظیم می‌شوند. sessionهای account همچنان absolute و پایدارند. cleanup فقط نام‌های scratch شناخته‌شده را حذف می‌کند و نه session/data؛ خطای cleanup مانع shutdown نشست نمی‌شود. پوشه دارای فایل ناشناخته دست‌نخورده می‌ماند و tmpfs در recreate پاک می‌شود.

اصلاح محدود vendor ضروری بود چون Logger settings نهایی است و IPC نوع logger را override می‌کند: scripts/patch-madeline.php فقط Logger.php و Ipc/Runner/ProcessRunner.php نسخه8.7.0 را با hash سورس اصلی بررسی و patch می‌کند؛ هر تغییر upstream ناشناخته build را fail می‌کند. نسخه dependency و lockfile تغییر نکرده‌اند. وقتی callback برنامه موجود است، constructor Logger همواره Closure redacting را انتخاب می‌کند؛ مسیر FILE_LOGGER قدیمی، default و restoration فایل خصوصی خام ایجاد نمی‌کنند. ProcessRunner bootstrap برنامه را از auto_prepend_file بارگذاری می‌کند و cwd والد را به کودک منتقل نمی‌کند؛ کودک scratch مستقل دارد. bootstrap هویت IPC را نیز حفظ می‌کند چون Magic light پیش از تعریف constants در entry.php اجرا می‌شود. این مداخله فقط وقتی application callback/constant موجود است فعال است؛ رفتار عمومی دیگر مصرف‌کنندگان library تغییر نمی‌کند. patch در build dependency image، installer و suite اعمال می‌شود؛ vendor موجود محلی نیز برای آزمون patch شده، ولی سورس vendor ignored است و patch script در release/context قرار دارد.

regression واقعی Docker در Compose smoke دو container با runtime image یکسان، UID10001، read-only، cap_drop ALL، no-new-privileges، tmpfs و network none اجرا می‌کند. legacy mode بدون bootstrap، Logger FILE_LOGGER را در /opt/app باز کرده و باید Exception واقعی Read-only file system را بازتولید کند. fixed mode restored logger قدیمی و MADELINE_WORKER، fallback واقعی PrimeModule، API constructor تا خطای موردانتظار نبود credential (showPrompt=false)، و QR helper SVG با token مصنوعی را بررسی می‌کند. session تست فقط داخل scratch است. auth.exportLoginToken، اتصال Telegram، IPC server/session واقعی، اسکن QR و2FA عمداً قابل تأیید با تست offline نیستند؛ کاربر باید پس از قبولی Actions آن‌ها را جداگانه تأیید کند.

محلی: PHP/shell lint،35 unit،160 locked update،6 migration،7 Docker config،34 logger/serialization/privacy/scratch و spawned PHP bootstrap،27 HTTP موفق. 287 قرارداد offline،138 regression CI،18 publish mock،13 diagnostics mock و ignore/privacy (44 مسیر خصوصی،98 سورس و archive fixture90 فایل) موفق بودند؛ Composer audit اجرا شد و audit advisory نداشت. نصب موقت از allowlist با56 dependency و اجرای suite نیز موفق بود. permission0700 روی Windows قابل تأیید POSIX نیست و در regression Linux شرط است. lifecycle Linux و container/API واقعی readonly در محیط Windows بدون Docker engine اجرا نشدند؛ gate جدید در Actions فعال و بدون skip است. production issue هنوز end-to-end تأیید نشده؛ هیچ VPS، session، config/credential، ports/TLS یا SSH تغییر نکرده و push/deploy انجام نشده است.

## اصلاح logger ورود QR — ۱۳ اکتبر ۲۰۲۶

سورس نصب‌شده MadelineProto8.7.0 بررسی شد: Settings/Logger::setExtra رشته callable را قبول می‌کند، اما Logger.php:421 آن را بدون تبدیل به Revolt/EventLoop::queue می‌دهد؛ queue فقط Closure می‌پذیرد. patch در runtime.php از `Closure::fromCallable('ubcc_madeline_log')` استفاده می‌کند. dependency، session یا تنظیمات Docker/شبکه/TLS تغییر نکردند.

Settings/Logger::__sleep عمداً extra از نوع Closure را serialize نمی‌کند؛ پس native serialization به خطای «Serialization of Closure» نمی‌رسد. __wakeup logger پیش‌فرض را بازمی‌گرداند؛ make_api و مسیر bot هر بار settings تازه را configure می‌کنند و API constructor همان settings را هنگام اتصال/restoration برای updateSettings ارسال می‌کند. regression با کلاس‌های واقعی vendor، serialize/unserialize logger و Settings کامل و merge settings تازه، از جمله settings قدیمی با string callback، این رفتار را بررسی می‌کند. این آزمون session واقعی و اتصال Telegram ایجاد نمی‌کند؛ تنها bootstrap hookهای سراسری Magic در process تست ایزوله می‌شوند.

MadelineProto8.7.0 مقدار ERROR را به NOTICE clamp می‌کند؛ callback شرط error-only را نیز اجرا می‌کند. known config secrets، مقادیر quoted چندکلمه‌ای، Authorization/Bearer، auth_key، access/refresh/session tokens، QR token و session_secret/session_string در stringها redact می‌شوند؛ object/array/Throwable بدون محتوای آن‌ها به پیام عمومی تبدیل می‌شوند. تست‌ها هیچ secret واقعی ندارند و فقط fixture را بررسی می‌کنند. redaction محافظه‌کارانه ممکن است متن پس از فیلد حساس را نیز حذف کند؛ رشته ناشناس بدون نام فیلد را نمی‌توان با اطمینان به عنوان secret تشخیص داد، بنابراین نباید session یا secret خام به logger ارسال شود.

tests/madeline_logger.php در scripts/test.sh و allowlistهای Docker/release ثبت شد تا همان regression در CI Linux نیز اجرا شود. آزمون محلی: PHP/shell lint،35 unit،160 locked updates،6 migration،7 Docker config،29 logger،27 HTTP موفق.281 قرارداد offline،127 regression CI،18 publish mock،13 diagnostics mock و ignore/privacy در هر دو mode موفق؛ Composer audit advisory گزارش نکرد. lifecycle Linux محلی به علت Windows/نبود pcntl/posix skip شد. Docker و ورود QR واقعی در این محیط بدون engine اجرا نشدند؛ قبولی GitHub Actions و آزمون کاربر روی QR شرط تأیید end-to-end باقی است. داده/نشست/config موجود دست‌نخورده‌اند و هیچ VPS خوانده یا تغییر داده نشده است.

clean-install از allowlist عمومی در temp، نصب56 dependency از lockfile، platform requirements و کل suite نیز موفق شد. تلاش اول به علت 7z ابزار AOMEI در PATH محلی و symlinkهای مستندات vendor شکست خورد؛ retry با 7-Zip اصلی فقط در PATH همان process موفق بود؛ سورس یا dependency version تغییر نکرد. تست ignore44 مسیر خصوصی و95 سورس واجد شرایط را بررسی و بسته fixture87 فایل را تأیید کرد. patch فقط هفت فایل public را تغییر داده و staged است؛ push/deploy انجام نشده است.

بخش‌های بعد گزارش تاریخی اولیه‌اند؛ workspace فعلی Git repository است.

تاریخ بررسی: ۱۰ اکتبر ۲۰۲۶. محل کار: `F:\project\self`، همان پوشه سورس استخراج‌شده موجود. هیچ آرشیو سورس دریافت یا باز نشده و پروژه جایگزین ساخته نشده است.

## شناسایی ساختار و جریان اجرا

اسکن بازگشتی اولیه ۲۹۷۲ فایل در کل فضای کاری نشان داد؛ بیشتر آن‌ها وابستگی‌های نصب‌شده داخل `vendor/` بودند. فایل Composer و فایل‌های اجرایی در ریشه هستند؛ زیرپروژه `auto_bot/` وجود ندارد. فضای کاری Git repository نیست؛ ادعای commit یا PR برای تغییرات مطرح نمی‌شود.

| دسته | فایل یا مسیر | کاربرد و تصمیم |
|---|---|---|
| پنل وب | `index.php` | ورود و HTML فارسی، روتر محدود توسعه محلی |
| API وب | `api.php` | عملیات حساب، قابلیت‌ها، آرشیو، چت و رسانه با auth و CSRF |
| کتابخانه | `lib.php`, `runtime.php` | تنظیمات، احراز هویت، مسیرها، JSON، قفل و پردازش |
| کارگر | `worker.php` | ثبت PID و قفل انحصاری برای bot یا QR |
| ربات مشترک | `bot_template.php` | event handlerها، بازیابی، کمپین، ساعت و دستورهای تلگرامی |
| ورود و IPC | `qr_worker.php`, `madeline_tools.php` | ورود QR/2FA و اتصال API به نشست کارگر روشن |
| supervisor | `supervisor.php` | شروع درخواست‌شده، بازیابی پس از خروج و حفظ قصد اجرا پس از ریبوت |
| فرمان خطی | `console.php` | doctor، وضعیت و مدیریت حساب |
| رابط | `assets/app.js`, `assets/styles.css` | رابط موجود RTL و تم‌ها؛ بازطراحی نشده است |
| وابستگی | `composer.json`, `composer.lock`, `vendor/` | وابستگی PHP و نصب تکرارپذیر؛ ارتقای هدفمند phpseclib |
| محرمانه | `config.php` | تنظیمات واقعی؛ محتوای موجود تغییر نکرده است |
| فهرست قدیمی | `accounts.json` | سه رکورد حساب در بررسی اولیه؛ محتوای موجود تغییر نکرده است |
| داده حساب | `accounts/` | در بررسی اولیه هیچ فایل runtime در این پوشه نبود؛ حذف یا پاک‌سازی نشده است |
| لاگ موجود | `MadelineProto.log` | داده حساس موجود؛ محتوای آن تغییر یا در گزارش نقل نشده است |
| داده نصب جدید | `.panel/` | فهرست فعال، نشست وب، محدودیت ورود، لاگ و پشتیبان؛ هنگام نصب ایجاد می‌شود |
| استقرار | `deploy/`, `scripts/`, `install.sh`, `run.sh` | قالب Nginx/FPM/systemd/logrotate و فرمان‌های نگهداری |
| بررسی توسعه | `.claude/` | تنظیمات ابزار محلی؛ در استقرار یا نقشه روابط استفاده نمی‌شود |
| نقشه اختیاری | `graphify-out/` | روابط سورس و معماری؛ برای اجرای VPS لازم نیست |

پنل JS به API درخواست می‌دهد؛ API کتابخانه را برای فایل‌های وضعیت و مدیریت کارگر صدا می‌زند. در استقرار تولیدی API فقط قصد اجرای bot یا QR را ثبت می‌کند؛ supervisor کارگر را در cgroup سرویس systemd اجرا می‌کند. هر حساب از **همان ربات مشترک** با مسیر داده اختصاصی استفاده می‌کند. نشست‌ها و رسانه از مسیر وب مستقیم قابل دریافت نیستند؛ رسانه فقط از API احرازشده و با کنترل مسیر ارائه می‌شود.

فایل `accounts/<key>/bot.php` در نسخه‌های قدیمی می‌تواند سورس سفارشی داشته باشد؛ نسخه اصلاح‌شده آن را حذف نمی‌کند و همچنان ربات مشترک را اجرا می‌کند. مسیرهای `backup_beta/` و `extracted/` که در راهنمای قدیمی ذکر شده بودند در اسکن اولیه وجود نداشتند؛ برای آن‌ها وجود پشتیبان فرض نشده است.

## یافته‌ها و اصلاحات

| مشکل اولیه | اصلاح |
|---|---|
| روتر توسعه همه فایل‌های واقعی ریشه، از جمله JSON، لاگ و نشست را ارائه می‌کرد | allowlist دقیق پنل، API و دو asset؛ قالب Nginx نیز default-deny است |
| API روش GET را برای عملیات تغییردهنده رد نمی‌کرد | allowlist عملیات خواندنی و نوشتنی، پاسخ 405 و CSRF روی POST |
| حذف خودکار سورس قدیمی حساب هنگام ensure | حذف این رفتار؛ حفظ فایل سفارشی |
| PID صرفاً با وجود پردازش بررسی می‌شد و پس از سه ثانیه SIGKILL ارسال می‌شد | بررسی cmdline کارگر اختصاصی، قفل نشست و توقف با SIGTERM؛ گزارش توقف ناقص |
| `nohup` راه‌اندازی پس از reboot و بازیابی خرابی نداشت | supervisor با قصد اجرای پایدار و systemd با Restart=on-failure |
| تحت FPM، `PHP_BINARY` می‌توانست به php-fpm اشاره کند | مسیر PHP CLI پیش‌فرض و override سرویس `/usr/bin/php` |
| نوشتن مستقیم JSON توسط ربات و تداخل با پنل | نوشتن اتمی و قفل تراکنش؛ ادغام تغییرات با اولویت تغییر تازه پنل |
| JSON خراب به‌صورت داده خالی تفسیر می‌شد | خطای صریح بدون بازنویسی با پیش‌فرض خالی |
| بررسی مسیر رسانه با prefix فاقد مرز پوشه | بررسی prefix همراه separator، realpath و فایل واقعی |
| محدودسازی ورود فقط در session بود | شمارنده پایدار به‌ازای آدرس شبکه، CSRF فرم ورود و strict session |
| رمز نمونه پنل قابل استفاده بود | رد رمز خالی و پیش‌فرض؛ پشتیبانی از هش استاندارد در کنار رمز موجود |
| QR بدون مهلت کلی و خروجی trace خام | مهلت QR، پیام خطای عمومی، حذف trace و حفظ فاصله‌های رمز 2FA |
| فایل انتقال 2FA ممکن بود حین نوشتن خوانده شود | ساخت فایل موقت خصوصی و rename اتمی |
| خطای API می‌توانست اطلاعات داخلی را به مرورگر بدهد | پاسخ عمومی و ثبت نوع خطا، پالایش مقادیر محرمانه لاگ |
| راهنمای قدیمی نصب با بازکردن آرشیو و اجرای public development server بود | راهنمای کامل انتقال همین سورس، Nginx/FPM و دسترسی محلی SSH یا HTTPS |
| وابستگی phpseclib نسخه 3.0.55 دارای هشدار Composer بود | ارتقا به 3.0.57 در lock و vendor؛ ممیزی مجدد بدون هشدار |

گزارش Composer آسیب‌پذیری `CVE-2026-84308` / `GHSA-q97c-8qh3-fpc6` را برای نسخه قبلی نشان داد. [رکورد اعلام‌شده](https://github.com/advisories/GHSA-q97c-8qh3-fpc6) مرجع آن است؛ نسخه اصلاح‌شده با محدودیت وابستگی موجود MadelineProto سازگار حل شد. ممیزی بدون هشدار، نبود هر نوع آسیب‌پذیری ناشناخته در همه کدها را تضمین نمی‌کند.

## حفاظت داده و تنظیمات

هش SHA-256 محتوای `config.php`، `accounts.json` و `MadelineProto.log` قبل از تغییرات در `audit/preserved-hashes.json` ثبت شد. مقادیر محرمانه یا محتوای این فایل‌ها در مستندات آورده نشده‌اند. مقایسه نهایی تأیید کرد محتوای هر سه فایل با هش اولیه یکسان است؛ نتیجه در `audit/verification.json` ثبت شده است. آزمون‌ها از config ساختگی و حساب موقت استفاده می‌کنند.

نصب روی Ubuntu مالکیت و مجوز runtime را برای کاربر `ubcc` تنظیم می‌کند؛ داده را حذف نمی‌کند. برای امکان نگهداری سورس فقط خواندنی، فهرست حساب‌ها در نخستین نصب یک بار در `.panel/accounts.json` کپی می‌شود. فایل اولیه باقی می‌ماند و نصب دوباره کپی را بازنویسی نمی‌کند. مسیر داده حساب‌ها ثابت می‌ماند. فرمان update فقط فایل‌های سورس مشخص را کپی می‌کند. backup هنگام خاموشی موقت سرویس‌ها، نشست‌ها را کپی می‌کند و پشتیبان از دسترسی عمومی محافظت می‌شود.

## آزمون‌های انجام‌شده و حدود تأیید

- بررسی syntax همه فایل‌های PHP پروژه و آزمون‌ها با PHP 8.4.23 روی Windows.
- بررسی syntax همه اسکریپت‌های Bash با Bash موجود Git for Windows.
- بررسی syntax فایل JS موجود با Node.
- ۳۱ بررسی واحد برای کلید، JSON سالم/خراب، ادغام و نوشتن واقعی رویدادهای هم‌زمان و آرشیو، ایجاد و reset وضعیت، رسانه، حفظ سورس قدیمی، پالایش، قفل، مهاجرت فهرست فعال و ورود ناقص در Windows. آزمون مرز symlink نیز در Linux اضافه اجرا می‌شود.
- ۲۷ بررسی HTTP روی نسخه موقت مستقل، شامل ورود و خروج، زبان فارسی، مسیرهای خصوصی، 405، CSRF، ساخت حساب و مقاومت شمارنده ورود در برابر حذف کوکی.
- چهار پردازش هم‌زمان و ۱۶۰ تغییر زیر قفل بدون از دست رفتن تغییر.
- `composer validate --no-check-publish`: فایل و lock معتبر؛ هشدار غیرمسدودکننده نبود فیلد license، چون مجوز مالک پروژه مشخص نشده و مجوز فرضی اضافه نشده است.
- `composer check-platform-reqs --no-dev`: الزامات وابستگی روی PHP محلی برآورده شد.
- `composer audit --locked --no-dev`: پس از ارتقا، هیچ advisory یا بسته abandoned گزارش نشد.

**در این محیط اجرا نشده:** apt، Nginx واقعی، PHP-FPM واقعی، systemd و آزمون process lifecycle لینوکس، چون میزبان Windows است و WSL نصب نیست. آزمون `tests/linux_lifecycle.php` برای اجرا روی Linux نوشته شده و در Windows صریحاً SKIP می‌شود. نصب VPS آن را همراه سایر آزمون‌ها اجرا می‌کند. ورود واقعی QR/2FA، چت، رسانه و رویدادهای تلگرام بدون ورود مالک حساب انجام نشده‌اند. بنابراین وضعیت، «سورس اصلاح‌شده و آزمون‌های محلی موفق، نیازمند تأیید عملی روی VPS» است؛ ادعای نصب موفق روی سرور کاربر وجود ندارد.

## ملاحظات باقی‌مانده

- پنل موجود و منطق اصلی ربات حفظ شده‌اند؛ آزمون انتهابه‌انتهای همه event handlerهای تلگرام در این محیط ممکن نبود. بازیابی پیام‌هایی که ربات پیش‌تر ندیده امکان‌پذیر نیست.
- رسانه و آرشیو ممکن است حجم زیادی مصرف کنند؛ ظرفیت دیسک و سیاست نگهداری با کاربر است. رفتار موجود سقف آرشیو را حفظ می‌کند.
- پردازش خارجی با PID قدیمی که worker جدید نیست عمداً متوقف نمی‌شود؛ پیش از مهاجرت، نسخه قدیمی را از پنل قبلی خاموش کنید.
- فایل‌های JSON برای تعداد محدود حساب مناسب‌اند؛ قفل‌های کوتاه و ادغام، نیاز فعلی را پوشش می‌دهند. تضمین تراکنش برای ارسال پیام خارجی با ثبت زمان ارسال وجود ندارد؛ قطع برق بین ارسال و ثبت وضعیت ممکن است باعث تکرار ارسال شود.
- توقف systemd سقف ۱۲۰ ثانیه دارد و پس از آن سیاست systemd ممکن است پردازش باقی‌مانده را بکشد. توقف معمول پنل SIGKILL ارسال نمی‌کند.
- تغییر رمز پنل به‌تنهایی نشست وب موجود را فوراً باطل نمی‌کند؛ روش خروج اضطراری در راهنما آمده است.
- لاگ خطای پروتکل با سطح ERROR و پالایش تنظیم شده، ولی لاگ و آرشیو همچنان داده خصوصی‌اند؛ باید خصوصی باقی بمانند.
- پشتیبان محلی باید خارج VPS نیز نگهداری شود. update ناموفق سرویس‌ها را خاموش نگه می‌دارد و بازگشت مستند شده است.
- استخراج graphify دارای endpoint حل‌نشده، self-loop و collapse رابطه است؛ `graphify-out/GRAPH_HEALTH.txt` محدودیت‌ها را نشان می‌دهد. نقشه فقط کمک معماری است، جای آزمون و خواندن سورس نیست.
