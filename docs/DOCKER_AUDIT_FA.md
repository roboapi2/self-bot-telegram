# ممیزی Docker و CI/CD — ۲۰۲۶/۱۰/۱۱

## اصلاح vulnerability gateway — run 38159228613

وضعیت: **image نهایی فقط پس از قبولی Actions جدید پذیرفته می‌شود. هیچ VPS یا secret واقعی تغییر نکرده است.**

[log واقعی validate](https://github.com/roboapi2/self-bot-telegram/actions/runs/38159228613/job/114528457339) بررسی شد. اسکن runtime موفق و در17:23:38 UTC اسکن gateway با4 HIGH قابل اصلاح شکست خورد: CVE-2026-78669 در x/net0.59.0 و سه finding stdlib Go1.26.8 شامل CVE-2026-78667، CVE-2026-78669 و CVE-2026-97031. fixهای log، x/net0.60.0 و Go1.26.9/1.27.2 هستند. این run نشان داد اصلاح deadlock و cache مؤثر بوده و مرحله validation تا اسکن در حدود3 دقیقه رسید؛ زمان cold build جدید هنوز با Actions اندازه‌گیری نشده است.

در زمان بررسی، [metadata رسمی Docker](https://raw.githubusercontent.com/docker-library/official-images/master/library/caddy) و GitHub releases آخرین stable را2.11.7 نشان دادند. registry رسمی برای tag2.11.7-alpine همان digest آسیب‌پذیر `sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f` را برگرداند؛ بنابراین tag بدون digest یا latest راه‌حل پذیرفته‌شده نیست. [Go release history](https://go.dev/doc/devel/release#go1.26.9) انتشار patch امنیتی را تأیید می‌کند؛ version و checksum x/net از Go proxy/checksum database دریافت شدند.

gateway جدید:

- source پایدار Caddy2.11.7 با checksum SHA256 آرشیو Go proxy ثابت است؛ main.go همان entrypoint upstream با تمام standard modules است. Caddy به عنوان dependency نسخه‌دار در build metadata باقی می‌ماند تا scanner نسخه واقعی را تشخیص دهد.
- go.mod/go.sum کامل با ابزار Go1.26.9 ایجاد شدند؛ x/net0.60.0 pin شده است. GOTOOLCHAIN=local، go mod verify و build با -mod=readonly از تغییر خودکار toolchain/lock جلوگیری می‌کنند.
- builder رسمی `golang:1.26.9-alpine3.23` با digest `sha256:6750308561953d451210ed1a8086d36c8c8bdcbf672d38d9d0d424b8a7a0216c` و runtime رسمی Alpine3.23 با digest `sha256:85fe1e81d6758c208f3e1eed4338a1997e19d4be002d4dd32d3100c9a8c010a0` مستقیماً از registry بررسی شدند. binary statically linked و runtime فاقد compiler/package install شناور است.
- فقط CA bundle از image Caddy رسمی pin‌شده COPY می‌شود؛ binary آسیب‌پذیر آن در image نهایی وجود ندارد. USER10001، read-only، cap_drop ALL، no-new-privileges، HTTPS خودکار و bindهای پایدار data/caddy و data/caddy-config حفظ شدند.
- application Dockerfile فقط entrypoint و health.php موردنیاز را در مسیر docker runtime کپی می‌کند؛ سورس build gateway وارد runtime PHP نمی‌شود. پنل فارسی و داده خصوصی تغییر نکردند.

زنجیره deployment:

- CI با cache مستقل gateway هر دو image را اسکن می‌کند؛ HIGH/CRITICAL، ignore-unfixed و exit-code1 همان قبلی‌اند؛ هیچ CVE suppress نشده است. configuration Caddy و smoke واقعی gateway با UID/read-only، image ID دقیق، proxy privacy، health و persistence هر دو مسیر certificate بررسی می‌شوند. فقط fixture جداگانه smoke از HTTP بدون ACME خارجی استفاده می‌کند؛ Caddyfile تولیدی HTTPS خودکار دارد.
- artifact شامل هر دو image و دو image ID با checksum tar است. publish پس از pull هر دو digest، revision و content ID را تطبیق می‌دهد. اگر هر کدام با artifact متفاوت باشند، هر دو image دوباره scan و همان pair smoke می‌شود. version/edge به هر دو image اعمال و دو فایل digest در Release قرار می‌گیرند.
- Compose ورودی اجباری UBCC_GATEWAY_IMAGE دارد؛ deploy/SSH wrapper فقط سه آرگومان APP_DIGEST SEQUENCE GATEWAY_DIGEST معتبر را می‌پذیرند. current/previous و rollback هر دو digest را نگه می‌دارند. state قدیمی بدون gateway fail می‌شود؛ مهاجرت بازبینی‌شده در PRODUCTION_DEPLOY_FA مستند است و ابزار قدیمی خودکار نصب مجدد نمی‌شود.
- repository variable `PRODUCTION_DEPLOY_ENABLED` فقط با مقدار دقیق true job deploy را فعال می‌کند؛ پیش‌فرض unset یعنی skipped. validate، GHCR و Releases فعال‌اند. در این کار متغیر remote تنظیم نشده است. هر دو digest برای manual workflow لازم‌اند.
- validate15 دقیقه، smoke300 ثانیه با120 ثانیه مهلت cleanup، scanهای محدود، build cache و reuse artifact قبلی حفظ شدند. cold build Go هزینه اولیه دارد؛ compiled layer با تغییر commit یا PHP سورس دوباره ساخته نمی‌شود.

آزمون محلی: PHP/shell lint،16 بلوک workflow،35 unit،160 locked update،6 migration،7 Docker config و27 HTTP موفق. regressionهای timeout/diagnostics، قراردادهای deployment/opt-in/gateway و privacy package اجرا شدند. Go module verification، build native patched و Caddyfile validation واقعی موفق؛ binary version برابر2.11.7 و build metadata برابرGo1.26.9 و x/net0.60.0 است. این build native جایگزین Docker build نیست. Linux lifecycle/rollback در container، Trivy image نهایی، health/privacy/persistence Compose و HTTPS واقعی هنوز منتظر Actions هستند. cross-compile واقعی Linux/amd64 با CGO_ENABLED=0 و -mod=readonly موفق شد و metadata همان versionهای patched را دارد. config fixture smoke نیز با Caddy native به HTTP8080 و health loopback2016 بدون ACME تبدیل و بررسی شد؛ health site تولیدی اکنون صریحاً bind127.0.0.1 دارد.

نتایج نهایی offline:95 قرارداد regression CI،13 assertion diagnostics،18 assertion اجرای shell واقعی publish با registry ساختگی (تطبیق هر دو image، اختلاف runtime و اختلاف gateway)،279 قرارداد Docker/deployment موفق.44 مسیر خصوصی و94 مسیر سورس در هر دو ignore mode و archive fixture86 فایل تأیید شدند. Composer audit vulnerability گزارش نکرد؛ hashهای config/account/log بدون تغییر‌اند. ابزار Trivy0.75.0 رسمی با checksum دریافت و scan مستقل binaryهای rebuilt امتحان شد، اما دانلود DB از mirror با unexpected EOF و retry مستقیم GHCR با deadline4 دقیقه شکست خورد. بنابراین نتیجه vulnerability scan محلی موجود نیست و صفر HIGH/CRITICAL ادعا نمی‌شود. گیت Trivy کامل روی هر دو image در Actions شرط انتشار و پذیرش باقی می‌ماند. هیچ push یا deploy انجام نشده است.

## رفع deadlock و محدودسازی زمان CI — run 38141926133

وضعیت: **اصلاح محلی آماده است؛ تأیید Docker فقط پس از قبولی Actions روی Ubuntu24.04.**

در [run شکست‌خورده](https://github.com/roboapi2/self-bot-telegram/actions/runs/38141926133)، Nginx در 13:10:30 UTC موفقیت configuration test را چاپ کرد، اما validate در 13:52:45 UTC لغو شد. علت این توقف با بررسی entrypoint تأیید شد: `dc run --rm --no-deps nginx nginx -t` به entrypoint برنامه می‌رسید؛ شاخه nginx پس از `nginx -t`، سرور را با `daemon off` اجرا می‌کند. بنابراین فرمان configuration test تمام نمی‌شد. هر دو آزمون یک‌بارمصرف، شامل diagnostics، اکنون دقیقاً `dc run --rm --no-deps --entrypoint nginx nginx -t` هستند. آزمون داخل container فعال (`exec`) نیازی به override ندارد.

محدودیت‌ها و cleanup:

- validate حداکثر15 دقیقه؛ بدنه smoke حداکثر300 ثانیه با120 ثانیه مهلت مستقل برای trap و cleanup؛ step حداکثر7 دقیقه. این سقف‌ها جایگزین hang42 دقیقه‌ای شده‌اند.
- هر Compose command با GNU timeout اجرا می‌شود: up100، run30، restart/stop25، down20، ps/logs5 و سایر فرمان‌ها15 ثانیه. health waitهای Compose نیز صریحاً30/60/90 ثانیه‌اند. restart health retry سقف45 ثانیه دارد.
- diagnostics بودجه جداگانه دارد: Nginx test8 ثانیه، inspect3 ثانیه برای حداکثر5 container؛ ps/logs تمام containerها را پوشش می‌دهند. kill-after در cleanup یک ثانیه است تا جمع بدترین بودجه‌های تشخیص و cleanup در مهلت120 ثانیه باقی بماند. exit code شکست اصلی حفظ می‌شود.
- خروجی START/END با timestamp UTC روی stderr است؛ stdout فرمان‌های inspect و HTTP برای assertionها پاک باقی می‌ماند.
- پس از down، containerهای باقی‌مانده با label پروژه تصادفی `ubcc-ci-*` force-remove و خالی بودن inventory بررسی می‌شود. تمام Docker runهای workflow نیز label پروژه مخصوص run دارند؛ step نهایی `always()` آن‌ها و پروژه‌های smoke ثبت‌شده را پاک می‌کند. helper پروژه تولیدی را رد می‌کند و هیچ volume را حذف نمی‌کند. اگر daemon پاسخ ندهد cleanup سریع fail می‌شود؛ حذف واقعی در این حالت قابل تضمین نیست و runner GitHub موقتی است.

بهینه‌سازی بدون حذف گیت:

- target CI وارث runtime است؛ ابتدا CI با shared runtime layers ساخته و سپس runtime از همان builder/cache بارگذاری می‌شود. build اضافه برای export سورس حذف شده؛ canary privacy مستقیم در image CI بررسی می‌شود.
- BuildKit GHA cache، دانلودهای pip و پایگاه Trivy cache می‌شوند. cache دیتابیس روزانه refresh می‌شود؛ اسکن HIGH/CRITICAL همچنان اجرا می‌شود. فقط شکست export اختیاری cache قابل چشم‌پوشی است؛ build و security gate خطا را عبور نمی‌دهند.
- PHP/HTTP/Linux lifecycle، Composer audit، Gitleaks، Trivy runtime و gateway، Caddy config، Compose health/privacy/persistence و rollback mock حفظ شده‌اند. manual digest دقیقاً روی image درخواست‌شده scan/smoke می‌شود.
- image فقط برای انتشار ذخیره می‌شود؛ artifact تصویر از بسته deployment جداست و rolling فقط بسته کوچک را دانلود می‌کند. checksum tar و image ID هنگام publish کنترل می‌شوند. پس از pull digest رجیستری، revision و image ID با artifact آزموده‌شده تطبیق دارند. فقط در تطبیق دقیق، scan/smoke تکراری حذف می‌شود؛ SHA tag موجود با image ID متفاوت دوباره scan/smoke می‌شود. خطای timeout/auth/network هنگام بررسی tag، به معنی نبود tag پذیرفته نمی‌شود.
- هدف5 تا10 دقیقه برای validate معمولی با cache گرم است؛ هنوز زمان واقعی نسخه اصلاح‌شده اندازه‌گیری نشده و cold build/download ممکن است طولانی‌تر باشد.

اعتبارسنجی محلی:

- lint PHP/shell و16 بلوک shell workflow موفق؛35 unit،160 locked updates،6 migration،7 Docker configuration و27 HTTP موفق. lifecycle Linux محلی به علت Windows/نبود pcntl/posix skip شد.
-62 regression CI با Nginx ساختگی و GNU timeout واقعی: رفتار entrypoint قدیمی، خروج سریع فرمان مستقیم، kill فرآیند مقاوم به TERM، timestamp، label one-off و رد cleanup تولیدی بررسی شدند.
-13 assertion diagnostics با Docker ساختگی، شامل logs معلق، حفظ exit23 و حذف orphan؛265 قرارداد offline موفق شدند.44 مسیر خصوصی و88 مسیر سورس در هر دو ignore mode بررسی و بسته fixture80 فایل تأیید شد. Composer audit آسیب‌پذیری گزارش نکرد و hashهای config/account/log بدون تغییر بودند. این نتایج اجرای Docker واقعی محسوب نمی‌شوند.
- build، Trivy/Gitleaks داخل container، Compose، healthcheck واقعی، rollback Linux و smoke image اصلاح‌شده محلی اجرا نشدند؛ engine موجود نیست. VPS تولیدی دست‌نخورده است. قبولی run جدید Actions شرط پذیرش نهایی است.

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
docker build -f docker/gateway/Dockerfile -t ubcc-gateway-candidate .
bash scripts/docker/ci-smoke.sh ubcc-candidate ubcc-gateway-candidate
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
