# راه‌اندازی GitHub Actions و GHCR

workflow در `.github/workflows/cicd.yml` است. PR فقط تست و ساخت انجام می‌دهد. push روی main پس از قبولی تست، image با `sha-COMMIT` را در `ghcr.io/roboapi2/self-bot-telegram` منتشر می‌کند؛ `edge` و prerelease با نام `continuous` به آخرین HEAD تأییدشده اشاره می‌کنند. tag دقیق مانند `v1.2.3` image نسخه و Release با checksum می‌سازد. نسخه مستقر همیشه digest است. `continuous` trigger نسخه نیست و workflow رویداد release ندارد.

## Secrets لازم

در GitHub: Settings → Environments → New environment → `production`. برای استقرار کاملاً خودکار required reviewer قرار ندهید؛ دسترسی محیط را به main و tagهای نسخه محدود کنید. Environment secrets:

| نام | مقدار |
|---|---|
| `VPS_HOST` | IP یا نام DNS VPS بدون پروتکل |
| `VPS_USER` | دقیقاً `ubcc-deploy` |
| `VPS_PORT` | پورت SSH، معمولاً `22` |
| `VPS_SSH_KEY` | کلید خصوصی اختصاصی بدون passphrase مخصوص این automation |
| `VPS_KNOWN_HOSTS` | خط host key مورد اعتماد سرور برای همان host و port |

API Telegram و رمز پنل فقط در VPS هستند؛ آن‌ها را در secrets CI نگذارید. `GITHUB_TOKEN` خودکار است و برای انتشار package/release مجوز محدود job دریافت می‌کند. Settings → Actions اجازه اجرای workflow بدهید. در تنظیمات package GHCR بخش Manage Actions access، دسترسی write همین repository را فعال کنید؛ خصوصاً اگر package قبلاً ایجاد شده باشد. workflow برای نام repository فعلی طراحی شده است؛ fork باید نام image و کنترل digest را هماهنگ تغییر دهد.

## کلید SSH و host verification

روی دستگاه امن خود یک جفت کلید اختصاصی بسازید:

```bash
ssh-keygen -t ed25519 -f github-ubcc -C github-actions-ubcc
```

محتوای `github-ubcc` را در secret خصوصی و فقط محتوای `.pub` را در VPS، `/home/ubcc-deploy/.ssh/authorized_keys` قرار دهید. مالک دایرکتوری/فایل `ubcc-deploy` و permissionها 700/600 باشند. برای این کلید از گزینه `restrict` در authorized_keys استفاده کنید. کاربر عضو گروه Docker نیست؛ sudo فقط wrapper اعتبارسنجی‌شده را اجرا می‌کند.

روی VPS پس از setup:

```bash
sudo install -d -m 0700 -o ubcc-deploy -g ubcc-deploy /home/ubcc-deploy/.ssh
sudoedit /home/ubcc-deploy/.ssh/authorized_keys
sudo chown ubcc-deploy:ubcc-deploy /home/ubcc-deploy/.ssh/authorized_keys
sudo chmod 0600 /home/ubcc-deploy/.ssh/authorized_keys
```

در editor یک خط `restrict ssh-ed25519 PUBLIC_KEY_BASE64 github-actions-ubcc` با مقدار واقعی کلید **عمومی** اضافه کنید. فایل کلید خصوصی را به VPS کپی نکنید. از دستگاه امن با همان کلید اتصال را آزمایش کنید: `ssh -i github-ubcc -p 22 ubcc-deploy@VPS_HOST true`؛ پورت/host واقعی را جایگزین کنید و fingerprint را پیش از قبول تطبیق دهید.

host key را از کنسول مورد اعتماد ارائه‌دهنده یا اتصال قبلاً تأییدشده بگیرید. `ssh-keyscan` به‌تنهایی اثبات هویت نیست؛ fingerprint را با `sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` روی کنسول سرور تطبیق دهید. خط known_hosts برای پورت غیراستاندارد باید `[HOST]:PORT` باشد. workflow از StrictHostKeyChecking=yes استفاده می‌کند و کلید تازه را خودکار نمی‌پذیرد.

## اولین اجرا و انتشار

ابتدا نصب و credentialهای VPS را طبق DOCKER_INSTALL_FA انجام دهید، سپس secrets محیط را تکمیل کنید. این تغییرات را بازبینی، commit و به main push کنید. صفحه Actions باید validate، publish، rolling و deploy موفق نشان دهد. archive پاک، checksum و `IMAGE_DIGEST.txt` در Releases هستند. اگر deploy به علت آماده نبودن سرور شکست خورد، image منتشرشده باقی می‌ماند؛ پس از اصلاح سرور از Run workflow روی main و `image_digest` دقیق به شکل `sha256:` به همراه 64 رقم hex استفاده کنید.

برای نسخه پایدار پس از بازبینی:

```bash
git tag v1.2.3
git push origin v1.2.3
```

tag نسخه منتشرشده را جابه‌جا نکنید. tag SHA موجود بازنویسی نمی‌شود و برچسب نسخه موجود با digest متفاوت رد می‌شود. GitHub jobها serialized هستند؛ VPS نیز flock دارد و run_number قدیمی‌تر را رد می‌کند. GitHub concurrency ممکن است اجراهای pending میانی را با جدیدتر جایگزین کند؛ آخرین اجرا هدف استقرار است. نسخه‌های قدیمی که دیر تمام شوند روی نسخه جدیدتر deploy نمی‌شوند. تغییر tag continuous باعث حلقه نمی‌شود.

## گیت‌های کیفیت

Linux CI قراردادهای بسته/ignore، اسکن redacted با Gitleaks، تست PHP و lifecycle لینوکس، composer audit، ساخت Docker، Trivy برای HIGH/CRITICAL قابل اصلاح و smoke واقعی Compose را اجرا می‌کند. آسیب‌پذیری فاقد fix با `--ignore-unfixed` مانع انتشار نیست؛ وضعیت آن را جداگانه بررسی کنید. manual digest نیز scan و smoke می‌شود. تست smoke داده مصنوعی دارد و Telegram واقعی را فعال نمی‌کند. actionها با SHA و imageهای پایه و Caddy با digest pin شده‌اند؛ ارتقاهای امنیتی را با PR، بررسی digest و همین CI انجام دهید.

اگر گیت شکست خورد، انتشار/استقرار انجام نمی‌شود؛ خطای dependency را با اصلاح lockfile و آزمون دوباره رفع کنید، گیت را صرفاً برای سبز شدن حذف نکنید. ساخت و اتصال VPS در محیط فعلی محلی اجرا نشده‌اند؛ اولین اجرای GitHub Actions شرط پذیرش Docker این نسخه است.
