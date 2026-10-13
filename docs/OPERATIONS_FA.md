# عملیات روزانه، پشتیبان و تکمیل آزمون‌ها

همه فرمان‌های این راهنما روی VPS و از پوشه `/opt/self` هستند مگر محل دیگری ذکر شده باشد. فرمان‌های `sudo` از حساب SSH مدیر اجرا می‌شوند. `ubcc` حساب برنامه و بدون ورود است؛ برای کار روزانه به root shell نیاز ندارید.

## شروع، توقف، وضعیت و لاگ

```bash
cd /opt/self
sudo bash scripts/control.sh doctor
sudo bash scripts/control.sh status
sudo bash scripts/control.sh accounts
sudo bash scripts/control.sh start
sudo bash scripts/control.sh restart
sudo bash scripts/control.sh logs
```

برای توقف برنامه و FPM:

```bash
sudo bash scripts/control.sh stop
```

Nginx باقی می‌ماند و هنگام توقف FPM ممکن است پنل 502 نشان دهد. این فرمان‌ها کل سرویس PHP 8.3 FPM را اداره می‌کنند؛ دیگر poolهای همان نسخه هم متأثر می‌شوند. توقف سرویس ناظر قصد اجرای حساب‌ها را حفظ می‌کند؛ پس از شروع دوباره، حساب‌های دارای `enabled` برمی‌گردند. دکمه Stop خود حساب قصد اجرا را پاک می‌کند.

برای یک حساب، `acc_1` را با کلید واقعی جایگزین کنید؛ اجرا به هویت **کاربر برنامه** است:

```bash
sudo -u ubcc env UBCC_PROCESS_MODE=supervisor UBCC_PHP_BIN=/usr/bin/php8.3 /usr/bin/php8.3 console.php start acc_1
sudo -u ubcc env UBCC_PROCESS_MODE=supervisor UBCC_PHP_BIN=/usr/bin/php8.3 /usr/bin/php8.3 console.php stop acc_1
sudo -u ubcc env UBCC_PROCESS_MODE=supervisor UBCC_PHP_BIN=/usr/bin/php8.3 /usr/bin/php8.3 console.php restart acc_1
```

در حالت supervisor خروجی موفق Start یعنی درخواست ثبت شده؛ وضعیت واقعی را چند ثانیه بعد ببینید. PID فایل برای کشتن پردازش کافی نیست؛ برنامه مالکیت پردازش را با مسیر worker بررسی می‌کند. اجرای موازی `bot.php` یا کارگر با root باعث تداخل نشست می‌شود.

لاگ‌ها ممکن است اطلاعات عملیاتی حساس داشته باشند؛ پیش از اشتراک‌گذاری بررسی و پاک‌سازی کنید:

```bash
sudo journalctl -u userbot-supervisor -n 100 --no-pager
sudo tail -n 80 .panel/php-error.log
sudo tail -n 80 accounts/acc_1/bot.log
sudo tail -n 80 accounts/acc_1/qr_worker.log
sudo tail -n 80 /var/log/nginx/ubcc-error.log
sudo logrotate --debug /etc/logrotate.d/ubcc
```

فایل‌هایی که هنوز ساخته نشده‌اند ممکن است موجود نباشند. logrotate لاگ‌های bot/QR/FPM را روزانه یا در اندازه بالاتر از ۱۰ مگابایت با نگهداری ۷ نسخه مدیریت می‌کند؛ `copytruncate` در ترافیک سنگین احتمال از دست رفتن چند خط دارد. قالب با debug بررسی می‌شود؛ برای اثبات چرخش واقعی روی سرور آزمایشی، `sudo logrotate --force /etc/logrotate.d/ubcc` را اجرا و فایل‌های `.1`/فشرده را بررسی کنید. لاگ Nginx تحت سیاست عمومی `/var/log/nginx/*.log` سیستم است. فایل قدیمی `MadelineProto.log` خودکار پاک نمی‌شود.

## پشتیبان و بازیابی

```bash
cd /opt/self
sudo bash scripts/backup.sh
sudo ls -lh .panel/backups
```

فرمان نخست مسیر یک فایل `ubcc-...tar.gz` را چاپ می‌کند. هنگام کپی، FPM و ناظر متوقف و سپس فقط سرویس‌های قبلاً روشن برگردانده می‌شوند. توقف ناظر کارگرهای تحت systemd را نیز متوقف می‌کند. اگر پردازش قدیمی خارج از این سرویس دارید ابتدا از راه همان نصب قدیمی آن را متوقف کنید؛ پشتیبان نشست فعال قابل اتکا نیست.

پشتیبان شامل سورس، config، فهرست فعال و قدیمی، نشست‌های Telegram، پیام‌ها، رسانه و آرشیو است. `vendor/`، نسخه‌های پشتیبان قبلی، releaseها و نشست ورود وب پنل کنار گذاشته می‌شوند. فایل‌های `/etc` و تنظیمات SSH/فایروال پشتیبان این فرمان نیستند؛ برای بازیابی کل VPS آن‌ها را نیز جداگانه در مخزن خصوصی ذخیره کنید. از فضای دیسک با `df -h` مراقبت کنید و یک کپی خارج از VPS نگه دارید. آرشیو حاوی کلیدهای ورود واقعی است و عمومی نیست.

برای دریافت خصوصی روی کامپیوتر شخصی، مسیر دقیق خروجی را جایگزین کنید. فایل‌ها برای ubcc خصوصی هستند؛ ابتدا با sudo کپی موقت بسازید:

```bash
sudo install -m 0600 -o "$USER" /opt/self/.panel/backups/ubcc-EXACT.tar.gz "$HOME/ubcc-private-backup.tar.gz"
```

روی **PowerShell شخصی**:

```powershell
scp USER@SERVER_IP:~/ubcc-private-backup.tar.gz .
```

پس از انتقال، روی VPS `rm -- ~/ubcc-private-backup.tar.gz` را اجرا کنید. نسخه خارج سرور را در محل امن نگه دارید.

برای **بازگرداندن آگاهانه وضعیت قدیمی**، مسیر واقعی پشتیبان را بدهید:

```bash
sudo bash scripts/restore.sh /opt/self/.panel/backups/ubcc-EXACT.tar.gz
sudo bash scripts/validate-vps.sh
```

این فرمان ابتدا از وضعیت فعلی پشتیبان خصوصی می‌گیرد، سپس سورس/config و داده‌های حساب را به وضعیت پشتیبان انتخاب‌شده برمی‌گرداند. تغییرات بعد از آن تاریخ در نسخه جاری جایگزین می‌شوند ولی پشتیبان قبل از restore نگه داشته می‌شود. نام `EXACT` نمونه است. برای اطمینان از فایل درست، پیش از اجرا `sudo tar -tzf /path/to/backup.tar.gz` را بخوانید؛ فهرست ممکن است نام حساب داشته باشد، آن را منتشر نکنید. restore لینک، device و مسیر خروج از پوشه را رد می‌کند؛ برای آرشیو قدیمی دارای symlink ابتدا بازیابی جداگانه و بررسی دستی لازم است. رمز QR/2FA و نشست را در ویرایشگر دستکاری نکنید.

برای اثبات قابل بازیابی بودن پشتیبان، در **VPS آزمایشی جداگانه** نسخه را به همان مسیر نصب کنید، فایل پشتیبان را خصوصی انتقال دهید، restore را اجرا کنید و فهرست/وضعیت JSON/رسانه را مقایسه کنید. اولین بررسی را بدون فعال‌سازی حساب واقعی انجام دهید. اعتبار نشست Telegram پس از بازگردانی فقط با اتصال مجاز ثابت می‌شود.

## به‌روزرسانی امن

نسخه جدید تمیز را در پوشه staging مانند `/var/tmp/self-next` قرار دهید؛ روی پوشه فعال فایل کپی نکنید. در کامپیوتر شخصی برای نمونه:

```powershell
ssh USER@SERVER_IP "mkdir -p ~/self-next"
scp -r F:\project\self\releases\ubcc-20261011-ignore-audit\* USER@SERVER_IP:~/self-next/
```

روی VPS:

```bash
sudo test ! -e /var/tmp/self-next && sudo mv ~/self-next /var/tmp/self-next
cd /opt/self
sudo bash scripts/update.sh /var/tmp/self-next
sudo bash scripts/validate-vps.sh
```

اگر staging از قبل وجود دارد، برای نسخه بعد نام تازه انتخاب کنید. update ابتدا سورس جدید را آزمایش و پشتیبان سازگار می‌گیرد؛ سپس FPM/ناظر را متوقف، تنها فایل‌های مجاز کد و مستندات را منتقل و Composer را از lock نصب می‌کند. `config.php`، accounts، .panel و نشست‌ها از staging منتقل نمی‌شوند. پس از آزمون و doctor سرویس‌ها شروع و حساب‌های فعال ادامه می‌دهند. update عمداً پایان موفق را با روشن کردن سرویس‌ها تمام می‌کند، حتی اگر پیش از آن خاموش بوده‌اند. شکست بعد از شروع تغییرات سرویس‌ها را خاموش باقی می‌گذارد؛ خطا را اصلاح یا snapshot چاپ‌شده را با restore برگردانید.

قالب‌های `/etc` خودکار بازنویسی نمی‌شوند. تغییر آن‌ها را با `diff` بررسی و سپس به‌صورت دستی اعمال کنید:

```bash
sed -e 's|@ROOT@|/opt/self|g' -e 's|@PHP_BIN@|/usr/bin/php8.3|g' deploy/userbot-supervisor.service.in > /tmp/ubcc-service.proposed
sudo diff -u /etc/systemd/system/userbot-supervisor.service /tmp/ubcc-service.proposed
```

خروج یک از diff یعنی تفاوت وجود دارد، نه خرابی. برای اعمال همین قالب سرویس بعد از بررسی:

```bash
sudo systemctl stop userbot-supervisor
sudo install -m 0644 /tmp/ubcc-service.proposed /etc/systemd/system/userbot-supervisor.service
sudo systemctl daemon-reload
sudo systemd-analyze verify /etc/systemd/system/userbot-supervisor.service
sudo systemctl start userbot-supervisor
```

برای FPM و Nginx نیز قالب را رندر و diff کنید؛ تنظیم HTTPS سفارشی را حذف نکنید. پس از تغییر FPM/Nginx ابتدا `sudo php-fpm8.3 -t` و `sudo nginx -t` و سپس reload انجام دهید.

## چک‌لیست حساب واقعی؛ تنها پس از مجوز مالک

آزمون‌های خودکار حساب ساختگی‌اند. برای حساب آزمایشی تحت کنترل خود، هر ردیف را با تاریخ و نتیجه ثبت کنید. هیچ تستی از این فهرست در محیط فعلی با Telegram اجرا نشده است.

| مرحله | انتظار و معیار قبول |
|---|---|
| ایجاد حساب | یک رکورد با کلید یکتا ایجاد شود؛ حساب‌های قدیمی تغییر نکنند |
| QR | اسکن دستگاه تحت کنترل مالک؛ پنل ورود موفق را نشان دهد؛ QR منقضی قابل تجدید باشد |
| 2FA | اگر فعال است رمز صحیح پذیرفته، رمز غلط خطای عمومی نشان داده و رمز در log دیده نشود؛ فایل انتقال بعد از مصرف حذف شود |
| نشست پایدار | پنجره را ببندید، دوباره پنل را باز کنید؛ ورود مجدد QR لازم نباشد |
| Start | چند ثانیه بعد وضعیت روشن و `/help` در Saved Messages پاسخ دهد |
| Stop/Start | وضعیت خاموش سپس روشن شود؛ QR دوباره لازم نباشد |
| Restart | حساب روشن شود و تنظیمات/آرشیو/نشست باقی بماند |
| چت/رسانه | چت تحت کنترل خود را باز کنید؛ تاریخچه و یک رسانه آزمایشی درست نمایش داده شود |
| حذف/ویرایش | پیام آزمایشی ابتدا دریافت و ذخیره، سپس حذف/ویرایش شود؛ فقط مقصد مجاز پاسخ مناسب بگیرد |
| ساعت پروفایل | در حساب آزمایشی روشن شود؛ خاموش‌کردن نام قبلی را برگرداند |
| کمپین | یک مقصد تحت کنترل مالک، فاصله حداقل ۱۰ دقیقه؛ pause/resume/delete و حفظ وضعیت بعد restart بررسی شود |
| reboot | حساب دارای قصد اجرا بعد boot خودکار روشن و حساب Stop‌شده خاموش بماند |
| مهاجرت/restore | فهرست و داده محفوظ و نشست بدون QR اضافی کار کند؛ نشست لغوشده در Telegram استثنا است |

پیام‌های حذف‌شده پیش از ثبت محلی قابل بازیابی تضمینی نیستند. ارسال، تغییر پروفایل و مجوز مقصد به Telegram و محدودیت FLOOD_WAIT وابسته است. QR موفق، به‌تنهایی اثبات موفقیت همه قابلیت‌ها نیست.

## آزمون crash و reboot روی VPS آزمایشی

ابتدا همه حساب‌های واقعی را از پنل Stop کنید یا این بخش را روی VPS آزمایشی بدون حساب واقعی اجرا کنید. سیگنال آزمون ناظر ممکن است کارگرهای وابسته را متوقف کند؛ انجام آن روی سرویس فعال کاربر توصیه نمی‌شود.

```bash
cd /opt/self
sudo bash scripts/validate-vps.sh
sudo systemctl show userbot-supervisor -p MainPID -p NRestarts
sudo systemctl kill --kill-whom=main --signal=SIGKILL userbot-supervisor
sleep 8
sudo systemctl is-active userbot-supervisor
sudo systemctl show userbot-supervisor -p MainPID -p NRestarts
```

انتظار: `active`، PID جدید و افزایش NRestarts. بازیابی crash خود worker، PID اشتباه، Stop، حفظ قصد اجرا و حفظ JSON در `tests/linux_lifecycle.php` با fixture جدا آزمایش می‌شوند. این fixture داده واقعی را لمس نمی‌کند.

پس از تهیه backup و اطمینان از دسترسی SSH کنسول ارائه‌دهنده:

```bash
sudo systemctl enable nginx php8.3-fpm userbot-supervisor
sudo reboot
```

دوباره متصل شوید و اجرا کنید:

```bash
cd /opt/self
sudo bash scripts/control.sh status
sudo bash scripts/validate-vps.sh
sudo bash scripts/control.sh accounts
```

این فرمان‌ها را با نتایج قبل reboot و ردیف‌های چک‌لیست مقایسه کنید. عبور همه بررسی‌های سرور و چک‌لیست Telegram شرط اعلام آمادگی تولید است.
# تشخیص چرخه حساب

در نسخه دارای lifecycle state، پاسخ «درخواست ثبت شد» به معنی running نیست. پنل باید پس از starting به «آماده و فعال» برسد؛ failure و تلاش بعدی را در کارت حساب بررسی کنید. پس از سه خطای startup، رفع علت و Start صریح لازم است. Stop قصد اجرا را پاک می‌کند و restart سرویس نباید حساب خاموش را دوباره فعال کند.

برای گزارش امن و فقط‌خواندنی، روی نصب Docker نسخه جدید `sudo bash /opt/ubcc/scripts/docker/control.sh diagnose` اجرا کنید. session، config، raw logs و docker inspect کامل را ارسال نکنید. [ممیزی چرخه و checklist پذیرش](LIFECYCLE_AUDIT_FA.md) را قبل از استقرار این patch بخوانید؛ تأیید Telegram واقعی همچنان لازم است.
