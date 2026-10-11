# استقرار و نگهداری تولید

ریشه ثابت سرور `/opt/ubcc` است. `data/accounts` شامل نشست‌ها و آرشیوها، `data/panel` شامل registry/session وب، و `data/caddy` و `data/caddy-config` شامل TLS هستند. `secrets` فقط فایل‌های خصوصی است. `state/current.env` و `previous.env` digestها را نگه می‌دارند. هیچ volume با `down -v` حذف نکنید. فایل‌های برنامه از image خواندنی‌اند و داده‌ها خارج از public root هستند.

## چک‌لیست اولین استقرار

- Ubuntu 24.04 amd64، دسترسی sudo، فضای آزاد برای image و backup.
- DNS درست و پورت‌های 80/443 آزاد؛ firewall شبکه Docker را نیز در نظر بگیرید.
- اجرای setup، تنظیم API/رمز قوی، آماده کردن read:packages برای image خصوصی.
- انتقال امن داده قدیمی و توقف کامل worker قدیمی پیش از Docker.
- کلید SSH اختصاصی، known_hosts تأییدشده و پنج secret محیط production.
- ابتدا push روی main با استقرار غیرفعال، قبولی validate/publish/rolling؛ سپس پس از آماده کردن VPS مقدار repository variable `PRODUCTION_DEPLOY_ENABLED=true` و اجرای workflow با هر دو digest Release.
- ورود HTTPS، ایجاد/ورود QR و 2FA، فعال‌سازی و آزمون یک حساب واقعی.
- restart و سپس reboot، بررسی حساب و نشست بدون ورود دوباره؛ backup و آزمون restore روی سرور جداگانه.

## فرمان‌ها

```bash
sudo bash /opt/ubcc/scripts/docker/control.sh start
sudo bash /opt/ubcc/scripts/docker/control.sh stop
sudo bash /opt/ubcc/scripts/docker/control.sh restart
sudo bash /opt/ubcc/scripts/docker/control.sh status
sudo bash /opt/ubcc/scripts/docker/control.sh logs supervisor
sudo bash /opt/ubcc/scripts/docker/control.sh logs web
sudo bash /opt/ubcc/scripts/docker/control.sh logs gateway
sudo bash /opt/ubcc/scripts/docker/control.sh doctor
sudo bash /opt/ubcc/scripts/docker/control.sh backup
```

Docker logها با سقف 10MB و پنج فایل برای هر سرویس نگهداری می‌شوند. مدخل لاگ یا backup ممکن است داده حساس برنامه داشته باشد؛ خروجی را عمومی نکنید. backup سرویس‌ها را برای سازگاری داده متوقف می‌کند و اگر در حال اجرا بودند دوباره شروع می‌کند. backup زمان‌بندی‌شده را فقط پس از آزمون دستور روی سرور به cron root اضافه کنید؛ فایل‌ها را رمزگذاری و خارج سرور نگه دارید و فضای دیسک/اعتبار گواهی را پایش کنید. backup شامل secret است و mode600 دارد.

لاگ هر حساب در data/accounts جدا از Docker log است؛ اندازه آن را پایش و از گزینه پاک‌سازی لاگ در پنل استفاده کنید. آرشیو/media و backup سقف خودکار ندارند؛ retention موردنیاز خود را با حفظ backup سالم تنظیم کنید. حافظه PHP هر process سقف512MB دارد و وب حداکثر8 process می‌سازد؛ این مقدار ظرفیت کل VPS نیست. قبل از فعال‌سازی حساب‌های زیاد مصرف را با `sudo docker stats --no-stream` و `df -h /opt/ubcc` بررسی کنید.

برای update دستی، digest را از انتشار تأییدشده بگیرید:

```bash
sudo /usr/local/sbin/ubcc-deploy \
  ghcr.io/roboapi2/self-bot-telegram@sha256:REPLACE_WITH_64_HEX_DIGEST 0 \
  ghcr.io/roboapi2/self-bot-telegram-gateway@sha256:REPLACE_WITH_64_HEX_GATEWAY_DIGEST
```

اسکریپت pull و config validation را قبل از توقف انجام می‌دهد، همه workerها را می‌بندد، backup می‌گیرد، migration را اجرا می‌کند، سرویس‌های سالم را بالا می‌آورد و HTTPS معتبر را می‌سنجد؛ سپس state موفق را ثبت می‌کند. وقفه کوتاه وجود دارد. نشست مشترک مانع blue/green هم‌زمان است. در شکست، digest قبلی با همان داده جاری بالا می‌آید. تغییر schema باید با نسخه قبلی سازگار بماند؛ migration فعلی مخرب نیست.

سرویس `ubcc-docker.service` پس از Docker/network startup ترتیب Compose را اعمال می‌کند. restart policy خرابی سرویس را پوشش می‌دهد. پس از اولین deploy:

```bash
sudo systemctl start ubcc-docker
sudo systemctl status ubcc-docker
sudo reboot
```

پس از اتصال دوباره status و doctor و ورود پنل را بررسی کنید. راه‌اندازی پس از reboot در این محیط آزمون واقعی نشده است.

## تغییر پیکربندی و ابزار میزبان

نسخه جدید gateway سفارشی patched را از GHCR و فقط با digest اجرا می‌کند؛ Compose دیگر Caddy قدیمی ثابت ندارد. روی نصب Docker قبلی، قبل از فعال کردن deploy، از ابزارهای قدیمی backup بگیرید، workerها را متوقف کنید و بسته عمومی جدید شامل Compose، Caddyfile، تمام scripts/docker و wrapper `/usr/local/sbin/ubcc-deploy` را با مالک root نصب کنید؛ data/secrets/settings را بازنویسی نکنید. setup تکراری جایگزین این ارتقای بازبینی‌شده نیست.

state قدیمی که فقط `UBCC_IMAGE` دارد عمداً fail می‌شود. ابتدا runtime فعلی و gateway patched انتخابی را با دو ورودی manual workflow و deploy غیرفعال scan/smoke کنید. پس از قبولی، با `sudoedit /opt/ubcc/state/current.env` فقط خط `UBCC_GATEWAY_IMAGE=ghcr.io/roboapi2/self-bot-telegram-gateway@sha256:...` تأییدشده را اضافه کنید و همان pair را با دستور سه‌آرگومانی deploy کنید. نسخه قدیمی state را در backup نگه دارید. از previous.env قدیمی بدون gateway rollback نکنید؛ helper آن را رد می‌کند. قبولی بعدی، current/previous را با هر دو digest ثبت می‌کند. فایل‌های certificate موجود در data/caddy و data/caddy-config حفظ می‌شوند. این مراحل در این محیط روی VPS اجرا نشده‌اند.

قبل از sudoedit تنظیمات backup بگیرید. فایل‌ها root-controlled و secretها متعلق به UID10001 با mode0400 بمانند. تغییر `settings.env`/secretها با restart اعمال می‌شود؛ Caddy DNS جدید باید قبل از restart آماده باشد. deploy معمولی تنظیمات میزبان را تغییر نمی‌دهد و بسته backup همان تنظیمات را ثبت می‌کند. setup تکراری فایل‌های نصب‌شده را حفظ می‌کند؛ برای ارتقای خود compose یا script، diff بسته جدید را بازبینی و با backup قبلی، فایل‌های عمومی را توسط root جایگزین کنید. هرگز پوشه `data`، `secrets` یا `state` را با محتوای بسته جایگزین نکنید. برای rollback چنین ارتقایی، فایل‌های پیکربندی همان backup را انتخابی برگردانید.

## عیب‌یابی

SSH fail: host/port، مالکیت کلید، authorized_keys، fingerprint و sudoers را بررسی کنید. GHCR denied: دسترسی package، login متعلق به root و PAT read:packages را بررسی کنید. TLS fail: DNS، AAAA، پورت‌ها و gateway logs. Permission denied: مالکیت فایل‌های data و secret باید UID10001 باشد؛ chmod777 ندهید. doctor fail: API/رمز ناقص، migration و supervisor logs. حساب کار نمی‌کند: registry، وضعیت فعال و لاگ همان حساب را خصوصی بررسی کنید؛ همه سرویس‌های نصب قدیمی باید خاموش باشند. خرابی rollback: stop کرده و مطابق ROLLBACK_FA digest معلوم سالم را اجرا کنید؛ backup داده را بی‌دلیل restore نکنید.
