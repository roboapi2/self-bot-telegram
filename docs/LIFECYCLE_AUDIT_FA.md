# ممیزی کامل چرخه اجرا — ۱۴ اکتبر ۲۰۲۶

## بازبینی مجدد درخواست جامع

فایل درخواست مجدد همان scope ممیزی قبلی را دارد؛ patch و یافته‌ها ادامه همان کارند. بررسی تکمیلی `start_qr_worker` race دیگری یافت: Stop خارج از lifecycle.lock مربوط به QR انجام می‌شد؛ concurrent Start می‌توانست در فاصله بین دو transaction وارد شود. Stop و ثبت QR intent اکنون زیر یک lock و با stop_account(alreadyLocked=true) انجام می‌شوند. regression قرارداد سورس این ترتیب را enforce می‌کند؛ Linux integration با QR worker مصنوعی انتقال bot→QR، cleanup و حفظ feature state را بررسی می‌کند. این تست QR واقعی یا2FA نیست و اجرای Linux هنوز UNVERIFIED است.

timeout تست Compose اکنون به جای فقط نام state، stage، fixed category، attempt/retry، heartbeat، intent و process_alive را چاپ می‌کند؛ هیچ response خام، credential، session، argv یا محتوای log چاپ نمی‌شود. deadlock در این تغییر معرفی نشده: Stop داخل همان lock از پارامتر alreadyLocked استفاده می‌کند. وضعیت اجرای جدید Actions برای این patch موجود نیست؛ همه ادعاهای PASS Docker باید تا قبولی run جدید معلق بمانند.

## اصلاح race بازیابی crash در CI — run38476529900

commit `f783854b48958562c0958ed33f7d84297a79c4bc` در Linux lifecycle، روی خط دارای دو await_worker با «Worker timeout» شکست خورد. طبق log ارائه‌شده، Compose، بسته release، scan و publish هنوز اجرا نشده بودند. پیام قدیمی مشخص نمی‌کند انتظار توقف timeout شده یا انتظار بازیابی؛ این نسبت‌دادن با log موجود قابل اثبات نیست.

**مکانیزم معیوب سورس تأیید شد:** worker PID و حتی readiness را قبل از پایان polling صد میلی‌ثانیه‌ای launch_worker ثبت می‌کند. تست قدیمی فقط worker_running=true را می‌دید و می‌توانست همان worker را پیش از بازگشت launcher بکشد. launcher سپس unsuccessful برمی‌گشت و supervise_account آن را spawn_failure ثبت می‌کرد؛ این category به‌طور عمدی retry_at=null دارد. بنابراین crash یک PHP واقعاً راه‌اندازی‌شده با ناتوانی دائمی spawn اشتباه گرفته می‌شد. این interleaving توضیح محتمل انتظار دوم است؛ بازتولید دقیق زمانی در همان image Linux هنوز در محیط محلی ممکن نیست.

اصلاح محدود در runtime.php:134 و:142، `record_launch_failure` در:148 را فراخوانی می‌کند: اگر نسل فعلی وارد bootstrap شده، failure از نوع process_exit و دارای backoff محدود است؛ اگر هیچ مرحله‌ای جز spawn طی نشده، سیاست spawn_failure دائمی حفظ می‌شود. failure قبلی worker و نسل جدیدتر overwrite نمی‌شوند. unit با کلاس‌های واقعی runtime نشان می‌دهد طبقه‌بندی قبلی حتی با stage handlers_ready و PID ثبت‌شده retry را دائماً قطع می‌کرد؛ اصلاح retry آینده می‌سازد، retry موجود را عقب نمی‌اندازد و نسل obsolete را تغییر نمی‌دهد. هیچ timeout برنامه یا تعداد تلاش‌ها افزایش نیافته است.

tests/linux_lifecycle.php انتظارهای مبهم را با deadline monotonic و milestoneهای متمایز جایگزین می‌کند: readiness و تکمیل scan ناظر قبل از crash عادی، توقف همان PID کشته‌شده (از جمله zombie)، ثبت process_exit و retry آینده، respawn با PID/birth identity/run_id جدید، heartbeat جلو‌رونده و حفظ feature state. Stop طی دو scan واقعی بررسی می‌شود؛ sleep دلخواه جایگزین assertion نشده است. تست جداگانه worker را بلافاصله بعد از readiness با SIGKILL مصنوعی می‌کشد تا مرگ هنگام launcher polling نیز پوشش داده شود. shutdown ناظر و پاک‌شدن PID/started_at bounded بررسی می‌شوند. failure فقط state/stage/category، PID، birth identity، kernel state، زمان و retry را چاپ می‌کند؛ raw logs، argv، environment و session چاپ نمی‌شوند.

gate production Compose همان race مصنوعی، crash detection و retry قبل از respawn، process identity جدید، heartbeat تازه و حفظ config حساب را بررسی می‌کند. UID10001، read_only، cap_drop، tmpfs و PID namespace مشترک حفظ‌اند. tests/linux_lifecycle.php اکنون از transient worker_running=false برای کل حساب به‌عنوان synchronization استفاده نمی‌کند؛ فرآیند کشته‌شده و نسل جدید جداگانه بررسی می‌شوند.

| نتیجه این اصلاح | وضعیت |
|---|---|
| defect سیاست retry و regression آفلاین | PASS؛ پنج assertion جدید،55 unit محلی روی Windows؛ تعداد Linux به دلیل assertion POSIX می‌تواند متفاوت باشد |
| suite محلی | PASS؛160 concurrency،6 migration،7 Docker config،34 logger،31 bot contract،29 HTTP و13 frontend؛ شمارش logger در Linux assertion permission اضافی دارد |
| قرارداد CI/package/privacy | PASS؛152 CI regression و298 قرارداد offline؛44 ignored /103 eligible، archive fixture95 فایل و checksum/allowlist موفق |
| Linux runtime و Compose با workerهای واقعی | UNVERIFIED؛ Docker executable/engine در محیط نیست و WSL نصب نیست. اجرای suite Linux محلی SKIPPED محیطی است؛ در Linux نبود pcntl/posix اکنون fail می‌شود و امکان skip کردن gate وجود ندارد |
| scans، rollback، publish و rolling نسخه جدید | UNVERIFIED تا Actions جدید؛ workflow، severity، timeout و security gateها تغییر نکرده‌اند |
| Telegram واقعی | UNVERIFIED؛ fixture فقط لایه Telegram را جایگزین می‌کند، نه supervisor/worker واقعی |

اپراتور باید این patch را review و خودش commit/push کند؛ قبولی کامل Linux lifecycle و Compose در Actions شرط تأیید است. هیچ VPS یا داده واقعی لمس نشده و هیچ push/deploy خودکار انجام نشده است. روند امن استقرار، rollback جفت image و checklist پذیرش پایین این سند همچنان معتبرند؛ این patch صرفاً با PASS محلی «تأیید production» اعلام نمی‌شود.

این گزارش درباره patch فعلی است. قبولی Actions نسخه قبلی (`080d684edab0022bfa990689ad3d7b2087982b92`، run38458306695) اثبات قبولی این تغییرات نیست. محیط محلی Windows/PHP8.4.23 است؛ Docker engine و میزبان Linux تست موجود نیست. هیچ VPS، session واقعی، credential یا تنظیمات production خوانده یا تغییر داده نشده؛ push و deploy انجام نشده است. ورود و عملکرد واقعی Telegram هنوز تأیید نشده‌اند.

## معماری و مسیر واقعی اجرا

`index.php` پنل فارسی و session وب را ارائه می‌کند. `assets/app.js` درخواست دارای CSRF را به `api.php` می‌فرستد؛ احراز هویت و allowlist عملیات قبل از تغییر داده اجرا می‌شوند. `start_account()` ثبت حساب، session، تنظیم API، نبود QR فعال و سلامت اخیر supervisor را بررسی می‌کند. پاسخ accepted فقط پذیرش درخواست است. فایل پایدار `enabled` قصد اجرا و `lifecycle.json` وضعیت مشاهده‌شده را نگه می‌دارند.

supervisor هر دو ثانیه حساب‌ها را جداگانه بررسی می‌کند. `lifecycle.lock` با LOCK_NB اجازه می‌دهد حساب دارای عملیات طولانی، رسیدگی به دیگر حساب‌ها را متوقف نکند. spawn با PHP CLI/nohup، آرگومان‌های escaped و بدون secret در argv انجام می‌شود. `worker.php` قفل انحصاری `worker.lock` مشترک bot/QR، نسل اجرا و قصد enabled را بررسی و PID و زمان تولد `/proc` را ثبت می‌کند. متغیرهای account/API در environment قرار می‌گیرند؛ سپس تعریف امن UserBot و launcher اجرا می‌شوند.

MadelineProto با session absolute همان حساب، scratch خصوصی و logger Closure راه‌اندازی می‌شود. bootstrap کودک IPC کلاس UserBot را بدون اجرای launcher می‌شناسد. `EventHandler` ابتدا onStart، filterها و pluginها را آماده می‌کند؛ hook محدود patch فقط پس از موفقیت این مرحله readiness ثبت می‌کند. onStart به‌تنهایی آماده‌بودن محسوب نمی‌شود. Cron پنج‌ثانیه‌ای heartbeat می‌دهد. پنل فقط وقتی نسل فعلی، process ownership، birth identity، enabled و heartbeat کمتر از۳۰ ثانیه معتبر باشند running نشان می‌دهد. این علامت سلامت handler محلی است؛ موفقیت ارسال یا دریافت Telegram را اثبات نمی‌کند.

Stop ابتدا enabled و QR request را پاک و نسل را عوض می‌کند؛ readiness دیررس نمی‌تواند آن را برگرداند. SIGTERM فقط به worker متعلق به همان حساب فرستاده می‌شود. API حداکثر۱۰ ثانیه منتظر توقف است؛ برای حفظ session SIGKILL production استفاده نمی‌کند. Restart زیر همان lifecycle lock انجام می‌شود. قفل data هنگام انتظار خروج worker نگه داشته نمی‌شود. shutdown آرام supervisor قصد enabled را حفظ می‌کند؛ crashهای غیرمنتظره با وضعیت پایدار قابل بازیابی‌اند.

## اشکالات تأییدشده و محدودیت تشخیص production

| اشکال سورس | شاهد و اصلاح |
|---|---|
| include تعریف UserBot اجرای launcher را هم شروع می‌کرد | include آفلاین بدون TG_API_ID با exit1 بازتولید شد؛ guard اجازه تعریف کلاس و اجرای launcher فقط از worker/entry مستقیم می‌دهد. کلاس serialized همان UserBot باقی است. |
| IPC متغیرهای putenv والد را از دست می‌داد | ProcessRunner8.7 فقط $_SERVER را export می‌کرد؛ تست تفاوت putenv/$_SERVER را ثابت می‌کند. getenv روی snapshot اولیه merge می‌شود؛ secret در argv/log قرار نمی‌گیرد. |
| PID زنده به‌اشتباه running محسوب می‌شد | readiness پس از آماده‌سازی واقعی handler، heartbeat، generation و birth identity جایگزین شد؛ started_at پس از readiness ثبت می‌شود. |
| خطای startup برای همیشه queued یا retry نامحدود می‌شد | failure پایدار با category/stage/time/class/file/line و retry bounded افزوده شد؛ بعد از سه تلاش ناموفق توقف retry تا درخواست صریح Start. |
| Stop زیر data.lock می‌توانست منتظر worker دارای نیاز به همان قفل بماند | lifecycle عملیات از data lock جدا شد؛ fixture هنگام SIGTERM داده را زیر data.lock flush می‌کند. |
| Restart هم‌زمان می‌توانست Stop را برگرداند | stop/start یک transaction روی lifecycle.lock؛ Start در حین پایان Stop خطای قابل‌فهم می‌دهد. |
| PIDfile قدیمی می‌توانست worker اصلی را پنهان کند | fallback به PID و birth identity پایدار و cmdline دقیق؛ PID نامرتبط هرگز signal نمی‌گیرد. |
| restored handler حساب متوقف امکان اجرای timer داشت | guard runtimeActive در handlerها/Cron و unsetEventHandler پشتیبانی‌شده هنگام API حساب متوقف؛ authorization پاک نمی‌شود. |
| متن پیام شامل photo/video نوع رسانه جعلی می‌ساخت | تشخیص media constructor و document attributes؛ regression با متن و رسانه مصنوعی. |
| آرشیو رسانه کل فایل را در RAM می‌خواند | Amp ByteStream pipe جایگزین شد؛ hash محتوای fixture یکسان است. |
| debug پیام/command/profile و exception را چاپ می‌کرد | محتوا از stdout حذف و خطای ساختاری بدون message ثبت می‌شود؛ redaction قبلی حفظ است. |
| JSON خراب یک حساب فهرست پنل یا عملیات bulk را مختل می‌کرد | status فیلد خطادار را گزارش و فایل را حفظ می‌کند؛ Start All و Restart هر حساب را جداگانه رسیدگی می‌کنند. diagnose حتی روی داده خراب بدون چاپ credential/exception message اجرا می‌شود. |

این‌ها defects اثبات‌شده سورس‌اند؛ هنوز معلوم نیست کدام مورد باعث «queued» روی VPS فعلی شده است. برای نسبت‌دادن قطعی علت، خروجی تشخیص امن نسخه فعلی یا نسخه جدید لازم است. session خراب، شکست اتصال/IPC و محدودیت Telegram فرضیه باقی می‌مانند؛ با fixture نمی‌توان آن‌ها را رد کرد. فایل config و session واقعی نباید برای تشخیص ارسال شوند.

شاهد جدید production در۱۴ اکتبر: ناظر recent healthy، PHP executable و shell_exec موجود؛ حساب enabled و logged_in، PID0 و bot/QR غیرزنده، فایل worker.lock موجود و آخرین log در00:04:36؛ دسته‌های regex خالی. این شواهد قصد اجرا و نبود worker پایدار را تأیید می‌کنند؛ خالی‌بودن regex خطا را رد نمی‌کند و وجود نام worker.lock به معنی flock فعال نیست. شکست spawn، pre-PID bootstrap، خروج پس از PID و خطای scan ناظر هنوز از یکدیگر تفکیک نشده‌اند؛ readonly علت قطعی اعلام نمی‌شود.

دو مسیر کم‌مشاهده دیگر اصلاح شدند: `runtime.php::launch_worker` قبل از shell، قابلیت append لاگ را بررسی و log_unwritable پایدار ثبت می‌کند؛ `worker.php` شکست bootstrap قبل از PID را catch و با stage pid_registration/class/file/line ثبت می‌کند. آزمون محلی real PHP worker با PID target از نوع directory، exit1 و failure پایدار را بازتولید/تأیید کرد؛ آزمون Compose فایل log/PID با mode0400 تحت UID10001 و stale PID متعلق به driver را اضافه می‌کند. heartbeat supervisor اکنون پس از تکمیل scan نوشته می‌شود؛ خطای سراسری دیگر قبل از scan heartbeat تازه ایجاد نمی‌کند. خطای یک account با failure اختصاصی هنوز اجازه رسیدگی به بقیه و سلامت خود ناظر را می‌دهد.

محل دقیق اصلاحات در سورس این نسخه: bot_template.php:2608 guard تعریف/launcher؛ scripts/patch-madeline.php:18 انتقال environment IPC و:23 readiness پس از validation؛ runtime.php:102 launch،:186 failure و:258 supervision؛ worker.php:23 ثبت PID و catch bootstrap؛ lib.php:304 پذیرش Start؛ supervisor.php:28 ثبت scan تکمیل‌شده. این شماره‌ها مربوط به patch فعلی‌اند و با ویرایش بعدی ممکن است تغییر کنند.

## وضعیت، خطا و بازیابی

وضعیت‌های پنل: stopped، queued، starting، running، stopping، failed، retrying. queued بدون مصرف ناظر پس از۳۰ ثانیه failed/supervisor_unavailable می‌شود. startup بیش از۱۲۰ ثانیه و heartbeat کهنه سبب failure و درخواست توقف می‌شوند. retry بعد از۱۰ و۲۰ ثانیه برای تلاش‌های ناموفق آغازین انجام می‌شود؛ تلاش سوم retry_at=null دارد. پس از۶۰ ثانیه readiness پایدار شمارنده پاک می‌شود. خطاهای session_missing، spawn_failure، state_corrupt و supervisor_account_error retry خودکار ندارند. یک account خراب مانع loop حساب بعدی نیست. QR session lock مشترک دارد؛ duplicate worker exit75 و failure مشخص دارد.

`console.php diagnose` فقط سلامت ناظر، writable بودن مسیرهای ضروری و خلاصه whitelist حساب‌ها را چاپ می‌کند؛ cfg/env، session، QR/2FA یا محتوای log چاپ نمی‌کند. روی نسخه جدید، یک فرمان تشخیص یکپارچه و فقط‌خواندنی:

```bash
sudo bash /opt/ubcc/scripts/docker/control.sh diagnose
```

اگر mount حساب قابل نوشتن نباشد failure همان حساب نیز ممکن است نتواند ذخیره شود؛ supervisor دسته state_unwritable ثبت می‌کند. لاگ خام و docker inspect کامل به اشتراک نگذارید؛ Config.Env ممکن است secret داشته باشد. خروجی تشخیص هنوز شامل کلید حساب و PID است؛ آن‌ها را عمومی منتشر نکنید.

برای image قدیمی که diagnose جدید ندارد، این **یک فرمان یکپارچه فقط‌خواندنی** علت‌های باقی‌مانده را محدود می‌کند. هیچ Start، flock probe، write یا اتصال Telegram انجام نمی‌دهد؛ محتوای log فقط برای استخراج category ثابت خوانده و چاپ نمی‌شود. lock از snapshot `/proc/locks` بررسی می‌شود، نه از وجود نام فایل؛ null یعنی وضعیت مشاهده نشد. race بین snapshot و تغییر پردازش ممکن است؛ نتیجه به‌تنهایی اثبات علت startup نیست.

```bash
sudo bash <<'SH'
set -ae
source /opt/ubcc/settings.env
source /opt/ubcc/state/current.env
docker compose --project-name ubcc --env-file /opt/ubcc/settings.env -f /opt/ubcc/compose.production.yml exec -T supervisor php <<'PHP'
<?php
require '/opt/app/lib.php';
set_error_handler(static fn(): bool => true);
$functions = [];
foreach (['shell_exec','proc_open','flock','posix_kill','pcntl_async_signals'] as $f) $functions[$f] = function_exists($f);
$health = (array) read_json_file('/opt/app/.panel/supervisor.health.json', []);
echo json_encode(['supervisor_recent' => time()-(int)($health['time']??0)<30,
 'completed_scan_recorded' => $health['scan_completed']??null, 'functions' => $functions,
 'php_executable' => is_executable(cfg()['php_bin']), 'nohup_executable' => is_executable('/usr/bin/nohup')]).PHP_EOL;
$locks = file_get_contents('/proc/locks');
foreach (load_accounts() as $key => $a) {
 try {
  $p = account_paths($key); $pid = is_file($p['pid']) ? (int)file_get_contents($p['pid']) : 0;
  $owned = [];
  foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmd) {
   $args = explode("\0", (string)file_get_contents($cmd));
   if (in_array('/opt/app/worker.php',$args,true) && in_array($key,$args,true)) $owned[] = (int)basename(dirname($cmd));
  }
  $lockHeld = null; $lockPid = null; $st = stat($p['dir'].'/worker.lock');
  if (is_string($locks) && is_array($st)) {
   $lockHeld = false; $dev=(int)$st['dev'];
   $major=(($dev>>8)&0xfff)|(($dev>>32)&0xfffff000);
   $minor=($dev&0xff)|(($dev>>12)&0xffffff00);
   foreach (explode("\n",$locks) as $line) {
    if (preg_match('/\b(\d+) ([0-9a-f]+):([0-9a-f]+):(\d+) /i',$line,$m)
        && hexdec($m[2])===$major && hexdec($m[3])===$minor && (int)$m[4]===(int)$st['ino']) { $lockHeld=true; $lockPid=(int)$m[1]; }
   }
  }
  $tail=is_file($p['log']) ? (string)file_get_contents($p['log'],false,null,max(0,filesize($p['log'])-32768)) : '';
  $categories=[];
  foreach (['Read-only file system'=>'readonly','Permission denied'=>'permission','TG_API_ID'=>'launcher_credentials',
   'Closure'=>'callback_type','event handler class'=>'handler_class','UserBot'=>'handler_reference',
   'Could not connect'=>'connection','Fatal error'=>'php_fatal','Uncaught'=>'uncaught','nohup:'=>'shell_launch',
   'worker_bootstrap_failure'=>'pre_pid','worker_exception'=>'worker_exception'] as $needle=>$category)
   if (str_contains($tail,$needle)) $categories[]=$category;
  echo json_encode(['key'=>$key,'enabled'=>is_file($p['dir'].'/enabled'),'logged_in'=>is_logged_in($key),
   'pid'=>$pid,'owned_worker_pids'=>$owned,'lock_held'=>$lockHeld,'lock_holder_alive'=>$lockPid===null?null:pid_running($lockPid),
   'account_writable'=>is_writable($p['dir']),'log_writable'=>is_writable(is_file($p['log'])?$p['log']:$p['dir']),
   'pid_target_writable'=>is_writable(file_exists($p['pid'])?$p['pid']:$p['dir']),
   'log_time'=>is_file($p['log'])?filemtime($p['log']):null,'failure_categories'=>$categories]).PHP_EOL;
 } catch (Throwable $e) { echo json_encode(['key'=>$key,'category'=>'diagnostic_read_error','class'=>get_class($e)]).PHP_EOL; }
}
PHP
SH
```

## قرارداد filesystem و privacy

| مسیر | نویسنده و محتوا | persistence / اشتراک / دسترسی |
|---|---|---|
| `/opt/app` و vendor | کد و patch build | root filesystem فقط‌خواندنی؛ runtime هیچ مجوز write عمومی ندارد. |
| `/run/secrets/config.php` | provision اپراتور؛ API hash/password | bind فقط‌خواندنی؛ UID10001 می‌خواند، در release/image نیست. |
| `/opt/app/accounts/<key>/session*` | QR/bot و IPC MadelineProto؛ auth keys | bind پایدار accounts، web/supervisor مشترک، خصوصی؛ safe/lightState/ipc/ipcState/lock/socket و فایل temp dependency کنار session؛ absolute path حفظ شده. |
| accounts/<key>/worker.lock، lifecycle.lock، state.lock، data.lock و PID/status | worker/supervisor/API | همان bind پایدار؛ flock به زنده‌بودن process وابسته است، باقی‌ماندن نام فایل قفل دلیل contention نیست. |
| lifecycle.json، enabled، started_at، qr_state، qr.requested | API/worker/supervisor | پایدار و مشترک؛ QR token و وضعیت ورود خصوصی‌اند؛ generation قبلی readiness نمی‌دهد. |
| QR2FA file | پنل و QR worker؛ password | خصوصی؛ cleanup موجود حفظ شد؛ نباید وارد package/log شود. |
| account JSON/cache/media/archive_media | handler و API؛ پیام خصوصی، رسانه، feature/campaign state | bind پایدار و قفل data؛ دانلود/upload مسیر account؛ پاک‌سازی فقط عملیات صریح کاربر، migration یا purge واقعی در تست انجام نمی‌شود. |
| account bot/QR logs | stdout/stderr worker | پایدار و خصوصی؛ متن private از debug حذف شده، redaction credential فعال است؛ quota/rotation فایل‌های per-account هنوز محدودیت باقی است. |
| `/opt/app/.panel` | API و supervisor؛ registry/health/locks، PHP session/throttle | bind پایدار web/supervisor مشترک؛ mode خصوصی و UID10001 طبق init؛ حاوی هویت و داده حساس. |
| `/tmp/ubcc-*` | Madeline bootstrap هر process، Prime fallback | tmpfs موجود؛ scratch تصادفی0700، cwd و Magic script_cwd صریح؛ cleanup فقط فایل‌های شناخته‌شده، recreate پاک می‌کند. |
| `/tmp/client,fastcgi,proxy,uwsgi,scgi` و PHP-FPM tmp | Nginx/PHP | tmpfs مستقل سرویس، بدون persistence؛ health/config tests نوشتن مسیر را بررسی می‌کنند. |
| gateway `/data` و `/config` | Caddy؛ ACME/certificate/state | bind پایدار خصوصی UID10001؛ automatic HTTPS و پورت‌ها بدون تغییر. |
| Docker stdout/json-file | Docker daemon | محدودیت10m/5file موجود؛ نباید secrets چاپ شوند. |
| `/opt/ubcc/state` و backupهای میزبان | deploy/control اپراتور | state digest جفت runtime/gateway و snapshot خصوصی؛ کپی خارج از CI، هیچ backup واقعی تغییر نکرده. |

init/deploy وجود bindها و owner10001 را آماده می‌کنند؛ smoke همین مالکیت و امنیت را روی داده مصنوعی ایجاد می‌کند. read_only، cap_drop ALL، no-new-privileges، PID namespace مشترک، ports، TLS و volumeها تغییر نکرده‌اند. default database/session serialization dependency حفظ شده؛ backend SQL جدید معرفی نشده است. permission POSIX و sockets فقط در Linux قابل تأییدند. cache/media قدیمی خودکار حذف نمی‌شود؛ اندازه دیسک و retention باید توسط اپراتور پایش شود.

## patch سازگاری MadelineProto8.7.0

نسخه Composer و lockfile ثابت‌اند. scripts/patch-madeline.php سورس اصلی سه فایل Logger، ProcessRunner و EventHandler را با hash بررسی و تغییر ناشناخته را fail می‌کند. Logger constructor/restoration با callback برنامه Closure/redaction را حفظ می‌کند؛ Settings serialization Closure را حذف می‌کند و bootstrap آن را دوباره می‌سازد. IPC auto_prepend_file قبل از dependency entry کلاس UserBot، logger و scratch مستقل را آماده می‌کند و Magic::isIpcWorker را درست نگه می‌دارد. ProcessRunner account environment جدید putenv را منتقل می‌کند.

hook readiness در EventHandler فقط برای کلاس UserBot و callback موجود برنامه فعال است. دلیل patch محدود این است که onStart پیش از filter/plugin validation اجرا می‌شود و future داخلی حتی در failure تکمیل می‌شود؛ استفاده از آن‌ها readiness کاذب می‌ساخت. bootstrap و API از مسیرهای پشتیبانی‌شده استفاده می‌کنند؛ dependency source جدید بدون بازبینی hash و تست build پذیرفته نمی‌شود. هر ارتقای MadelineProto نیازمند بررسی دوباره ترتیب startup، IPC و serialization است. صحت session و IPC واقعی Telegram هنوز نیازمند آزمون اپراتور است.

## ماتریس آزمون نسخه فعلی

PASS به معنای اجرای محلی مشخص است؛ SKIPPED یعنی gate موجود ولی محیط قادر به اجرا نبود؛ NOT TESTABLE یعنی نیاز به Telegram/production، نه آزمون مصنوعی. هیچ gate امنیتی خاموش نشده است.

| حوزه | نتیجه و شاهد |
|---|---|
| PHP/shell syntax، unit و atomic concurrency | PASS؛55 unit،160 locked update،6 migration،7 Docker configuration و16 بلوک shell workflow؛ real PHP worker خطای PID و lock path پیش از PID را ثبت می‌کند |
| login/logout، auth/CSRF، account API و ورودی نامعتبر | PASS؛29 HTTP security روی PHP server محلی، fixtureهای disposable |
| logger Closure/serialization، secret redaction، scratch و spawned bootstrap | PASS؛34 تست با vendor واقعی و Revolt، بدون Telegram |
| تعریف UserBot، vendor handler validation، filesystem helper و آرشیو متن/رسانه | PASS؛31 bot contract، stream hash و media classification مصنوعی |
| وضعیت فارسی و عدم افشای exception محتوا | PASS؛13 frontend regression و node syntax |
| CI timeout/entrypoint و cleanup، publish digest pair | PASS؛152 CI regression،18 publish mock،13 diagnostics mock؛ container واقعی اجرا نشده |
| قرارداد Docker/release/privacy | PASS؛298 قرارداد offline؛44 مسیر ignored و103 سورس eligible در هر دو mode؛ archive fixture با95 فایل و checksum/allowlist/privacy موفق |
| Composer advisory و clean install | PASS؛audit advisory ندارد؛56 dependency از lockfile، platform و کل suite در temp عمومی موفق |
| داده فعلی workspace | PASS؛ hash baseline محلی config/accounts/log بدون چاپ محتوا یا hash تأیید شد؛ داده موجود حفظ است |
| Linux real process: Start/Stop/Restart/crash/PID isolation | SKIPPED محلی؛ tests/linux_lifecycle.php نیازمند Linux/pcntl/posix و در CI فعال است |
| Compose با UID10001، readonly، shared PID و API واقعی | SKIPPED محلی؛ gate ci-smoke.sh بدون skip اضافه شده؛ fixture فقط bot_template را به synthetic Telegram layer bind می‌کند، worker/supervisor/API/runtime production واقعی می‌مانند |
| دو حساب، duplicate lock، startup exit، readonly failure، restart هنگام init، Stop، crash، supervisor crash، recreate/persistence و retry سه‌مرحله‌ای | SKIPPED اجرای Docker محلی؛ driver tests/compose_lifecycle.php، account credentials/session مصنوعی، timeout مستقل100s/40s؛ گزارش stage IPC/restoration مصنوعی است |
| image build، Trivy runtime+gateway، Gitleaks، rolling/rollback image gates | SKIPPED محلی؛ workflow15 دقیقه و همه gateها حفظ‌اند؛ قبولی run جدید ضروری است |
| QR scan،2FA، restoration و IPC server واقعی، auth Telegram | NOT TESTABLE بدون حساب واقعی/اتصال؛ API preflight آفلاین قبلی فقط filesystem را می‌سنجد |
| incoming/edit/delete/view-once، send/recovery، timers/campaign/profile و live settings روی Telegram | NOT TESTABLE؛ helper storage و registration PASS است، اما عملیات شبکه/permissions/flood limits را اثبات نمی‌کند |
| VPS reboot، ACME عمومی، backup و rollback production | NOT TESTABLE؛ ساختار/اسکریپت بررسی شده، VPS دست‌نخورده است |
| معیار پذیرش نسخه جدید در Docker/Telegram | UNVERIFIED؛ هیچ run جدید Actions یا تست واقعی Compose/Telegram برای این patch انجام نشده؛ patch آماده استقرار تأییدشده اعلام نمی‌شود |

هنوز هیچ ادعای PASS واقعی Docker برای این patch وجود ندارد. اولین run ممکن است اشکال integration بیشتری نشان دهد؛ diagnostics bounded فعال است. وضعیت‌های FAILED در fixtures failure عمدی تست هستند؛ failure محیطی حل‌نشده محلی نداریم. ویژگی‌های messaging، edit/delete و campaign حذف نشده‌اند؛ تست خودکار هیچ عملیات Telegram انجام نمی‌دهد.

## استقرار و rollback امن

۱. اپراتور diff و audit را بررسی و خودش commit/push کند. قبولی همه validate/publish/rolling gateهای نسخه جدید شرط ادامه است؛ opt-in PRODUCTION_DEPLOY_ENABLED پیش‌فرض غیرفعال و بدون تغییر است. هیچ image با tag مبهم یا image gateway قدیمی نباید deploy شود.

۲. قبل از تغییر production، وضعیت حساب‌ها و قصد enabled را خصوصی ثبت کنید. با فرمان backup موجود، سرویس‌ها آرام متوقف، snapshot خصوصی گرفته و دوباره راه‌اندازی می‌شوند. پشتیبان را با permission خصوصی و خارج از مسیر public نگه دارید؛ session فعال را بدون توقف کپی نکنید.

۳. فقط release و runtime/gateway digest دقیق همان run را با روند [استقرار موجود](PRODUCTION_DEPLOY_FA.md) به‌کار ببرید. mount data/secrets و domain/SSH/ports دست‌نخورده می‌مانند. سپس status و diagnose را بررسی و checklist زیر را اجرا کنید. healthy بودن پنج container به معنای آماده‌بودن هر account نیست.

۴. اگر readiness/Telegram آزمون پذیرش fail شد، قبل از retry نامحدود Stop کنید و تشخیص whitelist را نگه دارید. rollback با `sudo bash /opt/ubcc/scripts/docker/control.sh rollback` از previous.env هر دو image را برمی‌گرداند؛ [راهنمای rollback](ROLLBACK_FA.md) را رعایت کنید. session/data را با snapshot قدیمی overwrite نکنید؛ lifecycle metadata افزوده‌شده schema session را تغییر نداده و نسخه قبلی می‌تواند آن را نادیده بگیرد. قبل از rollback workerها باید آرام خارج شوند.

## یک checklist پذیرش production توسط اپراتور

- [ ] Actions نسخه جدید کاملاً سبز؛ digest runtime و gateway با release/state یکسان؛ پنج سرویس healthy و diagnose بدون secret.
- [ ] QR/2FA روی حساب مجاز آزمایشی موفق؛ refresh پنل و بازگشایی session بدون ورود مجدد؛ هیچ حساب واقعی reset نشده.
- [ ] Start ابتدا queued/starting و سپس running/heartbeat تازه؛ دو حساب جداگانه هم‌زمان و بدون جابه‌جایی داده؛ failure مصنوعی را فقط در CI اجرا کنید.
- [ ] در چت خصوصی آزمایشی و با رضایت طرفین، دریافت متن/رسانه، edit/delete و recovery/archive را روی پیام‌های disposable بررسی کنید؛ پاک‌کردن تاریخچه واقعی ممنوع.
- [ ] تغییر feature پنل، clock و campaign را فقط برای مقصد/زمان آزمایشی خودتان بررسی و سپس خاموش کنید؛ flood wait، permissions و delivery ثبت شوند.
- [ ] Stop واقعاً worker را خارج و timer/message را متوقف کند؛ refresh و انتظار ناظر آن را برنگرداند. Restart و Restart during initialization نسل تازه بسازند و running کاذب نداشته باشند.
- [ ] restart/recreate سرویس‌ها حساب enabled را بازیابی و حساب stopped را متوقف نگه دارد؛ session، media و certificate بمانند. crash عمدی worker فقط روی محیط/حساب آزمایشی؛ session واقعی را SIGKILL نکنید.
- [ ] در پنجره نگهداری مجاز، reboot میزبان و backup/rollback جفت image را مطابق راهنما بررسی کنید؛ هیچ credential، log خام یا private archive عمومی نشود.
- [ ] نتیجه هر مرحله و مشکل باقی‌مانده ثبت شود؛ تا همه Telegram مراحل تأیید نشده‌اند، برنامه «کاملاً عملیاتی» اعلام نشود.
