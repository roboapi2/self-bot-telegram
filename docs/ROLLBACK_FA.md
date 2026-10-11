# بازگشت نسخه و بازیابی داده

بازگشت معمولی فقط image را عوض می‌کند؛ نشست و registry جاری حفظ می‌شوند. تنظیمات میزبان در deploy خودکار تغییر نمی‌کنند. current.env فقط پس از health موفق ثبت می‌شود و previous.env نسخه موفق قبلی است. backup قبل از migration شامل تنظیمات همان لحظه نیز هست. در خطای deploy، نسخه قبلی خودکار force-recreate می‌شود؛ اگر health آن هم شکست بخورد فرمان با خطا تمام می‌شود و باید logs را بررسی کنید.

```bash
sudo bash /opt/ubcc/scripts/docker/control.sh rollback
sudo bash /opt/ubcc/scripts/docker/control.sh status
sudo bash /opt/ubcc/scripts/docker/control.sh doctor
```

اگر previous وجود ندارد، digest سالم را از Release قدیمی بخوانید و با `/usr/local/sbin/ubcc-deploy IMAGE@sha256:DIGEST` اجرا کنید. ورودی tag مانند edge/latest پذیرفته نمی‌شود. فرمان دستی sequence ندارد؛ deploy خودکار بعدی ممکن است نسخه جدیدتر را اعمال کند. برای توقف موقت انتشار، workflow یا دسترسی محیط production را در GitHub غیرفعال کنید. حذف/جابه‌جایی sequence راه rollback نیست.

## Restore داده، فقط برای حادثه داده

ابتدا health نسخه سالم را بررسی و backup جدید بگیرید. آرشیوهای `/opt/ubcc/backups` خصوصی هستند. این فرمان accounts و panel جاری را با داده backup جایگزین می‌کند و قبل از آن snapshot جدید می‌گیرد:

```bash
sudo bash /opt/ubcc/scripts/docker/restore.sh /opt/ubcc/backups/data-TIMESTAMP.tar.gz
sudo bash /opt/ubcc/scripts/docker/control.sh doctor
```

این عملیات از rollback image جدا و مخرب برای تغییرات پس از backup است؛ فقط با انتخاب آگاهانه backup سالم انجام دهید. script مسیرهای archive و symlink را رد می‌کند و فقط دو پوشه داده را restore می‌کند؛ secret، TLS و image جاری حفظ می‌شوند. اگر restore شکست خورد، سرویس ممکن است متوقف بماند؛ ابتدا خطا را اصلاح کنید و سپس start اجرا کنید. صحت نشست Telegram و سازگاری داده با image را روی سرور بررسی کنید. بازیابی کامل disaster recovery نیازمند نصب دوباره، بازیابی انتخابی config و credential و کپی data با مالکیت10001 است؛ از backup ناشناس استفاده نکنید.

پیکربندی میزبانِ تغییرکرده به‌صورت دستی را از backup پیش از تغییر در محل امن استخراج و diff کنید، سپس فقط فایل عمومی/secret موردنظر را با root برگردانید. rollback معمولی نباید accounts یا panel را از backup بازگرداند. آزمون restore را روی VPS جدا با نشست آزمایشی انجام دهید و آن را هم‌زمان با production روی همان حساب فعال نکنید.
