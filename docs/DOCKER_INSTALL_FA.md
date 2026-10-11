# نصب Docker روی Ubuntu 24.04

این مسیر برای VPS با معماری amd64، دامنه واقعی و دسترسی sudo است. نصب قدیمی و پنل فارسی حفظ شده‌اند. image روی GitHub ساخته می‌شود؛ VPS فقط digest مشخص را دریافت می‌کند. Docker این پروژه پنج سرویس دارد: runtime برای فضای PID مشترک، supervisor، PHP-FPM، Nginx و Caddy برای HTTPS. UID برنامه 10001 است. اشتراک فضای PID برای تشخیص صحیح worker و IPC ضروری است؛ فضای PID میزبان استفاده نمی‌شود.

## آماده‌سازی

1. رکورد A دامنه مانند `panel.example.com` را به IP سرور متصل کنید. AAAA را فقط با IPv6 درست ایجاد کنید. پورت‌های TCP 80 و 443 و پورت SSH باید در firewall ارائه‌دهنده باز باشند. هیچ پورت PHP یا Docker را عمومی نکنید.
2. بسته پاک را از GitHub Releases، انتشار `continuous` یا نسخه موردنظر دریافت کنید. فایل `.tar.gz.sha256` را کنار آن بگذارید و اجرا کنید:

```bash
sha256sum -c ubcc-RELEASE.tar.gz.sha256
tar -xzf ubcc-RELEASE.tar.gz
cd ubcc-RELEASE
sudo bash scripts/docker/setup.sh panel.example.com admin@example.com
```

نام واقعی فایل را جایگزین کنید. اسکریپت فقط Ubuntu 24.04 amd64 را می‌پذیرد، Docker از مخزن رسمی نصب می‌کند، کاربر محدود `ubcc-deploy` و سرویس startup را می‌سازد. اجرای دوباره تنظیمات نصب موجود را حفظ می‌کند. میزبان روی `/opt/ubcc` ثابت است؛ فایل‌های اجرایی و تنظیمات میزبان فقط برای root قابل تغییرند.

3. با `sudoedit /opt/ubcc/secrets/config.php` مقدار آرایه تنظیمات را وارد کنید:

```php
<?php
return [
    'api_id' => 123456,
    'api_hash' => 'REPLACE_WITH_PRIVATE_API_HASH',
    'admin_password' => 'REPLACE_WITH_LONG_UNIQUE_PASSWORD',
    'timezone' => 'Asia/Tehran',
];
```

API را از `my.telegram.org` بگیرید. نمونه بالا credential واقعی نیست. مسیرهای نصب قدیمی در کانتینر به `/opt/app` و PHP به `/usr/local/bin/php` تبدیل می‌شوند. فایل تنظیمات اصلی پروژه محلی تغییر نمی‌کند. در صورت نیاز متغیرهای `UBCC_API_ID`، `UBCC_API_HASH`، `UBCC_ADMIN_PASSWORD` و `UBCC_TZ` را با sudoedit در `/opt/ubcc/secrets/app.env` قرار دهید؛ کلیدهای فایل PHP بر env اولویت دارند. پسوند `_FILE` نیز برای خواندن فایل داخل کانتینر پشتیبانی می‌شود؛ فایل باید صریحاً mount شود. این فایل‌ها را commit یا upload نکنید.

4. برای image خصوصی GHCR، یک PAT فقط با `read:packages` و دسترسی به package ایجاد کنید. روی VPS با root وارد شوید:

```bash
sudo -i
read -rsp 'GHCR read token: ' GHCR_TOKEN; echo
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u YOUR_GITHUB_USER --password-stdin
unset GHCR_TOKEN
exit
```

توکن در فایل خصوصی Docker متعلق به root نگهداری می‌شود؛ آن را در command line یا GitHub logs قرار ندهید. برای package عمومی این ورود لازم نیست.

5. دستور اولین استقرار با digest واقعی `IMAGE_DIGEST.txt` انتشار:

```bash
sudo /usr/local/sbin/ubcc-deploy \
  ghcr.io/roboapi2/self-bot-telegram@sha256:REPLACE_WITH_64_HEX_DIGEST 0 \
  ghcr.io/roboapi2/self-bot-telegram-gateway@sha256:REPLACE_WITH_64_HEX_GATEWAY_DIGEST
sudo bash /opt/ubcc/scripts/docker/control.sh status
sudo bash /opt/ubcc/scripts/docker/control.sh doctor
curl --fail https://panel.example.com/healthz
```

Caddy گواهی معتبر را خودکار دریافت و تمدید می‌کند؛ نیازمند DNS درست و پورت آزاد است. فایل تنظیم دامنه `/opt/ubcc/settings.env` است. اولین استقرار تا موفق شدن HTTPS تأیید نمی‌شود. healthz عمومی فقط Nginx را بررسی می‌کند؛ healthcheck داخلی PHP و supervisor و doctor نیز شرط استقرارند.

## انتقال نصب قبلی

ابتدا backup نصب قبلی را بگیرید و supervisor/workerهای قدیمی را با فرمان رسمی همان نصب متوقف کنید. سرویس قدیمی و Docker هرگز روی نشست‌های یکسان هم‌زمان اجرا نشوند. پیش از توقف وب قدیمی، پیکربندی آن را نگه دارید؛ پورت 80/443 برای Caddy آزاد کنید. از ریشه واقعی نصب قبلی، قبل از اولین deploy:

```bash
sudo rsync -a accounts/ /opt/ubcc/data/accounts/
sudo rsync -a .panel/ /opt/ubcc/data/panel/
sudo cp -p config.php /opt/ubcc/secrets/config.php
sudo chown -R 10001:10001 /opt/ubcc/data/accounts /opt/ubcc/data/panel
sudo chown 10001:10001 /opt/ubcc/secrets/config.php
sudo chmod 0400 /opt/ubcc/secrets/config.php
```

اگر `.panel/accounts.json` وجود ندارد و registry قدیمی در `accounts.json` است، فقط در این حالت آن را به `/opt/ubcc/data/panel/accounts.json` کپی و مالکیت 10001 بدهید؛ registry موجود را بازنویسی نکنید. migration داخل کانتینر registry را بررسی می‌کند. این کپی‌ها فقط روی داده متعلق به همان نصب و بدون symlink خارجی انجام شوند. فایل‌های lock/PID کپی‌شده نشست نیستند؛ تشخیص مالکیت process و قفل‌های flock مانع استفاده از PID قدیمی می‌شود. برای انتقال نشست واقعی، اعتبارسنجی اتصال Telegram روی سرور همچنان لازم است.

## استفاده روزانه

به `https://panel.example.com` وارد شوید، رمز پنل را وارد کنید و از پنل فارسی حساب ایجاد کنید. QR را با Telegram گوشی اسکن کنید و اگر حساب 2FA دارد رمز را در همان جریان ورود پنل وارد کنید. تا تأیید ورود صبر کنید؛ سپس حساب را فعال کنید. فعال بودن حساب در registry پایدار است. خاموش کردن سرویس supervisor با غیرفعال کردن حساب در پنل متفاوت است: توقف سرویس قصد فعال بودن را حفظ می‌کند. نشست و آرشیو حساب در `data/accounts` و registry و session وب در `data/panel` هستند. backup این مسیرها خصوصی است.

فرمان‌های روزانه و CI/CD را در [راهنمای استقرار](PRODUCTION_DEPLOY_FA.md) و [راهنمای CI/CD](CICD_FA.md) ببینید.
